<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Where components are used — read from the derived `field_component_usage`
 * index (DECISIONS 0455, P1).
 *
 * {@see PageStore::writeSchema()} writes the index on every save of a page or
 * block (the `field_page_type` pattern, 0329), so nothing here parses page
 * JSON at read time. Counts the LATEST revision of each entity: a forward
 * draft that uses a component counts — narrowing would reach it too.
 *
 * Keys: `c:<component>`, `v:<component>:<variant>`, `t:<component>:<tone>`.
 */
final class UsageIndex {

  /**
   * The derived index field, on `aincient_page` nodes and `block` media.
   */
  public const FIELD = 'field_component_usage';

  /**
   * The indexed entity types and the bundle each is filtered to.
   */
  private const TARGETS = [
    'node' => ['bundle_key' => 'type', 'bundle' => 'aincient_page', 'kind' => 'page'],
    'media' => ['bundle_key' => 'bundle', 'bundle' => 'block', 'kind' => 'block'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * The usage keys for a merged page schema (top-level slots only).
   *
   * @return list<string>
   */
  public static function keys(array $schema): array {
    $keys = [];
    foreach ($schema['sections'] ?? [] as $section) {
      $name = is_array($section) ? (string) ($section['component'] ?? '') : '';
      if ($name === '') {
        continue;
      }
      $keys["c:$name"] = TRUE;
      $props = is_array($section['props'] ?? NULL) ? $section['props'] : [];
      foreach (['variant' => 'v', 'tone' => 't'] as $prop => $prefix) {
        if (is_string($props[$prop] ?? NULL) && $props[$prop] !== '') {
          $keys["$prefix:$name:{$props[$prop]}"] = TRUE;
        }
      }
    }
    return array_keys($keys);
  }

  /**
   * How many pages and blocks use each key — one grouped query per entity type.
   *
   * @return array<string, array{pages: int, blocks: int}>
   */
  public function counts(): array {
    $out = [];
    foreach (self::TARGETS as $entityType => $target) {
      $vids = $this->latestRevisionIds($entityType);
      if ($vids === []) {
        continue;
      }
      $table = $this->revisionTable($entityType);
      if ($table === NULL) {
        continue;
      }
      $query = $this->database->select($table, 'u');
      $query->addField('u', self::FIELD . '_value', 'k');
      $query->addExpression('COUNT(DISTINCT u.entity_id)', 'n');
      $query->condition('u.revision_id', $vids, 'IN');
      $query->groupBy('u.' . self::FIELD . '_value');
      $bucket = $target['kind'] === 'page' ? 'pages' : 'blocks';
      foreach ($query->execute() as $row) {
        $out[$row->k] ??= ['pages' => 0, 'blocks' => 0];
        $out[$row->k][$bucket] = (int) $row->n;
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * The pages and blocks (latest revision) using one key, titled, capped.
   *
   * @return array{total: int, items: list<array{type: string, id: string, title: string}>}
   */
  public function where(string $key, int $limit = 10): array {
    $items = [];
    $total = 0;
    foreach (self::TARGETS as $entityType => $target) {
      if (!$this->entityTypeManager->hasDefinition($entityType)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entityType);
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entityType);
      if (!isset($definitions[self::FIELD])) {
        continue;
      }
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->latestRevision()
        ->condition($target['bundle_key'], $target['bundle'])
        ->condition(self::FIELD, $key)
        ->execute();
      $total += count($ids);
      $take = array_slice($ids, 0, max(0, $limit - count($items)), TRUE);
      foreach ($take as $vid => $id) {
        $entity = $storage->loadRevision($vid);
        $items[] = [
          'type' => $target['kind'],
          'id' => (string) $id,
          'title' => $entity !== NULL ? (string) $entity->label() : (string) $id,
        ];
      }
    }
    return ['total' => $total, 'items' => $items];
  }

  /**
   * The latest revision id of every indexed entity of one type.
   *
   * @return list<int>
   */
  private function latestRevisionIds(string $entityType): array {
    $target = self::TARGETS[$entityType];
    if (!$this->entityTypeManager->hasDefinition($entityType)) {
      return [];
    }
    try {
      $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entityType);
      if (!isset($definitions[self::FIELD])) {
        return [];
      }
      $ids = $this->entityTypeManager->getStorage($entityType)->getQuery()
        ->accessCheck(FALSE)
        ->latestRevision()
        ->condition($target['bundle_key'], $target['bundle'])
        ->execute();
    }
    catch (\Throwable) {
      return [];
    }
    return array_map('intval', array_keys($ids));
  }

  /**
   * The field's revision table, or NULL when the field has no SQL storage.
   */
  private function revisionTable(string $entityType): ?string {
    $storage = $this->entityTypeManager->getStorage($entityType);
    if (!method_exists($storage, 'getTableMapping')) {
      return NULL;
    }
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entityType);
    $definition = $definitions[self::FIELD] ?? NULL;
    return $definition === NULL ? NULL : $storage->getTableMapping()->getDedicatedRevisionTableName($definition);
  }

}
