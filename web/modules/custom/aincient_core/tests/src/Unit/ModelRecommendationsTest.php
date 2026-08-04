<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\ModelRecommendations;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\aincient_core\RecommendationSource;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;

/**
 * Unit-tests the model/provider recommendation registry against the shipped
 * model-recommendations.yml.
 *
 * @group aincient
 * @covers \Drupal\aincient_core\ModelRecommendations
 */
final class ModelRecommendationsTest extends UnitTestCase {

  private ModelRecommendations $recommendations;

  protected function setUp(): void {
    parent::setUp();
    // Point getPath() at the real module root so the shipped YAML is exercised
    // (this also fails the build if the file stops parsing). State returns
    // nothing, so the source falls back to that bundled snapshot — which is the
    // behaviour on any install that has never clicked "Check for updates".
    // tests/src/Unit -> aincient_core.
    $moduleRoot = dirname(__DIR__, 3);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->with('aincient_core')->willReturn($moduleRoot);
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturn(NULL);
    $source = new RecommendationSource(
      $moduleList,
      $state,
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $this->createMock(TimeInterface::class),
    );
    $this->recommendations = new ModelRecommendations($source);
  }

  /**
   * A model is labelled by its longest matching needle, else "untested".
   */
  public function testLabelForModel(): void {
    // Recommended needles — the narrow backed set: Sonnet + Haiku.
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'claude-sonnet-4-20250101'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'claude-haiku-4'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('nanobanana', 'gemini-2.5-flash-image'));

    // Tested needles — connectable but not actively backed.
    $this->assertSame('tested', $this->recommendations->labelForModel('anthropic', 'claude-opus-4'));
    $this->assertSame('tested', $this->recommendations->labelForModel('mistral', 'mistral-small-2603'));

    // Not-recommended. OpenAI's GPT-4/o-series generation is VENDOR-DEPRECATED,
    // so it sits here rather than under "tested" — a retired model is a bad
    // suggestion regardless of how well it once worked (curated 2026-07-25).
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openai', 'gpt-3.5-turbo'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openai', 'gpt-4o'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openai', 'gpt-4o-mini'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openai', 'o3'));
    // ...but the current GPT-5.x families are simply UNTESTED by us — no label
    // either way. This is the distinction the registry exists to keep honest:
    // "we haven't tried it" is not "we advise against it".
    $this->assertSame('untested', $this->recommendations->labelForModel('openai', 'gpt-5.6-sol'));
    $this->assertSame('untested', $this->recommendations->labelForModel('openai', 'gpt-5.4-nano'));

    // The Gemini Flash needle is scoped to the generations actually tested — 2.0
    // and 2.5 were too weak for the reasoning/tool-loop roles...
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('gemini', 'gemini-2.5-flash'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('gemini', 'gemini-2.0-flash-lite'));
    // ...and must NOT condemn the 3.x generation nobody here has tested. A bare
    // `flash` needle used to do exactly that.
    $this->assertSame('untested', $this->recommendations->labelForModel('gemini', 'gemini-3.5-flash'));
    $this->assertSame('untested', $this->recommendations->labelForModel('gemini', 'gemini-3.6-flash'));
    // On OpenRouter the longer Flash needle out-matches the "gemini-2.5" tested needle.
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openrouter', 'google/gemini-2.5-flash'));
    // ...but the nanobanana image model (different provider) stays recommended.
    $this->assertSame('recommended', $this->recommendations->labelForModel('nanobanana', 'gemini-2.5-flash-image'));

    // Case-insensitive.
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'Claude-SONNET-4'));

    // No needle match, and unknown provider → untested.
    $this->assertSame('untested', $this->recommendations->labelForModel('anthropic', 'some-experimental-model'));
    $this->assertSame('untested', $this->recommendations->labelForModel('unknown_provider', 'anything'));
    // Mistral: medium-latest is backed; small/large and magistral-small are
    // tested. "magistral" is a distinct family, matched by its own needle.
    // Both the dated ids the profiles pin and the `-latest` aliases a site may
    // already be bound to resolve to the same label — the needles are family
    // names, so a version bump doesn't silently drop a model's badge.
    $this->assertSame('recommended', $this->recommendations->labelForModel('mistral', 'mistral-medium-2604'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('mistral', 'mistral-medium-latest'));
    $this->assertSame('tested', $this->recommendations->labelForModel('mistral', 'mistral-small-latest'));
    $this->assertSame('tested', $this->recommendations->labelForModel('mistral', 'mistral-large-2512'));
    $this->assertSame('tested', $this->recommendations->labelForModel('mistral', 'magistral-small-latest'));
  }

  /**
   * A proxy's models carry the UPSTREAM vendor's label.
   *
   * A LiteLLM proxy serves other vendors' models as `vendor/model`, and every
   * label we publish is written about the vendor. Without looking through the
   * proxy, `claude-sonnet-5` reads "Recommended" on a direct Anthropic key and
   * carries no badge at all through a proxy — while a vendor-deprecated model
   * loses its warning exactly where an operator is least likely to spot it.
   */
  public function testLabelForModelLooksThroughAProxy(): void {
    // The badge follows the model, not the route to it.
    $this->assertSame('recommended', $this->recommendations->labelForModel('litellm', 'anthropic/claude-sonnet-5'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('litellm', 'anthropic/claude-haiku-4-5'));
    $this->assertSame('tested', $this->recommendations->labelForModel('litellm', 'anthropic/claude-opus-5'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('litellm', 'mistral/mistral-medium-2604'));
    // Deprecation warnings survive the hop — the important half.
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('litellm', 'openai/gpt-4o'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('litellm', 'gemini/gemini-2.5-flash'));
    // Untested stays untested: a proxy doesn't launder a model into a claim.
    $this->assertSame('untested', $this->recommendations->labelForModel('litellm', 'openai/gpt-5.6-sol'));
    $this->assertSame('untested', $this->recommendations->labelForModel('litellm', 'gemini/gemini-3.5-flash'));
    $this->assertSame('untested', $this->recommendations->labelForModel('litellm', 'someone/whatever-1'));

    // A known vendor segment is authoritative: ONLY that vendor's needles apply,
    // so a model cannot inherit a label from a family name another vendor uses.
    $this->assertSame('untested', $this->recommendations->labelForModel('litellm', 'gemini/claude-sonnet-5'));

    // Where the segment is not a vendor we curate, the MODEL NAME decides. Both of
    // these shapes are ordinary on a real proxy: a stripped namespace, and one
    // namespaced by ROUTE (`vertex_ai/`, `bedrock/`, `azure/` — who serves it, not
    // who made it). Refusing them would blank most of a proxy's catalogue.
    $this->assertSame('recommended', $this->recommendations->labelForModel('litellm', 'claude-sonnet-5'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('litellm', 'gpt-4o-mini'));
    $this->assertSame('untested', $this->recommendations->labelForModel('litellm', 'gpt-5.4-nano'));
    $this->assertSame('recommended', $this->recommendations->labelForModel('litellm', 'vertex_ai/claude-sonnet-5'));
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('litellm', 'azure/gpt-4o'));

    // OpenRouter has its OWN entry in the document, and that stays the more
    // specific statement: its Flash needle out-matches Gemini's own.
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openrouter', 'google/gemini-2.5-flash'));
    // Where its entry says nothing, the vendor's does — no longer a blank.
    $this->assertSame('not-recommended', $this->recommendations->labelForModel('openrouter', 'openai/gpt-3.5-turbo'));

    // A non-proxy provider is NOT given the vendor sweep: what Anthropic serves
    // under its own key is Anthropic's, and a `vendor/` id there would be a
    // catalogue we don't understand rather than a route.
    $this->assertSame('untested', $this->recommendations->labelForModel('anthropic', 'openai/gpt-4o'));
  }

  /**
   * Provider recommendation reads the providers map; unknown → ''.
   */
  public function testProviderRecommendation(): void {
    $this->assertSame('tested', $this->recommendations->providerRecommendation('openai'));
    // DeepSeek is a named preset AND a tested provider — the two are separate
    // facts: the preset says we can reach it, the label says we have.
    $this->assertSame('tested', $this->recommendations->providerRecommendation('deepseek'));
    $this->assertSame('', $this->recommendations->providerRecommendation('unknown_provider'));
  }

  /**
   * The provider tier is neutral: everything assessed is `tested`, none is backed.
   *
   * Anthropic and Mistral used to be `recommended`. Stated as a property over the
   * whole map rather than two ids, because the point is not "these two moved" —
   * it is that a provider-level endorsement does not belong in this file at all,
   * and the next provider added must not quietly reintroduce one.
   */
  public function testNoProviderIsEndorsed(): void {
    foreach (['anthropic', 'mistral', 'openai', 'gemini', 'nanobanana', 'ollama', 'deepseek'] as $id) {
      $this->assertSame(
        'tested',
        $this->recommendations->providerRecommendation($id),
        sprintf('%s should be assessed but not endorsed.', $id),
      );
    }
  }

  /**
   * A model may still be backed — that claim is about the model, not the vendor.
   */
  public function testModelLabelsSurviveTheProviderDemotion(): void {
    $this->assertSame('recommended', $this->recommendations->labelForModel('anthropic', 'claude-sonnet-4-5'));
    $this->assertSame('tested', $this->recommendations->providerRecommendation('anthropic'));
  }

  /**
   * Rank orders recommended → tested → untested → not-recommended.
   */
  public function testRank(): void {
    $this->assertSame(0, $this->recommendations->rank('recommended'));
    $this->assertSame(1, $this->recommendations->rank('tested'));
    $this->assertSame(2, $this->recommendations->rank('untested'));
    $this->assertSame(3, $this->recommendations->rank('not-recommended'));
    // Unknown label sorts as untested.
    $this->assertSame(2, $this->recommendations->rank('bogus'));
  }

}
