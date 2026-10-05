<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

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
 * "Impact" here means: the slot is KEPT and still renders, but its component,
 * variant or tone is no longer offered for new placement (DECISIONS 0455, P0 —
 * PageStore keeps existing slots across a narrowing). The Content studio marks
 * such a slot "No longer offered". Catalog compile warnings ride along so a CI
 * gate sees config typos (unknown opener, empty variant subset) in the same report.
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
  public function run(?callable $catalogFor = NULL, ?array $kinds = NULL): array {
    $rows = [];
    $pages = 0;
    $impacts = 0;
    // The catalog a kind compiles to: the live one, or — for the studio's dry
    // run — one compiled from UNSAVED changes (DECISIONS 0455).
    $catalogFor ??= fn(string $kind): EffectiveCatalog => $this->catalog->for($kind);
    $memo = [];

    foreach ($this->entities() as [$entity, $forcedKind]) {
      $structure = json_decode((string) $entity->get('field_page_structure')->value, TRUE);
      if (!is_array($structure)) {
        continue;
      }
      // The stored kind; a missing/unknown type degrades to landing — the
      // exact fallback the validator and renderer apply (never fatal). A block
      // is always checked under the block kind.
      $kind = $forcedKind ?? trim((string) ($structure['type'] ?? ''));
      $kind = $kind === '' ? 'landing' : $kind;
      if ($kinds !== NULL && !in_array($kind, $kinds, TRUE)) {
        continue;
      }
      $pages++;
      $catalog = $memo[$kind] ??= $catalogFor($kind);

      // Recipe-mode structures own no slots — the typed body fields ARE the
      // content, and no catalog narrowing can orphan them.
      $slots = $structure['slots'] ?? NULL;
      if (!is_array($slots)) {
        continue;
      }

      $base = [
        'nid' => (string) $entity->id(),
        'title' => (string) $entity->label(),
        'langcode' => $entity->language()->getId(),
        'status' => $entity instanceof EntityPublishedInterface && $entity->isPublished() ? 'published' : 'unpublished',
        'kind' => $kind,
        'type' => $forcedKind !== NULL ? 'block' : 'page',
      ];
      $placeable = $catalog->placeableNames();
      $counts = [];
      foreach ($slots as $slot) {
        if (!is_array($slot)) {
          continue;
        }
        $component = (string) ($slot['component'] ?? '');
        $counts[$component] = ($counts[$component] ?? 0) + 1;
        $slotBase = $base + [
          'slot' => (string) ($slot['id'] ?? ''),
          'component' => $component,
        ];
        if (!in_array($component, $placeable, TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'component no longer offered',
            'detail' => sprintf('"%s" is no longer offered for kind "%s" — kept, still renders; it can\'t be placed again.', $component, $kind),
          ];
          // The component is gone; its variant/tone are moot.
          continue;
        }
        $variant = (string) ($slot['variant'] ?? '');
        if ($variant !== '' && !in_array($variant, $catalog->variantsFor($component) ?? [], TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'variant no longer offered',
            'detail' => sprintf('variant "%s" is no longer offered by "%s" (now: %s) — kept on this slot, not offered for new ones.', $variant, $component, implode('|', $catalog->variantsFor($component) ?? [])),
          ];
        }
        $tone = (string) ($slot['tone'] ?? '');
        if ($tone !== '' && !in_array($tone, $catalog->tonesFor($component), TRUE)) {
          $impacts++;
          $rows[] = $slotBase + [
            'impact' => 'tone no longer offered',
            'detail' => sprintf('tone "%s" is no longer offered by "%s" (now: %s) — kept on this slot, not offered for new ones.', $tone, $component, implode('|', $catalog->tonesFor($component))),
          ];
        }
      }
      // Kind-level rules: a new opener the page doesn't start with, a new
      // limit the page already exceeds. Reported, never enforced on old pages.
      $opener = $catalog->opener();
      $first = is_array($slots[0] ?? NULL) ? (string) ($slots[0]['component'] ?? '') : '';
      if ($opener !== NULL && $opener !== '' && $slots !== [] && $first !== $opener) {
        $impacts++;
        $rows[] = $base + ['slot' => '-', 'component' => $opener, 'impact' => 'opener missing', 'detail' => sprintf('starts with "%s", not "%s".', $first, $opener)];
      }
      foreach ($catalog->limits() as $name => $max) {
        if (($counts[$name] ?? 0) > $max) {
          $impacts++;
          $rows[] = $base + ['slot' => '-', 'component' => $name, 'impact' => 'over limit', 'detail' => sprintf('uses "%s" %d times (limit %d).', $name, $counts[$name], $max)];
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

  /**
   * Every page node, then every block media entity (the block kind).
   *
   * @return iterable<array{0: \Drupal\Core\Entity\ContentEntityInterface, 1: string|null}>
   */
  private function entities(): iterable {
    $targets = [['node', 'type', 'aincient_page', 'nid', NULL]];
    if ($this->entityTypeManager->hasDefinition('media')) {
      $targets[] = ['media', 'bundle', 'block', 'mid', 'block'];
    }
    foreach ($targets as [$entityType, $bundleKey, $bundle, $idKey, $kind]) {
      $storage = $this->entityTypeManager->getStorage($entityType);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition($bundleKey, $bundle)
        ->sort($idKey)
        ->execute();
      foreach ($storage->loadMultiple($ids) as $entity) {
        if ($entity instanceof ContentEntityInterface && $entity->hasField('field_page_structure')) {
          yield [$entity, $kind];
        }
      }
    }
  }

}
