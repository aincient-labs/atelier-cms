<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_chat\Event\ChatEvent;

/**
 * A per-request side channel into the chat SSE stream.
 *
 * The FlowDrop turn runs SYNCHRONOUSLY inside the dispatcher's generator
 * (SessionTurnService::executeTurn(wait: TRUE)), so nothing can be `yield`ed
 * while it executes — the stream goes silent for the whole run. But Drupal
 * events (e.g. flowdrop_runtime.job.completed) DO fire during that blocking
 * call, in the same request, and the controller has already disabled output
 * buffering. This relay lets event subscribers push frames straight onto the
 * open SSE response between the generator's own yields.
 *
 * The controller arms it with an emitter for the duration of a streamed turn
 * and disarms it in `finally`. Outside an armed window (cron, playground runs,
 * normal page requests) emit() is a no-op, so subscribers can fire blindly.
 */
final class StreamRelay {

  /**
   * The active emitter, or NULL outside a streamed chat turn.
   *
   * @var (\Closure(ChatEvent): void)|null
   */
  private ?\Closure $emitter = NULL;

  /**
   * Arm the relay for the duration of a streamed response callback.
   *
   * @param callable(ChatEvent): void $emitter
   *   Writes one event to the open SSE stream (echo frame + flush).
   */
  public function arm(callable $emitter): void {
    $this->emitter = $emitter(...);
  }

  /**
   * Disarm the relay (always call from `finally`).
   */
  public function disarm(): void {
    $this->emitter = NULL;
  }

  /**
   * Whether a streamed turn is currently listening.
   *
   * Lets a subscriber skip non-trivial work (e.g. building a widget payload)
   * when there's no open stream to push it onto — emit() is already a no-op
   * when disarmed, this just avoids the wasted computation.
   */
  public function isArmed(): bool {
    return $this->emitter !== NULL;
  }

  /**
   * Push an event onto the open stream; no-op when not armed.
   */
  public function emit(ChatEvent $event): void {
    if ($this->emitter !== NULL) {
      ($this->emitter)($event);
    }
  }

}
