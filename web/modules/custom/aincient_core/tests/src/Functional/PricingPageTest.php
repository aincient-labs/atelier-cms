<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Functional;

use Drupal\aincient_core\ModelRoles;
use Drupal\aincient_core\StudioSections;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the pricing surface Atelier took over from contrib.
 *
 * Four things are worth a browser test here, and all four are things an
 * operator either sees or is stopped by:
 *
 * - the table has one row per model ROLE, in the order the onboarding screen
 *   shows them, with the model that role is bound to and that model's rates.
 *   That comparability IS the page; a catalogue of priced models would not be;
 * - nothing on it is editable, because an operator override is precisely the
 *   precedence trap that under-reported a model 4x in contrib;
 * - a bound model with no rate is called out in its own row, with the
 *   consequence, because nothing else distinguishes a $0.00 call from a free
 *   one;
 * - an unbound role reads as unconfigured — the normal state of a keyless
 *   install, and not a fault.
 *
 * A fifth used to be here: that contrib's settings form and its pricing sync
 * were DENIED rather than merely unlinked. They are now neither. `ai_metering`
 * is uninstalled and the route subscriber that closed its two paths has been
 * deleted with it, so there is no route left to keep shut and that test would
 * have pinned a 404 while claiming to pin a 403 — a green assertion about a
 * guard that is gone. Removed rather than reworded: nothing under
 * `/admin/config/ai/ai-metering` is this module's business any more.
 *
 * @group aincient_core
 */
#[RunTestsInSeparateProcesses]
final class PricingPageTest extends BrowserTestBase {

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
  private const PATH = '/admin/config/aincient/pricing';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * One row per role, in wizard order, with its model and that model's rates.
   */
  public function testTheTableIsOneRowPerRoleInWizardOrder(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles', [
        ModelRoles::REASONING => ['provider_id' => 'anthropic', 'model_id' => 'claude-opus-5'],
        ModelRoles::TASK => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
        ModelRoles::FAST => ['provider_id' => 'anthropic', 'model_id' => 'claude-haiku-4-5-20251001'],
        ModelRoles::VISION => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
        ModelRoles::IMAGE => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image'],
      ])
      ->save();

    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);

    // The five rows, in the order ModelRoles::pickerDefinitions() declares —
    // the same order and labels the "Set the pace" screen shows.
    $labels = [];
    foreach ($this->xpath('//table//tbody//tr/td[1]//span[@class="ain-pricing__role"]') as $cell) {
      $labels[] = trim($cell->getText());
    }
    $this->assertSame(
      array_column(ModelRoles::pickerDefinitions(), 'label'),
      $labels,
    );

    // Each role's bound model sits beside it, and its rates beside that.
    $this->assertSession()->pageTextContains('anthropic:claude-opus-5');
    $this->assertSession()->pageTextContains('nanobanana:gemini-2.5-flash-image');
    // Per million tokens, the unit the vendor publishes in.
    $this->assertSession()->pageTextContains('$5');
    $this->assertSession()->pageTextContains('$25');
    $this->assertSession()->pageTextContains('US dollars per million tokens.');
    // The wizard's own sentence for the role, so the two screens read alike.
    $this->assertSession()->pageTextContains('The everyday tier');
    // Provenance, so a number can be checked against its source.
    $this->assertSession()->pageTextContains('2026-08-02');
    // A rate the vendor does not publish for this model is absent, not zero:
    // Google quotes no cache rate for the image model.
    $this->assertSession()->pageTextContains('n/a');
  }

  /**
   * A model no role binds is not on the page — this is a bill, not a catalogue.
   */
  public function testAnUnboundModelIsNotListed(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles', [
        ModelRoles::TASK => ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'],
      ])
      ->save();

    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('claude-sonnet-5');
    $this->assertSession()->pageTextNotContains('claude-opus-5');
  }

  /**
   * The page states the rates, offers no way to change them, and says where to.
   *
   * Not cosmetic: an operator-editable rate would outrank the shipped value
   * forever and go stale silently — contrib's manual-beats-synced precedence is
   * how a Haiku 4.5 binding stayed pinned at Haiku 3.5's price.
   *
   * ASSERTS THE DESTINATION, NOT A PHRASE. This used to require the literal
   * words "not editable here", and it broke when the intro was shortened even
   * though the page still behaved correctly — a test that fails on a reworded
   * sentence trains people to edit the test rather than read it. What has to
   * hold is that a read-only page does not leave the operator stuck: no control
   * that could imply an edit, and the name of the config object that IS the way
   * to make one. That name is machine text and will outlive any wording round
   * it.
   */
  public function testNothingOnThePageIsEditable(): void {
    $this->drupalGet(self::PATH);

    $this->assertSession()->elementNotExists('css', 'form input[type="number"]');
    $this->assertSession()->buttonNotExists('Save configuration');
    $this->assertSession()->pageTextContains('aincient_core.pricing');
  }

  /**
   * A bound model with no rate is flagged in its row, with the consequence.
   */
  public function testABoundUnpricedModelIsFlaggedInItsRow(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles.task', ['provider_id' => 'openai', 'model_id' => 'gpt-4o'])
      ->save();

    $this->drupalGet(self::PATH);
    // In the row: the consequence, not merely the condition.
    $this->assertSession()->pageTextContains('recorded at $0.00 and this site’s spend is understated');
    $this->assertSession()->elementExists('css', 'tr.ain-pricing__row--unpriced');
    // And in the shared notice above the table — the same sentence the models
    // form shows, so meeting it on both pages reads as one problem.
    $this->assertSession()->pageTextContains('task → openai:gpt-4o');
    $this->assertSession()->pageTextContains('understate what this site spends');
  }

  /**
   * A rate past its published end date is flagged on the row that shows it.
   *
   * The worse half of the pricing problem, and the quieter one. A MISSING rate is
   * reported in four places; a rate that has stopped being correct is reported
   * nowhere and keeps producing confident figures that are too low. Nothing about
   * the site changes on the day it lapses — only the date does — so the flag has
   * to appear beside the number without anyone touching the configuration.
   *
   * @see \Drupal\aincient_core\Usage\RateFreshness
   */
  public function testALapsedRateIsFlaggedOnItsRow(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles.reasoning', ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'])
      ->save();

    // Retro-date the shipped entry's expiry rather than the clock: the point of
    // the feature is that config alone decides this, and driving it from config
    // is what proves the render path reads the classifier at all.
    $pricing = $this->config('aincient_core.pricing');
    $models = $pricing->get('models');
    $models[0]['expires'] = '2020-01-01';
    $pricing->set('models', $models)->save();

    $this->drupalGet(self::PATH);

    // The consequence, in the row, next to the rate it invalidates.
    $this->assertSession()->pageTextContains('published end date has passed');
    $this->assertSession()->elementExists('css', 'tr.ain-pricing__row--lapsed');
    // And still priced — a lapsed rate is wrong, not absent, so the row must not
    // also claim the model is unpriced.
    $this->assertSession()->pageTextNotContains('No rate —');
  }

  /**
   * A rate inside its dates carries no freshness flag at all.
   *
   * Absent rather than merely quiet: a flag on every row is a flag nobody reads,
   * which would cost us the one above.
   */
  public function testACurrentRateCarriesNoFreshnessFlag(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles.reasoning', ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'])
      ->save();

    // Dated from the clock, NOT left as whatever the release shipped. The shipped
    // `checked` date is six months from going stale BY DESIGN, so asserting "no
    // flag" against it would plant a test that starts failing on a date nobody
    // chose — turning a real product warning into a broken gate. The freshness
    // rules themselves are pinned to a fixed clock in RateFreshnessTest; this
    // test only asks whether the render path stays quiet when there is nothing
    // to say.
    $pricing = $this->config('aincient_core.pricing');
    $models = $pricing->get('models');
    $models[0]['checked'] = date('Y-m-d');
    $models[0]['expires'] = date('Y-m-d', strtotime('+1 year'));
    $pricing->set('models', $models)->save();

    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextNotContains('published end date has passed');
    $this->assertSession()->pageTextNotContains('Not checked against the source');
    $this->assertSession()->elementNotExists('css', 'tr.ain-pricing__row--lapsed');
    $this->assertSession()->elementNotExists('css', 'tr.ain-pricing__row--stale');
  }

  /**
   * With every bound model priced, the warning is absent — not merely empty.
   *
   * A warning that renders unconditionally is a warning an operator learns to
   * ignore, which would cost us the one above.
   */
  public function testAFullyPricedSiteShowsNoWarning(): void {
    $this->config('aincient_core.model_roles')
      ->set('roles.task', ['provider_id' => 'anthropic', 'model_id' => 'claude-sonnet-5'])
      ->save();

    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextNotContains('understate what this site spends');
    $this->assertSession()->elementNotExists('css', 'tr.ain-pricing__row--unpriced');
  }

  /**
   * A role bound to nothing reads as unconfigured, not as a failure.
   *
   * The state of every keyless install, and of both image roles on a site that
   * never connected an image provider. It must not be dressed as a gap.
   */
  public function testAnUnboundRoleReadsAsUnconfigured(): void {
    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextContains('Not configured');
    $this->assertSession()->pageTextNotContains('understate what this site spends');
  }

  /**
   * The rate sheet and the models form link to each other.
   *
   * Same five roles, same bindings, one editable and one not — an operator who
   * reads a price and wants to change the model should not have to go hunting.
   */
  public function testTheRateSheetAndTheModelsFormAreLinked(): void {
    $this->drupalGet(self::PATH);
    $this->assertSession()->linkByHrefExists('/admin/config/aincient/models');

    $this->drupalGet('/admin/config/aincient/models');
    $this->assertSession()->linkByHrefExists(self::PATH);
  }

  /**
   * The studio's rate-sheet room lands on ours.
   *
   * The metering room is asserted by {@see UsagePageTest}, which owns the
   * dashboard that replaced contrib's.
   */
  public function testTheStudioSectionPointsAtOurPage(): void {
    $sections = StudioSections::sections();
    $this->assertSame('aincient_core.pricing', $sections['metering_settings']['route']);
  }

}
