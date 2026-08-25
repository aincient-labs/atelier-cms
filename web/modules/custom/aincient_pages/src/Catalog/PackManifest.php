<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;

/**
 * Reads and validates a pack's `atelier.pack.yml` (plans/byo-components.md §6.1/§7.1).
 *
 * The pack manifest names the pack's contract with the appliance: the metadata
 * API major it targets, the payloads it declares (`provides:`) and the config
 * patterns it owns (`owns:`, consumed later by the config_ignore fencing).
 * A pack is an ordinary Drupal module; the manifest sits at the module root.
 *
 * Pure static like {@see AdmissionGate}, and for the same reason: drush
 * `atelier:pack-validate`, the dev endpoints and any future boot-time consumer
 * must run the IDENTICAL rules — no container, no state, one code path.
 *
 * The `owns:` scoping rule is a SECURITY FLOOR, not a policy knob
 * (memory/security-floor-vs-governance-policy): a pattern must live inside the
 * pack's own config namespace (`<module>.*`) or the page-kind namespace
 * (`aincient_pages.page_kind.*`). Anything wider would let a pack fence OUR
 * config off from `config:import` — a pack declaring `field.*` must be
 * rejected, never tuned around.
 */
final class PackManifest {

  /** Manifest API majors this build understands (mirrors AdmissionGate). */
  public const KNOWN_API_MAJORS = [1];

  /** Payload kinds a pack may declare in `provides:`. */
  public const PAYLOADS = ['components', 'page_kinds', 'providers'];

  public const FILENAME = 'atelier.pack.yml';

  /**
   * Read + validate `<modulePath>/atelier.pack.yml`.
   *
   * @return array{found: bool, manifest: array, errors: string[], warnings: string[]}
   *   `found: FALSE` (with no errors) when the file does not exist — a pack
   *   without a manifest is legal today (the fixture packs of Phases 1–3
   *   predate it); consumers that REQUIRE one say so themselves.
   */
  public static function read(string $modulePath, string $module): array {
    $file = rtrim($modulePath, '/') . '/' . self::FILENAME;
    if (!is_file($file)) {
      return ['found' => FALSE, 'manifest' => [], 'errors' => [], 'warnings' => []];
    }
    try {
      $manifest = Yaml::decode((string) file_get_contents($file));
    }
    catch (InvalidDataTypeException $e) {
      return ['found' => TRUE, 'manifest' => [], 'errors' => [self::FILENAME . ' is not parseable YAML: ' . $e->getMessage()], 'warnings' => []];
    }
    if (!is_array($manifest)) {
      return ['found' => TRUE, 'manifest' => [], 'errors' => [self::FILENAME . ' must be a YAML mapping.'], 'warnings' => []];
    }
    [$errors, $warnings] = self::validate($manifest, $module);
    return ['found' => TRUE, 'manifest' => $manifest, 'errors' => $errors, 'warnings' => $warnings];
  }

  /**
   * Validate a decoded manifest against the api:1 contract.
   *
   * @return array{0: string[], 1: string[]}
   *   Errors (reject the manifest) and warnings (advisory).
   */
  public static function validate(array $manifest, string $module): array {
    $errors = [];
    $warnings = [];

    // `api` is the one mandatory key: refuse unknown majors rather than
    // guessing at a future contract (same rule as the component gate).
    $api = $manifest['api'] ?? NULL;
    if (!is_int($api)) {
      $errors[] = 'missing or non-integer "api" — declare the manifest API major (api: 1).';
    }
    elseif (!in_array($api, self::KNOWN_API_MAJORS, TRUE)) {
      $errors[] = sprintf('unknown api major %d — this appliance understands: %s.', $api, implode(', ', self::KNOWN_API_MAJORS));
    }

    if (isset($manifest['provides'])) {
      if (!is_array($manifest['provides'])) {
        $errors[] = '"provides" must be a list of payload kinds.';
      }
      else {
        foreach ($manifest['provides'] as $payload) {
          if (!is_string($payload) || !in_array($payload, self::PAYLOADS, TRUE)) {
            $warnings[] = sprintf('unknown payload "%s" in provides (known: %s) — ignored.', is_scalar($payload) ? (string) $payload : gettype($payload), implode(', ', self::PAYLOADS));
          }
        }
        // A provider payload must be declared loudly (§7.4) — but riding in
        // silently is the module's tagged services' business, checked at the
        // service layer, not here. Nothing to do at manifest level beyond
        // accepting the declaration.
      }
    }

    if (isset($manifest['owns'])) {
      if (!is_array($manifest['owns'])) {
        $errors[] = '"owns" must be a list of config-name patterns.';
      }
      else {
        foreach ($manifest['owns'] as $pattern) {
          if (!is_string($pattern) || $pattern === '') {
            $errors[] = '"owns" patterns must be non-empty strings.';
            continue;
          }
          if (!str_starts_with($pattern, "$module.") && !str_starts_with($pattern, 'aincient_pages.page_kind.')) {
            $errors[] = sprintf('owns pattern "%s" is out of scope — a pack may only own "%s.*" or "aincient_pages.page_kind.*" config (security floor).', $pattern, $module);
          }
        }
      }
    }

    if (isset($manifest['requires']) && !is_array($manifest['requires'])) {
      $warnings[] = '"requires" should be a mapping (e.g. requires: {atelier: ^0.9}) — ignored.';
    }

    return [$errors, $warnings];
  }

}
