<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

/**
 * The one shape every reference collapses to — built here so providers can't
 * drift. A descriptor is what the picker card, the in-field preview and the
 * agent's find tool all consume, regardless of the underlying entity type:
 *
 *   token        the opaque text token the schema stores (the only persisted bit)
 *   type         the reference type key (media / node / block / …)
 *   label        a human title (never empty — falls back to "(untitled)")
 *   description   a one-line gloss (alt text / body summary / "N sections" / '')
 *   thumb        an image URL for a visual preview, or NULL
 *   status       'published' | 'unpublished' | NULL (when the type has no status)
 *   edit_url     a link to edit the resource, or NULL (e.g. blocks edit in-studio)
 *   meta         type-specific extras (bundle, section count, …) — opaque to the UI
 */
final class ReferenceDescriptor {

  /**
   * Build a descriptor array — the single source of the shape.
   *
   * @return array{token: string, type: string, label: string, description: string, thumb: ?string, status: ?string, edit_url: ?string, meta: array}
   */
  public static function create(
    string $token,
    string $type,
    string $label,
    string $description = '',
    ?string $thumb = NULL,
    ?bool $published = NULL,
    ?string $editUrl = NULL,
    array $meta = [],
  ): array {
    return [
      'token' => $token,
      'type' => $type,
      'label' => trim($label) !== '' ? $label : '(untitled)',
      'description' => $description,
      'thumb' => $thumb,
      'status' => $published === NULL ? NULL : ($published ? 'published' : 'unpublished'),
      'edit_url' => $editUrl,
      'meta' => $meta,
    ];
  }

}
