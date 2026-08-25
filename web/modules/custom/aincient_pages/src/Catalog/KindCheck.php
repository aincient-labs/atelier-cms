<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The §6.3 dry run (plans/byo-components.md): would this catalog break live
 * pages? Compares every stored aincient_page structure against the effective
 * catalog its kind compiles to TODAY, and reports each slot whose component,
 * variant or tone the catalog no longer offers.
 *
 * A service — not drush code — so the analysis is kernel-testable and reusable
 * (the W9 `kind_check()` MCP tool renders the same rows). REPORT ONLY, by
 * design: narrowing a kind can orphan content, and a human decides what
 * happens to content — this never rewrites a node.
 *
 * "Impact" here means: the renderer/validator would degrade this slot on the
 * next save or render (component skipped, variant/tone clamped back to the
 * default). Catalog compile warnings ride along so a CI gate sees config typos
 * (unknown opener, empty variant subset) in the same report.
 */
final class KindCheck {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentCatalogInterface $catalog,
  ) {}

  /**
   * Analyse every aincient_page node (published AND unpublished — an
   * unpublished draft is still content a narrowing would orphan).
   *
   * @return array{rows: list<array<string, string>>, pages: int, impacts: int, warnings: int}
   *   rows: one per impact/warning, keys nid|title|langcode|status|kind|slot|
   *   component|impact|detail. pages: nodes examined. impacts: breaking rows.
   *   warnings: catalog compile warnings surfaced (nid '-').
   */
  public function run(): array {
    $rows = [];
    $pages = 0;
    $impacts = 0;

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'aincient_page')
      ->sort('nid')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_page_structure')) {
        continue;
      }
      $structure = json_decode((string) $node->get('field_page_structure')->value, TRUE);
      if (!is_array($structure)) {
        continue;
      }
      $pages++;
      // The stored kind; a missing/unknown type degrades to landing — the
      // exact fallback the validator and renderer apply (never fatal).
      $kind = trim((string) ($structure['type'] ?? ''));
      $kind = $kind === '' ? 'landing' : $kind;
      $catalog = $this->catalog->for($kind);

      // Recipe-mode structures own no slots — the typed body fields ARE the
      // content, and no catalog narrowing can orphan them.
      $slots = $structure['slots'] ?? NULL;
      if (!is_array($slots)) {
        continue;
      }

      $base = [
        'nid' => (string) $node->id(),
        'title' => (string) $node->label(),
        'langcode' => $node->language()->getId(),
        'status' => $node->isPublished() ? 'published' : 'unpublished',
        'kind' => $kind,
      ];
      $placeable = $catalog->placeableNames();
      foreach ($slots as $slot) {
        if (!is_array($slot)) {
          continue;
        }
        $component = (string) ($slot['component'] ?? '');
        $slotBase = $base + [
          'slot' => (string) ($slot['id'] ?? ''),
          'component' => $component,
        ];
        if (!in_array($component, $placeable, TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'component removed',
            'detail' => sprintf('"%s" is no longer placeable for kind "%s" — the renderer would skip this slot.', $component, $kind),
          ];
          // The component is gone; its variant/tone are moot.
          continue;
        }
        $variant = (string) ($slot['variant'] ?? '');
        if ($variant !== '' && !in_array($variant, $catalog->variantsFor($component) ?? [], TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'variant removed',
            'detail' => sprintf('variant "%s" is no longer offered by "%s" (now: %s) — a save would clamp it to the default.', $variant, $component, implode('|', $catalog->variantsFor($component) ?? [])),
          ];
        }
        $tone = (string) ($slot['tone'] ?? '');
        if ($tone !== '' && !in_array($tone, $catalog->tonesFor($component), TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'tone removed',
            'detail' => sprintf('tone "%s" is no longer offered by "%s" (now: %s) — a save would clamp it to the default.', $tone, $component, implode('|', $catalog->tonesFor($component))),
          ];
        }
      }
    }

    // Compile warnings per kind: never-fatal degradations (unknown opener,
    // empty variant subset…) a CI gate should see beside the content impacts.
    $warnings = 0;
    foreach (array_keys($this->catalog->kinds()) as $kindId) {
      foreach ($this->catalog->for($kindId)->warnings() as $warning) {
        $warnings++;
        $rows[] = [
          'nid' => '-',
          'title' => '-',
          'langcode' => '-',
          'status' => '-',
          'kind' => $kindId,
          'slot' => '-',
          'component' => '-',
          'impact' => 'catalog warning',
          'detail' => $warning,
        ];
      }
    }

    return ['rows' => $rows, 'pages' => $pages, 'impacts' => $impacts, 'warnings' => $warnings];
  }

}
