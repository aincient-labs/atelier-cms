<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Studio;

use Symfony\Component\Yaml\Yaml;

/**
 * The cross-file rules that keep the two module tiers honest.
 *
 * Pure filesystem checks over a `web/modules` root, with no Drupal bootstrap:
 * the root is a parameter so the guard test can aim the same code at the real
 * tree and at synthetic temp trees (a rule that has only ever passed on the real
 * tree has never been shown to FAIL). Each method returns a list of violation
 * messages — empty means the rule holds.
 *
 * The tiers (plans/studio-modules.md "The folder rule", DECISIONS 0430): a
 * module directly under `custom/` is core and must work with every studio off;
 * a module directly under `studio/` is a studio. Only DIRECT children are tier
 * modules — `custom/<m>/tests/modules/*` fixtures are not, though their
 * manifests are still checked by {@see self::manifestViolations()}.
 *
 * Manifest SCHEMA validation is deliberately not here; that is
 * {@see StudioManifest::validate()}. This is only what a schema cannot see:
 * rules that span files.
 */
final class StudioTierGuard {

  /**
   * Directory paths of the modules directly under a tier.
   *
   * @return array<string, string>
   *   Machine name => directory.
   */
  public static function tierModules(string $modulesRoot, string $tier): array {
    $out = [];
    foreach (glob($modulesRoot . '/' . $tier . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
      $name = basename($dir);
      if (is_file($dir . '/' . $name . '.info.yml')) {
        $out[$name] = $dir;
      }
    }
    ksort($out);
    return $out;
  }

  /**
   * Every manifest in the tree: the tiers' modules and test fixtures.
   *
   * @return array<string, string>
   *   Module machine name => manifest path.
   */
  public static function manifests(string $modulesRoot): array {
    $files = array_merge(
      glob($modulesRoot . '/custom/*/*.studios.yml') ?: [],
      glob($modulesRoot . '/studio/*/*.studios.yml') ?: [],
      glob($modulesRoot . '/custom/*/tests/modules/*/*.studios.yml') ?: [],
    );
    $out = [];
    foreach ($files as $file) {
      $out[basename($file, '.studios.yml')] = $file;
    }
    ksort($out);
    return $out;
  }

  /**
   * Rule (a): studio/* modules each have a manifest; custom/* have none.
   *
   * @return list<string>
   */
  public static function manifestPlacementViolations(string $modulesRoot): array {
    $violations = [];
    foreach (self::tierModules($modulesRoot, 'studio') as $name => $dir) {
      $found = glob($dir . '/*.studios.yml') ?: [];
      if (count($found) !== 1 || basename($found[0]) !== $name . '.studios.yml') {
        $violations[] = sprintf('studio/%s must have exactly one %s.studios.yml (found %d).', $name, $name, count($found));
      }
    }
    foreach (self::tierModules($modulesRoot, 'custom') as $name => $dir) {
      if ((glob($dir . '/*.studios.yml') ?: []) !== []) {
        $violations[] = sprintf('custom/%s declares a studio manifest; core modules must not (move it to studio/).', $name);
      }
    }
    return $violations;
  }

  /**
   * Rule (b): no studio->studio and no core->studio dependency.
   *
   * @return list<string>
   */
  public static function dependencyViolations(string $modulesRoot): array {
    $studios = array_keys(self::tierModules($modulesRoot, 'studio'));
    $violations = [];
    foreach (['studio', 'custom'] as $tier) {
      foreach (self::tierModules($modulesRoot, $tier) as $name => $dir) {
        foreach (self::dependencies($dir . '/' . $name . '.info.yml') as $dep) {
          if (!in_array($dep, $studios, TRUE)) {
            continue;
          }
          if ($tier === 'custom') {
            $violations[] = sprintf('custom/%s depends on studio module %s; core must work with every studio off.', $name, $dep);
          }
          elseif ($dep !== $name) {
            $violations[] = sprintf('studio/%s depends on studio module %s; studios may not depend on each other.', $name, $dep);
          }
        }
      }
    }
    return $violations;
  }

  /**
   * An info file's dependencies, normalised to machine names.
   *
   * Entries may be `name`, `project:name` or `name (>=1.0)`.
   *
   * @return list<string>
   */
  public static function dependencies(string $infoFile): array {
    $info = Yaml::parseFile($infoFile);
    $out = [];
    foreach (is_array($info) ? ($info['dependencies'] ?? []) : [] as $entry) {
      $entry = trim((string) $entry);
      $entry = trim((string) preg_replace('/\s*\(.*\)\s*$/', '', $entry));
      $parts = explode(':', $entry);
      $out[] = end($parts);
    }
    return $out;
  }

  /**
   * Rule (c): flows and capabilities a manifest names exist in its module, and
   * no capability is claimed twice.
   *
   * @return list<string>
   */
  public static function manifestViolations(string $modulesRoot): array {
    $violations = [];
    $claimed = [];
    foreach (self::manifests($modulesRoot) as $module => $manifestPath) {
      $dir = dirname($manifestPath);
      $manifest = Yaml::parseFile($manifestPath);
      foreach (is_array($manifest) ? $manifest : [] as $studio => $def) {
        if (!is_array($def)) {
          continue;
        }
        foreach ($def['flows'] ?? [] as $flow) {
          $path = sprintf('%s/config/install/flowdrop_workflow.flowdrop_workflow.%s.yml', $dir, $flow);
          if (!is_file($path)) {
            $violations[] = sprintf('%s: studio %s names flow "%s" but %s is missing.', $module, $studio, $flow, 'config/install/flowdrop_workflow.flowdrop_workflow.' . $flow . '.yml');
          }
        }
        foreach ($def['capabilities'] ?? [] as $slug) {
          $id = $module . ':' . $slug;
          if (isset($claimed[$id])) {
            $violations[] = sprintf('Capability %s is claimed by both %s and %s.', $id, $claimed[$id], $studio);
          }
          $claimed[$id] = $studio;
          if (!self::capabilityDefined($dir, $id)) {
            $violations[] = sprintf('%s: studio %s names capability "%s" but no class under src/Plugin/AiCapability declares #[Capability(id: \'%s\'.', $module, $studio, $slug, $id);
          }
        }
      }
    }
    return $violations;
  }

  /**
   * Whether a module defines a capability plugin with this id.
   */
  private static function capabilityDefined(string $moduleDir, string $id): bool {
    foreach (glob($moduleDir . '/src/Plugin/AiCapability/*.php') ?: [] as $file) {
      $source = (string) file_get_contents($file);
      if (preg_match('/#\[Capability\(\s*id:\s*[\'"]' . preg_quote($id, '/') . '[\'"]/', $source) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
