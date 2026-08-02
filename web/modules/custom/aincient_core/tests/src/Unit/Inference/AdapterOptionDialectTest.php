<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\AnthropicAdapter;
use Drupal\aincient_core\Inference\Adapter\GeminiAdapter;
use Drupal\aincient_core\Inference\Adapter\MistralAdapter;
use Drupal\aincient_core\Inference\Adapter\NanoBananaAdapter;
use Drupal\aincient_core\Inference\Adapter\OllamaAdapter;
use Drupal\aincient_core\Inference\Adapter\OpenAiAdapter;
use Drupal\aincient_core\Inference\Adapter\OpenAiCompatibleAdapter;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins each provider's option dialect — a rename nobody can see until it 400s.
 *
 * THE OUTAGE THIS PREVENTS. `symfony/ai`'s Gemini bridge assigns the options
 * array straight into Gemini's `generationConfig`, so an OpenAI-shaped
 * `max_tokens` is rejected by Google outright: `Invalid JSON payload received.
 * Unknown name "max_tokens" at 'generation_config'`. It is a whole-request
 * failure, not a degraded answer — so the moment a Gemini role binding resolves,
 * every reasoning turn on it would fail. Anthropic, meanwhile, really does take
 * `max_tokens`, and its behaviour must be byte-identical to what shipped.
 *
 * These are unit tests on purpose: the translation is a pure function of the
 * provider, so it can be pinned without a credential, and it is the one thing a
 * live probe of ONE provider cannot cover for the others.
 */
#[CoversClass(AnthropicAdapter::class)]
#[CoversClass(GeminiAdapter::class)]
#[CoversClass(NanoBananaAdapter::class)]
#[CoversClass(MistralAdapter::class)]
#[CoversClass(OllamaAdapter::class)]
#[CoversClass(OpenAiAdapter::class)]
#[CoversClass(OpenAiCompatibleAdapter::class)]
final class AdapterOptionDialectTest extends TestCase {

  /**
   * Anthropic gets exactly what it got before the seam existed.
   */
  public function testAnthropicIsIdentity(): void {
    $options = ['max_tokens' => 2048, 'temperature' => 0.7, 'tools' => ['x']];

    self::assertSame($options, $this->adapter(AnthropicAdapter::class)->translateOptions($options));
  }

  /**
   * An OpenAI-compatible endpoint speaks the same names, so also identity.
   */
  public function testOpenAiCompatibleIsIdentity(): void {
    $options = ['max_tokens' => 512, 'temperature' => 0.2];

    self::assertSame($options, $this->adapter(OpenAiCompatibleAdapter::class)->translateOptions($options));
  }

  /**
   * Gemini's token cap is `maxOutputTokens`, and `max_tokens` must be GONE.
   *
   * Leaving the old key in place alongside the new one would still 400 — Google
   * validates every key in generationConfig, so this is a rename, not an alias.
   */
  public function testGeminiRenamesTheTokenCap(): void {
    $translated = $this->adapter(GeminiAdapter::class)
      ->translateOptions(['max_tokens' => 2048]);

    self::assertSame(['maxOutputTokens' => 2048], $translated);
  }

  /**
   * Everything else Gemini already spells our way is left alone.
   *
   * `temperature` is a GenerationConfig field under the same name, and `tools` is
   * consumed by the bridge before generationConfig is built. Filtering them out
   * "to be safe" would silently drop an operator's setting — the failure mode this
   * whole seam is meant to avoid.
   */
  public function testGeminiLeavesOtherOptionsUntouched(): void {
    $translated = $this->adapter(GeminiAdapter::class)->translateOptions([
      'max_tokens' => 1024,
      'temperature' => 0.7,
      'tools' => ['declaration'],
    ]);

    self::assertSame(1024, $translated['maxOutputTokens']);
    self::assertSame(0.7, $translated['temperature']);
    self::assertSame(['declaration'], $translated['tools']);
    self::assertArrayNotHasKey('max_tokens', $translated);
  }

  /**
   * Options with no cap at all pass through unchanged (the gateway's case).
   */
  public function testGeminiPassesThroughWhenThereIsNoTokenCap(): void {
    self::assertSame([], $this->adapter(GeminiAdapter::class)->translateOptions([]));
  }

  /**
   * The image provider inherits Gemini's dialect — it IS a Gemini key.
   *
   * `nanobanana` is our historical id for Google's image model, not a separate
   * vendor, so a dialect fix on Gemini that missed it would leave image turns
   * broken in exactly the way this test exists to prevent.
   */
  public function testNanoBananaInheritsTheGeminiDialect(): void {
    self::assertSame(
      ['maxOutputTokens' => 256],
      $this->adapter(NanoBananaAdapter::class)->translateOptions(['max_tokens' => 256]),
    );
  }

  /**
   * Ollama's cap is `num_predict`, and getting it wrong is SILENT.
   *
   * The worst of the three dialects. Gemini rejects an unknown generation key
   * outright, so a mistake there is a loud 400 on the first turn; Ollama nests
   * whatever it does not recognise into its `options` object and IGNORES it. A
   * missed rename means every request succeeds with no cap applied — a local
   * model generating until it decides to stop, and nothing anywhere saying why.
   */
  public function testOllamaRenamesTheTokenCap(): void {
    $translated = $this->adapter(OllamaAdapter::class)
      ->translateOptions(['max_tokens' => 2048, 'temperature' => 0.7, 'tools' => ['x']]);

    self::assertSame(2048, $translated['num_predict']);
    self::assertArrayNotHasKey('max_tokens', $translated);
    // `temperature` is already Ollama's spelling, and `tools` is a top-level key
    // the bridge lifts out for itself. Both must survive untouched.
    self::assertSame(0.7, $translated['temperature']);
    self::assertSame(['x'], $translated['tools']);
  }

  /**
   * OpenAI's cap is `max_output_tokens`, because the Responses API is not chat.
   *
   * The vendor whose spelling the neutral name was BORROWED from no longer uses
   * it: `openai` rides `/v1/responses`, which caps output with
   * `max_output_tokens` and rejects unknown top-level parameters outright
   * (`Unknown parameter: 'max_tokens'`). The bridge cannot absorb it — its model
   * client merges the options array into the body verbatim — so this rename is
   * the difference between every OpenAI turn working and every one 400ing.
   */
  public function testOpenAiRenamesTheTokenCapForTheResponsesApi(): void {
    $translated = $this->adapter(OpenAiAdapter::class)
      ->translateOptions(['max_tokens' => 4096, 'temperature' => 0.4]);

    self::assertSame(4096, $translated['max_output_tokens']);
    self::assertArrayNotHasKey('max_tokens', $translated);
    self::assertSame(0.4, $translated['temperature']);
  }

  /**
   * Mistral speaks our vocabulary already, so identity — and that is asserted.
   *
   * The `openai_compatible` adapter proves the same point for the chat-completions
   * shape. A no-op here is a fact about Mistral's API, not an omission, and it is
   * worth a test precisely because "we didn't need to translate anything" and "we
   * forgot to translate" look identical in a diff.
   */
  public function testMistralIsIdentity(): void {
    $options = ['max_tokens' => 1024, 'temperature' => 0.3, 'tools' => ['x']];

    self::assertSame($options, $this->adapter(MistralAdapter::class)->translateOptions($options));
  }

  /**
   * An adapter with no dependencies wired.
   *
   * The translation is a pure rename and touches no collaborator, so constructing
   * without one both keeps the test honest and proves the claim: if the
   * translation ever reached for an HTTP client, this fatals.
   *
   * @param class-string<\Drupal\aincient_core\Inference\ProviderAdapterInterface> $class
   *   The adapter class to build.
   */
  private function adapter(string $class): ProviderAdapterInterface {
    return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
  }

}
