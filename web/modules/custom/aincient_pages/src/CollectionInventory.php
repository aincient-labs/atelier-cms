<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Owns a collection's query spec → canonical form → content hash, and finds
 * every placed index-mode collection across the site.
 *
 * The single place that turns "which posts, in what order" into a stable
 * identifier (DECISIONS 0329). The JSON index route ({@see \Drupal\aincient_pages\Controller\CollectionDataController}),
 * the static-export path inventory and the `collection` component all address
 * the same file through THIS hash, so a normalisation change lands in one place
 * and every consumer follows.
 *
 * Content-addressed by the QUERY (source + filter + sort), never by the page or
 * section position: the same "latest posts" strip on five landing pages, and
 * the blog index that lists them, all resolve to one hash and one JSON file —
 * stable under section reordering. The page size (`limit`/`per_page`) is
 * deliberately NOT in the hash: it bounds how many tiles render, not WHICH
 * posts match, and the JSON index carries the full ordered set the client
 * paginates over.
 */
final class CollectionInventory {

  /**
   * The default content set — blog posts. `source` names WHICH set of pages a
   * collection lists; today only the blog recipe produces a listable set.
   */
  public const DEFAULT_SOURCE = 'blog';

  /**
   * The content sets a collection may list. Each maps to a `field_page_type`
   * value ({@see PageStore::writePageType}); `filter`/taxonomy narrowing within
   * a source is deferred (see plans/collection-listings.md).
   */
  public const SOURCES = ['blog'];

  /**
   * Sort axes, first is the clamp default. Both sort on the node's own
   * `created` — the queryable projection of a post's authored date
   * ({@see PageStore::writePostDate}).
   */
  public const SORTS = ['newest', 'oldest'];

  public const DEFAULT_SORT = 'newest';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Reduce an authored spec to its canonical, hash-stable form.
   *
   * Clamps `source`/`sort` to the allow-list (an unknown value degrades to the
   * default rather than producing an empty listing) and sorts the filter map so
   * two specs that differ only in key order hash identically.
   *
   * @param array $spec
   *   The raw spec: {source?, filter?, sort?}. Extra keys are ignored — only
   *   the query-affecting ones survive into the canonical form.
   *
   * @return array{source: string, sort: string, filter: array}
   *   The canonical spec.
   */
  public function normalize(array $spec): array {
    $source = is_string($spec['source'] ?? NULL) && in_array($spec['source'], self::SOURCES, TRUE)
      ? $spec['source']
      : self::DEFAULT_SOURCE;
    $sort = in_array($spec['sort'] ?? NULL, self::SORTS, TRUE)
      ? $spec['sort']
      : self::DEFAULT_SORT;
    $filter = is_array($spec['filter'] ?? NULL) ? $spec['filter'] : [];
    // Sort recursively so nested narrowing (deferred, but forward-stable) never
    // makes the hash depend on authoring key order.
    $this->ksortRecursive($filter);
    return ['source' => $source, 'sort' => $sort, 'filter' => $filter];
  }

  /**
   * The content hash for a spec — the `{hash}` in `/_data/collections/{hash}.json`.
   *
   * A truncated SHA-256 of the canonical spec: collision-free for the handful
   * of distinct listings a site carries, and short enough to sit in a URL and a
   * data attribute.
   */
  public function specHash(array $spec): string {
    return substr(hash('sha256', json_encode($this->normalize($spec), JSON_THROW_ON_ERROR)), 0, 16);
  }

  /**
   * Every DISTINCT index-mode collection placed on a published page, keyed by
   * hash → canonical spec.
   *
   * Scans the STRUCTURE layer (`field_page_structure`), where a collection's
   * query knobs live as language-independent structural props
   * ({@see PageSchemaCodec::STRUCTURAL_PROPS}). Strip-mode instances are skipped
   * — they carry no island and prefetch nothing, so they need no JSON file. The
   * export path inventory and the JSON route both enumerate over this map, so a
   * hash the route will serve is exactly a hash the exporter will write.
   *
   * @return array<string, array{source: string, sort: string, filter: array}>
   *   hash => canonical spec.
   */
  public function indexCollections(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'aincient_page')
      ->condition('status', 1)
      ->execute();
    $found = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node->hasField('field_page_structure')) {
        continue;
      }
      $structure = json_decode((string) $node->get('field_page_structure')->value, TRUE);
      if (!is_array($structure) || ($structure['type'] ?? '') !== 'landing') {
        continue;
      }
      foreach ($structure['slots'] ?? [] as $slot) {
        if (!is_array($slot) || ($slot['component'] ?? '') !== 'collection') {
          continue;
        }
        if (($slot['mode'] ?? 'strip') !== 'index') {
          continue;
        }
        $spec = [
          'source' => $slot['source'] ?? NULL,
          'filter' => $slot['filter'] ?? [],
          'sort' => $slot['sort'] ?? NULL,
        ];
        $found[$this->specHash($spec)] = $this->normalize($spec);
      }
    }
    return $found;
  }

  /**
   * Sort an array's keys recursively, in place.
   */
  private function ksortRecursive(array &$array): void {
    ksort($array);
    foreach ($array as &$value) {
      if (is_array($value)) {
        $this->ksortRecursive($value);
      }
    }
  }

}
