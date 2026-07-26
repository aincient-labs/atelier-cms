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
   *
   * PROXY PROVIDERS ({@see ModelRoles::PROXY_PROVIDERS}) get a second look. Their
   * catalogue is other vendors' models under a `vendor/model` id, and everything
   * we curate is written about the vendor — so without this, `claude-sonnet-5`
   * reached through a LiteLLM proxy carries no badge at all, while the same model
   * on a direct Anthropic key reads "Recommended", and a vendor-deprecated model
   * loses its warning exactly where an operator is least likely to recognise it.
   * The proxy's OWN entry still wins when the document has one (OpenRouter has),
   * because that is the more specific statement.
   */
  public function labelForModel(string $providerId, string $modelId): string {
    $label = $this->matchLabel($providerId, $modelId);
    if ($label !== self::UNTESTED || !ModelRoles::isProxyProvider($providerId)) {
      return $label;
    }

    [$vendor, $bare] = ModelRoles::splitProxyModel($modelId);
    if ($vendor !== '' && isset($this->data()['models'][$vendor])) {
      // The segment names a vendor we curate — the precise case: only that
      // vendor's needles are consulted, so nothing can inherit another's label.
      return $this->matchLabel($vendor, $bare);
    }

    // Otherwise the MODEL NAME decides, matched across every vendor, longest hit
    // wins. Two shapes land here and both are ordinary on a proxy: an id with the
    // namespace stripped (`claude-sonnet-5`), and one namespaced by ROUTE rather
    // than vendor (`vertex_ai/claude-sonnet-5`, `bedrock/…`, `azure/…` — where the
    // segment is who serves it, not who made it). Refusing to label those would
    // blank the badge on most of a real proxy's catalogue.
    //
    // Safe because families are vendor-specific in practice — `sonnet` is only ever
    // Anthropic's, `gpt-4o` only OpenAI's — and a name matching nothing still lands
    // on UNTESTED. Worst case is a coincidental family name inheriting a label that
    // is advisory anyway; the alternative loses real deprecation warnings.
    $best = self::UNTESTED;
    $bestLen = -1;
    foreach (array_keys($this->data()['models'] ?? []) as $candidateVendor) {
      $match = $this->matchLength((string) $candidateVendor, $bare);
      if ($match === NULL) {
        continue;
      }
      [$vendorLabel, $len] = $match;
      if ($len > $bestLen || ($len === $bestLen && self::RANK[$vendorLabel] < self::RANK[$best])) {
        $best = $vendorLabel;
        $bestLen = $len;
      }
    }
    return $best;
  }

  /**
   * The label from ONE provider's needles ({@see self::labelForModel()}'s core).
   */
  private function matchLabel(string $providerId, string $modelId): string {
    $match = $this->matchLength($providerId, $modelId);
    return $match === NULL ? self::UNTESTED : $match[0];
  }

  /**
   * The winning label for a provider's model AND the needle length that won it.
   *
   * The length is what lets a cross-vendor sweep compare hits: a longer needle is
   * a more specific statement about the model, whichever vendor made it.
   *
   * @return array{0: string, 1: int}|null
   *   The label and its needle length, or NULL when nothing matched.
   */
  private function matchLength(string $providerId, string $modelId): ?array {
    $models = $this->data()['models'][$providerId] ?? [];
    if (!is_array($models)) {
      return NULL;
    }
    $modelId = strtolower(trim($modelId));
    $best = NULL;
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
        if ($best === NULL || $len > $bestLen || ($len === $bestLen && self::RANK[$label] < self::RANK[$best])) {
          $best = $label;
          $bestLen = $len;
        }
      }
    }
    return $best === NULL ? NULL : [$best, $bestLen];
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
