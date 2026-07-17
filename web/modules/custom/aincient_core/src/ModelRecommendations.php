<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the curated provider/model recommendation registry.
 *
 * Backend-owned guidance for the onboarding pickers: which providers and models
 * we recommend, have tested, or advise against. The data is a plain reference
 * file shipped in the module root ({@see model-recommendations.yml}) — NOT
 * config — so it can be updated as we test more models with only a cache clear,
 * mirroring how {@see \Drupal\aincient_pages\DesignTokens} loads its registry.
 *
 * This owns QUALITY LABELS + RANKING only. Which model a role opens ON
 * (pre-selection) is a separate concern still driven by
 * {@see ModelRoles::tierHints()} — a model's quality label is role-agnostic.
 */
final class ModelRecommendations {

  /**
   * Quality labels, best → worst. Providers use the first three; every model
   * resolves to exactly one of these, defaulting to UNTESTED.
   */
  public const RECOMMENDED = 'recommended';
  public const TESTED = 'tested';
  public const UNTESTED = 'untested';
  public const NOT_RECOMMENDED = 'not-recommended';

  /**
   * Sort weight per label (lower = shown first). Drives the picker's ordering:
   * recommended → tested → untested → not-recommended.
   */
  private const RANK = [
    self::RECOMMENDED => 0,
    self::TESTED => 1,
    self::UNTESTED => 2,
    self::NOT_RECOMMENDED => 3,
  ];

  /**
   * Parsed registry (['providers' => …, 'models' => …]), lazily loaded.
   *
   * @var array<string, mixed>|null
   */
  private ?array $data = NULL;

  public function __construct(private readonly ModuleExtensionList $moduleList) {}

  /**
   * The quality label for a provider's model, by longest-needle match.
   *
   * Case-insensitive. Every needle across every label is tested against the
   * model id; the LONGEST match wins (so a specific "gpt-4o-mini" beats the
   * broader "gpt-4o"), ties breaking toward the better (lower-rank) label. A
   * model matching nothing — or a provider we've said nothing about — is
   * {@see self::UNTESTED}.
   */
  public function labelForModel(string $providerId, string $modelId): string {
    $models = $this->data()['models'][$providerId] ?? [];
    if (!is_array($models)) {
      return self::UNTESTED;
    }
    $modelId = strtolower(trim($modelId));
    $best = self::UNTESTED;
    $bestLen = -1;
    foreach ($models as $label => $needles) {
      if (!isset(self::RANK[$label]) || !is_array($needles)) {
        continue;
      }
      foreach ($needles as $needle) {
        $needle = strtolower(trim((string) $needle));
        if ($needle === '' || !str_contains($modelId, $needle)) {
          continue;
        }
        $len = strlen($needle);
        if ($len > $bestLen || ($len === $bestLen && self::RANK[$label] < self::RANK[$best])) {
          $best = $label;
          $bestLen = $len;
        }
      }
    }
    return $best;
  }

  /**
   * The recommendation label for a provider, or '' when we've said nothing.
   *
   * One of {@see self::RECOMMENDED} / {@see self::TESTED} /
   * {@see self::NOT_RECOMMENDED}; an unknown/absent provider returns ''.
   */
  public function providerRecommendation(string $providerId): string {
    $label = (string) ($this->data()['providers'][$providerId] ?? '');
    return isset(self::RANK[$label]) ? $label : '';
  }

  /**
   * The sort weight for a label (lower sorts first); unknown → UNTESTED's rank.
   */
  public function rank(string $label): int {
    return self::RANK[$label] ?? self::RANK[self::UNTESTED];
  }

  /**
   * The parsed registry, loaded once from the module-root YAML.
   *
   * @return array<string, mixed>
   */
  private function data(): array {
    if ($this->data === NULL) {
      $path = $this->moduleList->getPath('aincient_core') . '/model-recommendations.yml';
      $parsed = is_file($path) ? Yaml::parseFile($path) : [];
      $this->data = is_array($parsed) ? $parsed : [];
    }
    return $this->data;
  }

}
