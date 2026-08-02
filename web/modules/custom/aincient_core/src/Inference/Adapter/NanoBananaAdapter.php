<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;

/**
 * Gemini's image models, under the provider id `nanobanana`.
 *
 * `nanobanana` IS NOT A VENDOR. It is our historical `drupal/ai` plugin id for
 * Google's image model — "Nano Banana" is what Google itself calls
 * `gemini-2.5-flash-image` in the model list's `displayName`, and the id stuck.
 * Stored `aincient_core.model_roles` bindings and the `aincient.nanobanana_api_key`
 * State key both name it, so it stays byte-identical even though it reads like a
 * joke: renaming it would orphan an operator's image-role binding and their stored
 * key.
 *
 * Everything about the transport is {@see GeminiAdapter}'s — the same key, the
 * same endpoint, the same enumeration call, and (per
 * {@see \Drupal\aincient_core\Inference\PlatformRegistry::settingsNameFor()}) the
 * same `gemini_provider.settings` credential pointer. The only difference is which
 * slice of the catalogue it enumerates, and that it answers the image-capability
 * contract. `listChatModels()` is deliberately inherited unchanged — the credential
 * here is a plain Google key, so the honest answer to "what chat models does this
 * key reach" is the one Gemini gives — but this provider is not OFFERED for chat
 * work ({@see self::servesChat()}), which is a separate question from what it can
 * answer.
 */
final class NanoBananaAdapter extends GeminiAdapter implements ImageGenerationAdapterInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'nanobanana';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Nano Banana (Gemini images)';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Google\'s Gemini image models — generates pictures and edits ones you give it, from the same Google AI Studio key.';
  }

  /**
   * {@inheritdoc}
   */
  public function servesChat(): bool {
    // It CAN — it is a Gemini platform, and `listChatModels()` below is
    // deliberately inherited. But it must not be OFFERED for chat: this id and
    // `gemini` are one Google key, so a picker that listed both would show all
    // thirty Gemini chat models twice, once under "Nano Banana", and invite a chat
    // role bound to the image id. Measured on a live install when this returned
    // TRUE: 71 chat options where there are 41 distinct models.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function supportsImageEditing(): bool {
    // Gemini's image models take image parts as input as readily as text, so
    // editing a source image is the same `generateContent` call with one more
    // part. This was the `ImageToImageInterface` half of the old drupal/ai
    // provider and it still holds.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function listImageModels(string $credential, string $endpoint = ''): array {
    return $this->enumerate($credential, $endpoint, imageOutput: TRUE);
  }

}
