<?php

declare(strict_types=1);

namespace Drupal\aincient_export\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\aincient_export\SnapshotStore;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Freeze & Live (DECISIONS 0416): serve visitors a frozen snapshot or live Drupal.
 *
 * The `atelier site freeze|snapshots|use|live|prune` Manager verbs shell into
 * these; `--format=json` is the machine seam.
 */
final class FreezeCommands extends DrushCommands {

  public function __construct(
    private readonly SnapshotStore $store,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('aincient_export.snapshot_store'));
  }

  /**
   * Export the published site into a new snapshot and serve it to visitors.
   */
  #[CLI\Command(name: 'aincient:freeze', aliases: ['afreeze'])]
  #[CLI\Option(name: 'label', description: 'A name for this snapshot (becomes part of its id).')]
  #[CLI\Option(name: 'keep', description: 'Mark the snapshot as kept: prune never removes it.')]
  #[CLI\Option(name: 'no-serve', description: 'Take the snapshot but keep serving whatever is served now.')]
  #[CLI\Option(name: 'force', description: 'Serve the snapshot even if the export reported failures, broken links or missing assets.')]
  #[CLI\Option(name: 'base-url', description: 'Scheme + host to render against. Defaults to the bootstrapped request host (drush --uri).')]
  #[CLI\Option(name: 'skip-link-check', description: 'Skip the post-export link check.')]
  #[CLI\Usage(name: 'drush aincient:freeze --label "Spring launch"', description: 'Snapshot the site and serve it.')]
  public function freeze(array $options = [
    'label' => NULL,
    'keep' => FALSE,
    'no-serve' => FALSE,
    'force' => FALSE,
    'base-url' => NULL,
    'skip-link-check' => FALSE,
  ]): int {
    $base_url = $options['base-url'] ?? \Drupal::request()->getSchemeAndHttpHost();
    $account = \Drupal::currentUser();

    [$snapshot, $result] = $this->store->freeze(
      $base_url,
      [
        'label' => $options['label'],
        'keep' => (bool) $options['keep'],
        'frozen_by' => $account->isAuthenticated() ? $account->getAccountName() : 'cli',
        'atelier_version' => (string) (getenv('ATELIER_VERSION') ?: 'dev'),
      ],
      ExportCommands::DEFAULT_CHECK_IGNORE,
      !$options['skip-link-check'],
      fn (string $message) => $this->io()->text($message),
    );

    foreach ($result->skipped as $path => $status) {
      $this->io()->warning(sprintf('Skipped %s (HTTP %d).', $path, $status));
    }
    foreach ($result->failures as $path => $error) {
      $this->io()->error(sprintf('Failed %s: %s', $path, $error));
    }
    foreach ($result->brokenLinks as $broken) {
      $this->io()->error(sprintf('Broken reference in %s: %s', $broken['file'], $broken['ref']));
    }
    foreach ($result->missingAssets as $path => $status) {
      $this->io()->error(sprintf('Missing asset %s (HTTP %d).', $path, $status));
    }

    $this->io()->success(sprintf('Snapshot %s: %d pages, %d assets.', $snapshot->id, $snapshot->pages, $snapshot->assets));

    if ($options['no-serve']) {
      $this->io()->text(sprintf('Not served (--no-serve). Serving: %s.', $this->store->current()));
      return $result->ok() ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
    }
    if (!$result->ok() && !$options['force']) {
      $this->io()->error(sprintf('Not served: the export has problems. Fix them and freeze again, or serve it anyway with `drush aincient:use %s`.', $snapshot->id));
      return self::EXIT_FAILURE;
    }
    $this->store->use($snapshot->id);
    $this->io()->success(sprintf('Visitors now see snapshot %s. Logged-in users keep seeing the live site.', $snapshot->id));
    return self::EXIT_SUCCESS;
  }

  /**
   * List snapshots and which one visitors see.
   */
  #[CLI\Command(name: 'aincient:snapshots', aliases: ['asnap'])]
  #[CLI\FieldLabels(labels: [
    'id' => 'Id',
    'serving' => 'Serving',
    'label' => 'Label',
    'frozen_at' => 'Frozen at',
    'frozen_by' => 'By',
    'atelier_version' => 'Atelier',
    'pages' => 'Pages',
    'assets' => 'Assets',
    'keep' => 'Kept',
  ])]
  #[CLI\DefaultTableFields(fields: ['serving', 'id', 'label', 'frozen_at', 'pages', 'keep'])]
  #[CLI\FilterDefaultField(field: 'id')]
  public function snapshots(array $options = ['format' => 'table']): RowsOfFields {
    $current = $this->store->current();
    $rows = [
      [
        'id' => SnapshotStore::LIVE,
        'serving' => $current === SnapshotStore::LIVE ? '●' : '',
        'label' => 'Live (Drupal renders every visit)',
        'frozen_at' => '',
        'frozen_by' => '',
        'atelier_version' => '',
        'pages' => '',
        'assets' => '',
        'keep' => '',
      ],
    ];
    foreach ($this->store->list() as $snapshot) {
      // Array union keeps the LEFT value: the display 'keep' must precede toArray().
      $rows[] = ['serving' => $snapshot->id === $current ? '●' : '', 'keep' => $snapshot->keep ? 'yes' : ''] + $snapshot->toArray();
    }
    if ($options['format'] === 'json') {
      foreach ($rows as &$row) {
        $row['serving'] = $row['serving'] !== '';
        $row['keep'] = $row['keep'] === 'yes' || $row['keep'] === TRUE;
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * Serve a snapshot (or "live") to visitors. Instant; nothing restarts.
   */
  #[CLI\Command(name: 'aincient:use', aliases: ['ause'])]
  #[CLI\Argument(name: 'id', description: 'A snapshot id from aincient:snapshots, or "live".')]
  #[CLI\Usage(name: 'drush aincient:use 20260910-1102-autumn-copy', description: 'Visitors see that snapshot.')]
  #[CLI\Usage(name: 'drush aincient:use live', description: 'Drupal renders every visit again.')]
  public function use(string $id): int {
    try {
      $this->store->use($id);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->io()->success($id === SnapshotStore::LIVE
      ? 'Live: Drupal renders every visit.'
      : sprintf('Visitors now see snapshot %s.', $id));
    return self::EXIT_SUCCESS;
  }

  /**
   * Back to live: Drupal renders every visit. Snapshots are kept.
   */
  #[CLI\Command(name: 'aincient:live', aliases: ['alive'])]
  public function live(): int {
    return $this->use(SnapshotStore::LIVE);
  }

  /**
   * Delete old snapshots: keeps the newest N, every kept one, and the served one.
   */
  #[CLI\Command(name: 'aincient:prune', aliases: ['aprune'])]
  #[CLI\Option(name: 'keep', description: 'How many recent unkept snapshots to retain.')]
  public function prune(array $options = ['keep' => SnapshotStore::DEFAULT_KEEP]): int {
    $deleted = $this->store->prune(max(0, (int) $options['keep']));
    $this->io()->success($deleted
      ? sprintf('Deleted %d snapshot(s): %s.', count($deleted), implode(', ', $deleted))
      : 'Nothing to prune.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Mark a snapshot kept (or not): prune never removes a kept snapshot.
   */
  #[CLI\Command(name: 'aincient:keep', aliases: ['akeep'])]
  #[CLI\Argument(name: 'id', description: 'A snapshot id.')]
  #[CLI\Option(name: 'unkeep', description: 'Clear the flag instead.')]
  public function keep(string $id, array $options = ['unkeep' => FALSE]): int {
    try {
      $snapshot = $this->store->setKeep($id, !$options['unkeep']);
    }
    catch (\InvalidArgumentException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->io()->success(sprintf('Snapshot %s is %s.', $snapshot->id, $snapshot->keep ? 'kept' : 'no longer kept'));
    return self::EXIT_SUCCESS;
  }

}
