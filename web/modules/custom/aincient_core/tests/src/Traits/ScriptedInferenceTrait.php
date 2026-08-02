<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Traits;

use Drupal\aincient_inference_test\ScriptedAdapter;

/**
 * Wires the scripted inference provider into a kernel test.
 *
 * The replacement for `drupal/ai`'s `echoai` in every kernel test that drives a
 * one-shot AI call. It binds a model role to a REAL registered adapter and stores
 * a credential the same way onboarding does, so what the test exercises is the
 * product's own resolution and capability logic — not a stubbed gateway.
 *
 * @see \Drupal\aincient_inference_test\ScriptedAdapter
 */
trait ScriptedInferenceTrait {

  /**
   * A real 1×1 PNG, so bytes that come back can be saved as an image.
   */
  private const SCRIPTED_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

  /**
   * Stores the provider's credential — i.e. makes it "connected".
   *
   * Without this the provider is bound but UNUSABLE, which is a state worth being
   * able to set up deliberately (a keyless install), so it is a separate step
   * rather than folded into binding.
   */
  private function connectScriptedProvider(): void {
    \Drupal::state()->set(ScriptedAdapter::CREDENTIAL_KEY, 'test-key');
    \Drupal::state()->set(ScriptedAdapter::IMAGE_KEY, base64_decode(self::SCRIPTED_PNG_BASE64));
  }

  /**
   * Binds a model role to the scripted provider.
   *
   * @param string $role
   *   The role to bind ({@see \Drupal\aincient_core\ModelRoles}).
   * @param string $modelId
   *   The model id. Anything containing `image` is answered with an image
   *   ({@see \Drupal\aincient_inference_test\ScriptedPlatform}).
   */
  private function bindScriptedRole(string $role, string $modelId = 'scripted-chat'): void {
    \Drupal::service('aincient_core.model_role_resolver')
      ->bind($role, ScriptedAdapter::PROVIDER_ID, $modelId);
  }

  /**
   * Scripts the text the provider answers a chat turn with.
   */
  private function scriptInferenceText(string $text): void {
    \Drupal::state()->set(ScriptedAdapter::TEXT_KEY, $text);
  }

  /**
   * Scripts the token usage the provider reports for the next call.
   *
   * Not called = the provider files no accounting at all, which is a different
   * answer from zero and is what {@see ScriptedAdapter::USAGE_KEY} explains.
   *
   * @param int $prompt
   *   Input tokens.
   * @param int $completion
   *   Output tokens, excluding thinking.
   * @param int|null $thinking
   *   Thinking tokens, as Gemini reports them separately from completion.
   * @param int|null $cacheRead
   *   Cache-read input tokens (billed at the discounted rate).
   * @param int|null $cacheCreation
   *   Cache-creation input tokens (billed ABOVE plain input).
   */
  private function scriptInferenceUsage(
    int $prompt,
    int $completion,
    ?int $thinking = NULL,
    ?int $cacheRead = NULL,
    ?int $cacheCreation = NULL,
  ): void {
    \Drupal::state()->set(ScriptedAdapter::USAGE_KEY, [
      'prompt' => $prompt,
      'completion' => $completion,
      'thinking' => $thinking,
      'cache_read' => $cacheRead,
      'cache_creation' => $cacheCreation,
    ]);
  }

  /**
   * What the platform was last invoked with (model, options, content parts).
   *
   * @return array{model: string, options: array<string, mixed>, parts: array<int, string>}
   *   The recorded call.
   */
  private function lastInferenceCall(): array {
    return \Drupal::state()->get(ScriptedAdapter::LAST_CALL_KEY, [
      'model' => '',
      'options' => [],
      'parts' => [],
    ]);
  }

}
