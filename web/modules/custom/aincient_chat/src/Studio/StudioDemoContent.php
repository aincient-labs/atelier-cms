<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Drupal\aincient_core\Demo\DemoContentTracker;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports a studio's demo content the first time the studio is switched on.
 *
 * The manifest's `demo:` key names a directory in the providing module
 * (plans/studio-modules.md "Demo content", DECISIONS 0430). Every `*.yml` file
 * in it is ONE entity:
 *
 * @code
 * entity_type: node
 * values:
 *   type: aincient_page
 *   title: 'An example landing page'
 * @endcode
 *
 * `values` is handed to the entity storage's create() verbatim, so a file can
 * say whatever the entity type accepts. Each saved entity is tagged in the
 * demo table ({@see DemoContentTracker::track()}) so "Clear examples" — the
 * button in Settings and `drush atelier:demo-clear` — can remove it later.
 *
 * ONCE MEANS ONCE. Demo content is content, not config: it is never
 * re-imported on upgrade, never touched by `cim`, and a studio switched off
 * and on again does not get its examples back — an owner who cleared them
 * meant it. The record of "already imported" is a State list, not the demo
 * table, precisely so that clearing the examples does not re-arm the import.
 * `import(..., force: TRUE)` (drush) is the one way to seed again.
 *
 * All-or-nothing per studio: a file that fails rolls the studio's whole import
 * back and leaves it un-imported, so a half-seeded room cannot happen and the
 * next enable (or a drush retry) starts clean. The failure is logged, never
 * thrown — switching a studio on must not fail because an example would not
 * save.
 */
final class StudioDemoContent {

  /**
   * The State key holding the ids of studios whose demo content was imported.
   */
  public const STATE_KEY = 'aincient_chat.studio_demo_imported';

  public function __construct(
    private readonly StudioManager $studios,
    private readonly DemoContentTracker $tracker,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleList,
    private readonly StateInterface $state,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether a studio ships demo content at all.
   */
  public function ships(string $id): bool {
    return $this->studios->get($id)?->demoPath() !== NULL;
  }

  /**
   * Whether a studio's demo content has been imported (cleared or not).
   */
  public function imported(string $id): bool {
    return in_array($id, $this->importedIds(), TRUE);
  }

  /**
   * Import a studio's demo content, once.
   *
   * @return int
   *   How many entities were created; 0 when the studio ships none, has
   *   already been imported (unless $force), or the import failed.
   */
  public function import(string $id, bool $force = FALSE): int {
    $studio = $this->studios->get($id);
    $path = $studio?->demoPath();
    if ($studio === NULL || $path === NULL) {
      return 0;
    }
    if (!$force && $this->imported($id)) {
      return 0;
    }
    $files = $this->files($studio, $path);
    $created = 0;
    $transaction = $this->database->startTransaction();
    try {
      foreach ($files as $file) {
        $definition = Yaml::decode((string) file_get_contents($file));
        if (!is_array($definition) || !is_string($definition['entity_type'] ?? NULL) || !is_array($definition['values'] ?? NULL)) {
          throw new \UnexpectedValueException(sprintf('%s: expected an `entity_type` string and a `values` map.', basename($file)));
        }
        $entity = $this->entityTypeManager->getStorage($definition['entity_type'])->create($definition['values']);
        $entity->save();
        $this->tracker->track($entity, $id);
        $created++;
      }
      $this->markImported($id);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->logger->error('Importing the %studio studio\'s demo content failed and was rolled back: @message', [
        '%studio' => $id,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
    unset($transaction);
    $this->logger->info('Imported @count demo entities for the %studio studio.', [
      '@count' => $created,
      '%studio' => $id,
    ]);
    return $created;
  }

  /**
   * Forget that a studio was imported, so the next enable seeds it again.
   *
   * Used by nothing in the product today; it exists for tests and for a drush
   * operator who wants the next switch-on to behave like the first.
   */
  public function forget(string $id): void {
    $this->state->set(self::STATE_KEY, array_values(array_diff($this->importedIds(), [$id])));
  }

  /**
   * @return list<string>
   */
  private function importedIds(): array {
    $raw = $this->state->get(self::STATE_KEY, []);
    return array_values(array_map('strval', is_array($raw) ? $raw : []));
  }

  private function markImported(string $id): void {
    $this->state->set(self::STATE_KEY, array_values(array_unique([...$this->importedIds(), $id])));
  }

  /**
   * The demo files, sorted by name so an author can order them with a prefix.
   *
   * @return list<string>
   */
  private function files(StudioInterface $studio, string $path): array {
    $provider = (string) ($studio->getPluginDefinition()['provider'] ?? '');
    $dir = DRUPAL_ROOT . '/' . $this->moduleList->getPath($provider) . '/' . $path;
    $files = glob($dir . '/*.yml') ?: [];
    sort($files, SORT_STRING);
    return array_values($files);
  }

}
