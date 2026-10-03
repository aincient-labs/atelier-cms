<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Demo;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Remembers which entities a studio seeded as demo content, and removes them.
 *
 * Backed by the `aincient_demo_content` table (see
 * aincient_core_demo_content_table() for why it is a table and not a base
 * field). A studio calls {@see self::track()} for each node or media item it
 * imports on first enable; "clear examples" — `drush atelier:demo-clear` and,
 * later, a button in the studio — calls {@see self::clear()}.
 *
 * THE TAG MEANS "CAME FROM US", NOT "UNTOUCHED". An owner who edited a demo page
 * and then clears the examples loses the edit: a clear that spared edited items
 * would leave half a demo behind, and an owner who wants to keep one should
 * simply save it as their own page first. Demo content is content, not config,
 * so this is the only thing that ever removes it — never `cim`, never an upgrade
 * (plans/studio-modules.md "Demo content", DECISIONS 0430).
 *
 * Only entities STILL PRESENT are deleted; a row whose entity the owner already
 * deleted by hand is dropped silently, because the goal state — it is gone — is
 * already true.
 */
final class DemoContentTracker {

  public const TABLE = 'aincient_demo_content';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Tag an entity as demo content of a studio (upsert: re-tagging moves it).
   */
  public function track(EntityInterface $entity, string $studio): void {
    $this->database->merge(self::TABLE)
      ->keys([
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => (string) $entity->id(),
      ])
      ->fields([
        'studio' => $studio,
        'created' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * The tracked rows, optionally for one studio.
   *
   * @return list<array{entity_type: string, entity_id: string, studio: string}>
   */
  public function tracked(?string $studio = NULL): array {
    $query = $this->database->select(self::TABLE, 'd')
      ->fields('d', ['entity_type', 'entity_id', 'studio'])
      ->orderBy('studio')
      ->orderBy('entity_type')
      ->orderBy('entity_id');
    if ($studio !== NULL) {
      $query->condition('studio', $studio);
    }
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'entity_type' => (string) $row->entity_type,
        'entity_id' => (string) $row->entity_id,
        'studio' => (string) $row->studio,
      ];
    }
    return $rows;
  }

  /**
   * Delete every tracked entity still present, then forget the rows.
   *
   * @param string|null $studio
   *   Limit to one studio, or NULL for all.
   *
   * @return int
   *   How many entities were actually deleted (already-gone ones do not count).
   */
  public function clear(?string $studio = NULL): int {
    $rows = $this->tracked($studio);
    $deleted = 0;
    $transaction = $this->database->startTransaction();
    try {
      foreach ($rows as $row) {
        if (!$this->entityTypeManager->hasDefinition($row['entity_type'])) {
          continue;
        }
        $entity = $this->entityTypeManager
          ->getStorage($row['entity_type'])
          ->load($row['entity_id']);
        if ($entity !== NULL) {
          $entity->delete();
          $deleted++;
        }
      }
      $delete = $this->database->delete(self::TABLE);
      if ($studio !== NULL) {
        $delete->condition('studio', $studio);
      }
      $delete->execute();
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('Clearing demo content failed: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
    unset($transaction);
    if ($deleted > 0) {
      $this->logger->info('Cleared @count demo entities (@studio).', [
        '@count' => $deleted,
        '@studio' => $studio ?? 'all studios',
      ]);
    }
    return $deleted;
  }

}
