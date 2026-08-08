<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Turns a collection query spec into pre-resolved listing records — the one
 * code path that runs at request time on a live appliance and at export time
 * for a static bundle (DECISIONS 0329; plans/collection-listings.md).
 *
 * Reads the real, indexed projection fields ({@see PageStore::writePageType}
 * for the type axis, the node's own `created` for the sort axis) so the query
 * is a genuine entity query — it filters and sorts in the database, never by
 * loading every node and filtering in PHP. Each record is flattened to the
 * shape both the `article-teaser` SDC (rendered HTML) and the JSON index
 * (client re-render) consume, with the media token already resolved to a
 * concrete derivative URL — the browser cannot resolve `media:<id>`, and an
 * unwarmed derivative 404s in a static export.
 */
final class CollectionResolver {

  /**
   * The image style teaser tiles render at (16:9, matching article-teaser's
   * aspect) — the same style the theme's node--teaser preprocess uses.
   */
  private const TEASER_STYLE = '640w360h';

  /**
   * How a `date` is formatted for display — from the queryable `created`
   * timestamp, which is always a real date, so a listing tile never renders a
   * `<time>` whose text is not a date (the open question the free-text schema
   * `date` raised — sidestepped by sourcing the shown date from `created`).
   */
  private const DATE_FORMAT = 'F j, Y';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityEmbedResolver $embed,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly CollectionInventory $inventory,
  ) {}

  /**
   * Resolve a spec to listing records plus their cache metadata and total.
   *
   * @param array $spec
   *   The query spec {source?, filter?, sort?} — clamped via the inventory.
   * @param string|null $langcode
   *   Language to resolve each post's teaser in (its active translation);
   *   NULL uses the current content language.
   * @param int|null $limit
   *   Bound the returned records (a strip's `limit`, an index page's
   *   `per_page`); NULL returns the whole matching set (the JSON index).
   *
   * @return array{records: array<int, array>, total: int, cacheability: \Drupal\Core\Cache\CacheableMetadata}
   *   `records` are the flattened listing rows; `total` is the full match count
   *   (unbounded by $limit) so a listing can show "showing N of total".
   */
  public function resolve(array $spec, ?string $langcode = NULL, ?int $limit = NULL): array {
    $spec = $this->inventory->normalize($spec);
    $storage = $this->entityTypeManager->getStorage('node');
    $cacheability = (new CacheableMetadata())
      // A publish/unpublish or new post must invalidate every listing. One
      // bundle here, so the narrower node_list:<bundle> tag buys nothing —
      // node_list is the correct, if coarse, axis (DECISIONS 0329).
      ->addCacheTags(['node_list']);

    $total = (int) $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'aincient_page')
      ->condition('status', 1)
      ->condition('field_page_type', $spec['source'])
      ->count()
      ->execute();

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'aincient_page')
      ->condition('status', 1)
      ->condition('field_page_type', $spec['source'])
      ->sort('created', $spec['sort'] === 'oldest' ? 'ASC' : 'DESC');
    if ($limit !== NULL && $limit > 0) {
      $query->range(0, $limit);
    }
    $ids = $query->execute();

    $records = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $node = $this->entityRepository->getTranslationFromContext($node, $langcode);
      $records[] = $this->record($node, $cacheability);
    }

    return ['records' => $records, 'total' => $total, 'cacheability' => $cacheability];
  }

  /**
   * The `article-teaser` prop bag for one record — the SDC's string props, with
   * the read-more link pointed back at the post.
   */
  public static function teaserProps(array $record): array {
    $props = [
      'title' => $record['title'],
      'url' => $record['url'],
      'summary' => $record['teaser'],
      'date' => $record['date'],
      'more_url' => $record['url'],
      'more_label' => 'Read more',
    ];
    if ($record['image'] !== '') {
      $props['image'] = $record['image'];
      $props['image_alt'] = $record['image_alt'];
    }
    return array_filter($props, static fn ($v) => $v !== '');
  }

  /**
   * The JSON index record for one row — flat, pre-resolved, and stable: the
   * shape the island re-renders from and the payload the export writes.
   */
  public static function jsonRecord(array $record): array {
    return [
      'id' => $record['id'],
      'title' => $record['title'],
      'url' => $record['url'],
      'date' => $record['date'],
      'created' => $record['created'],
      'tags' => $record['tags'],
      'image' => $record['image'],
      'teaser' => $record['teaser'],
    ];
  }

  /**
   * Flatten one node (in its active translation) to a listing record, bubbling
   * the node's cache metadata into the shared bag.
   */
  private function record(NodeInterface $node, CacheableMetadata $cacheability): array {
    $cacheability->addCacheableDependency($node);

    // toString(TRUE) so the alias's own cacheability (path_alias list tag)
    // bubbles up rather than leaking a metadata-in-render-context warning.
    $generated = $node->toUrl()->toString(TRUE);
    $cacheability->addCacheableDependency($generated);

    $title = $this->fieldString($node, 'field_teaser_title');
    if ($title === '') {
      $title = (string) $node->label();
    }

    $token = $this->fieldString($node, 'field_teaser_image');
    $image = '';
    $imageAlt = '';
    if ($token !== '') {
      $image = (string) ($this->embed->url($token, self::TEASER_STYLE) ?? '');
      $imageAlt = (string) ($this->embed->alt($token) ?? '');
    }

    $created = (int) $node->get('created')->value;

    return [
      'id' => (int) $node->id(),
      'title' => $title,
      'url' => $generated->getGeneratedUrl(),
      'created' => $created,
      'date' => $this->dateFormatter->format($created, 'custom', self::DATE_FORMAT),
      'teaser' => $this->fieldString($node, 'field_teaser_description'),
      'image' => $image,
      'image_alt' => $imageAlt,
      // Taxonomy is deferred (plans/collection-listings.md) — an empty, stable
      // axis so the JSON shape does not change when it lands.
      'tags' => [],
    ];
  }

  /**
   * A field's scalar `value`, or '' when the field is absent or empty.
   */
  private function fieldString(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim((string) $node->get($field)->value);
  }

}
