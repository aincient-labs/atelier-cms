<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Update;

use Aincient\PageModel\Catalog\ComponentCatalogInterface;
use Aincient\PageModel\ComponentCatalog;
use Aincient\PageModel\ComponentId;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Psr\Log\LoggerInterface;

/**
 * The one-time rewrite of stored component names to full ids (0.30.0).
 *
 * DECISIONS 0535/0536, failure-modes plan S2/S3 + R3/R4. Content (D1–D3):
 * every row of the structure and content fields of pages and blocks, current
 * and revision tables, every language — decoded, mapped on the known paths
 * only ({@see ComponentId::mapStructure()}, {@see ComponentId::mapContent()}),
 * written back only when it changed, with PageStore's encoding. Config
 * (D4–D6): page kinds and the site constraint, through the config API (not
 * an entity save: a site's own kind is not a pack's bare input to warn
 * about). Idempotent: a full id is kept, so a second run changes nothing.
 * Batched by entity id and counted per table. The usage index (D7) is not
 * touched (S7).
 *
 * The same pass, restricted to rows holding `unresolved:` names, re-resolves
 * them when a module is installed ({@see resolveAfterInstall()}, S6.3).
 */
final class ComponentIdRewrite {

  /**
   * Field → which schema layer it stores.
   */
  public const FIELDS = ['field_page_structure' => 'structure', 'field_page_content' => 'content'];

  /**
   * Entity types that store page layers: pages (node) and blocks (media).
   */
  private const ENTITY_TYPES = ['node', 'media'];

  /**
   * Rows per step. Paged by row, not by entity: one page can hold thousands of
   * revisions × languages, and a step must fit the appliance's memory_limit
   * whatever the edit history (an over-limit step would roll back every boot).
   */
  public const BATCH = 200;

  public function __construct(
    private readonly Connection $database,
    private readonly ComponentCatalogInterface $catalog,
    private readonly ComponentPluginManager $components,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Prepare a run: the tables and the name map, read once.
   *
   * @throws \RuntimeException
   *   When the built-ins are not discovered — mapping now would store every
   *   built-in as unresolved. The update fails; converge rolls back.
   */
  public function start(array &$sandbox, bool $unresolvedOnly = FALSE): void {
    // Discovery may predate this request's module set (atelier was enabled
    // by update 10020 in the same run).
    $this->components->clearCachedDefinitions();
    $this->catalog->reset();
    $providers = ComponentId::providers($this->catalog->discovered());
    if (!in_array(ComponentCatalog::BUILTIN_PROVIDER, $providers, TRUE)) {
      throw new \RuntimeException('Atelier\'s built-in components are not discovered (is the atelier module enabled?), so stored component names cannot be mapped. Nothing was changed.');
    }
    $sandbox['providers'] = $providers;
    $sandbox['unresolved_only'] = $unresolvedOnly;
    $sandbox['tables'] = $this->tables();
    $sandbox['t'] = 0;
    $sandbox['last'] = NULL;
    $sandbox['counts'] = [];
    $sandbox['unresolved'] = [];
    $sandbox['#finished'] = $sandbox['tables'] === [] ? 1 : 0;
  }

  /**
   * One batch of rows; sets $sandbox['#finished'].
   */
  public function step(array &$sandbox): void {
    if ($sandbox['t'] >= count($sandbox['tables'])) {
      $sandbox['#finished'] = 1;
      return;
    }
    ['table' => $table, 'field' => $field, 'revision' => $revision] = $sandbox['tables'][$sandbox['t']];
    $column = $field . '_value';
    $counts = $sandbox['counts'][$table] ?? ['rows' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0];

    // Keyset paging over the table's primary key, in key order: values change,
    // keys never do, so no row is skipped or seen twice.
    $keys = $revision
      ? ['entity_id', 'revision_id', 'deleted', 'delta', 'langcode']
      : ['entity_id', 'deleted', 'delta', 'langcode'];
    $query = $this->database->select($table, 'f')
      ->fields('f', array_merge($keys, [$column]))
      ->range(0, self::BATCH);
    foreach ($keys as $key) {
      $query->orderBy('f.' . $key);
    }
    if ($sandbox['last'] !== NULL) {
      $args = [];
      foreach ($keys as $i => $key) {
        $args[':last' . $i] = $sandbox['last'][$key];
      }
      $query->where(
        '(' . implode(', ', array_map(static fn(string $k): string => 'f.' . $k, $keys)) . ') > ('
        . implode(', ', array_keys($args)) . ')',
        $args,
      );
    }
    if ($sandbox['unresolved_only']) {
      $query->condition($column, '%' . $this->database->escapeLike(ComponentId::UNRESOLVED . ':') . '%', 'LIKE');
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    if ($rows === []) {
      $sandbox['t']++;
      $sandbox['last'] = NULL;
    }
    else {
      $map = function (string $name) use (&$sandbox): string {
        $id = ComponentId::resolve($name, $sandbox['providers']);
        if (str_starts_with($id, ComponentId::UNRESOLVED . ':')) {
          $sandbox['unresolved'][$id] = ($sandbox['unresolved'][$id] ?? 0) + 1;
        }
        return $id;
      };
      $transaction = $this->database->startTransaction();
      foreach ($rows as $row) {
        $counts['rows']++;
        $data = json_decode((string) $row[$column], TRUE);
        if (!is_array($data)) {
          // Empty (a symmetric translation's structure) or not JSON: leave it.
          $counts['invalid']++;
          continue;
        }
        $mapped = self::FIELDS[$field] === 'structure'
          ? ComponentId::mapStructure($data, $map)
          : ComponentId::mapContent($data, $map);
        if ($mapped === $data) {
          $counts['unchanged']++;
          continue;
        }
        $update = $this->database->update($table)->fields([$column => json_encode($mapped)]);
        foreach ($keys as $key) {
          $update->condition($key, $row[$key]);
        }
        $update->execute();
        $counts['updated']++;
      }
      unset($transaction);
      $last = end($rows);
      $sandbox['last'] = array_intersect_key($last, array_flip($keys));
    }
    $sandbox['counts'][$table] = $counts;
    $sandbox['#finished'] = $sandbox['t'] >= count($sandbox['tables'])
      ? 1
      : min(0.99, ($sandbox['t'] + 0.5) / count($sandbox['tables']));
  }

  /**
   * Rewrite page kinds and the site constraint (D4–D6).
   *
   * @return array{kinds: int, constraint: bool}
   *   How many kinds changed, and whether the constraint did.
   */
  public function rewriteConfig(array &$sandbox): array {
    $map = static fn(string $name): string => ComponentId::resolve($name, $sandbox['providers']);
    $kinds = 0;
    foreach ($this->configFactory->listAll('aincient_pages.page_kind.') as $name) {
      $config = $this->configFactory->getEditable($name);
      $data = $config->getRawData();
      $mapped = ComponentId::mapKind($data, $map);
      if ($mapped !== $data) {
        $config->setData($mapped)->save(TRUE);
        $kinds++;
      }
    }
    $config = $this->configFactory->getEditable('aincient_pages.site_constraint');
    $data = $config->getRawData();
    $mapped = ComponentId::mapConstraint($data, $map);
    if (is_array($mapped['overrides_off'] ?? NULL)) {
      $mapped['overrides_off'] = array_values(array_map(
        static fn(mixed $id): mixed => is_string($id) ? (ComponentId::bridge($id) ?? $id) : $id,
        $mapped['overrides_off'],
      ));
    }
    $constraint = !$config->isNew() && $mapped !== $data;
    if ($constraint) {
      $config->setData($mapped)->save(TRUE);
    }
    return ['kinds' => $kinds, 'constraint' => $constraint];
  }

  /**
   * Close a run: caches, the log, the summary line.
   */
  public function finish(array $sandbox, array $config): string {
    // SQL bypassed the entity API (no new revision, `changed` untouched —
    // intended), so drop what cached the old values.
    foreach (self::ENTITY_TYPES as $type) {
      if ($this->entityTypeManager->hasDefinition($type)) {
        $this->entityTypeManager->getStorage($type)->resetCache();
      }
    }
    Cache::invalidateTags(['entity_field_info', 'node_list', 'media_list', 'rendered']);
    \Drupal::cache('entity')->deleteAll();
    $this->catalog->reset();

    $updated = 0;
    foreach ($sandbox['counts'] as $table => $c) {
      $updated += $c['updated'];
      $this->logger->notice('Component ids in @table: @updated updated, @unchanged already full or without components, @invalid empty, of @rows rows.', [
        '@table' => $table,
        '@updated' => $c['updated'],
        '@unchanged' => $c['unchanged'],
        '@invalid' => $c['invalid'],
        '@rows' => $c['rows'],
      ]);
    }
    $unresolved = $sandbox['unresolved'];
    if ($unresolved !== []) {
      arsort($unresolved);
      $this->logger->warning('Sections whose component no installed module provides were kept as unresolved (they render as nothing, as before): @list. Installing the module that provides them restores them.', [
        '@list' => implode(', ', array_map(static fn(string $id, int $n): string => "$id ×$n", array_keys($unresolved), $unresolved)),
      ]);
    }
    return sprintf(
      'Component ids: %d stored rows updated, %d page kinds%s rewritten, %d unresolved names kept.',
      $updated,
      $config['kinds'],
      $config['constraint'] ? ' and the site constraint' : '',
      count($unresolved),
    );
  }

  /**
   * Re-resolve `unresolved:` names after a module is installed (S6.3).
   *
   * Runs to completion in one request: only rows that hold an unresolved
   * name are read, which is none on a healthy site.
   */
  public function resolveAfterInstall(): void {
    if (!$this->hasUnresolved()) {
      return;
    }
    $sandbox = [];
    try {
      $this->start($sandbox, TRUE);
    }
    catch (\RuntimeException) {
      return;
    }
    while ($sandbox['#finished'] < 1) {
      $this->step($sandbox);
    }
    $this->finish($sandbox, $this->rewriteConfig($sandbox));
  }

  /**
   * Whether any stored row holds an unresolved name. Cheap: one LIKE per
   * table, stopping at the first hit.
   */
  private function hasUnresolved(): bool {
    $like = '%' . $this->database->escapeLike(ComponentId::UNRESOLVED . ':') . '%';
    foreach ($this->tables() as ['table' => $table, 'field' => $field]) {
      $hit = $this->database->select($table, 'f')
        ->fields('f', ['entity_id'])
        ->condition($field . '_value', $like, 'LIKE')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($hit !== FALSE) {
        return TRUE;
      }
    }
    foreach ($this->configFactory->listAll('aincient_pages.page_kind.') as $name) {
      if (str_contains(json_encode($this->configFactory->get($name)->getRawData()) ?: '', ComponentId::UNRESOLVED . ':')) {
        return TRUE;
      }
    }
    return str_contains(json_encode($this->configFactory->get('aincient_pages.site_constraint')->getRawData()) ?: '', ComponentId::UNRESOLVED . ':');
  }

  /**
   * The field tables that exist on this site.
   *
   * @return list<array{table: string, field: string, revision: bool}>
   */
  private function tables(): array {
    $schema = $this->database->schema();
    $tables = [];
    foreach (self::ENTITY_TYPES as $type) {
      foreach (array_keys(self::FIELDS) as $field) {
        foreach (["{$type}__{$field}" => FALSE, "{$type}_revision__{$field}" => TRUE] as $table => $revision) {
          if ($schema->tableExists($table)) {
            $tables[] = ['table' => $table, 'field' => $field, 'revision' => $revision];
          }
        }
      }
    }
    return $tables;
  }

}
