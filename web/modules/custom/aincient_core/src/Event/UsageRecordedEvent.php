<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Announces one recorded inference call, after the row is in the table.
 *
 * WHY AN EVENT AND NOT A RETURN VALUE. The one thing that has to hear about a
 * call the instant it completes is the console: `aincient_chat`'s
 * UsageStreamSubscriber turns each of these into a `usage` frame on the open SSE
 * stream, which is how the operator sees a running per-turn cost while the turn
 * is still running. The recorder is called from three seams buried inside the
 * agent loop, the gateway and the chat node; threading a callback out of all
 * three to reach a stream relay that may not even be armed is the coupling this
 * event exists to avoid.
 *
 * DISPATCHED AFTER THE INSERT, never before. A subscriber that reacted to a call
 * that then failed to persist would be showing the operator a number the
 * dashboard will never agree with.
 *
 * THE SHAPE IS DELIBERATELY THE PREDECESSOR'S. It carries the same eight values,
 * under the same names, that `ai_metering`'s MeteringRecordCreatedEvent did —
 * this replaces that event rather than improving on it, and a console footer
 * that had to learn a new vocabulary in the same change would make a regression
 * in the stream indistinguishable from a regression in the store. What is NOT
 * here is the row id: a subscriber that wanted to go back and read the row would
 * be describing a different feature, and handing out a primary key invites it.
 */
final class UsageRecordedEvent extends Event {

  /**
   * The event name.
   *
   * Subscribers bind to this by the constant, not by a literal string. The
   * literal was a habit inherited from when the event belonged to an optional
   * contrib module and autoloading its class had to be avoided; now that the
   * event is ours, referencing it is safe and the name is one an IDE can follow.
   */
  public const EVENT_NAME = 'aincient_core.usage_recorded';

  /**
   * @param int $uid
   *   The account the call was made for.
   * @param string $providerId
   *   The adapter that served the call.
   * @param string $modelId
   *   The model that served it, as resolved.
   * @param string $operationType
   *   One of the UsageRecorder OPERATION_* values.
   * @param int $inputTokens
   *   Input tokens as recorded — cache writes folded in, matching the column.
   * @param int $outputTokens
   *   Output tokens, thinking included.
   * @param int $cachedTokens
   *   Cache-read tokens, a subset of the input figure above.
   * @param float $costUsd
   *   What the call cost, per Atelier's own rate table. A zero here can mean
   *   "free" or "unpriced"; the log line the recorder writes tells them apart.
   */
  public function __construct(
    private readonly int $uid,
    private readonly string $providerId,
    private readonly string $modelId,
    private readonly string $operationType,
    private readonly int $inputTokens,
    private readonly int $outputTokens,
    private readonly int $cachedTokens,
    private readonly float $costUsd,
  ) {}

  /**
   * The account the call was made for.
   */
  public function getUid(): int {
    return $this->uid;
  }

  /**
   * The adapter that served the call.
   */
  public function getProviderId(): string {
    return $this->providerId;
  }

  /**
   * The model that served the call, as resolved.
   */
  public function getModelId(): string {
    return $this->modelId;
  }

  /**
   * The operation the call performed.
   */
  public function getOperationType(): string {
    return $this->operationType;
  }

  /**
   * Input tokens as recorded, cache writes included.
   */
  public function getInputTokens(): int {
    return $this->inputTokens;
  }

  /**
   * Output tokens, thinking included.
   */
  public function getOutputTokens(): int {
    return $this->outputTokens;
  }

  /**
   * Cache-read tokens — a subset of the input figure, not an addition to it.
   */
  public function getCachedTokens(): int {
    return $this->cachedTokens;
  }

  /**
   * What the call cost in USD.
   */
  public function getCostUsd(): float {
    return $this->costUsd;
  }

}
