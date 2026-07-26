<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

/**
 * Reads the curated provider/model quality labels.
 *
 * Backend-owned guidance for the onboarding pickers: which providers and models
 * we recommend, have tested, or advise against. The data comes from
 * {@see RecommendationSource} — the bundled `model-recommendations.yml`, or the
 * newer published document if an operator has fetched one. Either way it is
 * reference data, NOT config, so updating it needs no config import.
 *
 * This owns QUALITY LABELS + RANKING only, and those are role-agnostic. Which
 * model a role opens ON is a separate concern: {@see ModelPresetResolver} (the
 * published profiles) with {@see ModelRoles::tierHints()} underneath it.
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

  public function __construct(private readonly RecommendationSource $source) {}

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
   * The document in force — bundled snapshot or fetched, we don't care which.
   *
   * @return array<string, mixed>
   */
  private function data(): array {
    return $this->source->document();
  }

}
