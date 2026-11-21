<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Aincient\PageModel\ComponentId;
use Drupal\Component\Serialization\Yaml;

/**
 * Finds component ids written before 0.30.0 in a pack's files.
 *
 * Two kinds, both still accepted until 0.32.0 and both warned here, so a pack
 * author learns before then — pack-validate (and so the template CI and the
 * `pack_validate` MCP tool) names each with the fix:
 *  - old built-in ids `aincient_pages:<name>` in templates and `replaces:`
 *    (DECISIONS 0534, S5.1; bridged at render),
 *  - bare names in a shipped page kind (DECISIONS 0536; qualified on install).
 * A scan of the pack's own files: it sees what no page happens to use yet.
 */
final class LegacyIdScan {

  /**
   * @return string[]
   *   One warning per file and old id, naming the replacement.
   */
  public static function scan(string $root): array {
    $warnings = array_merge(self::scanComponents($root), self::scanKinds($root));
    sort($warnings);
    return $warnings;
  }

  /**
   * Bare component names in the pack's shipped page kinds.
   *
   * @return string[]
   */
  private static function scanKinds(string $root): array {
    $warnings = [];
    foreach (glob($root . '/config/{install,optional}/aincient_pages.page_kind.*.yml', GLOB_BRACE) ?: [] as $file) {
      try {
        $values = Yaml::decode((string) file_get_contents($file));
      }
      catch (\Throwable) {
        continue;
      }
      $bare = is_array($values) ? ComponentId::bareNamesInKind($values) : [];
      if ($bare !== []) {
        $warnings[] = sprintf('%s names components without their provider (%s) — write provider:name (e.g. atelier:hero, or your module name for your own components); bare names are refused from Atelier 0.32.0.', substr($file, strlen($root) + 1), implode(', ', $bare));
      }
    }
    return $warnings;
  }

  /**
   * Old built-in ids in templates and component definitions.
   *
   * @return string[]
   */
  private static function scanComponents(string $root): array {
    $dir = $root . '/components';
    if (!is_dir($dir)) {
      return [];
    }
    $pattern = '/(["\'])(' . preg_quote(ComponentId::LEGACY_PROVIDER, '/') . ':[a-z0-9_-]+)\1/';
    $warnings = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      $path = $file->getPathname();
      if (!str_ends_with($path, '.twig') && !str_ends_with($path, '.component.yml')) {
        continue;
      }
      if (!preg_match_all($pattern, (string) file_get_contents($path), $m)) {
        continue;
      }
      $relative = substr($path, strlen($root) + 1);
      foreach (array_unique($m[2]) as $old) {
        $warnings[] = sprintf('%s names %s, which is now %s — it still works until Atelier 0.32.0; change it to %s.', $relative, $old, ComponentId::bridge($old), ComponentId::bridge($old));
      }
    }
    return $warnings;
  }

}
