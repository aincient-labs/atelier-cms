<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Controller;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\RateFreshness;
use Drupal\aincient_core\Usage\UnpricedNotice;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rate sheet: what each ROLE this site is configured with costs to run.
 *
 * ORGANISED BY ROLE, WHICH IS THE POINT. The obvious page here is a catalogue —
 * every priced model, one row each — and it is the wrong one. An operator does
 * not choose models, they choose roles: the five rows on the onboarding "Set the
 * pace" screen and on {@see \Drupal\aincient_core\Form\ModelRolesForm}. So this
 * page shows the SAME five rows, in the SAME order, with the SAME labels
 * ({@see ModelRoles::pickerDefinitions()}, iterated rather than restated so the
 * two cannot drift), and puts each role's bound model and that model's rates
 * beside it. Read down the column and you have this site's actual price list;
 * a catalogue would only tell you what we happen to have priced.
 *
 * Which also means: a priced model no role binds is NOT shown. It is not what
 * this site spends.
 *
 * WHY A PAGE AND NOT A FORM. Nothing here is editable, so a ConfigFormBase would
 * put a "Save configuration" button under a screen with no inputs — a control
 * that promises an edit the page cannot make. The contrib metering settings form
 * Atelier used to carry was that mistake at scale: a screen full of savable rate
 * fields that nothing on the billing path read any more. It was closed off, and
 * then dropped with the module; this page is what took its place, and it does
 * not pretend to be editable.
 *
 * WHY THE RATES ARE NOT EDITABLE, WHICH IS THE LOAD-BEARING PART. They ship in
 * `aincient_core.pricing` and change by release, deliberately. An operator
 * override would rebuild on our side the precedence trap we left contrib to
 * escape: in the rate table Atelier used to depend on, manually-entered rates
 * outranked synced ones BY DESIGN and forever, which is how
 * `claude-haiku-4-5-20251001` stayed pinned at
 * Haiku 3.5's $0.25/$1.25 — 4x low — and how an unlisted sonnet-5 turn that
 * really cost $0.0475 was recorded as $0.00, a 1,100x under-report. A rate that
 * can only be corrected by shipping a correction cannot go stale behind
 * someone's back. Correcting one is a config edit and a release, on purpose.
 *
 * The provenance — source URL and the date the figure was last checked against
 * it — travels with every row for that reason, though it sits behind the numbers
 * rather than beside them: the rates are what the page is read for, the
 * provenance is what it is audited with.
 */
final class PricingController extends ControllerBase {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly ModelRoleResolver $roleResolver,
    private readonly ModelPricing $pricing,
    private readonly UnpricedNotice $unpricedNotice,
    private readonly RateFreshness $freshness,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('aincient_core.model_role_resolver'),
      $container->get('aincient_core.model_pricing'),
      $container->get('aincient_core.unpriced_notice'),
      $container->get('aincient_core.rate_freshness'),
    );
  }

  /**
   * The page.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(): array {
    $pricingConfig = $this->config->get(ModelPricing::CONFIG);
    $rolesConfig = $this->config->get(ModelRoleResolver::CONFIG);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-pricing']],
      '#attached' => ['library' => ['aincient_core/pricing']],
      // Both objects are read on every request; without them a rate corrected by
      // a release — or a role rebound on the models form — would keep rendering
      // from the page cache, which is the same staleness this page exists to
      // catch.
      '#cache' => [
        'tags' => array_merge($pricingConfig->getCacheTags(), $rolesConfig->getCacheTags()),
      ],
    ];

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-pricing__intro']],
      // One sentence, because the page IS the argument: the rows below say what
      // each role costs, and the only thing prose has to add is where a wrong
      // number gets corrected. The reason they are not editable here — the
      // precedence trap that once priced a model at a quarter of its real rate —
      // is written out in this class's docblock and in {@see ModelPricing}, which
      // is where someone changing the design will read it. Retelling it above the
      // table only pushed the table off the first screen.
      '#value' => (string) $this->t('Rates ship with the release; correct one by changing the <code>aincient_core.pricing</code> config object.'),
    ];

    // The same warning the models form shows over the same bindings — one
    // silent failure, one sentence, so meeting it on both pages reads as one
    // problem rather than two.
    $build['unpriced'] = $this->unpricedNotice->build(
      (array) ($rolesConfig->get('roles') ?? []),
    );

    $rows = [];
    foreach ($this->roleRows() as $row) {
      $rows[] = [
        'class' => array_filter([
          $row['bound'] && !$row['priced'] ? 'ain-pricing__row--unpriced' : '',
          // A lapsed rate gets the same visual weight as a missing one, because
          // it is the worse of the two: no rate is reported everywhere, a wrong
          // rate is reported nowhere and still adds up.
          $row['freshness'] === RateFreshness::LAPSED ? 'ain-pricing__row--lapsed' : '',
          $row['freshness'] === RateFreshness::STALE ? 'ain-pricing__row--stale' : '',
        ]),
        'data' => [
          [
            'data' => [
              '#type' => 'inline_template',
              // The note lives HERE, under the role, and not in the provenance
              // cell where it used to sit. It is prose about the RATE ("the
              // previous table had this 4x too low"), not about the source, and
              // parking a two-line sentence in the last column made that column
              // the widest thing on the page and set every row's height from it.
              // Under the role's own description it reads as a continuation of
              // the same voice, and the table collapses to something scannable.
              '#template' => '<span class="ain-pricing__role">{{ label }}</span><span class="ain-pricing__role-desc">{{ description }}</span>{% if note %}<span class="ain-pricing__note">{{ note }}</span>{% endif %}',
              '#context' => [
                'label' => $row['label'],
                'description' => $row['description'],
                // Optional in the config, and most entries carry none — hence
                // the guard rather than an always-rendered empty element.
                'note' => $row['note'],
              ],
            ],
            'class' => ['ain-pricing__subject'],
          ],
          ['data' => $this->modelCell($row), 'class' => ['ain-pricing__id']],
          ['data' => $this->rateCell($row, 'input')],
          ['data' => $this->rateCell($row, 'output')],
          ['data' => $this->rateCell($row, 'cache_read')],
          ['data' => $this->rateCell($row, 'cache_write')],
          ['data' => $this->sourceCell($row), 'class' => ['ain-pricing__provenance']],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Role'),
        $this->t('Model'),
        $this->t('Input'),
        $this->t('Output'),
        $this->t('Cache read'),
        $this->t('Cache write'),
        $this->t('Source'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['ain-pricing__rates']],
      // Per MILLION tokens, because that is the unit every vendor publishes in
      // AND the unit the config stores: an operator checking this page against a
      // price sheet should be able to compare the two numbers without counting
      // zeros.
      '#caption' => $this->t('US dollars per million tokens.'),
    ];

    // The models form is this page's twin — same five roles, same bindings, one
    // editable and one not. Each links to the other so an operator who arrives
    // at the price and wants to change the model (or the reverse) is one click
    // from it rather than hunting the admin tree.
    $build['models_link'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-pricing__link']],
      '#value' => (string) $this->t('Change which model a role is bound to on <a href="@url">Atelier models</a>.', [
        '@url' => Url::fromRoute('aincient_core.model_roles')->toString(),
      ]),
    ];

    return $build;
  }

  /**
   * One row per role: what it is bound to and what that costs.
   *
   * Public and free of render concerns so the attribution can be tested without
   * a page: the ordering, the unbound state and the unpriced state are the three
   * facts this page asserts, and all three are wrong-but-plausible if they slip.
   *
   * Bindings come from {@see ModelRoleResolver::binding()} — the EXPLICIT one,
   * with no resolve() fallback. A page reporting configuration must show an
   * unbound role as unbound; showing Vision inheriting the task model would
   * describe a call path, not a setting, and the operator would go looking for
   * a binding they never made.
   *
   * @return list<array{role: string, label: string, description: string, provider: string, model: string, bound: bool, priced: bool, free: bool, input: ?float, output: ?float, cache_read: ?float, cache_write: ?float, source: string, checked: string, note: string, freshness: ?string}>
   *   One row per role in {@see ModelRoles::pickerDefinitions()} order.
   */
  public function roleRows(): array {
    $rows = [];
    foreach (ModelRoles::pickerDefinitions() as $role => $definition) {
      $binding = $this->roleResolver->binding($role);
      $provider = (string) ($binding['provider_id'] ?? '');
      $model = (string) ($binding['model_id'] ?? '');
      $bound = $provider !== '' && $model !== '';

      $rate = $bound ? $this->pricing->published($provider, $model) : NULL;
      $rows[] = [
        'role' => $role,
        'label' => $definition['label'],
        'description' => $definition['description'],
        'provider' => $provider,
        'model' => $model,
        'bound' => $bound,
        'priced' => $rate !== NULL,
        'free' => (bool) ($rate['free'] ?? FALSE),
        'input' => $rate['input'] ?? NULL,
        'output' => $rate['output'] ?? NULL,
        'cache_read' => $rate['cache_read'] ?? NULL,
        'cache_write' => $rate['cache_write'] ?? NULL,
        'source' => (string) ($rate['source'] ?? ''),
        'checked' => (string) ($rate['checked'] ?? ''),
        'note' => (string) ($rate['note'] ?? ''),
        // Whether the rate beside it can still be believed. A number that has
        // stopped being correct is worse than one that is missing: the missing
        // one is reported everywhere, while this one keeps producing confident
        // figures that are simply too low. The provenance column already answers
        // "where did this come from"; this answers "does it still hold".
        'freshness' => $bound ? $this->freshness->state($provider, $model) : NULL,
      ];
    }
    return $rows;
  }

  /**
   * The model cell: the binding, plus the flag when we cannot price it.
   *
   * The unpriced flag says the CONSEQUENCE, not just the condition. "No rate" is
   * a fact an operator can read past; "recorded at $0.00 and your spend is
   * understated" is the reason they should not.
   *
   * @param array<string, mixed> $row
   *   A row from {@see self::roleRows()}.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function modelCell(array $row): array {
    if (!$row['bound']) {
      // Not an error. A keyless install has bound nothing, and both image roles
      // are optional by design — saying "not configured" is the honest report.
      return [
        '#type' => 'inline_template',
        '#template' => '<span class="ain-pricing__unbound">{{ text }}</span>',
        '#context' => ['text' => $this->t('Not configured')],
      ];
    }

    $cell = [
      '#type' => 'inline_template',
      '#template' => '{{ binding }}{% if flag %}<span class="ain-pricing__flag">{{ flag }}</span>{% endif %}',
      '#context' => [
        'binding' => $row['provider'] . ':' . $row['model'],
        'flag' => '',
      ],
    ];
    if (!$row['priced']) {
      $cell['#context']['flag'] = $this->t('No rate — calls on this role are recorded at $0.00 and this site’s spend is understated.');
    }
    return $cell;
  }

  /**
   * The provenance cell: where the figure came from, and whether it still holds.
   *
   * WHY A LABELLED LINK AND NOT THE URL. A printed
   * `https://platform.claude.com/docs/en/pricing` is 44 characters with no
   * spaces, so in a table cell it broke mid-word across three lines
   * (`…claude.com/d` / `ocs/en/pricing`), which made this the widest column on a
   * seven-column page and set the height of every row from the least important
   * thing in it. The host alone is what an operator actually reads — it says
   * "the vendor's own price sheet" or "somebody's blog" — and the full URL is
   * still one hover (the `title`) and one click away. Nothing is lost but the
   * wrapping.
   *
   * A source that is not a URL is printed as written: the local-inference entry
   * says "Local inference — no per-token billing", which is a provenance
   * statement and not a broken link.
   *
   * The check date is a machine string about the row rather than a claim in the
   * row, so it wears the mono micro-label (Law 13) instead of a body line, and
   * the freshness verdict sits below both — it is the one thing here that can
   * change what you should DO about the number beside it.
   *
   * @param array<string, mixed> $row
   *   A row from {@see self::roleRows()}.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function sourceCell(array $row): array {
    $source = (string) $row['source'];
    $host = $source === '' ? NULL : parse_url($source, PHP_URL_HOST);

    return [
      '#type' => 'inline_template',
      '#template' => '{% if url %}<a class="ain-pricing__source" href="{{ url }}" title="{{ url }}" rel="noreferrer">{{ label }}</a>{% elseif label %}<span class="ain-pricing__source">{{ label }}</span>{% endif %}'
      . '{% if checked %}<span class="ain-pricing__checked">{{ checked }}</span>{% endif %}'
      . '{% if freshness %}<span class="ain-pricing__flag {{ freshness_class }}">{{ freshness }}</span>{% endif %}',
      '#context' => [
        'url' => is_string($host) && $host !== '' ? $source : '',
        // `www.` carries no information and only eats width.
        'label' => is_string($host) && $host !== ''
          ? preg_replace('/^www\./', '', $host)
          : $source,
        'checked' => $row['checked'],
        // States the consequence, since "expired" alone reads as bookkeeping
        // rather than as a number this site is actively billing itself at.
        'freshness' => match ($row['freshness']) {
          RateFreshness::LAPSED => $this->t('This rate’s published end date has passed — the provider charges something else now, and every spend figure using it is wrong.'),
          RateFreshness::STALE => $this->t('Not checked against the source in over six months. It may still be right; nobody has confirmed it.'),
          default => '',
        },
        // The two verdicts are different severities and must not be told apart
        // only by whether the row has an edge: lapsed is a known error (brick),
        // stale is a request to go and look (ochre).
        'freshness_class' => match ($row['freshness']) {
          RateFreshness::LAPSED => 'ain-pricing__flag--lapsed',
          RateFreshness::STALE => 'ain-pricing__flag--stale',
          default => '',
        },
      ],
    ];
  }

  /**
   * One rate cell — the number, or an honest reason there isn't one.
   *
   * A blank and a zero mean different things and must not look the same: an
   * absent rate is a gap in the table (those tokens go unbilled and the
   * dashboard under-reports), a zero on a `free` entry is a fact about local
   * inference. Collapsing the two is the failure {@see ModelPricing} exists to
   * end, and it would be undone here by printing "$0" for both.
   *
   * @param array<string, mixed> $row
   *   A row from {@see self::roleRows()}.
   * @param string $class
   *   The token class: input, output, cache_read or cache_write.
   */
  private function rateCell(array $row, string $class): string|\Stringable {
    if (!$row['bound']) {
      // No model, so no rate to state — an em dash, because an empty cell reads
      // as a rendering failure rather than an assertion.
      return $this->t('—');
    }
    if (!$row['priced']) {
      return $this->t('not priced');
    }
    $perMtok = $row[$class];
    if ($perMtok === NULL) {
      // The entry prices this model but publishes nothing for this token class
      // — Google quotes no cache rate for the image model, for instance. "n/a"
      // is the truth; "$0.00" would be a claim we cannot make.
      return $row['free'] ? $this->t('free') : $this->t('n/a');
    }
    if ($perMtok === 0.0 && $row['free']) {
      return $this->t('free');
    }
    // TWO DECIMALS, FIXED. Trimming trailing zeros produced `$2`, `$0.2`, `$2.5`
    // and `$30` in one column, so the decimal points did not line up and the
    // tabular figures the CSS asks for had nothing to align. A price column that
    // does not align is a price column nobody compares by eye, which is the only
    // reason this table is a table.
    $formatted = number_format((float) $perMtok, 2, '.', '');
    if ($formatted === '0.00' && (float) $perMtok > 0.0) {
      // A rate too small to survive rounding is real money and must not be
      // printed as zero — that collapse is the exact failure {@see ModelPricing}
      // exists to end. Widen this one cell rather than the whole column.
      $formatted = rtrim(number_format((float) $perMtok, 6, '.', ''), '0');
    }
    return '$' . $formatted;
  }

}
