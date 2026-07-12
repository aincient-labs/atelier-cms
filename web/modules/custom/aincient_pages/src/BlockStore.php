<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\media\MediaInterface;

/**
 * Validates, persists, and resolves reusable GLOBAL BLOCKS.
 *
 * A global block is a saved page-schema FRAGMENT — a shared CTA, banner or
 * footer note authored once and placed on many pages. As of the Library IA
 * (DECISIONS 0137/0138) a block is a **`block` MEDIA entity** (was an
 * `aincient_block` node), so it lives in the Library alongside images and is
 * referenced by a media token. It carries the SAME translatable structure +
 * content fields as a page ({@see PageStore}), so a block reuses the whole
 * layering machinery: {@see PageSchemaCodec} splits/merges it, and
 * {@see PageStore::resolve} (which is entity-agnostic) handles per-language
 * inheritance. Moderation runs through {@see NodeModeration} with the `media`
 * entity type. The deliberate difference from a page is reach, not shape: a page
 * renders standalone at its URL, whereas a block has no page of its own — it is
 * spliced into a host page wherever a `block` slot references it
 * ({@see PageSpikeController}). Editing the block updates every page that uses it.
 *
 * The host page's `block` slot still carries a `block:<id>` token where `<id>` is
 * now the MEDIA id (the `media:<id>` unification of the picker is a later Library
 * increment); {@see EntityEmbedResolver} parses both forms and both resolve here.
 *
 * Blocks are themselves composed of ordinary sections; a block may NOT contain a
 * `block` slot (nesting is stripped on write), so render-time expansion is always
 * one level deep and can never loop.
 */
final class BlockStore {

  /**
   * The media bundle a global block is stored as.
   */
  private const BUNDLE = 'block';

  /**
   * The block entity type — a media entity (DECISIONS 0138).
   */
  private const ENTITY_TYPE = 'media';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
    private readonly PageStore $pages,
    private readonly NodeModeration $moderation,
  ) {}

  /**
   * Validate + persist a fragment as a new `block` media entity.
   *
   * Like pages, a new block starts as a DRAFT; publishing is the explicit
   * transition ({@see publish}). content_moderation derives the status from the
   * moderation state.
   *
   * @return string
   *   The new block's media id.
   */
  public function store(array $schema): string {
    $media = $this->storage()->create([
      'bundle' => self::BUNDLE,
      'uid' => $this->currentUser->id(),
      'moderation_state' => 'draft',
    ]);
    // writeSchema validates + splits (and sets the media `name` from the title);
    // strip any nested block slots first so a block can only ever contain ordinary
    // sections (one-level expansion).
    $this->pages->writeSchema($media, $this->stripNested($schema));
    $media->save();
    return (string) $media->id();
  }

  /**
   * Validate + persist a fragment onto an EXISTING block media entity.
   *
   * Mirrors {@see PageStore::update}: a new attributed revision per save, and an
   * optional $langcode targets a translation (auto-created, symmetric — a block
   * translation overlays the source copy and inherits the source layout). Returns
   * FALSE if the id isn't a block, or $langcode names an unconfigured language.
   */
  public function update(string $id, array $schema, ?string $langcode = NULL): bool {
    $media = $this->storage()->load($id);
    if (!$media instanceof ContentEntityInterface || $media->bundle() !== self::BUNDLE) {
      return FALSE;
    }
    $target = $this->translationToWrite($media, $langcode);
    if ($target === NULL) {
      return FALSE;
    }
    $this->pages->writeSchema($target, $this->stripNested($schema));
    if ($media instanceof MediaInterface) {
      $media->setNewRevision(TRUE);
      $media->setRevisionUserId((int) $this->currentUser->id());
      $media->setRevisionLogMessage('Updated via the page studio (block).');
    }
    $media->save();
    return TRUE;
  }

  /**
   * Save the working fragment as a DRAFT (forward revision; live block unchanged).
   * The block parallel of {@see PageStore::saveDraft}. Create-or-update.
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   */
  public function saveDraft(array $schema, ?string $id = NULL, ?string $langcode = NULL, ?int $baseVid = NULL): ?array {
    if ($id === NULL) {
      return $this->stateEnvelope($this->store($schema));
    }
    return $this->editRevision($id, $schema, 'draft', $langcode, $baseVid, 'Saved draft via the page studio (block).');
  }

  /**
   * Write the latest fragment (if given) then PUBLISH the block — save + go-live
   * in one click. Also serves Approve (needs_review → published).
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   */
  public function publish(string $id, ?array $schema = NULL, ?string $langcode = NULL, ?int $baseVid = NULL): ?array {
    return $this->editRevision($id, $schema, 'published', $langcode, $baseVid, 'Published via the page studio (block).');
  }

  /**
   * Apply a pure editorial transition (submit / reject / archive / restore) to a
   * block — no fragment write, just a validated state change.
   *
   * @throws \Drupal\aincient_pages\Exception\RevisionConflictException
   */
  public function transition(string $id, string $transitionId, ?int $baseVid = NULL): ?array {
    $media = $this->moderation->loadLatestRevision($id, self::BUNDLE, NULL, self::ENTITY_TYPE);
    if ($media === NULL) {
      return NULL;
    }
    $target = $this->moderation->targetState($media, $transitionId);
    if ($target === NULL) {
      return NULL;
    }
    return $this->editRevision($id, NULL, $target, NULL, $baseVid, sprintf('Editorial transition: %s.', $transitionId));
  }

  /**
   * Load the LATEST revision of a block for editing (the editable head) as the
   * merged fragment plus its moderation/legibility envelope and base `vid`.
   * The block parallel of {@see PageStore::loadLatest}.
   */
  public function loadLatest(string $id, ?string $langcode = NULL): ?array {
    $media = $this->moderation->loadLatestRevision($id, self::BUNDLE, $langcode, self::ENTITY_TYPE);
    if ($media === NULL) {
      return NULL;
    }
    return [
      'node_id' => $id,
      'langcode' => $langcode,
      'schema' => $this->pages->resolve($media),
    ] + $this->moderation->legibility($media, (bool) $media->access('update'));
  }

  /**
   * Shared moderation-aware write for blocks: pin to base revision, optionally
   * write the (nested-stripped) fragment, set + validate the target state, stamp
   * a revision, save. Mirrors {@see PageStore::editRevision}.
   */
  private function editRevision(string $id, ?array $schema, string $targetState, ?string $langcode, ?int $baseVid, string $log): ?array {
    $this->moderation->assertHead($id, $baseVid, self::ENTITY_TYPE);
    $media = $this->moderation->loadLatestRevision($id, self::BUNDLE, NULL, self::ENTITY_TYPE);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    if ($this->moderation->state($media) !== $targetState
      && !$this->moderation->canReachState($media, $targetState)) {
      return NULL;
    }
    if ($schema !== NULL) {
      $target = $this->translationToWrite($media, $langcode);
      if ($target === NULL) {
        return NULL;
      }
      $this->pages->writeSchema($target, $this->stripNested($schema));
    }
    $media->set('moderation_state', $targetState);
    $media->setNewRevision(TRUE);
    $media->setRevisionUserId((int) $this->currentUser->id());
    $media->setRevisionLogMessage($log);
    $media->save();
    return $this->stateEnvelope($id);
  }

  /**
   * The post-write state envelope for a block id (state + transitions + base vid).
   */
  private function stateEnvelope(string $id): ?array {
    $media = $this->moderation->loadLatestRevision($id, self::BUNDLE, NULL, self::ENTITY_TYPE);
    if ($media === NULL) {
      return NULL;
    }
    return ['node_id' => $id] + $this->moderation->legibility($media, (bool) $media->access('update'));
  }

  /**
   * The resolved (merged) fragment schema for a block, or NULL if not a block.
   */
  public function load(string $id, ?string $langcode = NULL): ?array {
    $media = $this->storage()->load($id);
    if (!$media instanceof ContentEntityInterface || $media->bundle() !== self::BUNDLE) {
      return NULL;
    }
    if ($langcode !== NULL && $media->hasTranslation($langcode)) {
      $media = $media->getTranslation($langcode);
    }
    return $this->pages->resolve($media);
  }

  /**
   * The block's sections in $langcode, for render-time inline expansion.
   *
   * Returns an empty list for a missing/empty block (a dangling ref simply
   * renders nothing). Nested block slots can't exist (stripped on write), but are
   * defensively filtered here too so expansion is always one level deep.
   *
   * @return array<int, array>
   */
  public function resolveSections(string $id, ?string $langcode = NULL): array {
    $schema = $this->load($id, $langcode);
    $sections = is_array($schema['sections'] ?? NULL) ? $schema['sections'] : [];
    return array_values(array_filter(
      $sections,
      static fn($s): bool => is_array($s) && ($s['component'] ?? '') !== 'block',
    ));
  }

  /**
   * Alias of {@see resolveSections} for a `media:<id>` block token.
   *
   * A block is a media entity, so a `block:<id>` and a `media:<id>` token that
   * names a `block`-bundle media resolve identically; {@see PageSpikeController}
   * routes both here. Kept as a named seam for the media-token path.
   *
   * @return array<int, array>
   */
  public function resolveMediaSections(string $id, ?string $langcode = NULL): array {
    return $this->resolveSections($id, $langcode);
  }

  /**
   * A directory of saved blocks for the studio's block picker, newest-edited
   * first: `{ id, title, changed }` per block.
   *
   * @return array<int, array{id: string, title: string, changed: int}>
   */
  public function list(int $limit = 50): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', self::BUNDLE)
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    $out = [];
    foreach ($this->storage()->loadMultiple($ids) as $media) {
      $out[] = [
        'id' => (string) $media->id(),
        'title' => (string) $media->label(),
        'changed' => (int) $media->get('changed')->value,
      ];
    }
    return $out;
  }

  /**
   * Drop any nested `block` slot so a block is only ever composed of ordinary
   * sections — render-time expansion is therefore always one level deep.
   */
  private function stripNested(array $schema): array {
    if (!is_array($schema['sections'] ?? NULL)) {
      return $schema;
    }
    $schema['sections'] = array_values(array_filter(
      $schema['sections'],
      static fn($s): bool => !is_array($s) || ($s['component'] ?? '') !== 'block',
    ));
    return $schema;
  }

  /**
   * Resolve the translation a write should land on, creating it if needed.
   * (Block translations are always symmetric — they inherit the source layout.)
   */
  private function translationToWrite(ContentEntityInterface $media, ?string $langcode): ?ContentEntityInterface {
    $source = $media->getUntranslated()->language()->getId();
    if ($langcode === NULL || $langcode === $source) {
      return $media->getUntranslated();
    }
    if (!$this->languageManager->getLanguage($langcode)) {
      return NULL;
    }
    return $media->hasTranslation($langcode)
      ? $media->getTranslation($langcode)
      : $media->addTranslation($langcode);
  }

  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
  }

}
