<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Controller;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\aincient_core\Usage\UnpricedNotice;
use Drupal\aincient_core\Usage\CallSites;
use Drupal\aincient_core\Usage\UsageQuery;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Atelier's usage dashboard: what the AI work cost, and what caused the cost.
 *
 * BY CALL SITE, WHICH IS THE POINT. Every metering dashboard on offer groups
 * spend by editor and by month. On a site where one person drives one console —
 * which is every Atelier install for a long while — that produces a single row
 * and answers nothing. The question an operator actually has is "what is running
 * up the bill", and the column that answers it is `context_id`: the recorder tags
 * every call with the part of the product that made it
 * ({@see \Drupal\aincient_core\Usage\UsageRecorder}). It is the first
 * breakdown on this page, and the reason the page is ours.
 *
 * ONE SCREEN, CUT BACK TO IT. This page used to stack four breakdowns and five
 * paragraphs of prose, and the effect was that the one section worth reading was
 * somewhere in the middle of it. Two of the four are gone. "By editor" was a
 * single row on the single-operator appliance every Atelier install is for a long
 * while — a breakdown of one thing is not a breakdown — and "Recent calls" was
 * fifty rows of tail that pushed the totals off the screen; it is now a page of
 * its own ({@see self::log()}), where it can be all of them instead of the last
 * fifty. NEITHER FACT IS LOST: `uid` is still a column in both exports, so
 * per-editor spend is a pivot away, and the log page names the editor per call.
 * What survives here is what answers "what is spending my money" — three totals,
 * the warning that says when they are too low, and the two axes that explain
 * them.
 *
 * NO CHART LIBRARY, NO HTMX, NO ASSET FROM ANYWHERE BUT THIS ORIGIN. The
 * metering UIs on offer draw with Chart.js from jsdelivr and htmx from unpkg.
 * Atelier ships as an offline appliance: on a site with no route to the public
 * internet those two are a console full of failed requests and a dashboard of
 * blank rectangles, and on a site with one they are a third party watching an
 * administrator work. Everything here is server-rendered; the proportion bars are
 * two nested spans and a width.
 *
 * NOTHING HERE ENFORCES ANYTHING. No quota bars, no budget columns, no
 * threshold badges. Per-role budgets and alerts were dropped when Atelier took
 * pricing over, because the implementation they came from fired them from an
 * event nothing dispatched — a control that accepts a value it will never apply
 * is worse than a missing one. This page reports; the invoice enforces.
 *
 * THE LOG IS OURS, TABLE INCLUDED. `aincient_ai_usage` ships with this module and
 * is written by {@see \Drupal\aincient_core\Usage\UsageRecorder}, so there is
 * no optional dependency left to be absent: {@see UsageQuery::available()} can
 * only answer FALSE on a site whose database updates have not been run, which is
 * what the guard at the top of {@see self::page()} says.
 */
final class UsageController extends ControllerBase {

  /**
   * The selectable periods: query value => days, NULL for the whole log.
   *
   * A plain GET parameter and three links. A select element would need a submit
   * button or JavaScript to do anything, and this page has no JavaScript on
   * purpose; three links are also three URLs, which is what someone pastes into
   * a ticket when they want a colleague to see the same numbers.
   */
  private const PERIODS = ['7' => 7, '30' => 30, 'all' => NULL];

  /**
   * The period used when none is asked for.
   *
   * Long enough that a site used twice a week has something to show, short
   * enough that "spend" means something current rather than lifetime.
   */
  private const DEFAULT_PERIOD = '30';

  /**
   * The export columns, in order — the contract both exports keep.
   *
   * Declared here rather than derived from a row so an empty period still
   * produces a file with a header, and so the CSV and the JSON cannot drift into
   * carrying different fields. `context_id` and `call_site` name the call site,
   * the axis this whole surface is built on; `unpriced` is the derived flag that
   * makes a $0.00 row findable with a filter. `uid` is here because the page
   * dropped its per-editor table: on the appliance that is one row, but the
   * export is where a site with several editors goes to get it back.
   */
  private const EXPORT_COLUMNS = [
    'id', 'timestamp', 'time', 'uid', 'context_id', 'call_site',
    'provider_id', 'model_id', 'operation', 'input_tokens', 'output_tokens',
    'cached_tokens', 'cost_usd', 'unpriced',
  ];

  public function __construct(
    private readonly UsageQuery $usage,
    private readonly CallSites $callSites,
    private readonly UnpricedNotice $unpricedNotice,
    private readonly ModelPricing $pricing,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('aincient_core.usage_query'),
      $container->get('aincient_core.call_sites'),
      $container->get('aincient_core.unpriced_notice'),
      $container->get('aincient_core.model_pricing'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('pager.manager'),
    );
  }

  /**
   * The dashboard.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function page(Request $request): array {
    $period = $this->period($request);
    $since = $this->since($period);

    $build = $this->frame('aincient_core.usage', $period);

    if (!$this->usage->available()) {
      $build['unavailable'] = $this->missingTable();
      return $build;
    }

    $totals = $this->usage->totals($since);

    // ===== 1. The totals =====
    $build['totals'] = [
      '#theme' => 'item_list',
      '#attributes' => ['class' => ['ain-usage__totals']],
      '#items' => [
        $this->stat($this->t('Spend'), $this->money($totals['spend'])),
        $this->stat($this->t('Calls'), number_format($totals['calls'])),
        $this->stat($this->t('Tokens'), number_format($totals['tokens'])),
      ],
    ];
    // Immediately under the figures it invalidates, not at the top of the page:
    // it is a caption on those three numbers, and a banner above the fold is a
    // banner people learn to scroll past.
    $build['under_report'] = $this->unpricedNotice->recorded($this->usage->unpricedModels($since));

    // ===== 2. By call site — the headline =====
    $build['call_sites_heading'] = $this->heading($this->t('By call site'));
    $build['call_sites'] = $this->callSiteTable($since);

    // ===== 3. By model =====
    $build['models_heading'] = $this->heading($this->t('By model'));
    $build['models'] = $this->modelTable($since);

    // ===== 4. The way out: the same period, in three other containers =====
    $build['footer'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-usage__exports']],
      '#value' => (string) $this->t('Every call in this period: <a href="@log">the full call log</a> · export as <a href="@csv">CSV</a> or <a href="@json">JSON</a>.', [
        '@log' => $this->usageLogLink($period),
        '@csv' => $this->exportUrl('aincient_core.usage_export_csv', $period),
        '@json' => $this->exportUrl('aincient_core.usage_export_json', $period),
      ]),
    ];

    return $build;
  }

  /**
   * The full call log: every call in the period, paged, one row each.
   *
   * WHAT THE DASHBOARD USED TO END WITH, MOVED AND MADE COMPLETE. Fifty rows of
   * newest-first tail sat under four tables on the dashboard, where they were
   * both too many to skim past and too few to answer anything — "was that image
   * run this morning or yesterday" is a question the 51st row holds. On its own
   * page it can be the whole log, and the dashboard above it can be read in one
   * screen. It also replaces the `ai_usage_log` view that shipped with contrib
   * metering, which was deleted with the module.
   *
   * The editor is a column here rather than a table of its own: per-call it is a
   * fact worth having on a shared site, and summing it into a breakdown was the
   * thing that produced one row and no information.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function log(Request $request): array {
    $period = $this->period($request);
    $since = $this->since($period);

    $build = $this->frame('aincient_core.usage_log', $period);

    if (!$this->usage->available()) {
      $build['unavailable'] = $this->missingTable();
      return $build;
    }

    // COUNT first, then the window. The pager needs the total to know how many
    // links to draw, and the current page to know where the window starts —
    // reading the rows first and counting them would only ever count fifty.
    $pager = $this->pagerManager->createPager(
      $this->usage->count($since),
      UsageQuery::PAGE_LIMIT,
    );
    $build['calls'] = $this->logTable(
      $this->usage->page($since, $pager->getCurrentPage() * UsageQuery::PAGE_LIMIT, UsageQuery::PAGE_LIMIT),
    );
    $build['pager'] = ['#type' => 'pager'];

    return $build;
  }

  /**
   * The URL of the full call log, carrying the period being looked at.
   *
   * Unguarded, unlike the contrib view link this replaces: the route is ours and
   * declared in this module's `aincient_core.routing.yml`, so it cannot be
   * missing on a site running this code. A try/catch here would only be able to
   * hide our own broken deployment.
   */
  private function usageLogLink(string $period): string {
    return Url::fromRoute('aincient_core.usage_log', [], ['query' => ['period' => $period]])->toString();
  }

  /**
   * The shell both pages share: the container, the cache rule, the periods.
   *
   * @param string $route
   *   The route the period links point back at — each page keeps the operator on
   *   itself when they change the window, because sending someone from page 3 of
   *   the log to the dashboard for clicking "7 days" loses their place to answer
   *   a question they did not ask.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function frame(string $route, string $period): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-usage']],
      '#attached' => ['library' => ['aincient_core/usage']],
      '#cache' => [
        // Uncacheable, deliberately, and NOT for want of a tag. `UsageLog` does
        // invalidate `aincient_ai_usage` on every write, so tagging this page
        // would in fact keep it fresh — but it would also throw away and rebuild
        // the whole render on every single AI call, which on a busy console is a
        // cache that never survives long enough to be read. The page is a handful
        // of aggregate queries; recomputing them on request is cheaper than
        // maintaining a cache entry with that invalidation rate. What must not
        // happen is a stale one: a spend figure that stopped moving while the
        // spending did not is the same failure as one that is quietly too low.
        'max-age' => 0,
        'contexts' => ['url.query_args:period', 'url.query_args:page'],
      ],
      'periods' => $this->periodLinks($route, $period),
    ];
  }

  /**
   * The one thing that can go wrong: the table is not there yet.
   *
   * NOT A MISSING MODULE ANY MORE. The log is `aincient_ai_usage`, installed by
   * this module, so the only way to reach this branch is an upgraded site whose
   * database updates have not been run — code ahead of schema. Said as the
   * command that fixes it, and the route stays reachable so the studio's "AI
   * usage" room opens onto an instruction rather than a 404 that reads as a
   * broken install.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function missingTable(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ain-usage__intro']],
      '#value' => (string) $this->t('There is no usage log table on this site yet. Atelier records what each AI call costs into its own <code>aincient_ai_usage</code> table, which is created by this module’s database updates — run <code>drush updb</code> (or visit <code>/update.php</code>) and calls made from then on will be reported here. Calls made in the meantime are not recorded anywhere and cannot be recovered.'),
    ];
  }

  /**
   * The CSV export — the same rows, with the call site contrib's export omits.
   *
   * Streamed rather than assembled: see {@see UsageQuery::stream()}.
   */
  public function exportCsv(Request $request): Response {
    $period = $this->period($request);
    $rows = $this->usage->stream($this->since($period));

    $response = new StreamedResponse(function () use ($rows): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      // The header is written from the column list rather than from the first
      // row, so an empty period still downloads a file that says what it would
      // have contained — a zero-byte CSV reads as a failed export.
      fputcsv($out, self::EXPORT_COLUMNS, escape: '');
      foreach ($rows as $row) {
        fputcsv($out, array_map(
          static fn (mixed $value): string => match (TRUE) {
            is_bool($value) => $value ? 'yes' : 'no',
            // Fixed decimals, not PHP's default float cast: a cost of $0.00003725
            // stringifies as "3.725E-5", which a spreadsheet imports as text and a
            // human reads as nothing at all. Eight places is the column's own
            // precision, so nothing is rounded away.
            is_float($value) => number_format($value, 8, '.', ''),
            default => (string) $value,
          },
          $this->exportRow($row),
        ), escape: '');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->exportFilename($period, 'csv') . '"');
    return $response;
  }

  /**
   * The JSON export — the same rows and the same columns as the CSV.
   */
  public function exportJson(Request $request): JsonResponse {
    $period = $this->period($request);
    $rows = [];
    foreach ($this->usage->stream($this->since($period)) as $row) {
      $rows[] = $this->exportRow($row);
    }

    $response = new JsonResponse([
      'period' => $period,
      'generated' => $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'c'),
      'calls' => $rows,
    ]);
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->exportFilename($period, 'json') . '"');
    return $response;
  }

  /**
   * One exported row, in the order both exports write it.
   *
   * `context_id` AND the label it resolves to. The raw tag is first because it is
   * the stable key a spreadsheet should pivot on; the label is a convenience that
   * can change wording between releases. `unpriced` is derived rather than left
   * to the reader — a zero in the cost column beside a non-zero token count is
   * exactly the thing an export is used to go looking for.
   *
   * @return array<string, string|int|float|bool>
   *   One row, keyed by {@see self::EXPORT_COLUMNS}.
   */
  private function exportRow(object $row): array {
    $tokens = (int) $row->input_tokens + (int) $row->output_tokens;
    $cost = (float) $row->cost_usd;
    return [
      'id' => (int) $row->id,
      'timestamp' => (int) $row->timestamp,
      'time' => $this->dateFormatter->format((int) $row->timestamp, 'custom', 'c'),
      'uid' => (int) $row->uid,
      'context_id' => (string) ($row->context_id ?? ''),
      'call_site' => $this->callSites->label($row->context_id ?? ''),
      'provider_id' => (string) $row->provider_id,
      'model_id' => (string) $row->model_id,
      'operation' => (string) $row->operation,
      'input_tokens' => (int) $row->input_tokens,
      'output_tokens' => (int) $row->output_tokens,
      'cached_tokens' => (int) $row->cached_tokens,
      'cost_usd' => $cost,
      'unpriced' => $tokens > 0 && $cost === 0.0,
    ];
  }

  /**
   * The call-site table: label, what it is, and a bar drawn on tokens.
   *
   * The bar is scaled by TOKENS and never by spend — see
   * {@see CallSites::decorate()}. An unpriced model records $0.00, so a bar drawn
   * on money would show the biggest consumer on the page as the smallest bar.
   * That used to be said in a caption under the table; the caption is gone with
   * the rest of the page's prose, and the rule it protects lives with the code
   * that applies it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function callSiteTable(int|null $since): array {
    $rows = [];
    foreach ($this->callSites->decorate($this->usage->byCallSite($since)) as $row) {
      $rows[] = [
        'class' => $row['known'] ? [] : ['ain-usage__row--unknown'],
        'data' => [
          [
            'data' => [
              '#type' => 'inline_template',
              '#template' => '<span class="ain-usage__site">{{ label }}</span><span class="ain-usage__tag">{{ tag }}</span><span class="ain-usage__site-desc">{{ description }}</span><span class="ain-usage__bar"><span class="ain-usage__bar-fill" style="width: {{ share }}%"></span></span>',
              '#context' => [
                'label' => $row['label'],
                // The raw tag beside the label, always. The label is ours and
                // may be reworded; the tag is what a grep of the code and a
                // filter on the export both key on.
                'tag' => $row['context_id'] !== '' ? $row['context_id'] : '(none)',
                'description' => $row['description'],
                'share' => number_format($row['share'], 1, '.', ''),
              ],
            ],
          ],
          ['data' => number_format($row['calls'])],
          ['data' => number_format($row['tokens'])],
          ['data' => $this->money($row['spend'])],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Call site'), $this->t('Calls'), $this->t('Tokens'), $this->t('Spend')],
      '#rows' => $rows,
      '#empty' => $this->t('No calls recorded in this period.'),
      '#attributes' => ['class' => ['ain-usage__table', 'ain-usage__table--sites']],
    ];
  }

  /**
   * The per-model table.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function modelTable(int|null $since): array {
    $rows = [];
    foreach ($this->sortByTokens($this->usage->byModel($since)) as $row) {
      // A `free` entry's zero is a fact about local inference, not a gap. The
      // query counts zero-cost calls without knowing that, so the rate table
      // decides here whether the count is worth showing — the same rule
      // UnpricedNotice::recorded() applies to the warning above, so the flag and
      // the warning cannot disagree about which rows are a problem.
      $rate = $this->pricing->rate((string) $row['provider_id'], (string) $row['model_id']);
      $unpriced = $row['unpriced_calls'] > 0 && !($rate !== NULL && ($rate['free'] ?? FALSE));
      $rows[] = [
        'class' => $unpriced ? ['ain-usage__row--unpriced'] : [],
        'data' => [
          [
            // THE FLAG RIDES WITH THE MODEL, NOT WITH THE MONEY, and that is a
            // layout fix as much as a semantic one. It used to hang under the
            // Spend figure, where a sentence of prose in a numeric cell widened
            // that column to 312px against the call-site table's 80px — two
            // stacked tables whose right edges disagreed by a quarter of the
            // page, which is precisely what reads as unfinished. Constraining
            // the column instead just wrapped the sentence into a five-line
            // sliver. It belongs here regardless: the fact it reports is about
            // the model, and the model id is the one thing an operator needs in
            // order to go and add the missing rate.
            'data' => [
              '#type' => 'inline_template',
              '#template' => '<span class="ain-usage__id">{{ model }}</span>{% if flag %}<span class="ain-usage__flag">{{ flag }}</span>{% endif %}',
              '#context' => [
                'model' => $row['provider_id'] . ':' . $row['model_id'],
                // PAST TENSE, and only past tense when a rate exists today: the
                // flag is derived from `cost = 0` on rows already written, so a
                // model priced since those rows landed is not a gap to close,
                // and saying "has no rate" about one sends the operator to an
                // entry that is already there. Same split as
                // UnpricedNotice::recorded(), so the flag and the warning above
                // cannot disagree about which rows are a problem.
                'flag' => $unpriced
                  ? ($rate === NULL
                    ? $this->t('@count of these calls have no rate and are counted as $0.00', ['@count' => $row['unpriced_calls']])
                    : $this->t('@count of these calls were recorded before this model had a rate, and stay at $0.00', ['@count' => $row['unpriced_calls']]))
                  : '',
              ],
            ],
          ],
          ['data' => number_format($row['calls'])],
          ['data' => number_format($row['tokens'])],
          ['data' => $this->money($row['spend'])],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [$this->t('Model'), $this->t('Calls'), $this->t('Tokens'), $this->t('Spend')],
      '#rows' => $rows,
      '#empty' => $this->t('No calls recorded in this period.'),
      '#attributes' => ['class' => ['ain-usage__table']],
    ];
  }

  /**
   * One page of the call log: a row per call, editor included.
   *
   * Accounts are loaded once for the page rather than per row — fifty rows on a
   * shared site is fifty loads of the same handful of users otherwise. The
   * fallback wording is the one the retired per-editor table used: a deleted
   * account still owns its calls, and naming it by uid keeps the row visible
   * instead of dropping a real cost off the page.
   *
   * @param list<array<string, mixed>> $calls
   *   Raw log rows from {@see UsageQuery::page()}.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function logTable(array $calls): array {
    $uids = array_filter(array_map(static fn (array $row): int => (int) $row['uid'], $calls));
    $accounts = $uids === [] ? [] : $this->entityTypeManager()->getStorage('user')->loadMultiple($uids);

    $rows = [];
    foreach ($calls as $row) {
      $account = $accounts[(int) $row['uid']] ?? NULL;
      $rows[] = [
        $this->dateFormatter->format((int) $row['timestamp'], 'short'),
        [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span class="ain-usage__site">{{ label }}</span><span class="ain-usage__tag">{{ tag }}</span>',
            '#context' => [
              'label' => $this->callSites->label($row['context_id'] ?? ''),
              'tag' => (string) ($row['context_id'] ?? '') !== '' ? $row['context_id'] : '(none)',
            ],
          ],
        ],
        ['data' => $row['provider_id'] . ':' . $row['model_id'], 'class' => ['ain-usage__id']],
        (string) $row['operation'],
        $account?->getDisplayName() ?? $this->t('Deleted or anonymous (uid @uid)', ['@uid' => (int) $row['uid']]),
        number_format((int) $row['input_tokens']),
        number_format((int) $row['output_tokens']),
        $this->money((float) $row['cost_usd']),
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('When'),
        $this->t('Call site'),
        $this->t('Model'),
        $this->t('Operation'),
        $this->t('Editor'),
        $this->t('In'),
        $this->t('Out'),
        $this->t('Cost'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No calls recorded in this period.'),
      '#attributes' => ['class' => ['ain-usage__table', 'ain-usage__table--log']],
    ];
  }

  /**
   * The three period links, with the active one marked.
   *
   * @param string $route
   *   The page the links point back at — see {@see self::frame()}.
   * @param string $active
   *   The period currently on screen.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function periodLinks(string $route, string $active): array {
    $items = [];
    foreach (self::PERIODS as $value => $days) {
      $classes = ['ain-usage__period'];
      $attributes = [];
      // PHP folds the numeric keys of PERIODS to ints, so the comparison is
      // made on strings — '7' === 7 is false and would leave nothing marked.
      if ((string) $value === $active) {
        $classes[] = 'is-active';
        // aria-current, not just a class: the selected period is the difference
        // between two very different sets of numbers, and a screen reader has
        // nothing else on the page to tell it which one is on screen.
        $attributes['aria-current'] = 'page';
      }
      $items[] = [
        '#type' => 'link',
        '#title' => $this->periodTitle((string) $value),
        // No `page` in the query: changing the window changes how many pages
        // there are, and carrying page 7 into a period that has two is a pager
        // pointing at nothing.
        '#url' => Url::fromRoute($route, [], ['query' => ['period' => $value]]),
        '#attributes' => ['class' => $classes] + $attributes,
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ain-usage__periods']],
      'links' => $items,
    ];
  }

  /**
   * The requested period, or the default — never an arbitrary query value.
   */
  private function period(Request $request): string {
    $period = (string) $request->query->get('period', self::DEFAULT_PERIOD);
    // array_key_exists, NOT isset: 'all' maps to NULL (no lower bound) and
    // isset() reports a NULL value as absent, which silently narrowed "All time"
    // back to the default window while the link still read as selected.
    return array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
  }

  /**
   * The timestamp a period starts at, or NULL for the whole log.
   */
  private function since(string $period): ?int {
    $days = self::PERIODS[$period];
    return $days === NULL ? NULL : $this->time->getRequestTime() - ($days * 86400);
  }

  /**
   * The heading over a section, naming the period so a figure can be quoted.
   */
  private function periodTitle(string $period): string|\Stringable {
    $days = self::PERIODS[$period];
    return $days === NULL
      ? $this->t('All time')
      : $this->t('Last @days days', ['@days' => $days]);
  }

  /**
   * A section heading.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function heading(string|\Stringable $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#attributes' => ['class' => ['ain-usage__heading']],
      '#value' => (string) $text,
    ];
  }

  /**
   * One figure in the totals row.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function stat(string|\Stringable $label, string $value): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="ain-usage__stat-label">{{ label }}</span><span class="ain-usage__stat-value">{{ value }}</span>',
      '#context' => ['label' => $label, 'value' => $value],
    ];
  }

  /**
   * A dollar figure, at a precision that does not round the answer away.
   *
   * Four decimals below a dollar. A thread-naming call costs $0.0005, and
   * two-decimal money would print every such row — and the columns they add up
   * to — as "$0.00", which is the exact appearance this page has to reserve for a
   * real zero.
   */
  private function money(float $value): string {
    if ($value === 0.0) {
      return '$0.00';
    }
    if ($value < 0.0001) {
      // A real fraction of a cent, printed as "$0.0000", is the one thing this
      // page must never show: it is pixel-identical to the recorded zero that
      // means "unpriced", and the two are opposite facts. Say it is small
      // instead of rounding it into the failure state.
      return '<$0.0001';
    }
    return '$' . number_format($value, $value < 1 ? 4 : 2);
  }

  /**
   * Sorts aggregate rows largest-first by tokens.
   *
   * By tokens, not by spend, for the reason the bars are: an unpriced model sums
   * to $0.00 and would sort to the bottom of the page while being the biggest
   * thing on it.
   *
   * @param list<array<string, mixed>> $rows
   *   Aggregate rows carrying a `tokens` key.
   *
   * @return list<array<string, mixed>>
   *   The same rows, largest first.
   */
  private function sortByTokens(array $rows): array {
    usort($rows, static fn (array $a, array $b): int => (int) $b['tokens'] <=> (int) $a['tokens']);
    return $rows;
  }

  /**
   * An export URL carrying the period the operator is looking at.
   */
  private function exportUrl(string $route, string $period): string {
    return Url::fromRoute($route, [], ['query' => ['period' => $period]])->toString();
  }

  /**
   * A filename that says what is in the file without opening it.
   */
  private function exportFilename(string $period, string $extension): string {
    return sprintf(
      'atelier-ai-usage-%s-%s.%s',
      $period === 'all' ? 'all' : $period . 'd',
      $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y-m-d'),
      $extension,
    );
  }

}
