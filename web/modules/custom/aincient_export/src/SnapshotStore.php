<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;

/**
 * The frozen-snapshot store: folders under <private>/frozen plus one symlink.
 *
 * `frozen/current` is the ONLY serving state (DECISIONS 0416). Present and
 * pointing at a marker-bearing snapshot means visitors get that snapshot's
 * files; absent means Drupal renders every visit (Live). The web server reads
 * the same symlink, so nothing here can disagree with what is served.
 *
 * Live is "no symlink" rather than "symlink to the docroot" on purpose: a
 * file-exists rule pointed at the docroot would happily serve
 * sites/default/settings.php as a static file.
 */
final class SnapshotStore {

  public const CURRENT = 'current';

  public const LIVE = 'live';

  public const DEFAULT_KEEP = 10;

  private readonly string $frozenDir;

  public function __construct(
    private readonly Exporter $exporter,
    private readonly TimeInterface $time,
    ?string $frozenDir = NULL,
  ) {
    $this->frozenDir = $frozenDir ?? self::defaultFrozenDir();
  }

  /**
   * <file_private_path>/frozen, resolved against the docroot when relative.
   */
  public static function defaultFrozenDir(): string {
    $private = (string) Settings::get('file_private_path', dirname(DRUPAL_ROOT) . '/private');
    if (!str_starts_with($private, '/')) {
      $private = DRUPAL_ROOT . '/' . $private;
    }
    return PathUtil::normalize($private) . '/frozen';
  }

  public function frozenDir(): string {
    return $this->frozenDir;
  }

  /**
   * All snapshots, newest first (ids sort chronologically).
   *
   * @return \Drupal\aincient_export\Snapshot[]
   */
  public function list(): array {
    if (!is_dir($this->frozenDir)) {
      return [];
    }
    $snapshots = [];
    foreach (scandir($this->frozenDir, SCANDIR_SORT_DESCENDING) ?: [] as $entry) {
      $dir = $this->frozenDir . '/' . $entry;
      if ($entry === self::CURRENT || $entry[0] === '.' || is_link($dir) || !is_dir($dir)) {
        continue;
      }
      if (($snapshot = Snapshot::fromDir($dir)) !== NULL) {
        $snapshots[] = $snapshot;
      }
    }
    return $snapshots;
  }

  public function get(string $id): ?Snapshot {
    if (!self::validId($id)) {
      return NULL;
    }
    return Snapshot::fromDir($this->frozenDir . '/' . $id);
  }

  /**
   * The served snapshot id, or "live" when Drupal renders every visit.
   */
  public function current(): string {
    $link = $this->frozenDir . '/' . self::CURRENT;
    if (!is_link($link)) {
      return self::LIVE;
    }
    $target = readlink($link);
    if ($target === FALSE) {
      return self::LIVE;
    }
    $id = basename($target);
    // A dangling link (snapshot pruned by hand) is Live in practice: the web
    // server finds no files. Report it as such rather than a ghost id.
    return $this->get($id) !== NULL ? $id : self::LIVE;
  }

  public function isFrozen(): bool {
    return $this->current() !== self::LIVE;
  }

  /**
   * Serves a snapshot (or Live). Atomic: link a temp name, then rename over.
   *
   * @throws \InvalidArgumentException
   *   When the id is not a marker-bearing snapshot.
   */
  public function use(string $idOrLive): void {
    $link = $this->frozenDir . '/' . self::CURRENT;
    if ($idOrLive === self::LIVE) {
      if (is_link($link)) {
        unlink($link);
      }
      return;
    }
    $snapshot = $this->get($idOrLive);
    if ($snapshot === NULL) {
      throw new \InvalidArgumentException(sprintf('No snapshot "%s" in %s (a snapshot is a folder carrying %s).', $idOrLive, $this->frozenDir, Exporter::MARKER));
    }
    // Relative target so the volume can move (host bind mount vs container path).
    $tmp = $this->frozenDir . '/.' . self::CURRENT . '.' . bin2hex(random_bytes(4));
    if (!symlink($snapshot->id, $tmp)) {
      throw new \RuntimeException(sprintf('Cannot create symlink in %s.', $this->frozenDir));
    }
    if (!rename($tmp, $link)) {
      @unlink($tmp);
      throw new \RuntimeException(sprintf('Cannot replace %s.', $link));
    }
  }

  /**
   * Exports the published site into a new snapshot folder.
   *
   * Does NOT serve it — callers decide based on the result (a snapshot with
   * failures or broken links should not go in front of visitors unforced).
   *
   * @param array<string, mixed> $marker
   *   label / frozen_by / atelier_version / keep for the marker file.
   *
   * @return array{0: \Drupal\aincient_export\Snapshot, 1: \Drupal\aincient_export\ExportResult}
   */
  public function freeze(string $baseUrl, array $marker = [], array $checkIgnore = [], bool $runLinkCheck = TRUE, ?callable $progress = NULL): array {
    $now = $this->time->getCurrentTime();
    $id = date('Ymd-Hi', $now);
    $label = isset($marker['label']) ? trim((string) $marker['label']) : '';
    if ($label !== '') {
      $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) ?? '', '-');
      if ($slug !== '') {
        $id .= '-' . substr($slug, 0, 40);
      }
    }
    // Two freezes in one minute: suffix rather than clobber.
    $dir = $this->frozenDir . '/' . $id;
    for ($n = 2; is_dir($dir); $n++) {
      $dir = $this->frozenDir . '/' . $id . '-' . $n;
    }
    $id = basename($dir);

    $this->ensureFrozenDir();
    $result = $this->exporter->export(new ExportOptions(
      outDir: $dir,
      baseUrl: $baseUrl,
      runLinkCheck: $runLinkCheck,
      checkIgnore: $checkIgnore,
      markerExtra: [
        'label' => $label !== '' ? $label : NULL,
        'frozen_at' => date(DATE_ATOM, $now),
        'frozen_by' => (string) ($marker['frozen_by'] ?? 'cli'),
        'atelier_version' => (string) ($marker['atelier_version'] ?? ''),
        'keep' => (bool) ($marker['keep'] ?? FALSE),
      ],
    ), $progress);
    $this->adoptOwnership($dir);

    $snapshot = Snapshot::fromDir($dir);
    if ($snapshot === NULL) {
      throw new \RuntimeException(sprintf('Export into %s produced no marker.', $dir));
    }
    return [$snapshot, $result];
  }

  /**
   * Flips the keep flag in a snapshot's marker.
   */
  public function setKeep(string $id, bool $keep): Snapshot {
    $snapshot = $this->get($id);
    if ($snapshot === NULL) {
      throw new \InvalidArgumentException(sprintf('No snapshot "%s".', $id));
    }
    $marker = $snapshot->dir . '/' . Exporter::MARKER;
    $data = json_decode((string) file_get_contents($marker), TRUE) ?: [];
    $data['keep'] = $keep;
    file_put_contents($marker, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return $this->get($id);
  }

  /**
   * Deletes one snapshot. Refuses the one being served.
   */
  public function delete(string $id): void {
    $snapshot = $this->get($id);
    if ($snapshot === NULL) {
      throw new \InvalidArgumentException(sprintf('No snapshot "%s".', $id));
    }
    if ($this->current() === $id) {
      throw new \LogicException(sprintf('Snapshot "%s" is being served; switch first.', $id));
    }
    $this->deleteTree($snapshot->dir);
  }

  /**
   * Keeps the newest $keep snapshots plus every kept one and the served one.
   *
   * @return string[]
   *   Ids deleted.
   */
  public function prune(int $keep = self::DEFAULT_KEEP): array {
    $deleted = [];
    $current = $this->current();
    $seen = 0;
    foreach ($this->list() as $snapshot) {
      if ($snapshot->keep || $snapshot->id === $current) {
        continue;
      }
      if (++$seen <= $keep) {
        continue;
      }
      $this->deleteTree($snapshot->dir);
      $deleted[] = $snapshot->id;
    }
    return $deleted;
  }

  public static function validId(string $id): bool {
    return (bool) preg_match('/^[0-9]{8}-[0-9]{4}(-[a-z0-9-]+)?$/', $id);
  }

  private function ensureFrozenDir(): void {
    if (!is_dir($this->frozenDir) && !mkdir($this->frozenDir, 0775, TRUE) && !is_dir($this->frozenDir)) {
      throw new \RuntimeException(sprintf('Cannot create %s.', $this->frozenDir));
    }
    $this->adoptOwnership($this->frozenDir, FALSE);
  }

  /**
   * Under drush-as-root (the appliance) new folders would be root-owned and
   * the web server could not read derivatives written later; hand them to the
   * owner of the private directory. No-op when not root.
   */
  private function adoptOwnership(string $path, bool $recursive = TRUE): void {
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
      return;
    }
    $owner = fileowner(dirname($this->frozenDir));
    $group = filegroup(dirname($this->frozenDir));
    if ($owner === FALSE || $group === FALSE) {
      return;
    }
    $targets = [$path];
    if ($recursive) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
      foreach ($iterator as $info) {
        $targets[] = $info->getPathname();
      }
    }
    foreach ($targets as $target) {
      @chown($target, $owner);
      @chgrp($target, $group);
    }
  }

  private function deleteTree(string $dir): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $info) {
      $info->isDir() && !$info->isLink() ? rmdir($info->getPathname()) : unlink($info->getPathname());
    }
    rmdir($dir);
  }

}
