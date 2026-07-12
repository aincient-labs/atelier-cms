<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Splits a page-schema into its STRUCTURE and CONTENT layers, and merges them
 * back. The single source of the layering rule.
 *
 * A page is authored, edited and rendered as one merged schema
 * (`{type, title, sections:[{id, component, props}]}`) — the shape the agent op
 * grammar, the studio and the renderer all speak. STORAGE, however, keeps two
 * fields so translation can share one layout while the words diverge per
 * language (Drupal field translation is per-field — a translatable field
 * translates its WHOLE value, so "structure shared, content per-language" is
 * impossible inside one field):
 *
 *   field_page_structure  { type, slots:[ { id, component, tone?, variant?, columns? } ] }
 *   field_page_content    { title, slots:{ "<slotId>": { …everything else… } } }
 *
 * The split is by ONE axis only — translatability. STRUCTURAL props (the
 * language-independent layout knobs {@see STRUCTURAL_PROPS}) stay with the
 * slot; everything else (copy, URLs, image refs, repeatable rows) is the
 * per-language content overlay, keyed by the slot's stable id (Phase 1). The
 * codec is a pure value transform: {@see PageStore} validates and persists;
 * the resolver ({@see PageStore::resolve}) handles per-language inheritance.
 *
 * Invariant: merge(split($schema)) equals $schema for any validated schema
 * (modulo prop key ordering, which carries no meaning).
 */
final class PageSchemaCodec {

  /**
   * The structural (language-independent) prop keys — the layout/appearance
   * knobs that travel with the slot, shared across all languages. Everything
   * else a component declares is content (translatable). Matches the locked
   * PROP_VOCAB structural words in {@see ComponentCatalog}.
   *
   * `ref` (the global-block placement on a `block` slot) is structural: "which
   * block goes here" is a shared layout decision, while the block ENTITY owns its
   * per-language copy. `entity` (an `embed` token) is deliberately NOT structural
   * — it's content, so a translation may embed its own entity (an empty overlay
   * inherits the source token), mirroring how media tokens live in content.
   */
  public const STRUCTURAL_PROPS = ['tone', 'variant', 'columns', 'ref'];

  /**
   * Split a merged page-schema into its structure + content layers.
   *
   * @param array $schema
   *   A validated merged schema ({@see PageStore::validate}).
   *
   * @return array{structure: array, content: array}
   *   The two stored layers.
   */
  public static function split(array $schema): array {
    $type = ($schema['type'] ?? '') === 'blog' ? 'blog' : 'landing';

    // Blog is a locked content recipe: no slots, the body fields ARE the
    // content. Structure carries only the regime; content carries the rest.
    if ($type === 'blog') {
      $content = $schema;
      unset($content['type']);
      return ['structure' => ['type' => 'blog'], 'content' => $content];
    }

    $structure = ['type' => 'landing', 'slots' => []];
    $content = ['title' => (string) ($schema['title'] ?? ''), 'slots' => []];
    foreach ($schema['sections'] ?? [] as $section) {
      $id = (string) ($section['id'] ?? '');
      $props = is_array($section['props'] ?? NULL) ? $section['props'] : [];
      $slot = ['id' => $id, 'component' => $section['component'] ?? ''];
      foreach (self::STRUCTURAL_PROPS as $key) {
        if (array_key_exists($key, $props)) {
          $slot[$key] = $props[$key];
        }
      }
      $structure['slots'][] = $slot;
      // The content overlay = everything that isn't structural.
      $content['slots'][$id] = array_diff_key($props, array_flip(self::STRUCTURAL_PROPS));
    }
    return ['structure' => $structure, 'content' => $content];
  }

  /**
   * Merge a structure + content layer back into a merged page-schema.
   *
   * @param array $structure
   *   The structure layer ({type, slots}).
   * @param array $content
   *   The content layer ({title, slots} for landing; body fields for blog).
   *
   * @return array
   *   The merged schema (validate() shape) — ready for the renderer / op grammar.
   */
  public static function merge(array $structure, array $content): array {
    $type = ($structure['type'] ?? '') === 'blog' ? 'blog' : 'landing';

    if ($type === 'blog') {
      // type first, then the body fields (title, category, …).
      $merged = ['type' => 'blog'];
      foreach ($content as $key => $value) {
        if ($key !== 'type') {
          $merged[$key] = $value;
        }
      }
      return $merged;
    }

    $overlay = is_array($content['slots'] ?? NULL) ? $content['slots'] : [];
    $merged = [
      'type' => 'landing',
      'title' => (string) ($content['title'] ?? 'AIncient page'),
      'sections' => [],
    ];
    foreach ($structure['slots'] ?? [] as $slot) {
      $id = (string) ($slot['id'] ?? '');
      // Structural props live on the slot; content props in the overlay.
      $props = array_intersect_key($slot, array_flip(self::STRUCTURAL_PROPS));
      $props += is_array($overlay[$id] ?? NULL) ? $overlay[$id] : [];
      $merged['sections'][] = [
        'id' => $id,
        'component' => $slot['component'] ?? '',
        'props' => $props,
      ];
    }
    return $merged;
  }

}
