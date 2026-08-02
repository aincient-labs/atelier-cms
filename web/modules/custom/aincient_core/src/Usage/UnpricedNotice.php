<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Usage;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The one "we cannot price this" warning, shared by every surface showing it.
 *
 * THREE SURFACES SAY THIS SENTENCE — the models form, where a model is chosen,
 * the pricing page, where the rate table is read, and the usage dashboard, where
 * the consequence finally shows up as a number. They must say the SAME
 * sentence: the failure is silent (an unpriced call is recorded at $0.00 and
 * looks exactly like a free one), so an operator who meets the warning on one
 * page and a softer version of it on the other has no way to tell whether they
 * are looking at one problem or two.
 *
 * The dashboard's is a different TENSE, not a different warning — the other two
 * say "calls on this binding will be recorded at $0.00", it says "these calls
 * were" — which is why {@see self::recorded()} lives here beside
 * {@see self::build()} rather than in the controller that renders it.
 *
 * It lives beside {@see ModelPricing} rather than under `Form/` because both a
 * form and a controller render it, and the shared thing between them is the
 * pricing domain, not the form API.
 *
 * Not folded into {@see \Drupal\aincient_core\Hook\PricingRequirements}: the
 * status report is a different medium with a severity, a title and a value, and
 * collapsing the two would force one of them into the other's shape.
 */
final class UnpricedNotice {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModelPricing $pricing,
  ) {}

  /**
   * The warning for a set of role bindings, or [] when everything is priced.
   *
   * @param array<string, mixed> $bindings
   *   The `roles` map from `aincient_core.model_roles`.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function build(array $bindings): array {
    $unpriced = $this->pricing->unpriced($bindings);
    if ($unpriced === []) {
      return [];
    }

    $items = [];
    foreach ($unpriced as $role => $binding) {
      $items[] = $this->t('@role → @binding', ['@role' => $role, '@binding' => $binding]);
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-pricing__unpriced']],
      'text' => [
        '#type' => 'item',
        '#markup' => $this->t('Atelier has no price for some of the models bound on this site, so their calls are recorded as costing nothing and usage reporting will understate what this site spends. The models still work — only the accounting is missing. Rates live in the <code>aincient_core.pricing</code> config object.'),
      ],
      'roles' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * The warning for calls ALREADY RECORDED at $0.00, or [] when there are none.
   *
   * THE SINGLE MOST IMPORTANT THING ON THE USAGE DASHBOARD. Every other number
   * there is a sum of `cost_usd`, and a row with real tokens and a zero
   * in that column drags every one of those sums down without leaving a mark —
   * the totals do not look broken, they look cheap. So the notice states the
   * CONSEQUENCE ("the spend figures below are lower than the bill") rather than
   * the condition ("some models are unpriced"), and it carries the count, because
   * the difference between two rows and two thousand is the difference between
   * ignoring this and stopping to fix it.
   *
   * Models the rate table marks `free` are dropped: a local Ollama really did
   * cost nothing, and warning about it would make the warning meaningless on
   * every site that runs one.
   *
   * THE REST SPLIT IN TWO, AND THE ADVICE IS OPPOSITE. "Recorded at $0.00" is
   * derived — `cost = 0 AND tokens > 0` — because the table has no column saying
   * whether a row was priced when it was written. So it cannot distinguish a model
   * that is unpriced NOW from one that was unpriced THEN and has since been given
   * a rate. The rate table can: ask it. A model with no rate today is a live gap
   * and the fix is to add one; a model that already has a rate has no gap left to
   * fix, and telling its operator to "add a rate" sends them to a config object
   * where the entry is already sitting. Only the historical rows stay wrong, and
   * they stay wrong permanently — nothing recomputes a written row.
   *
   * This was not hypothetical: `claude-sonnet-5` was unpriced for exactly as long
   * as it took to notice a $0.0475 turn recorded as $0.00, and the rows from that
   * window are still in the table underneath a rate that is now correct.
   *
   * @param list<array{provider_id: string, model_id: string, calls: int, tokens: int}> $models
   *   Zero-cost-with-tokens rows grouped by model, from
   *   {@see \Drupal\aincient_core\Usage\UsageQuery::unpricedModels()}.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function recorded(array $models): array {
    $stillUnpriced = [];
    $nowPriced = [];
    $calls = 0;
    $tokens = 0;

    foreach ($models as $model) {
      $providerId = (string) $model['provider_id'];
      $modelId = (string) $model['model_id'];
      $rate = $this->pricing->rate($providerId, $modelId);
      if ($rate !== NULL && ($rate['free'] ?? FALSE)) {
        continue;
      }

      $calls += (int) $model['calls'];
      $tokens += (int) $model['tokens'];
      $item = $this->t('@binding — @calls calls, @tokens tokens', [
        '@binding' => $providerId . ':' . $modelId,
        '@calls' => number_format((int) $model['calls']),
        '@tokens' => number_format((int) $model['tokens']),
      ]);
      if ($rate === NULL) {
        $stillUnpriced[] = $item;
      }
      else {
        $nowPriced[] = $item;
      }
    }

    if ($stillUnpriced === [] && $nowPriced === []) {
      return [];
    }

    // The shared half: the totals are low, and no written row will ever be
    // recomputed. True of both groups, so it is said once and up front.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-usage__unpriced']],
      'text' => [
        '#type' => 'item',
        '#markup' => $this->t('The spend below is an under-report. @calls calls consuming @tokens tokens were recorded as costing $0.00 — they are counted in the token and call figures and missing from the money. A recorded row is never recomputed, so these stay at zero whatever happens to the rates.', [
          '@calls' => number_format($calls),
          '@tokens' => number_format($tokens),
        ]),
      ],
    ];

    // The actionable half. Only these models are still losing money on every
    // call, and only for these is "add a rate" the right instruction.
    if ($stillUnpriced !== []) {
      $build['unpriced_text'] = [
        '#type' => 'item',
        '#markup' => $this->t('Atelier still has no rate for these, so their <em>future</em> calls will be recorded at $0.00 too. Add them to the <code>aincient_core.pricing</code> config object:'),
      ];
      $build['unpriced'] = [
        '#theme' => 'item_list',
        '#items' => $stillUnpriced,
      ];
    }

    // The historical half. Nothing to do, and saying so matters: an operator
    // sent to add a rate that is already there concludes the page is wrong.
    if ($nowPriced !== []) {
      $build['priced_text'] = [
        '#type' => 'item',
        '#markup' => $this->t('These now have a rate — the zero rows predate it, and new calls on them are counted correctly. Nothing to fix:'),
      ];
      $build['priced'] = [
        '#theme' => 'item_list',
        '#items' => $nowPriced,
      ];
    }

    return $build;
  }

}
