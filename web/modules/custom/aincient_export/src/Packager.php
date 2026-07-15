<?php

declare(strict_types=1);

namespace Drupal\aincient_export;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;

/**
 * Packages an export into one zip: site/ plus opt-in data extras.
 *
 * Layout: "site/" (the deployable static snapshot), "config/sync/" (when
 * requested), "users.json" (when requested). Deploy adapters re-zip site/
 * contents at the root themselves where a host requires it.
 */
final class Packager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Builds the zip; returns the number of files added.
   *
   * @throws \RuntimeException
   *   When the zip cannot be created or a source is missing.
   */
  public function package(string $staticDir, string $zipPath, bool $includeConfig, bool $includeUsers): int {
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
      throw new \RuntimeException(sprintf('Cannot create zip at %s', $zipPath));
    }
    $count = $this->addTree($zip, $staticDir, 'site');

    if ($includeConfig) {
      $config_dir = Settings::get('config_sync_directory');
      if (!$config_dir) {
        throw new \RuntimeException('No config_sync_directory is configured.');
      }
      if (!str_starts_with($config_dir, '/')) {
        $config_dir = DRUPAL_ROOT . '/' . $config_dir;
      }
      $config_dir = realpath($config_dir);
      if (!$config_dir) {
        throw new \RuntimeException('The config sync directory does not exist.');
      }
      $count += $this->addTree($zip, $config_dir, 'config/sync');
    }

    if ($includeUsers) {
      $zip->addFromString('users.json', $this->exportUsers());
      $count++;
    }

    if (!$zip->close()) {
      throw new \RuntimeException(sprintf('Failed writing zip at %s', $zipPath));
    }
    return $count;
  }

  private function addTree(\ZipArchive $zip, string $dir, string $prefix): int {
    $count = 0;
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file_info) {
      if (!$file_info->isFile()) {
        continue;
      }
      $relative = substr($file_info->getPathname(), strlen(rtrim($dir, '/')) + 1);
      $zip->addFile($file_info->getPathname(), $prefix . '/' . $relative);
      $count++;
    }
    return $count;
  }

  /**
   * Serializes user accounts — deliberately WITHOUT password hashes.
   *
   * The export may travel (support bundles, migrations to other platforms),
   * so credential material stays out; imported users reset their password.
   */
  private function exportUsers(): string {
    $storage = $this->entityTypeManager->getStorage('user');
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->sort('uid')
      ->execute();
    $users = [];
    /** @var \Drupal\user\UserInterface $user */
    foreach ($storage->loadMultiple($uids) as $user) {
      $users[] = [
        'uid' => (int) $user->id(),
        'uuid' => $user->uuid(),
        'name' => $user->getAccountName(),
        'mail' => $user->getEmail(),
        'status' => $user->isActive(),
        'roles' => array_values(array_diff($user->getRoles(), ['authenticated'])),
        'created' => (int) $user->getCreatedTime(),
        'timezone' => $user->getTimeZone(),
        'langcode' => $user->language()->getId(),
      ];
    }
    return json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
  }

}
