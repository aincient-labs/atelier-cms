<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Theme\ComponentPluginManager;

/**
 * The full pack-validate pass — gate + CSS lint + pack manifest, one source.
 *
 * Extracted from the drush command so `atelier:pack-validate` (client CI) and
 * the dev-mode HTTP endpoint (the `atelier mcp` server's `pack_validate` tool)
 * run the IDENTICAL checks — a pack that passes in the editor's coding agent
 * must not fail in CI, and vice versa.
 */
final class PackValidator {

  public function __construct(
    private readonly ComponentPluginManager $componentManager,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * Validate the installed components — optionally scoped to one pack module.
   *
   * @return array{rows: list<array{component: string, tier: string, provider: string, status: string, messages: string}>, rejected: int, pack: ?array{found: bool, errors: string[], warnings: string[]}}
   *   `rows` is the per-component report; `rejected` counts components the
   *   gate refuses (a boot would exclude them); `pack` is the atelier.pack.yml
   *   verdict when a module was named (NULL for a whole-site run). A pack
   *   manifest error counts as a rejection — CI must fail on it.
   *
   * @throws \InvalidArgumentException
   *   When a named module provides neither atelier components nor a manifest.
   */
  public function validate(string $module = ''): array {
    // Always gate over ALL definitions, then filter the report: a name
    // collision with a built-in (or another pack) is only visible when the
    // whole namespace is checked — validating a module in isolation would
    // pass a component the real boot rejects.
    $definitions = $this->componentManager->getDefinitions();
    $verdicts = AdmissionGate::check($definitions);

    $pack = NULL;
    if ($module !== '') {
      $verdicts = array_filter($verdicts, fn(array $v) => $v['provider'] === $module);
      $manifest = PackManifest::read($this->moduleList->getPath($module), $module);
      $pack = ['found' => $manifest['found'], 'errors' => $manifest['errors'], 'warnings' => $manifest['warnings']];
      if ($verdicts === [] && !$manifest['found']) {
        throw new \InvalidArgumentException(sprintf('Module "%s" provides no atelier-carrying components (no SDC with thirdPartySettings.atelier) and no %s. Is it installed?', $module, PackManifest::FILENAME));
      }
    }

    // W5 advisory CSS lint: read each declared stylesheet and warn on the
    // machine-checkable contract breaches (hardcoded colours, fractional
    // opacity). Warnings only — the gate never rejects on taste.
    $cssIssues = [];
    foreach ($definitions as $definition) {
      $name = (string) ($definition['machineName'] ?? '');
      $sheet = (string) ($definition['thirdPartySettings']['atelier']['stylesheet'] ?? '');
      if ($sheet === '' || !isset($verdicts[$name])) {
        continue;
      }
      $file = $this->moduleList->getPath((string) ($definition['provider'] ?? '')) . '/' . $sheet;
      if (!is_file($file)) {
        $cssIssues[$name][] = sprintf('declared stylesheet "%s" is missing on disk — the shell will skip it.', $sheet);
        continue;
      }
      foreach (StylesheetLint::lint((string) file_get_contents($file)) as $issue) {
        $cssIssues[$name][] = "$sheet $issue";
      }
    }

    $rows = [];
    $rejected = 0;
    foreach ($verdicts as $name => $verdict) {
      if ($verdict['errors'] !== []) {
        $rejected++;
      }
      $warnings = array_merge($verdict['warnings'], $cssIssues[$name] ?? []);
      $rows[] = [
        'component' => $name,
        'tier' => $verdict['tier'] ?? '',
        'provider' => $verdict['provider'],
        'status' => $verdict['errors'] !== [] ? 'REJECTED' : ($warnings !== [] ? 'WARN' : 'OK'),
        'messages' => implode(' ', array_merge($verdict['errors'], $warnings)),
      ];
    }
    if ($pack !== NULL && $pack['errors'] !== []) {
      $rejected++;
    }

    return ['rows' => $rows, 'rejected' => $rejected, 'pack' => $pack];
  }

}
