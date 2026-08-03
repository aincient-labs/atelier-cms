<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\Yaml\Yaml;

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

  /**
   * The bundled suggestions, relative to the module.
   *
   * A plain YAML file rather than a second config object, for the same reason
   * `model-recommendations.yml` is one: it is OUR published opinion, not this
   * site's decision, so it must not appear in `drush cex` output where a diff
   * would imply the operator chose it. When the suggestion channel lands
   * (`plans/model-identity.md`) a fetched document layers in here, between the
   * bundle and the operator, without moving anything else.
   */
  private const SUGGESTIONS_FILE = 'model-pricing.yml';

  /**
   * Parsed suggestions, memoised per request.
   *
   * @var list<array<string, mixed>>|null
   */
  private ?array $suggestions = NULL;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * The suggested entry for a model, or NULL — what WE would price it at.
   *
   * Never consulted for billing on its own account: {@see self::entry()} reaches
   * it only when the operator has said nothing, and the pricing form shows it
   * beside their value so a disagreement is visible rather than silently lost.
   *
   * @return array<string, mixed>|null
   *   The raw suggestion entry, in the published per-million-token unit.
   */
  public function suggestion(string $providerId, string $modelId): ?array {
    return $this->matchIn($this->suggestions(), $providerId, $modelId);
  }

  /**
   * Every rate we could plausibly mean by this model id, best first.
   *
   * {@see self::suggestion()} answers "do we price exactly this?", which through
   * a proxy is almost always no: the id is an alias out of the operator's own
   * config and arrives namespaced (`openai/gpt-4.1`), date-stamped
   * (`claude-haiku-4-5-20251001`) or both. Those two shapes are not guesses about
   * what a model IS — they are conventions about how ids are written — so this
   * normalises them away and offers what matches underneath.
   *
   * It goes no further on purpose. `production-fast` gets nothing, and family
   * matching ("this contains 'sonnet'") is deliberately not attempted: an alias
   * that merely SOUNDS like a model is exactly the case where a plausible wrong
   * price is worse than none, because nothing downstream can tell it from a
   * right one.
   *
   * Every result is a proposal for the form to offer, never a rate this class
   * will bill at — {@see self::entry()} does not call this. A match must be
   * chosen by a human, because only they can see where their proxy routes.
   *
   * @return list<array{entry: array<string, mixed>, from: string, exact: bool}>
   *   Each candidate, the `provider:model` it came from, and whether it matched
   *   this provider and id outright.
   */
  public function candidates(string $providerId, string $modelId): array {
    $out = [];
    $exact = $this->suggestion($providerId, $modelId);
    if ($exact !== NULL) {
      $out[] = [
        'entry' => $exact,
        'from' => $providerId . ':' . (string) ($exact['model'] ?? $modelId),
        'exact' => TRUE,
      ];
    }

    $wanted = self::normalizeId($modelId);
    if ($wanted === '') {
      return $out;
    }

    foreach ($this->suggestions() as $candidate) {
      $itsProvider = (string) ($candidate['provider'] ?? '');
      $itsModel = (string) ($candidate['model'] ?? '');
      // A provider-wide wildcard says nothing about which model this is, so it
      // cannot be evidence for a name match.
      if ($itsModel === '' || $itsModel === '*') {
        continue;
      }
      if ($exact !== NULL && $itsProvider === $providerId && $itsModel === (string) ($exact['model'] ?? '')) {
        continue;
      }
      if (self::normalizeId($itsModel) === $wanted) {
        $out[] = [
          'entry' => $candidate,
          'from' => $itsProvider . ':' . $itsModel,
          'exact' => FALSE,
        ];
      }
    }

    return $out;
  }

  /**
   * A model id reduced to the part that names the model.
   *
   * Strips the two things that are notation rather than identity: a routing
   * namespace ahead of the last `/` or `:`, and a vendor's release stamp after
   * the name (`-20251001`, `-latest`). The date suffix is not a hypothetical —
   * `claude-haiku-4-5-20251001` failing to match `claude-haiku-4-5` is precisely
   * the shape that sat 4x underpriced in the table this replaced.
   */
  private static function normalizeId(string $modelId): string {
    $id = strtolower(trim($modelId));
    $cut = max(strrpos($id, '/'), strrpos($id, ':'));
    if ($cut !== FALSE && $cut !== 0) {
      $id = substr($id, $cut + 1);
    }
    return (string) preg_replace('/-(latest|\d{6,8})$/', '', $id);
  }

  /**
   * Every suggestion we ship, for the form's "models you could price" list.
   *
   * @return list<array<string, mixed>>
   */
  public function suggestions(): array {
    if ($this->suggestions !== NULL) {
      return $this->suggestions;
    }
    $path = $this->moduleList->getPath('aincient_core') . '/' . self::SUGGESTIONS_FILE;
    $parsed = is_readable($path) ? Yaml::parse((string) file_get_contents($path)) : NULL;
    $models = is_array($parsed) ? ($parsed['models'] ?? NULL) : NULL;
    // A missing or malformed bundle costs suggestions, never billing: the
    // operator's own rates are read from config and are untouched by this.
    return $this->suggestions = is_array($models) ? array_values(array_filter($models, 'is_array')) : [];
  }

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
    // The OPERATOR's layer first, and completely: a site that has priced this
    // model has answered the question, and our suggestion does not get a second
    // vote. That is the whole precedence rule, and it is the reverse of the
    // contrib table's — there, manual beat synced silently and forever, so a
    // stale hand-entered rate could never be corrected. Here the operator still
    // wins, but {@see self::suggestion()} stays readable beside their value, so
    // the pricing form can SHOW that we now suggest something different. Silence
    // was the trap; the override was not.
    $operator = $this->matchIn(
      (array) ($this->configFactory->get(self::CONFIG)->get('models') ?? []),
      $providerId,
      $modelId,
    );
    return $operator ?? $this->suggestion($providerId, $modelId);
  }

  /**
   * The precedence rule itself, over one layer of entries.
   *
   * Exact `provider:model` beats a provider-wide `*`, and nothing else matches —
   * see {@see self::rate()} for why a broader fallback is worse than no answer.
   * Factored out so the operator layer and the suggestion layer cannot drift
   * into resolving the same table two different ways.
   *
   * @param array<mixed> $entries
   *   Candidate entries from one layer.
   *
   * @return array<string, mixed>|null
   */
  private function matchIn(array $entries, string $providerId, string $modelId): ?array {
    $wildcard = NULL;
    foreach ($entries as $candidate) {
      if (!is_array($candidate) || (string) ($candidate['provider'] ?? '') !== $providerId) {
        continue;
      }
      $itsModel = (string) ($candidate['model'] ?? '');
      if ($itsModel === $modelId) {
        return $candidate;
      }
      if ($itsModel === '*' && $wildcard === NULL) {
        $wildcard = $candidate;
      }
    }
    return $wildcard;
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
