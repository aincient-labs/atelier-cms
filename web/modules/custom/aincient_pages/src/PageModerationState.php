<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read-only: the moderation state of ONE translation of a page, as a person
 * would read it ("Draft", "Needs review", "Published", "Archived").
 *
 * Moderation is per language ({@see NodeModeration::latestVid}), so the state
 * reported is that of the LATEST revision of the requested translation — the
 * one the studio edits — not the default revision. Labels come from the
 * workflow config via {@see NodeModeration::stateLabel}, never hardcoded.
 *
 * When the live (default-revision) copy of the translation is published but
 * the latest state is something else (a forward draft / review), the label
 * keeps both facts: "Draft · published copy live". A page that is not
 * published, or whose latest revision IS the published one, reads as the bare
 * state label.
 */
final class PageModerationState {

  public function __construct(
    private readonly NodeModeration $moderation,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Describe one page translation.
   *
   * A page with no translation in $langcode falls back to its source language
   * (the head loader does the same), which is what opens from a listing.
   *
   * @return array{state: string, label: string, live: bool}|null
   *   `state` is the latest revision's state id, `label` its display text
   *   (with the live-copy suffix when it applies), `live` whether a published
   *   copy of this translation is serving. NULL when the node is missing or
   *   not moderated.
   */
  public function describe(string|int $nid, string $bundle, ?string $langcode = NULL): ?array {
    $head = $this->moderation->loadHead((string) $nid, $bundle, $langcode);
    if ($head === NULL || !$this->moderation->isModerated($head)) {
      return NULL;
    }
    $state = $this->moderation->state($head);
    $label = $this->moderation->stateLabel($head);

    $default = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    $live = FALSE;
    // A translation that exists only in a forward revision has no live copy
    // (never fall back to the source language's copy here).
    if ($default !== NULL && $default->hasTranslation($head->language()->getId())) {
      $live = $default->getTranslation($head->language()->getId())->isPublished();
    }
    $published = $this->moderation->state($head) === 'published';
    if ($live && !$published) {
      $label .= ' · published copy live';
    }
    return ['state' => $state, 'label' => $label, 'live' => $live];
  }

}
