<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\aincient_pages\Exception\RevisionConflictException;
use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\TranslatableRevisionableStorageInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * The editorial-workflow seam over Drupal core `content_moderation`.
 *
 * The page/block stores own page-SCHEMA shaping; this owns the MODERATION facts
 * around the node — which revision is the editable head, the current state and
 * its label, the transitions the current user may legally perform, and the
 * optimistic-concurrency check that pins a write to the revision it was based on.
 *
 * It exists so {@see PageStore} and {@see BlockStore} (both plain
 * `node`-bundle stores) share ONE moderation implementation rather than each
 * re-deriving forward-revision / transition logic. The decision "can this user
 * edit?" is NOT here — that is Drupal entity access (`$node->access('update')`),
 * read by every consumer; this only answers "what state, which revision, which
 * transitions" and performs the state change once the caller has authorised it.
 */
final class NodeModeration {

  public function __construct(
    private readonly ModerationInformationInterface $moderationInformation,
    private readonly StateTransitionValidationInterface $transitionValidation,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Whether a node bundle is under the editorial workflow at all.
   */
  public function isModerated(ContentEntityInterface $node): bool {
    return $this->moderationInformation->isModeratedEntity($node);
  }

  /**
   * Whether this entity moderates its translations INDEPENDENTLY.
   *
   * TRUE for a translatable bundle on a multilingual site — then every
   * translation has its own editable head (its latest translation-affecting
   * revision) and a new revision is built with core's translation-aware
   * {@see \Drupal\Core\Entity\ContentEntityStorageInterface::createRevision()},
   * so publishing one language never publishes another language's pending
   * draft. FALSE (monolingual site, or a bundle without content translation)
   * keeps the whole-entity model: one head = the latest revision.
   */
  public function isolatesTranslations(ContentEntityInterface $entity): bool {
    return $entity->isTranslatable();
  }

  /**
   * The editable HEAD revision id of one translation (may be a forward draft
   * ahead of the published default), or NULL if the entity is gone.
   *
   * Per-language when {@see isolatesTranslations}: the latest revision that
   * AFFECTED $langcode (core's `getLatestTranslationAffectedRevisionId()`), so
   * an EN save never moves the DE head and vice versa. $langcode NULL means the
   * SOURCE (default) translation. A translation that has never been written has
   * no head of its own: it resolves to the DEFAULT revision, which is exactly
   * the revision a new translation is built on (a pending source draft must not
   * ride into another language's first save). Not isolated → the entity's latest
   * revision, whatever language.
   *
   * $entityTypeId defaults to `node`; the block store passes `media`
   * ({@see BlockStore}, DECISIONS 0138).
   */
  public function latestVid(string $id, string $entityTypeId = 'node', ?string $langcode = NULL): ?int {
    $storage = $this->revisionStorage($entityTypeId);
    $default = $storage->load((int) $id);
    if ($default instanceof ContentEntityInterface
      && $storage instanceof TranslatableRevisionableStorageInterface
      && $this->isolatesTranslations($default)) {
      $langcode ??= $default->getUntranslated()->language()->getId();
      $vid = $storage->getLatestTranslationAffectedRevisionId((int) $id, $langcode);
      return (int) ($vid ?? $default->getRevisionId());
    }
    $vid = $storage->getLatestRevisionId((int) $id);
    return $vid === NULL ? NULL : (int) $vid;
  }

  /**
   * Load the raw editable HEAD revision of one translation ({@see latestVid}),
   * in $langcode when that translation exists in it (else the untranslated
   * entity). This is the base a WRITE starts from — pass it to
   * {@see createRevision}. Reads use {@see loadLatestRevision}.
   */
  public function loadHead(string $id, string $bundle, ?string $langcode = NULL, string $entityTypeId = 'node'): ?ContentEntityInterface {
    $vid = $this->latestVid($id, $entityTypeId, $langcode);
    if ($vid === NULL) {
      return NULL;
    }
    $entity = $this->revisionStorage($entityTypeId)->loadRevision($vid);
    if (!$entity instanceof ContentEntityInterface || $entity->bundle() !== $bundle) {
      return NULL;
    }
    if ($langcode !== NULL && $entity->hasTranslation($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }
    return $entity;
  }

  /**
   * Load the editable head of a moderated entity for READING (the revision the
   * studio shows), in the requested translation when present — distinct from
   * the default (published) revision the public route renders.
   *
   * The head of a non-source translation can be older than the default revision
   * (another language was saved since). Its own fields are current, but the
   * SOURCE translation riding in that old revision is not — and a symmetric
   * translation inherits its layout from it. So the returned entity overlays the
   * source translation's translatable fields from the DEFAULT revision: the same
   * merge core's createRevision() performs on the next write, so what the studio
   * shows is what that write will be based on. It is a read view: never save it
   * (writes go through {@see loadHead} + {@see createRevision}).
   *
   * Returns a {@see ContentEntityInterface} (a node for pages, a media entity for
   * blocks), or NULL if it doesn't exist or isn't the expected bundle. Callers
   * that need node-specific API re-narrow with `instanceof NodeInterface`.
   */
  public function loadLatestRevision(string $id, string $bundle, ?string $langcode = NULL, string $entityTypeId = 'node'): ?ContentEntityInterface {
    $entity = $this->loadHead($id, $bundle, $langcode, $entityTypeId);
    if ($entity === NULL
      || $entity->isDefaultRevision()
      || $entity->isDefaultTranslation()
      || !$this->isolatesTranslations($entity)) {
      return $entity;
    }
    $default = $this->revisionStorage($entityTypeId)->load((int) $id);
    if (!$default instanceof ContentEntityInterface) {
      return $entity;
    }
    $view = clone $entity;
    $source = $view->getUntranslated();
    $skip = $this->mergeSkippedFields($view);
    foreach ($default->getUntranslated()->getTranslatableFields(FALSE) as $name => $items) {
      if (!in_array($name, $skip, TRUE)) {
        $source->set($name, $items->getValue());
      }
    }
    return $view;
  }

  /**
   * Start the next revision of ONE translation: $head is the per-language head
   * from {@see loadHead}, in the translation being written.
   *
   * Isolated ({@see isolatesTranslations}): core's translation-aware
   * `createRevision()` — the active translation keeps its head values, every
   * OTHER translation is taken from the current default revision, so the new
   * revision carries no other language's pending draft. Not isolated: the
   * whole-entity model, a new revision of $head itself.
   *
   * The `default` flag is derived from the workflow, never a hardcoded state:
   * TRUE when $targetState is a default-revision state, or when the default
   * revision isn't published yet (content_moderation's own rule — a draft of a
   * never-published page IS the default). $targetState NULL (a content write
   * that doesn't change state) uses the translation's current state.
   * content_moderation re-derives the same flag on save; passing it here keeps
   * the revision object honest in between.
   */
  public function createRevision(ContentEntityInterface $head, ?string $targetState = NULL): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage($head->getEntityTypeId());
    if (!$this->isolatesTranslations($head) || !$storage instanceof ContentEntityStorageInterface) {
      $head->setNewRevision(TRUE);
      return $head;
    }
    return $storage->createRevision($head, $this->becomesDefault($head, $targetState ?? $this->state($head)));
  }

  /**
   * Whether a revision moving $entity to $state becomes the default revision,
   * per the entity's workflow (see {@see createRevision}). An unmoderated entity
   * has no pending revisions — every save is the default.
   */
  public function becomesDefault(ContentEntityInterface $entity, string $state): bool {
    $workflow = $this->moderationInformation->getWorkflowForEntity($entity);
    if (!$workflow || !$workflow->getTypePlugin()->hasState($state)) {
      return TRUE;
    }
    $definition = $workflow->getTypePlugin()->getState($state);
    return ($definition instanceof ContentModerationState && $definition->isDefaultRevisionState())
      || !$this->moderationInformation->isDefaultRevisionPublished($entity);
  }

  /**
   * Mark ONLY $target's translation as affected by the revision about to be
   * saved (no-op when not isolated).
   *
   * Core recomputes the flag from field changes, and a change to ANY
   * untranslatable field (the revision co-authors, revision_graph's provenance
   * field — both written on every save) counts as a change to every
   * translation. Left alone, a DE save would become EN's head too and hide EN's
   * own pending draft from the studio. Explicitly set flags are not recomputed.
   */
  public function pinAffectedTranslation(ContentEntityInterface $target): void {
    if (!$this->isolatesTranslations($target)) {
      return;
    }
    $written = $target->language()->getId();
    foreach (array_keys($target->getTranslationLanguages()) as $langcode) {
      $target->getTranslation($langcode)->setRevisionTranslationAffected($langcode === $written);
    }
  }

  /**
   * The translation a language-less write pinned to $baseVid is about, or NULL.
   *
   * A pure editorial transition carries the studio's `base_vid` but (from the
   * console today) no langcode. When $baseVid is a revision that affected
   * exactly one translation AND is still that translation's head, it can only
   * have come from that language's studio — resolve to it. Anything else
   * (ambiguous, stale, foreign) returns NULL and the caller falls back to the
   * source translation, where a stale base still fails {@see assertHead}.
   */
  public function langcodeForBase(string $id, int $baseVid, string $entityTypeId = 'node'): ?string {
    $revision = $this->revisionStorage($entityTypeId)->loadRevision($baseVid);
    if (!$revision instanceof ContentEntityInterface
      || (string) $revision->id() !== $id
      || !$this->isolatesTranslations($revision)) {
      return NULL;
    }
    $affected = [];
    foreach (array_keys($revision->getTranslationLanguages()) as $langcode) {
      if ($revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
        $affected[] = $langcode;
      }
    }
    if (count($affected) !== 1) {
      return NULL;
    }
    return $this->latestVid($id, $entityTypeId, $affected[0]) === $baseVid ? $affected[0] : NULL;
  }

  /**
   * The node's current moderation state id (e.g. `draft`, `published`).
   */
  public function state(ContentEntityInterface $node): string {
    return (string) $node->get('moderation_state')->value;
  }

  /**
   * The human label for the node's current state (from the workflow config).
   */
  public function stateLabel(ContentEntityInterface $node): string {
    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    $stateId = $this->state($node);
    if ($workflow && $workflow->getTypePlugin()->hasState($stateId)) {
      return (string) $workflow->getTypePlugin()->getState($stateId)->label();
    }
    return $stateId;
  }

  /**
   * A forward (pending) draft exists — the latest revision is newer than the
   * published default, so "what you're editing" ≠ "what's live".
   */
  public function hasPendingDraft(ContentEntityInterface $node): bool {
    return $this->moderationInformation->hasPendingRevision($node);
  }

  /**
   * The transitions the current user may legally perform FROM the node's current
   * state, as `[ { id, label, to, to_label } ]` — the source of truth for which
   * workflow buttons the studio shows. Read straight from
   * content_moderation, never a hand-rolled map.
   *
   * @return array<int, array{id: string, label: string, to: string, to_label: string}>
   */
  public function transitions(ContentEntityInterface $node, ?AccountInterface $account = NULL): array {
    $account ??= $this->currentUser;
    $out = [];
    foreach ($this->transitionValidation->getValidTransitions($node, $account) as $transition) {
      $out[] = [
        'id' => $transition->id(),
        'label' => (string) $transition->label(),
        'to' => $transition->to()->id(),
        'to_label' => (string) $transition->to()->label(),
      ];
    }
    return $out;
  }

  /**
   * Whether the current user may perform a named transition from the node's
   * current state (used to gate a transition before applying it).
   */
  public function canTransition(ContentEntityInterface $node, string $transitionId, ?AccountInterface $account = NULL): bool {
    foreach ($this->transitions($node, $account) as $transition) {
      if ($transition['id'] === $transitionId) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether the current user holds a legal transition from the node's current
   * state TO $targetState. This is the gate the stores use before setting a new
   * `moderation_state`, because a direct `$node->save()` does NOT enforce
   * content_moderation's transition constraint — so without this check an illegal
   * state change would persist and bypass the per-transition permission.
   */
  public function canReachState(ContentEntityInterface $node, string $targetState, ?AccountInterface $account = NULL): bool {
    foreach ($this->transitions($node, $account) as $transition) {
      if ($transition['to'] === $targetState) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The target state id a named transition would move this node to, or NULL if
   * the workflow has no such transition.
   */
  public function targetState(ContentEntityInterface $node, string $transitionId): ?string {
    $workflow = $this->moderationInformation->getWorkflowForEntity($node);
    if (!$workflow || !$workflow->getTypePlugin()->hasTransition($transitionId)) {
      return NULL;
    }
    return $workflow->getTypePlugin()->getTransition($transitionId)->to()->id();
  }

  /**
   * Guard a write against a stale base revision (optimistic concurrency).
   *
   * Throws {@see RevisionConflictException} (→ HTTP 409) when $baseVid is given
   * and the node's current latest revision has moved past it — someone advanced
   * the document since the studio loaded it, so the write would clobber newer
   * work. A NULL $baseVid skips the check (create path / legacy callers).
   *
   * The head is $langcode's own ({@see latestVid}; NULL = the source), so a DE
   * save is never rejected as stale because EN saved meanwhile, and vice versa.
   */
  public function assertHead(string $id, ?int $baseVid, string $entityTypeId = 'node', ?string $langcode = NULL): void {
    if ($baseVid === NULL) {
      return;
    }
    $current = $this->latestVid($id, $entityTypeId, $langcode);
    if ($current !== $baseVid) {
      throw new RevisionConflictException($baseVid, $current);
    }
  }

  /**
   * The state-legibility envelope the studio renders from: current state + label,
   * whether a draft is pending, the user's legal transitions, and the base `vid`
   * to pin the next write to. `can_edit` is the SURFACED result of the access
   * call the caller passes in — not a second source of truth.
   *
   * @return array{moderation_state: string, state_label: string, has_pending_draft: bool, can_edit: bool, transitions: array<int, array{id: string, label: string, to: string, to_label: string}>, base_vid: int}
   */
  public function legibility(ContentEntityInterface $node, bool $canEdit): array {
    return [
      'moderation_state' => $this->state($node),
      'state_label' => $this->stateLabel($node),
      'has_pending_draft' => $this->hasPendingDraft($node),
      'can_edit' => $canEdit,
      'transitions' => $this->transitions($node),
      'base_vid' => (int) $node->getRevisionId(),
    ];
  }

  /**
   * Fields never copied by the read overlay — the same revision bookkeeping
   * core's createRevision() merge skips, plus the language keys.
   *
   * @return string[]
   */
  private function mergeSkippedFields(ContentEntityInterface $entity): array {
    $type = $entity->getEntityType();
    $skip = array_values($type->getRevisionMetadataKeys());
    foreach (['id', 'revision', 'revision_translation_affected', 'langcode', 'default_langcode', 'uuid', 'bundle'] as $key) {
      if ($type->hasKey($key)) {
        $skip[] = $type->getKey($key);
      }
    }
    return $skip;
  }

  private function revisionStorage(string $entityTypeId): RevisionableStorageInterface {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    return $storage;
  }

}
