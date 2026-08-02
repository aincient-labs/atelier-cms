<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Functional;

use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\aincient_core\StudioSections;
use Drupal\aincient_core\Usage\UsageQuery;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the usage dashboard Atelier took over from contrib.
 *
 * What is worth a browser test here is what an operator either reads and acts
 * on, or is stopped by:
 *
 * - the CALL SITE breakdown is on the page, with real rows behind it. It is the
 *   reason the page is ours: `context_id` says which part of Atelier spent the
 *   money, no contrib UI shows it, and a per-editor report on a one-operator
 *   appliance answers nothing;
 * - a tag nobody labelled is still shown. The rows most worth seeing are the
 *   ones nobody predicted;
 * - tokens recorded against a $0.00 cost produce the under-report warning, and a
 *   fully-priced period produces none. This is the whole reason Atelier took
 *   pricing over — a $0.0475 turn recorded as $0.00 — and a warning that fires
 *   unconditionally is one people learn to ignore;
 * - the period selector actually re-scopes the numbers. A filter that renders
 *   but does not filter is a wrong total presented as a chosen one;
 * - the dashboard is the ONE SCREEN it was cut back to: no per-editor table and
 *   no recent-calls tail. Both were dropped on purpose — a breakdown of one
 *   operator is not a breakdown, and fifty rows of tail pushed the totals off
 *   the screen — so their absence is asserted, not merely unmentioned. A section
 *   that quietly came back would put the headline below the fold again;
 * - the call log that replaced the tail is a page of its own, gated on the same
 *   permission, naming the editor per call and PAGING. Fifty rows is the window,
 *   and a pager that renders without moving the window shows page two of page
 *   one;
 * - our exports carry `context_id`. Contrib's omitted it, which is what made
 *   them useless for the only question worth asking of this data;
 * - the page reaches no third-party host. Atelier ships as an offline appliance.
 *
 * WHAT IS NO LONGER ASSERTED HERE. Contrib's dashboard, its by-role twin, their
 * four exports and the hub used to be pinned as 403 — denied rather than merely
 * unlinked. `ai_metering` is uninstalled and the route subscriber that denied
 * them is deleted, so those paths resolve to nothing at all and a test asserting
 * 403 would have been asserting a guard that no longer exists. The same goes for
 * the `ai_usage_log` view: it was deleted with the module and its replacement,
 * {@see \Drupal\aincient_core\Controller\UsageController::log()}, is covered
 * below.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class UsagePageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aincient_core'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Our page's path.
   */
  private const PATH = '/admin/reports/aincient/usage';

  /**
   * The full call log, which the dashboard's deleted tail became.
   */
  private const LOG_PATH = '/admin/reports/aincient/usage/log';

  /**
   * The account whose rows the seeds belong to.
   */
  private UserInterface $editor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->editor = $this->drupalCreateUser(['view aincient usage']);
    $this->drupalLogin($this->editor);
  }

  /**
   * The call-site breakdown is the headline, and it survives an unknown tag.
   */
  public function testTheCallSiteBreakdownIsTheHeadline(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 9000, 1000, 0.0475);
    $this->seed('aincient_chat_thread_namer', 'claude-haiku-4-5-20251001', 100, 20, 0.00005);
    $this->seed('a_call_site_from_the_future', 'claude-haiku-4-5-20251001', 50, 10, 0.00002);
    $this->seed(NULL, 'claude-haiku-4-5-20251001', 30, 5, 0.00001);

    $this->drupalGet(self::PATH);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    $assert->pageTextContains('By call site');
    // The human labels.
    $assert->pageTextContains('Agent turn');
    $assert->pageTextContains('Thread naming');
    // …beside the raw tags, which are what the code and the export key on.
    $assert->pageTextContains(UsageRecorder::TAG_AGENT_TURN);
    $assert->pageTextContains('aincient_chat_thread_namer');
    // A tag this release does not write is shown verbatim and marked, never
    // dropped — dropping it would understate the spend on this very page.
    $assert->pageTextContains('a_call_site_from_the_future');
    $assert->elementExists('css', 'tr.ain-usage__row--unknown');
    // And a row with no tag at all is reported as untagged, not omitted.
    $assert->pageTextContains('Untagged');

    // The one breakdown behind it, and nothing else.
    $assert->pageTextContains('By model');
    $assert->pageTextContains('anthropic:claude-sonnet-5');
  }

  /**
   * The dashboard stops at two breakdowns — no editors, no tail.
   *
   * ABSENCE IS THE ASSERTION. Both sections rendered here until this release and
   * both were cut for a stated reason: "By editor" is one row on the
   * single-operator appliance every Atelier install is, and a breakdown of one
   * thing is not a breakdown; "Recent calls" was fifty rows of tail that pushed
   * the three totals — the numbers the page exists for — off the first screen.
   * Neither fact was lost (uid is still in both exports, and the tail became a
   * whole page), so the only way to protect the decision is to fail if either
   * heading reappears.
   */
  public function testTheDashboardDoesNotRenderEditorsOrARecentCallsTail(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 9000, 1000, 0.0475);

    $this->drupalGet(self::PATH);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    $assert->pageTextNotContains('By editor');
    $assert->pageTextNotContains('Recent calls');
    // The editor's name would only be on this page via one of those two
    // sections, so its absence is the same claim made from the data side.
    $assert->pageTextNotContains($this->editor->getAccountName());
    // Exactly the two headings that survived, in order.
    $headings = array_map(
      static fn ($element): string => trim($element->getText()),
      $this->getSession()->getPage()->findAll('css', 'h2.ain-usage__heading'),
    );
    $this->assertSame(['By call site', 'By model'], $headings);
  }

  /**
   * Tokens recorded at $0.00 say so, and say what it costs the reader.
   *
   * The single most important behaviour on the page. The row below burned 17,162
   * tokens and recorded nothing; without the warning the totals do not look
   * broken, they look cheap.
   */
  public function testTokensWithNoCostRaiseTheUnderReportWarning(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 15000, 2162, 0.0);

    $this->drupalGet(self::PATH);
    $assert = $this->assertSession();

    // The consequence, not merely the condition.
    $assert->pageTextContains('The spend below is an under-report');
    $assert->pageTextContains('17,162 tokens');
    // Named, because the binding is the one fact needed to add the rate.
    $assert->pageTextContains('anthropic:claude-sonnet-5');
    // And flagged in the per-model row that produced it.
    $assert->elementExists('css', 'tr.ain-usage__row--unpriced');
  }

  /**
   * A model with no rate TODAY is the actionable case: add a rate.
   *
   * Its future calls will keep recording $0.00, so this is the only group the
   * page may tell an operator to go and fix.
   */
  public function testAModelWithNoRateIsReportedAsActionable(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'gpt-4o', 5000, 500, 0.0, provider: 'openai');

    $this->drupalGet(self::PATH);
    $assert = $this->assertSession();

    $assert->pageTextContains('The spend below is an under-report');
    $assert->pageTextContains('still has no rate');
    $assert->pageTextContains('openai:gpt-4o');
    $assert->pageTextNotContains('Nothing to fix');
  }

  /**
   * A model priced SINCE those rows landed has nothing left to fix, and says so.
   *
   * The derivation is `cost = 0 AND tokens > 0`, which cannot tell "unpriced now"
   * from "was unpriced then" — so the rate table is asked. Sending an operator to
   * add a rate that is already in the config object is how a correct page gets
   * read as a broken one. `claude-sonnet-5` is the real case: it was unpriced for
   * as long as it took to notice, and those rows are still in the table.
   */
  public function testAModelPricedSinceIsReportedAsHistorical(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 15000, 2162, 0.0);

    $this->drupalGet(self::PATH);
    $assert = $this->assertSession();

    // Still an under-report — the money is still missing from the totals.
    $assert->pageTextContains('The spend below is an under-report');
    $assert->pageTextContains('Nothing to fix');
    // And emphatically NOT an instruction to add a rate that already exists.
    $assert->pageTextNotContains('still has no rate');
    // The per-model flag makes the same distinction, in the past tense.
    $assert->pageTextContains('were recorded before this model had a rate');
  }

  /**
   * A period where everything was priced shows no warning at all.
   *
   * Absent, not merely empty: a warning that renders unconditionally is one an
   * operator stops reading, which would cost us the one above.
   */
  public function testAFullyPricedPeriodShowsNoWarning(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 9000, 1000, 0.0475);

    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextNotContains('under-report');
    $this->assertSession()->elementNotExists('css', 'tr.ain-usage__row--unpriced');
  }

  /**
   * A model the rate table marks `free` is a real zero, and stays quiet.
   *
   * A local Ollama genuinely costs nothing. Warning about it would make the
   * warning meaningless on every site that runs one.
   */
  public function testAFreeModelsZeroIsNotAnUnderReport(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'llama3', 4000, 500, 0.0, provider: 'ollama');

    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextNotContains('under-report');
    // Nor is the per-model row flagged: `free: true` in the rate table is what
    // turns this zero from an absence into a statement.
    $this->assertSession()->elementNotExists('css', 'tr.ain-usage__row--unpriced');
  }

  /**
   * The period selector re-scopes the numbers rather than merely rendering.
   */
  public function testThePeriodFilterChangesTheTotals(): void {
    // Inside every window.
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 1000, 100, 0.0055, age: 86400);
    // Older than 30 days, so only "All time" may count it.
    $this->seed(UsageRecorder::TAG_SIMPLE_CHAT, 'claude-sonnet-5', 7000, 700, 0.0385, age: 90 * 86400);

    // Default window: the recent row only.
    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('1,100');
    $this->assertSession()->pageTextNotContains('Brand and flow specialists');

    // Seven days: same, and the heading says which window is on screen. The
    // HEADING, not the page text — every period name is always on the page as a
    // link, so asserting the text would pass no matter which one is selected.
    $this->drupalGet(self::PATH, ['query' => ['period' => '7']]);
    $this->assertActivePeriod('Last 7 days');
    $this->assertSession()->pageTextNotContains('Brand and flow specialists');

    // All time: both rows, so the token total is the sum.
    $this->drupalGet(self::PATH, ['query' => ['period' => 'all']]);
    $this->assertActivePeriod('All time');
    $this->assertSession()->pageTextContains('Brand and flow specialists');
    $this->assertSession()->pageTextContains('8,800');

    // A period nobody offers falls back to the default rather than to no filter
    // — an unrecognised value must not silently widen the window.
    $this->drupalGet(self::PATH, ['query' => ['period' => 'forever']]);
    $this->assertActivePeriod('Last 30 days');
    $this->assertSession()->pageTextNotContains('Brand and flow specialists');
  }

  /**
   * The exports carry `context_id` — the column contrib's exports omit.
   */
  public function testTheExportsCarryTheCallSite(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 9000, 1000, 0.0);

    $this->drupalGet(self::PATH . '/export/csv');
    $csv = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString('context_id', $csv);
    $this->assertStringContainsString('call_site', $csv);
    $this->assertStringContainsString(UsageRecorder::TAG_AGENT_TURN, $csv);
    $this->assertStringContainsString('Agent turn', $csv);
    // The derived flag, so a $0.00 row is findable with a filter rather than by
    // eye down a column of numbers.
    $this->assertStringContainsString('unpriced', $csv);
    // The cost column under the name the table gives it. It was
    // `estimated_cost_usd` while the store was contrib's, and "estimated" was
    // never true of it — the figure is what Atelier's own rate sheet charged.
    $this->assertStringContainsString('cost_usd', $csv);
    $this->assertStringNotContainsString('estimated_cost_usd', $csv);
    // Two columns that left with the contrib table: one held the string 'cloud'
    // in every row ever written, the other the string 'completed'. A column with
    // one value is a column a reader has to be told to ignore.
    $this->assertStringNotContainsString('provider_type', $csv);
    $this->assertStringNotContainsString('status', $csv);
    // `uid` stays, and now carries the whole per-editor story: the dashboard's
    // editor table is gone, so this column IS how a shared site gets it back.
    $this->assertStringContainsString('uid', $csv);

    $this->drupalGet(self::PATH . '/export/json');
    $json = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertIsArray($json);
    $this->assertSame(UsageRecorder::TAG_AGENT_TURN, $json['calls'][0]['context_id']);
    $this->assertSame('Agent turn', $json['calls'][0]['call_site']);
    $this->assertTrue($json['calls'][0]['unpriced']);
    // Both containers carry the same columns, or a spreadsheet and a script
    // reading the same period disagree about what this site spent.
    $this->assertArrayHasKey('cost_usd', $json['calls'][0]);
    $this->assertArrayHasKey('uid', $json['calls'][0]);
    $this->assertArrayNotHasKey('estimated_cost_usd', $json['calls'][0]);
    $this->assertArrayNotHasKey('provider_type', $json['calls'][0]);
    $this->assertArrayNotHasKey('status', $json['calls'][0]);

    // Same permission as the page: a role that can read the numbers on screen
    // gains nothing by downloading them.
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet(self::PATH . '/export/csv');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The page is gated on OUR permission, and on nothing else.
   *
   * The permission is `view aincient usage` rather than the contrib one this
   * report replaced, and the reason has outlived the module: access control that
   * borrowed another project's permission would have silently opened or closed
   * this page the day that module was uninstalled — which is the day that has
   * now happened. An authenticated account holding nothing is the negative case,
   * because it is the state every non-operator role on an Atelier site is in.
   */
  public function testThePageIsGatedOnOurOwnPermission(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view aincient usage']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * The dashboard's deleted tail is a page of its own, and it is reachable.
   *
   * The link is followed rather than merely asserted: a footer sentence pointing
   * at a route nobody can resolve is exactly the dead end contrib's hub was, and
   * the whole reason the tail was allowed to leave the dashboard is that there
   * is somewhere for it to go.
   */
  public function testTheCallLogIsReachableFromTheDashboardAndNamesTheEditor(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 9000, 1000, 0.0475);

    $this->drupalGet(self::PATH);
    $this->clickLink('the full call log');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $assert->addressMatches('#' . preg_quote(self::LOG_PATH, '#') . '#');

    // The per-call facts the dashboard no longer summarises: the editor, whose
    // table was dropped, and the call site, which is still the axis.
    $assert->pageTextContains('Editor');
    $assert->pageTextContains($this->editor->getAccountName());
    $assert->pageTextContains('Agent turn');
    $assert->pageTextContains(UsageRecorder::TAG_AGENT_TURN);
    $assert->pageTextContains('anthropic:claude-sonnet-5');
  }

  /**
   * The call log is behind the same permission as the numbers it explains.
   *
   * Not a stricter one and not a looser one: it is the same facts at a finer
   * grain, so a role that can read the totals gains nothing by reading the rows,
   * and a role that cannot must not reach them by typing the path.
   */
  public function testTheCallLogIsGatedOnTheSamePermission(): void {
    $this->drupalLogin($this->drupalCreateUser([]));
    $this->drupalGet(self::LOG_PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view aincient usage']));
    $this->drupalGet(self::LOG_PATH);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * The log pages at fifty, and page two is the NEXT fifty.
   *
   * A pager that renders but does not move the window is the same class of bug
   * as a period filter that does not filter: the operator is shown page one
   * twice and concludes the log ends there. Fifty-one rows is the smallest
   * fixture that can tell the difference — the tokens are made unique per row so
   * a row can be identified by sight on the page it belongs to.
   */
  public function testTheCallLogPagesAtFifty(): void {
    for ($i = 0; $i < 51; $i++) {
      // Descending age, so row 0 is the newest and lands first on page one; the
      // oldest (and only) row on page two is the 3,001-token one.
      $this->seed(
        UsageRecorder::TAG_AGENT_TURN,
        'claude-sonnet-5',
        1000 + ($i * 40),
        10,
        0.001,
        age: $i * 60,
      );
    }

    $this->drupalGet(self::LOG_PATH);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    $this->assertCount(50, $this->getSession()->getPage()->findAll('css', 'table.ain-usage__table--log tbody tr'));
    // Newest first: the 1,000-token row is on page one, the 3,000-token one is
    // the 51st and therefore is not.
    $assert->pageTextContains('1,000');
    $assert->pageTextNotContains('3,000');

    $this->drupalGet(self::LOG_PATH, ['query' => ['page' => 1]]);
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'table.ain-usage__table--log tbody tr'));
    $assert->pageTextContains('3,000');
    $assert->pageTextNotContains('1,000');
  }

  /**
   * Nothing on the page is fetched from another host.
   *
   * Contrib's dashboard pulls Chart.js from jsdelivr and htmx from unpkg. On an
   * appliance with no route to the internet those are blank rectangles and an
   * inert button; on one with a route they are a third party watching an
   * administrator work.
   */
  public function testThePageLoadsNothingFromACdn(): void {
    $this->seed(UsageRecorder::TAG_AGENT_TURN, 'claude-sonnet-5', 100, 10, 0.001);
    $this->drupalGet(self::PATH);

    $ourHost = parse_url($this->baseUrl, PHP_URL_HOST);
    foreach ($this->getSession()->getPage()->findAll('css', 'script[src], link[rel="stylesheet"]') as $element) {
      $url = (string) ($element->getAttribute('src') ?? $element->getAttribute('href'));
      // A relative URL has no host and is by definition ours. Anything with one
      // must be this site — including a protocol-relative '//cdn/…', which
      // parse_url reads as a host exactly as a browser would.
      $host = parse_url($url, PHP_URL_HOST);
      $this->assertTrue(
        $host === NULL || $host === FALSE || $host === $ourHost,
        'Asset ' . $url . ' is loaded from another host.',
      );
    }
  }

  /**
   * The studio's metering room lands on ours.
   */
  public function testTheStudioSectionPointsAtOurPage(): void {
    $this->assertSame('aincient_core.usage', StudioSections::sections()['metering']['route']);
  }

  /**
   * Asserts which window the figures on screen belong to.
   *
   * Read off the SELECTED period link, not off a heading: the page's headings
   * name its sections ("By call site", "By model") and the period is stated only
   * by which of the three links carries `is-active`. Asserting the page text
   * would pass whatever is selected — all three names are always on the page.
   */
  private function assertActivePeriod(string $expected): void {
    $active = $this->getSession()->getPage()->find('css', '.ain-usage__period.is-active');
    $this->assertNotNull($active, 'The page marks which period it is reporting.');
    $this->assertSame($expected, trim($active->getText()));
    // aria-current too: the selected window is the difference between two very
    // different sets of numbers and a screen reader has nothing else to go on.
    $this->assertSame('page', $active->getAttribute('aria-current'));
  }

  /**
   * Writes one usage row, with control over the two things that matter.
   *
   * Inserted straight into the table rather than through
   * {@see \Drupal\aincient_core\Usage\UsageLog::record()}: that writer stamps the
   * row with the request time, and this test's whole subject is rows with a
   * chosen age and a chosen (sometimes zero) cost. The columns are the ones
   * `aincient_core_schema()` declares — `provider_type` and `status` went with
   * the contrib table, and `estimated_cost_usd` is `cost_usd` now.
   */
  private function seed(
    ?string $tag,
    string $model,
    int $input,
    int $output,
    float $cost,
    string $provider = 'anthropic',
    int $age = 0,
  ): void {
    \Drupal::database()->insert(UsageQuery::TABLE)->fields([
      'uid' => (int) $this->editor->id(),
      'timestamp' => \Drupal::time()->getRequestTime() - $age,
      'provider_id' => $provider,
      'operation' => UsageRecorder::OPERATION_CHAT,
      'model_id' => $model,
      'input_tokens' => $input,
      'output_tokens' => $output,
      'cached_tokens' => 0,
      'cost_usd' => $cost,
      'context_id' => $tag,
      'token_details' => json_encode(['usage_reported' => TRUE]),
    ])->execute();
  }

}
