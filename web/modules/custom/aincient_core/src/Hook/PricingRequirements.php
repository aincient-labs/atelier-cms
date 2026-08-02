<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Hook;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\RateFreshness;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Tells the status report when a model this site USES has no price.
 *
 * THE SECOND HALF OF THE VALIDATION, and not a duplicate of the one on the
 * models form. The form warns at the moment an operator binds a model — the
 * moment the decision is being made and is cheapest to change. This one nags
 * afterwards, which is the case the form cannot cover: a binding made before a
 * model was retired from the rate table, a profile re-applied by a
 * recommendations refresh, a site configured by Drush or by the onboarding
 * wizard and never revisited. A gap that only announces itself on a page nobody
 * has open is the same silence this whole change is about.
 *
 * IN A HOOK CLASS, not `aincient_core.install`. `SystemManager::listRequirements()`
 * invokes `runtime_requirements` without loading `.install` files, so a
 * procedural implementation there would simply never run.
 */
final class PricingRequirements {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModelPricing $pricing,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RateFreshness $freshness,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   Requirements, keyed as core expects.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    return $this->coverage() + $this->freshnessRequirement();
  }

  /**
   * Whether every bound model has a rate at all.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement.
   */
  private function coverage(): array {
    $bindings = (array) ($this->configFactory->get('aincient_core.model_roles')->get('roles') ?? []);
    $unpriced = $this->pricing->unpriced($bindings);

    if ($unpriced === []) {
      return [
        'aincient_core_model_pricing' => [
          'title' => $this->t('Atelier model pricing'),
          'value' => $this->t('Every bound model is priced'),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }

    $items = [];
    foreach ($unpriced as $role => $binding) {
      $items[] = $this->t('@role → @binding', ['@role' => $role, '@binding' => $binding]);
    }

    return [
      'aincient_core_model_pricing' => [
        'title' => $this->t('Atelier model pricing'),
        'value' => $this->formatPlural(
          count($unpriced),
          '1 bound model has no price',
          '@count bound models have no price',
        ),
        // Says what it COSTS to leave it — a warning that only names a missing
        // config key gets read as pedantry and ignored.
        'description' => [
          'intro' => [
            '#markup' => $this->t('These roles are bound to models Atelier has no rate for, so every call they serve is recorded at $0.00 and the usage dashboard under-reports what this site spends. Add a rate to the <code>aincient_core.pricing</code> config object.'),
          ],
          'roles' => [
            '#theme' => 'item_list',
            '#items' => $items,
          ],
        ],
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

  /**
   * Whether the rates we DO have can still be believed.
   *
   * THE COMPLEMENT OF THE ONE ABOVE, and the harder of the two. A missing rate
   * announces itself — the check above, a warning on the models form, a log line
   * on the first call. A rate that has quietly stopped being correct announces
   * nothing at all: the dashboard keeps producing confident figures that are
   * simply too low. `claude-sonnet-5` ships at an introductory $2/$10 with a
   * published end date; the day it lapses, every spend number on this site
   * under-reports by 33% and not one thing about the site has changed.
   *
   * The status report is the right home because this fires on a DATE, not on an
   * action. There is no page an operator visits and no button they press on the
   * day a price changes, so the only warning that can reach them is one on a page
   * they check for other reasons. {@see RateFreshness} explains why a date written
   * down in advance is the only signal available to an offline appliance.
   *
   * @return array<string, array<string, mixed>>
   *   One requirement.
   */
  private function freshnessRequirement(): array {
    $problems = $this->freshness->problems();
    if ($problems === []) {
      return [
        'aincient_core_rate_freshness' => [
          'title' => $this->t('Atelier rate freshness'),
          'value' => $this->t('No rate has lapsed or gone unverified'),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }

    $lapsed = array_values(array_filter(
      $problems,
      static fn (array $p): bool => $p['state'] === RateFreshness::LAPSED,
    ));
    $stale = array_values(array_filter(
      $problems,
      static fn (array $p): bool => $p['state'] === RateFreshness::STALE,
    ));

    $description = [];
    if ($lapsed !== []) {
      // First, and worded as a fact rather than a suspicion: the vendor published
      // the end date, we wrote it down, and it has passed. "Might be wrong" here
      // would be false modesty that gets the line skimmed.
      $description['lapsed_intro'] = [
        '#markup' => $this->t('These rates had a published end date and it has passed, so Atelier is billing this site at a price the provider no longer charges — every spend figure on the usage report is wrong, and almost certainly too low. Update the rate in the <code>aincient_core.pricing</code> config object and move its <code>checked</code> and <code>expires</code> dates.'),
      ];
      $description['lapsed'] = [
        '#theme' => 'item_list',
        '#items' => array_map(
          fn (array $p): string => (string) $this->t('@binding — lapsed @days days ago, on @date', [
            '@binding' => $p['binding'],
            '@days' => $p['days'],
            '@date' => $p['date'],
          ]),
          $lapsed,
        ),
      ];
    }
    if ($stale !== []) {
      // Second, and deliberately softer: nobody knows that these are wrong. The
      // ask is to go and read the price sheet, not to change a number.
      $description['stale_intro'] = [
        '#markup' => $this->t('Nobody has checked these against the provider\'s price sheet in over six months. They may still be right — but provider prices move, and an unverified rate is one nobody can defend. Re-read the source and update the <code>checked</code> date.'),
      ];
      $description['stale'] = [
        '#theme' => 'item_list',
        '#items' => array_map(
          fn (array $p): string => $p['date'] === ''
            ? (string) $this->t('@binding — never recorded as checked', ['@binding' => $p['binding']])
            : (string) $this->t('@binding — last checked @days days ago, on @date', [
              '@binding' => $p['binding'],
              '@days' => $p['days'],
              '@date' => $p['date'],
            ]),
          $stale,
        ),
      ];
    }

    return [
      'aincient_core_rate_freshness' => [
        'title' => $this->t('Atelier rate freshness'),
        'value' => $lapsed !== []
          ? $this->formatPlural(count($lapsed), '1 rate has lapsed', '@count rates have lapsed')
          : $this->formatPlural(count($stale), '1 rate is unverified', '@count rates are unverified'),
        'description' => $description,
        // A lapsed rate is an ERROR: we are not warning that something might be
        // wrong, we are reporting a number we know to be wrong and are still
        // using. Staleness is only ever a warning — the honest claim there is
        // ignorance, and an error over ignorance is how a status report gets a
        // permanent red line that everyone learns to scroll past.
        'severity' => $lapsed !== []
          ? RequirementSeverity::Error
          : RequirementSeverity::Warning,
      ],
    ];
  }

}
