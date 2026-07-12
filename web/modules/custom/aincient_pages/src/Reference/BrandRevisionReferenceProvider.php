<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Reference;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * The `entity:aincient_brand_revision:<id>` reference type — brand history.
 *
 * A DISCOVERY-ONLY citizen of the catalog. A brand revision is a bare
 * point-in-time snapshot of the site brand ({@see \Drupal\aincient_pages\Entity\BrandRevision})
 * written on every brand save — it has no canonical URL, no edit form and no
 * meaningful view display, so unlike media/node/user it is not MEANT to be
 * page-embedded: the studio embed picker never offers it (it requests explicit
 * types), so a brand-revision token only surfaces on an explicit
 * `types=aincient_brand_revision` search or a describe(). What it gives the unified
 * layer is uniform SEARCH + DESCRIBE, so the agent's find tool and any future
 * brand-history UI can list and preview past looks through the one code path —
 * proving the catalog generalises to a type that is referenceable for discovery
 * but not for rendering.
 */
final class BrandRevisionReferenceProvider implements ReferenceProviderInterface {

  private const ENTITY_TYPE = 'aincient_brand_revision';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public function typeKey(): string {
    return self::ENTITY_TYPE;
  }

  public function search(?string $query, int $limit): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $q = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, $limit);
    // A revision has no title — its only human handle is the change summary.
    if ($query !== NULL && trim($query) !== '') {
      $q->condition('summary', trim($query), 'CONTAINS');
    }
    $out = [];
    foreach ($storage->loadMultiple($q->execute()) as $revision) {
      $out[] = $this->toDescriptor($revision);
    }
    return $out;
  }

  public function describe(int $id): ?array {
    $revision = $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->load($id);
    return $revision !== NULL ? $this->toDescriptor($revision) : NULL;
  }

  private function toDescriptor(object $revision): array {
    $summary = trim((string) $revision->get('summary')->value);
    $created = (int) $revision->get('created')->value;
    // The entity has no label key and no version field, but its id is a
    // monotonic per-save ordinal — so a "version + date" combo is the natural,
    // scannable handle (ids can have GAPS once pruning drops the oldest, so vN
    // is a stable unique ordinal, not a gapless 1..N sequence).
    $when = $created > 0 ? $this->dateFormatter->format($created, 'short') : '';
    $label = sprintf('Brand v%s', $revision->id());
    if ($when !== '') {
      $label .= ' · ' . $when;
    }
    return ReferenceDescriptor::create(
      token: 'entity:' . self::ENTITY_TYPE . ':' . $revision->id(),
      type: self::ENTITY_TYPE,
      label: $label,
      // What changed, plus who changed it.
      description: $this->changeGloss($revision, $summary),
      thumb: NULL,
      // A revision has no published/unpublished state, and no canonical/edit
      // form — it is restored from the brand history, not edited at a node form.
      published: NULL,
      editUrl: NULL,
      meta: ['created' => $created, 'version' => (int) $revision->id()],
    );
  }

  /**
   * The change summary, suffixed with "— by <author>" when the author is known.
   */
  private function changeGloss(object $revision, string $summary): string {
    $author = '';
    if ($revision instanceof EntityOwnerInterface) {
      $owner = $revision->getOwner();
      $author = $owner !== NULL && (int) $owner->id() > 0 ? $owner->getDisplayName() : '';
    }
    if ($summary !== '' && $author !== '') {
      return sprintf('%s — by %s', $summary, $author);
    }
    if ($author !== '') {
      return 'by ' . $author;
    }
    return $summary;
  }

}
