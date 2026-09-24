<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\Component\Plugin\PluginManagerInterface;
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
    // The studio plugin manager, or NULL when the chat layer isn't installed —
    // see the service definition. Typed as the core interface because this
    // module must not name an aincient_chat class.
    private readonly ?PluginManagerInterface $studios = NULL,
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
      $path = $this->moduleList->getPath($module);
      $manifest = PackManifest::read($path, $module);
      // The capability fence (Phase 3b) is checked for ANY named module, with
      // or without a manifest: a pack that ships agent verbs is rejected on the
      // strength of the directory alone, and a missing manifest must not be a
      // way around it.
      $fence = CapabilityFence::check($path, $module);
      [$studioErrors, $studioWarnings] = $this->gradeStudios($module, $manifest['manifest']);
      $pack = [
        'found' => $manifest['found'],
        'errors' => array_merge($manifest['errors'], $fence, $studioErrors),
        'warnings' => array_merge($manifest['warnings'], $studioWarnings),
      ];
      if ($verdicts === [] && !$manifest['found'] && $pack['errors'] === [] && $pack['warnings'] === []) {
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

  /**
   * Grade the `studios` payload (plans/console-extension-point.md Phase 3).
   *
   * Two directions, because both silences are expensive: a pack that DECLARES
   * studios and ships none has a manifest that lies, and a pack that ships
   * studios and declares none arrives as a surprise on the permissions page.
   *
   * The id rule is the one that cannot be relaxed later: studio ids share one
   * flat namespace with ours and become permission names and URL values, so a
   * third-party id must be prefixed with its module name
   * ({@see \Drupal\aincient_chat\Attribute\Studio}).
   *
   * @param array $manifest
   *   The decoded manifest (empty when the pack has none).
   *
   * @return array{0: string[], 1: string[]}
   *   Errors and warnings.
   */
  private function gradeStudios(string $module, array $manifest): array {
    if ($this->studios === NULL) {
      // No chat layer on this install — nothing to grade against, and guessing
      // from the filesystem would be a second, weaker copy of the rule.
      return [[], []];
    }
    $declared = in_array('studios', (array) ($manifest['provides'] ?? []), TRUE);
    $ids = [];
    foreach ($this->studios->getDefinitions() as $id => $definition) {
      if ((string) ($definition['provider'] ?? '') === $module) {
        $ids[] = (string) $id;
      }
    }

    $errors = [];
    $warnings = [];
    if ($declared && $ids === []) {
      $errors[] = 'declares the "studios" payload but ships no studio plugin (expected at least one class in src/Plugin/Studio). Note that a studio only appears here once the module is installed.';
    }
    if (!$declared && $ids !== []) {
      $warnings[] = sprintf('ships studio plugin(s) %s but does not declare "studios" in provides — declare the payload so the manifest says what the pack adds.', implode(', ', $ids));
    }
    foreach ($ids as $id) {
      if ($id !== $module && !str_starts_with($id, $module . '_')) {
        $errors[] = sprintf('studio id "%s" is out of scope — a pack studio id must be its module name or start with "%s_", because studio ids share one namespace with Atelier\'s and become permission names and URL values.', $id, $module);
      }
    }
    return [$errors, $warnings];
  }

}
