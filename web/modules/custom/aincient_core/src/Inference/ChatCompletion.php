<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * What one single-turn chat call produced.
 *
 * Carries the provider and model that actually served the call, not the ones a
 * caller asked for: a node stores `model: null` and an `aincient_role:…`
 * operation type, so "which model answered this" is knowable only after
 * resolution — and it is the thing an operator reading a job trail needs.
 */
final class ChatCompletion {

  /**
   * Constructs a ChatCompletion.
   *
   * @param string $text
   *   The model's text, trimmed. '' when the model said nothing.
   * @param string $providerId
   *   The provider that served the call.
   * @param string $modelId
   *   The model that served the call.
   * @param int $tokensUsed
   *   The provider's own token count, or 0 when it reported none
   *   ({@see ResultUnpacker::totalTokens()}).
   */
  public function __construct(
    public readonly string $text,
    public readonly string $providerId,
    public readonly string $modelId,
    public readonly int $tokensUsed,
  ) {}

}
