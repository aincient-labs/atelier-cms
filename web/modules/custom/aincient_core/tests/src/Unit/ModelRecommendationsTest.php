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
   * Provider recommendation reads the providers map; unknown → ''.
   */
  public function testProviderRecommendation(): void {
    $this->assertSame('recommended', $this->recommendations->providerRecommendation('anthropic'));
    $this->assertSame('tested', $this->recommendations->providerRecommendation('openai'));
    // Mistral is now a backed provider (medium-latest handles the tool loop).
    $this->assertSame('recommended', $this->recommendations->providerRecommendation('mistral'));
    $this->assertSame('', $this->recommendations->providerRecommendation('unknown_provider'));
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
