<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the Studio Backend override of the AI Metering dashboard table.
 *
 * The per-user "Alert threshold" control is non-functional in our build and
 * the "Details" column only linked into the retired ai_usage_log view, so the
 * theme ships an override of ai-metering-dashboard.html.twig that removes both
 * columns while keeping the Monthly Budget control (the one that actually
 * enforces spend). This test makes sure we never regress into shipping the
 * broken columns — it asserts the override is what renders and that its shape
 * holds.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
class AiMeteringDashboardColumnsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  // aincient_chat pulls in aincient_core + provides the `fonts` library the
  // Studio Backend theme depends on; block carries the theme's default blocks.
  protected static $modules = ['ai_metering', 'ai', 'block', 'aincient_chat'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * aincient_chat (pulled in for its `fonts` library) ships install config that
   * predates its own schema — an unrelated, pre-existing drift. Relax strict
   * schema so it doesn't mask what this test actually guards (the dashboard
   * columns). Not a licence to ship bad schema; tracked separately.
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Dashboard route path.
   */
  private const DASHBOARD_PATH = '/admin/reports/ai-metering';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Our override lives in the Studio Backend theme, which is the admin theme
    // in production. Install it and make it the admin theme so the dashboard
    // (an admin route) renders through the override rather than the contrib
    // template.
    \Drupal::service('theme_installer')->install(['aincient_studio_backend']);
    $this->config('system.theme')->set('admin', 'aincient_studio_backend')->save();
  }

  /**
   * The dashboard shows Monthly Budget and hides Alert threshold + Details.
   */
  public function testBrokenColumnsAreRemoved(): void {
    // 'view the administration theme' is required or the admin negotiator
    // falls back to the default theme (and the contrib template) — which would
    // hide the very override we mean to assert.
    $account = $this->drupalCreateUser([
      'view ai metering dashboard',
      'view the administration theme',
    ]);
    $this->drupalLogin($account);

    // The breakdown table only renders when there is usage, so log one call.
    $this->container->get('ai_metering.quota_manager')->logUsage(
      uid: (int) $account->id(),
      providerId: 'anthropic',
      modelId: 'claude-haiku-4-5-20251001',
      operationType: 'chat',
      inputTokens: 18,
      outputTokens: 10,
      cachedTokens: 0,
      costUsd: 0.0000070,
    );

    $this->drupalGet(self::DASHBOARD_PATH);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    // The kept control is present…
    $assert->pageTextContains('Monthly budget');
    // …and the breakdown table actually rendered (guards against asserting
    // "absent" merely because the whole table is missing).
    $assert->pageTextContains('Editor breakdown');
    $assert->elementExists('css', 'table#ai-usage-table');

    // …while the broken / retired columns are gone from the markup.
    $assert->pageTextNotContains('Alert threshold');
    $assert->pageTextNotContains('Details');
    $assert->pageTextNotContains('View calls');

    // The table header carries exactly the eight columns we ship.
    $headers = $this->getSession()->getPage()->findAll('css', 'table#ai-usage-table thead th');
    $this->assertCount(8, $headers, 'The editor-breakdown table renders eight columns.');

    // The kept control must actually be wired: the Save button posts via HTMX,
    // so both the button and the HTMX library have to be on the page. Contrib
    // ships the button but forgets the library (aincient_core repairs that in
    // hook_library_info_alter) — guard against regressing back to an inert
    // Save.
    $assert->elementExists('css', '.ai-quota-save[hx-post]');
    $assert->elementExists('css', 'script[src*="htmx"]');
  }

}
