<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Usage\ModelPricing;
use Drupal\KernelTests\KernelTestBase;

/**
 * The rate sheet's editable half.
 *
 * Four properties, each of which fails silently and plausibly if it slips —
 * which is the whole hazard class this screen lives in. A wrong rate is a small
 * believable number, and nothing downstream can tell it from a right one.
 *
 * @group aincient
 */
final class PricingFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aincient_core', 'key', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['aincient_core']);
    $this->installEntitySchema('user');
  }

  /**
   * The shipped object is EMPTY: the rates are suggestions, not decisions.
   *
   * If this ever ships populated again, every entry renders as "set on this
   * site" and the disagreement notice compares our suggestion against a copy of
   * itself — the safeguard silently stops safeguarding, which is exactly how the
   * contrib table it replaced went wrong.
   */
  public function testTheOperatorLayerShipsEmpty(): void {
    $this->assertSame(
      [],
      (array) $this->config(ModelPricing::CONFIG)->get('models'),
    );
  }

  /**
   * The suggestions still price a model the operator has said nothing about.
   *
   * The split must not have cost billing accuracy on a direct vendor key, which
   * is the case the suggestions genuinely cover.
   */
  public function testSuggestionsStillPriceADirectKey(): void {
    $pricing = $this->container->get('aincient_core.model_pricing');

    $published = $pricing->published('openai', 'gpt-4.1');
    $this->assertNotNull($published);
    $this->assertSame(2.0, $published['input']);
    $this->assertSame(8.0, $published['output']);
  }

  /**
   * An operator rate outranks the suggestion — and stays legible as theirs.
   *
   * The override winning is correct: their deployment, their bill. What must
   * remain true alongside it is that our suggestion is still READABLE, because
   * that is what lets the form show a disagreement rather than swallow it.
   */
  public function testAnOperatorRateWinsWithoutHidingTheSuggestion(): void {
    $this->config(ModelPricing::CONFIG)->set('models', [
      [
        'provider' => 'openai',
        'model' => 'gpt-4.1',
        'input_per_mtok' => 1.5,
        'output_per_mtok' => 6.0,
        'source' => 'Set on this site by admin.',
        'checked' => '2026-08-03',
      ],
    ])->save();

    $pricing = $this->container->get('aincient_core.model_pricing');

    $this->assertSame(1.5, $pricing->published('openai', 'gpt-4.1')['input']);
    // Ours is still there to compare against — the disagreement is visible.
    $this->assertSame(2.0, $pricing->suggestion('openai', 'gpt-4.1')['input_per_mtok']);
  }

  /**
   * A namespaced alias offers the model it names — as a candidate, not a price.
   *
   * The two shapes normalised away are notation, not identity: a routing
   * namespace (`openai/gpt-4.1`) and a vendor release stamp
   * (`claude-haiku-4-5-20251001`, the literal id that sat 4x underpriced in the
   * table this replaced). Everything found here is a PROPOSAL — the billing
   * lookup does not call this, because only the operator can see where their own
   * alias actually routes.
   */
  public function testNotationIsNormalisedAwayWhenSuggestingCandidates(): void {
    $pricing = $this->container->get('aincient_core.model_pricing');

    $namespaced = $pricing->candidates('openai_compatible', 'openai/gpt-4.1');
    $this->assertNotSame([], $namespaced);
    $this->assertSame('openai:gpt-4.1', $namespaced[0]['from']);
    $this->assertFalse($namespaced[0]['exact'], 'A proxy alias is never an exact match.');
    $this->assertSame(2.0, $namespaced[0]['entry']['input_per_mtok']);

    // Normalisation runs on BOTH sides, which is what makes the date stamp
    // notation rather than identity: the shipped entry carries one and the
    // proxy alias does not, and they still meet.
    $undated = $pricing->candidates('openai_compatible', 'anthropic/claude-haiku-4-5');
    $this->assertSame('anthropic:claude-haiku-4-5-20251001', $undated[0]['from'] ?? NULL);

    // And the offer stays an offer: nothing about it changes what we bill.
    $this->assertNull($pricing->published('openai_compatible', 'openai/gpt-4.1'));
  }

  /**
   * An alias that merely SOUNDS like a model gets nothing.
   *
   * Deliberately no family matching. Through a proxy the id is whatever the
   * operator typed into their own config, so "contains sonnet" is not evidence —
   * and a plausible wrong price is worse than none, because nothing downstream
   * can tell it from a right one. Silence here is the feature.
   */
  public function testAnOpaqueAliasIsNotGuessedAt(): void {
    $pricing = $this->container->get('aincient_core.model_pricing');

    $this->assertSame([], $pricing->candidates('openai_compatible', 'production-fast'));
    $this->assertSame([], $pricing->candidates('openai_compatible', 'sonnet-5-prod'));
  }

  /**
   * A direct vendor key matches itself, and says so.
   *
   * The exact flag is what the form spends on the difference between "our
   * published rate, which stays current" and "a name match, copied once" — so it
   * has to be right, not merely present.
   */
  public function testADirectKeyMatchesExactly(): void {
    $candidates = $this->container->get('aincient_core.model_pricing')
      ->candidates('openai', 'gpt-4.1');

    $this->assertTrue($candidates[0]['exact']);
    $this->assertSame('openai:gpt-4.1', $candidates[0]['from']);
  }

  /**
   * A proxied model nobody has priced stays UNPRICED, not zero.
   *
   * The case the whole redesign exists for: `openai_compatible:claude-sonnet-5`
   * is a real binding on a real install, and its id is an alias we cannot map to
   * a vendor. Answering $0.00 here is the 1,100x under-report that started all
   * of this; answering "I do not know" is the feature.
   */
  public function testAnUnmappedProxyModelIsUnpricedRatherThanFree(): void {
    $pricing = $this->container->get('aincient_core.model_pricing');

    $this->assertNull($pricing->published('openai_compatible', 'claude-sonnet-5'));
    $this->assertFalse($pricing->isPriced('openai_compatible', 'claude-sonnet-5'));

    $cost = $pricing->cost('openai_compatible', 'claude-sonnet-5', 1000, 1000, 0, 0);
    $this->assertSame(0.0, $cost['total']);
    $this->assertFalse($cost['free']);
    // The list that makes the zero reportable rather than silent.
    $this->assertNotSame([], $cost['unpriced']);
  }

}
