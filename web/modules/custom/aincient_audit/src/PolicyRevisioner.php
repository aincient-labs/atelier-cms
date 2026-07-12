<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Records and restores per-policy revisions (Phase 4).
 *
 * The Brand-studio parallel ({@see \Drupal\aincient_pages\BrandRevisioner}),
 * adapted to the fact that policies are MANY config entities rather than one:
 * every revision is keyed by `policy_id`, and dedup / prune / restore all scope
 * to a single policy. snapshot() is called from the config-save subscriber on
 * every policy save, so the history captures every write path (the studio Publish
 * endpoint, the bare admin form, config import, the install/seed) without
 * instrumenting each writer. restore() writes a past snapshot back to config —
 * which itself fires another save, so a restore is just another point in history.
 */
final class PolicyRevisioner {

  /**
   * Keep at most this many revisions PER POLICY; older ones are pruned.
   */
  public const MAX_REVISIONS = 50;

  public const ENTITY_TYPE = 'aincient_policy_revision';

  /**
   * The config-name prefix shared by every `aincient_policy` config entity.
   *
   * `aincient_audit` (provider) + `policy` (config_prefix) → `aincient_audit.policy.<id>`.
   */
  public const CONFIG_PREFIX = 'aincient_audit.policy.';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  private function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
  }

  /**
   * Record a snapshot of one policy's config as it now stands.
   *
   * Dedupes against the latest revision OF THE SAME POLICY: if the data is
   * byte-identical, nothing is written. Prunes that policy's history to
   * MAX_REVISIONS afterwards.
   */
  public function snapshot(string $policyId, array $data, string $summary): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $latest = $this->latest($policyId);
    if ($latest && $latest->get('data')->value === $json) {
      return;
    }
    $this->storage()->create([
      'uid' => $this->currentUser->id(),
      'policy_id' => $policyId,
      'summary' => mb_substr($summary, 0, 512),
      'data' => $json,
    ])->save();
    $this->prune($policyId);
  }

  /**
   * The most recent revision of a policy, or NULL.
   */
  public function latest(string $policyId): ?ContentEntityInterface {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('policy_id', $policyId)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();
    /** @var \Drupal\Core\Entity\ContentEntityInterface|null $entity */
    $entity = $ids ? $this->storage()->load((int) reset($ids)) : NULL;
    return $entity;
  }

  /**
   * Recent revisions of a policy, newest first.
   *
   * @return \Drupal\aincient_audit\Entity\PolicyRevision[]
   *   The loaded revision entities.
   */
  public function recent(string $policyId, int $limit = self::MAX_REVISIONS): array {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('policy_id', $policyId)
      ->sort('id', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $ids ? array_values($this->storage()->loadMultiple($ids)) : [];
  }

  /**
   * Restore a policy to the snapshot with the given revision id.
   *
   * Writes the stored snapshot back to the policy's config verbatim; the
   * resulting save is itself snapshotted by the subscriber, so the restore
   * appears in history and remains reversible.
   *
   * @return bool
   *   TRUE if the revision was found and restored.
   */
  public function restore(int $id): bool {
    $revision = $this->storage()->load($id);
    if (!$revision) {
      return FALSE;
    }
    $policyId = (string) $revision->get('policy_id')->value;
    $data = json_decode((string) $revision->get('data')->value, TRUE);
    if ($policyId === '' || !is_array($data)) {
      return FALSE;
    }
    $this->configFactory->getEditable(self::CONFIG_PREFIX . $policyId)->setData($data)->save();
    return TRUE;
  }

  /**
   * Delete a policy's revisions beyond MAX_REVISIONS, keeping the newest.
   */
  private function prune(string $policyId): void {
    $ids = $this->storage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('policy_id', $policyId)
      ->sort('id', 'DESC')
      ->range(self::MAX_REVISIONS, 1000000)
      ->execute();
    if ($ids) {
      $this->storage()->delete($this->storage()->loadMultiple($ids));
    }
  }

}
