<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * What a call costs, according to a rate table Atelier owns and can be checked.
 *
 * WHY THIS EXISTS — HISTORY WORTH KEEPING. Cost used to come from
 * `ai_metering`'s TokenEstimator, which resolved a rate through four lookups
 * and, when all four missed, priced every token at 0.0 and returned a total of
 * 0.0 with no signal that it had failed. An unpriced model was therefore
 * indistinguishable from a free one — and that was not a hypothetical: a real
 * sonnet-5 agent turn that billed $0.0475 was recorded across four rows as
 * $0.00, because nothing in that table named `claude-sonnet-5`.
 *
 * WHY WE NEVER WROTE OUR RATES INTO ITS TABLE. Its precedence was
 * manual-beats-synced BY DESIGN, so a stale manual entry permanently outranked a
 * correct synced one — which is how `claude-haiku-4-5-20251001` stayed pinned at
 * Haiku 3.5's $0.25/$1.25. Its settings object was config-ignored here as well,
 * so correcting the shipped file would only ever have reached fresh installs.
 * Winning that precedence game was the trap; not playing it was the fix. This
 * class was the first piece of the metering stack Atelier took over; the table
 * (`aincient_ai_usage`) and the dashboard followed, and `ai_metering` is gone.
 *
 * THE GAP IS THE FEATURE. This class never invents a rate. A model it cannot
 * price is reported as unpriced — by {@see self::cost()} to the recorder (which
 * logs it), by {@see self::unpriced()} to the status report and the models form.
 * The only silent zero is one an entry explicitly marks `free`.
 */
final class ModelPricing {

  /**
   * The config object holding the rate table.
   */
  public const CONFIG = 'aincient_core.pricing';

  /**
   * Rates are stored per million tokens; billing happens per token.
   *
   * The stored unit is the one vendors publish in, so an entry can be diffed
   * against a price sheet by eye. The conversion lives here, once.
   */
  private const PER_MTOK = 1000000.0;

  /**
   * The token classes a call can be billed for, in reporting order.
   *
   * Named here because three separate places need the same vocabulary: the
   * arithmetic below, the "which of these had no rate" list it returns, and the
   * warning the recorder writes from that list.
   */
  private const CLASSES = ['input', 'output', 'cache_read', 'cache_write'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The rate entry for a model, or NULL when we have not priced it.
   *
   * Two matches only — the exact provider+model, then a `*` wildcard for the
   * whole provider — and deliberately no third. The estimator's "any provider
   * that prices this model id" fallback is what let a Vertex text rate stand in
   * for a Google image model, and a lookup that can quietly answer with someone
   * else's price is worse than one that answers "I do not know".
   *
   * The table is a LIST, so this scans it. Five entries; an index would be
   * cache-invalidation work in exchange for nothing measurable.
   *
   * @return array{input: ?float, output: ?float, cache_read: ?float, cache_write: ?float, free: bool, source: string, checked: string, note: string, key: string}|null
   *   Per-TOKEN rates (NULL per class where the entry publishes none), plus the
   *   provenance and the `provider:model` the entry declares.
   */
  public function rate(string $providerId, string $modelId): ?array {
    $entry = $this->entry($providerId, $modelId);
    if ($entry === NULL) {
      return NULL;
    }

    $rates = [];
    foreach (self::CLASSES as $class) {
      $published = $entry[$class . '_per_mtok'] ?? NULL;
      $rates[$class] = $published === NULL ? NULL : ((float) $published) / self::PER_MTOK;
    }

    return $rates + [
      'free' => (bool) ($entry['free'] ?? FALSE),
      'source' => (string) ($entry['source'] ?? ''),
      'checked' => (string) ($entry['checked'] ?? ''),
      'note' => (string) ($entry['note'] ?? ''),
      'key' => $providerId . ':' . (string) ($entry['model'] ?? ''),
    ];
  }

  /**
   * The rate entry AS PUBLISHED — per million tokens, the unit vendors quote.
   *
   * {@see self::rate()} answers the arithmetic's question and divides down to
   * per-token; a page showing the table to a human wants the number back in the
   * unit it was copied from a price sheet in, and multiplying the per-token
   * value up again would reintroduce float noise into figures whose only job is
   * to be compared by eye against that sheet.
   *
   * Both go through the SAME {@see self::entry()} lookup, which is the part that
   * must not be reimplemented: the rate sheet showing a role sitting on a `*`
   * wildcard that an exact entry actually prices would misstate what a call
   * costs, and a second copy of the precedence rule is how that happens.
   *
   * @return array{input: ?float, output: ?float, cache_read: ?float, cache_write: ?float, free: bool, source: string, checked: string, note: string, key: string}|null
   *   Per-MILLION-token rates (NULL per class where the entry publishes none),
   *   plus provenance and the `provider:model` the entry declares.
   */
  public function published(string $providerId, string $modelId): ?array {
    $entry = $this->entry($providerId, $modelId);
    if ($entry === NULL) {
      return NULL;
    }

    $rates = [];
    foreach (self::CLASSES as $class) {
      $value = $entry[$class . '_per_mtok'] ?? NULL;
      // Absent stays NULL. A published 0.0 on a `free` entry is a fact about
      // local inference; an absent rate is a gap that leaves those tokens
      // unbilled — collapsing the two into "$0.00" is the exact confusion this
      // class exists to end, and it must not be undone at the render step.
      $rates[$class] = $value === NULL ? NULL : (float) $value;
    }

    return $rates + [
      'free' => (bool) ($entry['free'] ?? FALSE),
      'source' => (string) ($entry['source'] ?? ''),
      'checked' => (string) ($entry['checked'] ?? ''),
      'note' => (string) ($entry['note'] ?? ''),
      'key' => $providerId . ':' . (string) ($entry['model'] ?? ''),
    ];
  }

  /**
   * The raw config entry that prices a model, or NULL — the one lookup.
   *
   * Exact `provider:model` first, then a provider-wide `*`, and deliberately no
   * third match; see {@see self::rate()} for why a broader fallback is worse
   * than no answer.
   *
   * @return array<string, mixed>|null
   */
  private function entry(string $providerId, string $modelId): ?array {
    $models = (array) ($this->configFactory->get(self::CONFIG)->get('models') ?? []);

    $entry = NULL;
    foreach ($models as $candidate) {
      if (!is_array($candidate) || (string) ($candidate['provider'] ?? '') !== $providerId) {
        continue;
      }
      $itsModel = (string) ($candidate['model'] ?? '');
      if ($itsModel === $modelId) {
        // An exact entry wins outright, wherever it sits in the list.
        return $candidate;
      }
      if ($itsModel === '*' && $entry === NULL) {
        $entry = $candidate;
      }
    }
    return $entry;
  }

  /**
   * Whether this site can put a number on what a model costs.
   */
  public function isPriced(string $providerId, string $modelId): bool {
    return $this->rate($providerId, $modelId) !== NULL;
  }

  /**
   * What one call cost, and which of its tokens we could not price.
   *
   * The four token counts are DISJOINT — cache-write tokens are not also in
   * `$input`. The metering table's `input_tokens` column still carries the sum
   * (see {@see UsageRecorder}), but the arithmetic needs them apart, because a
   * cache write is billed at a PREMIUM over plain input (1.25x on Anthropic's
   * 5-minute TTL) rather than at the same rate. Folding it into input was a
   * known ~25% understatement on the dominant term of a first turn, and it was
   * only tolerated because the estimator's table had no write rate to read.
   * Owning the table removes the excuse.
   *
   * @param string $providerId
   *   The provider that served the call.
   * @param string $modelId
   *   The model that served it, as resolved.
   * @param int $input
   *   Plain input tokens, EXCLUDING cache writes.
   * @param int $output
   *   Output tokens, thinking included.
   * @param int $cacheRead
   *   Tokens served from the provider's prompt cache.
   * @param int $cacheWrite
   *   Tokens written INTO the provider's prompt cache.
   *
   * @return array{input: float, output: float, cache_read: float, cache_write: float, total: float, free: bool, unpriced: list<string>}
   *   Per-class and total USD, whether the zero (if any) is deliberate, and the
   *   token classes that had tokens but no rate to charge them at.
   */
  public function cost(
    string $providerId,
    string $modelId,
    int $input,
    int $output,
    int $cacheRead,
    int $cacheWrite,
  ): array {
    $rate = $this->rate($providerId, $modelId);
    $counts = [
      'input' => $input,
      'output' => $output,
      'cache_read' => $cacheRead,
      'cache_write' => $cacheWrite,
    ];

    $cost = [];
    $unpriced = [];
    foreach (self::CLASSES as $class) {
      $perToken = $rate[$class] ?? NULL;
      $cost[$class] = $perToken === NULL ? 0.0 : $counts[$class] * $perToken;
      // Only a class that actually carried tokens can be reported as unpriced:
      // Anthropic publishes no cache rate for an image model, and a call that
      // used no cache should not be nagged about it. A rate of exactly 0.0 on a
      // NON-free entry counts as missing too — no paid model bills nothing, so
      // a literal zero there is a typo or an unfilled field, not a price.
      $missing = $perToken === NULL || ($perToken === 0.0 && !($rate['free'] ?? FALSE));
      if ($counts[$class] > 0 && $missing) {
        $unpriced[] = $class;
      }
    }

    return $cost + [
      'total' => array_sum($cost),
      'free' => (bool) ($rate['free'] ?? FALSE),
      'unpriced' => $unpriced,
    ];
  }

  /**
   * The role bindings this site cannot price — the validation both surfaces use.
   *
   * A pure function over the bindings so the status report and the models form
   * cannot disagree about what counts as unpriced. Unbound roles are skipped:
   * a role with no model is a configuration state, not a pricing gap.
   *
   * @param array<string, mixed> $bindings
   *   The `roles` map from `aincient_core.model_roles`.
   *
   * @return array<string, string>
   *   Role id => `provider:model`, for bound roles with no rate.
   */
  public function unpriced(array $bindings): array {
    $out = [];
    foreach ($bindings as $role => $binding) {
      $providerId = (string) (is_array($binding) ? ($binding['provider_id'] ?? '') : '');
      $modelId = (string) (is_array($binding) ? ($binding['model_id'] ?? '') : '');
      if ($providerId === '' || $modelId === '') {
        continue;
      }
      if (!$this->isPriced($providerId, $modelId)) {
        $out[(string) $role] = $providerId . ':' . $modelId;
      }
    }
    return $out;
  }

}
