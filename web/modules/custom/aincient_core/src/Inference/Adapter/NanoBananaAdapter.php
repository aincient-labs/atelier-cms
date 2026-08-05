<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

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
 * WHAT IS LEFT HERE, since {@see GeminiAdapter} became image-capable itself: an
 * identity, and one refusal. The drawing is inherited — the credential, the
 * endpoint, the enumeration call and the image-capability contract are all the
 * parent's, because they were never anything but "a Google key". So this class is
 * now the compatibility half of a single provider: the id an existing install has
 * its image role bound to, kept servable. Two ids drawing from the same key is
 * mildly redundant; retiring one is a migration (an update hook for the bindings,
 * and an onboarding change), not a tidy-up, so it stays.
 *
 * The refusal is {@see self::servesChat()}: this id must not be OFFERED for chat,
 * or one Google catalogue appears twice in every role select. `listChatModels()`
 * is still inherited unchanged — the credential is a plain Google key, so the
 * honest answer to "what can this key reach" is Gemini's whole chat list, which is
 * a different question from what this id is offered for.
 */
final class NanoBananaAdapter extends GeminiAdapter {

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
    // It CAN — it is a Gemini platform, and `listChatModels()` is deliberately
    // inherited. But it must not be OFFERED for chat: this id and `gemini` are one
    // Google key, so a picker that listed both would show all thirty Gemini chat
    // models twice, once under "Nano Banana", and invite a chat role bound to the
    // image id. Measured on a live install when this returned TRUE: 71 chat
    // options where there are 41 distinct models.
    return FALSE;
  }

}
