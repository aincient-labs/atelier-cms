<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Functional;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the pricing surface Atelier took over from contrib.
 *
 * This class used to assert the OPPOSITE of what it now does. Its central claim
 * was that nothing on the page is editable, because an operator override was the
 * precedence trap that under-reported a model 4x in contrib. That claim was
 * retired deliberately (DECISIONS 0304): it rested on being able to know every
 * price, and a model reached through a proxy carries an alias out of the
 * operator's own config, which we cannot map to a vendor and therefore cannot
 * price. A rate we cannot know is not a rate we can ship.
 *
 * What replaces it is not "overrides are fine now". It is that an override must
 * never win SILENTLY — which is what the trap actually was. So the assertions
 * below are about visibility:
 *
 * - a model with no rate says what that costs, in the row, in money terms;
 * - a model two roles share appears ONCE, because two editable copies of one
 *   value is a data-loss bug waiting for whoever saves last;
 * - a saved rate is attributed and dated, so it can be audited later;
 * - clearing a rate removes the override rather than writing a zero, since a
 *   zero is a claim ("free") and an absence is a question ("unpriced").
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
   * Bind roles to models, the way the models form would.
   *
   * @param array<string, array{provider_id: string, model_id: string}> $roles
   *   Role id => binding.
   */
  private function bind(array $roles): void {
    $this->config('aincient_core.model_roles')->set('roles', $roles)->save();
  }

  /**
   * The page loads for an operator, which the 500 this replaced did not.
   *
   * Worth its own assertion, because the first version of this form built
   * perfectly through `formBuilder()->getForm()` and 500'd on every real
   * request: Drupal's FormController resolves controller arguments BY NAME, so
   * `buildForm(array $form, FormStateInterface $formState)` cannot be given its
   * second argument and throws before a line of this class runs. Positional
   * calls never see it. Only a browser hitting the route does.
   */
  public function testThePageLoads(): void {
    $this->bind(['reasoning' => ['provider_id' => 'anthropic', 'model_id' => 'claude-opus-5']]);

    $this->drupalGet(self::PATH);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('anthropic:claude-opus-5');
  }

  /**
   * A model two roles share is ONE row, listing both.
   *
   * The reason this form is keyed by model rather than by role. Rendered twice,
   * the same underlying rate becomes two independently editable fields and the
   * later save silently discards the earlier one — a data-loss bug that would
   * look exactly like the operator changing their mind.
   */
  public function testAModelSharedByTwoRolesAppearsOnce(): void {
    $this->bind([
      'reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'openai/gpt-4.1'],
      'task' => ['provider_id' => 'openai_compatible', 'model_id' => 'openai/gpt-4.1'],
    ]);

    $this->drupalGet(self::PATH);

    $page = $this->getSession()->getPage()->getText();
    $this->assertSame(
      1,
      substr_count($page, 'openai_compatible:openai/gpt-4.1'),
      'A model bound to two roles must be offered once, not once per role.',
    );
    // And it says who is using it, so the single row is not ambiguous.
    $this->assertSession()->pageTextContains('High thinking');
    $this->assertSession()->pageTextContains('Task executor');
  }

  /**
   * An unpriced model states the consequence, not just the absence.
   *
   * "No rate" is a fact an operator can ignore. "The dashboard is under-reporting
   * until you set one" is the same fact with the reason to act attached, and it
   * is the whole difference between this page and the silent $0.00 it exists to
   * end.
   */
  public function testAnUnpricedModelSaysWhatItCosts(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'production-fast']]);

    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextContains('UNPRICED');
    $this->assertSession()->pageTextContains('under-report');
  }

  /**
   * Every figure names itself, and a missing one says so in words.
   *
   * The price column's heading is a VISUAL device: it sits once at the top and
   * the eye carries it down the page. Nothing carries it down for a screen
   * reader, which arrives at a row and is read two bare numbers in no stated
   * unit and no stated order. Worse, a missing rate is an em dash, which speech
   * announces as punctuation or skips outright — so the single state this page
   * exists to make loud would be the quietest thing in the row.
   *
   * Invisible by construction, which is exactly why it needs a test: nothing
   * about the rendered page would look wrong if this silently stopped.
   */
  public function testEveryRateIsLabelledForSpeechAndAGapIsSpoken(): void {
    $this->bind([
      'reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'production-fast'],
      'image' => ['provider_id' => 'nanobanana', 'model_id' => 'gemini-2.5-flash-image'],
    ]);

    $this->drupalGet(self::PATH);

    // The gap, in words rather than as punctuation.
    $this->assertSession()->pageTextContains('Input: no rate set.');
    $this->assertSession()->pageTextContains('Output: no rate set.');
    // And a real rate, carrying its column and its unit.
    $this->assertSession()->pageTextContains('Input: $0.30 per million tokens.');
    // The dash itself is decoration and must not be read out twice.
    $this->assertSession()->elementExists('css', '.ain-pricing__num--none [aria-hidden="true"]');
  }

  /**
   * A model we DO recognise arrives already priced, from the suggestions.
   *
   * The split that moved our rates out of config must not have cost billing
   * accuracy on a direct vendor key — the case suggestions genuinely cover.
   * Nothing is STORED for it: the row offers "our published rate", which keeps
   * the site on whatever we currently publish rather than freezing a copy.
   */
  public function testARecognisedModelIsPricedFromTheSuggestions(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai', 'model_id' => 'gpt-4.1']]);

    $this->drupalGet(self::PATH);

    // The ledger figures, in the column an operator scans down.
    $this->assertSession()->pageTextContains('2.00');
    $this->assertSession()->pageTextContains('8.00');
    $this->assertSession()->pageTextContains('Our published rate');
    $this->assertSession()->elementExists(
      'css',
      '[name="models[openai__gpt_4_1][choice]"][value="inherit"][checked]',
    );
    $this->assertSame([], $this->config(ModelPricing::CONFIG)->get('models'));
  }

  /**
   * A namespaced proxy alias is OFFERED the rate of the model it names.
   *
   * The case the whole matching layer exists for. `openai/gpt-4.1` through a
   * proxy is not a model we can price — the alias is the operator's, and it may
   * route anywhere — but the name is evidence, and the row must put that
   * evidence in front of them rather than shrug. It is offered, never applied:
   * only the operator can see where their proxy sends it.
   */
  public function testAProxyAliasIsOfferedTheRateOfTheModelItNames(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'openai/gpt-4.1']]);

    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextContains('Priced as openai:gpt-4.1');
    $this->assertSession()->pageTextContains('Matched by name');
    // Offered only: until the operator picks it, the model is still unpriced.
    $this->assertSession()->pageTextContains('unpriced');
    $this->assertSame([], $this->config(ModelPricing::CONFIG)->get('models'));
  }

  /**
   * Accepting a name match stores it stamped as a match, not as our rate.
   *
   * The provenance is the point. A figure arrived at by matching a name is this
   * site's number now — a human accepted a guess — and an audit must be able to
   * see that it began as one, rather than reading like a rate we published and
   * stand behind.
   */
  public function testAcceptingANameMatchStoresItAsAMatch(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'openai/gpt-4.1']]);
    $key = 'openai_compatible__openai_gpt_4_1';

    $this->drupalGet(self::PATH);
    $this->submitForm(["models[$key][choice]" => 'match_0'], 'Save configuration');

    $models = $this->config(ModelPricing::CONFIG)->get('models');
    $this->assertCount(1, $models);
    $this->assertSame('openai_compatible', $models[0]['provider']);
    $this->assertSame('openai/gpt-4.1', $models[0]['model']);
    $this->assertSame(2.0, $models[0]['input_per_mtok']);
    $this->assertStringContainsString('Matched from openai:gpt-4.1', $models[0]['source']);
  }

  /**
   * "Free" and a typed rate cannot both be true.
   *
   * The reason this is a radio. The form it replaced offered free as a checkbox
   * ALONGSIDE the rate fields and a third "use our suggestion" checkbox, so an
   * operator could assert three prices at once and the submit handler picked one
   * by an order that appeared nowhere on screen. What got stored then stopped
   * matching what they believed they had said — which is the failure this whole
   * page exists to end.
   */
  public function testFreeWinsOutrightOverAnythingLeftInTheRateFields(): void {
    $this->bind(['reasoning' => ['provider_id' => 'ollama', 'model_id' => 'llama3.3']]);
    $key = 'ollama__llama3_3';

    $this->drupalGet(self::PATH);
    $this->submitForm([
      "models[$key][choice]" => 'free',
      // Still in the DOM (a non-JS driver submits hidden fields), and still
      // ignored: the answer is the radio, not the leftovers.
      "models[$key][rates][input]" => '9.99',
    ], 'Save configuration');

    $models = $this->config(ModelPricing::CONFIG)->get('models');
    $this->assertCount(1, $models);
    $this->assertTrue($models[0]['free']);
    $this->assertArrayNotHasKey('input_per_mtok', $models[0]);
  }

  /**
   * A model no role uses is kept, but out of the way and with a reason.
   *
   * Not hidden: it may have billed real calls at $0.00, which is exactly what
   * this page exists to surface. Not inline either: nothing further will spend
   * on it, so it must not compete with a live binding. And the row says WHY it
   * is here — "not bound to a role" describes what it is not, which is never the
   * sentence that answers the question.
   */
  public function testAModelNoRoleUsesIsFiledAtTheBottomWithItsReason(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai', 'model_id' => 'gpt-4.1']]);
    $this->config(ModelPricing::CONFIG)->set('models', [
      [
        'provider' => 'mistral',
        'model' => 'mistral-large-latest',
        'input_per_mtok' => 2.0,
        'output_per_mtok' => 6.0,
        'source' => 'test',
        'checked' => '2026-08-03',
      ],
    ])->save();

    $this->drupalGet(self::PATH);

    $this->assertSession()->pageTextContains('Models no role uses (1)');
    $this->assertSession()->pageTextContains('priced on this site, unused');
    $this->assertSession()->elementExists(
      'css',
      '.ain-pricing__retired [name="models[_retired][mistral__mistral_large_latest][choice]"]',
    );
  }

  /**
   * A rate an operator saves is stored, attributed and dated.
   *
   * Attribution is the safeguard, not decoration: a figure nobody can trace is
   * the stale rate nobody could question, which is how contrib's table kept a
   * model at a quarter of its price for a year.
   */
  public function testASavedRateIsAttributedAndDated(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'production-fast']]);

    $this->drupalGet(self::PATH);
    $key = 'openai_compatible__production_fast';
    $this->submitForm([
      "models[$key][choice]" => 'custom',
      "models[$key][rates][input]" => '1.25',
      "models[$key][rates][output]" => '6.5',
    ], 'Save configuration');

    $models = $this->config(ModelPricing::CONFIG)->get('models');
    $this->assertCount(1, $models);
    $this->assertSame('openai_compatible', $models[0]['provider']);
    $this->assertSame('production-fast', $models[0]['model']);
    $this->assertSame(1.25, $models[0]['input_per_mtok']);
    $this->assertSame(6.5, $models[0]['output_per_mtok']);
    $this->assertNotEmpty($models[0]['source']);
    $this->assertNotEmpty($models[0]['checked']);
    // Untouched classes stay ABSENT — not zero. A zero would bill cache reads at
    // nothing and report no gap; an absence reports them as unpriced.
    $this->assertArrayNotHasKey('cache_read_per_mtok', $models[0]);
  }

  /**
   * Choosing "not set" removes the override instead of storing zeros.
   */
  public function testClearingARateRemovesTheOverride(): void {
    $this->bind(['reasoning' => ['provider_id' => 'openai_compatible', 'model_id' => 'production-fast']]);
    $this->config(ModelPricing::CONFIG)->set('models', [
      [
        'provider' => 'openai_compatible',
        'model' => 'production-fast',
        'input_per_mtok' => 1.25,
        'output_per_mtok' => 6.5,
        'source' => 'test',
        'checked' => '2026-08-03',
      ],
    ])->save();

    $this->drupalGet(self::PATH);
    $key = 'openai_compatible__production_fast';
    $this->submitForm(["models[$key][choice]" => 'none'], 'Save configuration');

    $this->assertSame([], $this->config(ModelPricing::CONFIG)->get('models'));
  }

}
