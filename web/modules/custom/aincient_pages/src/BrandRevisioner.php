<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Records and restores brand revisions.
 *
 * snapshot() is called from the config-save subscriber on every brand save, so
 * the history captures every write path (the no-AI form, the brand studio Publish endpoint,
 * the BrandRepository, config import) without instrumenting each writer.
 * restore() writes a past snapshot back to config — which itself fires another
 * save, so a restore is just another point in the history.
 */
final class BrandRevisioner {

  /**
   * Keep at most this many revisions; the oldest are pruned on each snapshot.
   */
  public const MAX_REVISIONS = 50;

  public const ENTITY_TYPE = 'aincient_brand_revision';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
  }

  /**
   * Record a snapshot of the brand config as it now stands.
   *
   * Dedupes: if the data is byte-identical to the most recent revision, nothing
   * is written. Prunes to MAX_REVISIONS afterwards.
   */
  public function snapshot(array $data, string $summary): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $latest = $this->latest();
    if ($latest && $latest->get('data')->value === $json) {
      return;
    }
    $this->storage()->create([
      'uid' => $this->currentUser->id(),
      'summary' => mb_substr($summary, 0, 512),
      'data' => $json,
    ])->save();
    $this->prune();
  }

  /**
   * The most recent revision, or NULL.
   */
  public function latest(): ?ContentEntityInterface {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    /** @var \Drupal\Core\Entity\ContentEntityInterface|null $entity */
    $entity = $ids ? $this->storage()->load((int) reset($ids)) : NULL;
    return $entity;
  }

  /**
   * Recent revisions, newest first.
   *
   * @return \Drupal\aincient_pages\Entity\BrandRevision[]
   *   The loaded revision entities.
   */
  public function recent(int $limit = self::MAX_REVISIONS): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $ids ? array_values($this->storage()->loadMultiple($ids)) : [];
  }

  /**
   * Restore the brand config to the snapshot with the given id.
   *
   * Writes the stored snapshot back to config verbatim; the resulting save is
   * itself snapshotted by the subscriber, so the restore appears in history and
   * remains reversible.
   *
   * @return bool
   *   TRUE if the revision was found and restored.
   */
  public function restore(int $id): bool {
    $revision = $this->storage()->load($id);
    if (!$revision) {
      return FALSE;
    }
    $data = json_decode((string) $revision->get('data')->value, TRUE);
    if (!is_array($data)) {
      return FALSE;
    }
    $this->configFactory->getEditable(BrandRepository::CONFIG)->setData($data)->save();
    return TRUE;
  }

  /**
   * Delete revisions beyond MAX_REVISIONS, keeping the newest.
   */
  private function prune(): void {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'DESC')
      ->range(self::MAX_REVISIONS, 1000000)
      ->execute();
    if ($ids) {
      $this->storage()->delete($this->storage()->loadMultiple($ids));
    }
  }

}
