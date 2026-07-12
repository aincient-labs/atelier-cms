<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\aincient_pages\Exception\RevisionConflictException;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
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
   * The latest revision id for a moderated entity (the editable HEAD — may be a
   * forward draft ahead of the published default), or NULL if it's gone.
   *
   * $entityTypeId defaults to `node` so every existing Page/Block caller is
   * unchanged; the block store passes `media` now that a global block is a media
   * entity ({@see BlockStore}, DECISIONS 0138).
   */
  public function latestVid(string $id, string $entityTypeId = 'node'): ?int {
    $vid = $this->revisionStorage($entityTypeId)->getLatestRevisionId((int) $id);
    return $vid === NULL ? NULL : (int) $vid;
  }

  /**
   * Load the LATEST revision of a moderated entity (the revision the studio
   * edits), in the requested translation when present. This is the editable head
   * — distinct from the default (published) revision the public route renders.
   *
   * Returns a {@see ContentEntityInterface} (a node for pages, a media entity for
   * blocks), or NULL if it doesn't exist or isn't the expected bundle. Callers
   * that need node-specific API re-narrow with `instanceof NodeInterface`.
   */
  public function loadLatestRevision(string $id, string $bundle, ?string $langcode = NULL, string $entityTypeId = 'node'): ?ContentEntityInterface {
    $vid = $this->latestVid($id, $entityTypeId);
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
   */
  public function assertHead(string $id, ?int $baseVid, string $entityTypeId = 'node'): void {
    if ($baseVid === NULL) {
      return;
    }
    $current = $this->latestVid($id, $entityTypeId);
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

  private function revisionStorage(string $entityTypeId): RevisionableStorageInterface {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    return $storage;
  }

}
