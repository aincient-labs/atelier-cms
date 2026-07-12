<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * A flow dispatcher whose paused (human-in-the-loop) turns can be resumed.
 *
 * When {@see FlowDispatcherInterface::dispatch()} yields an INTERRUPT event, the
 * turn pauses awaiting user input. The console posts the user's answer to the
 * resolve endpoint, which calls {@see self::resume()} to feed the answer back
 * into the paused flow and stream the continuation (a further INTERRUPT for a
 * chained pause, or a RESULT on completion).
 */
interface ResumableFlowDispatcherInterface extends FlowDispatcherInterface {

  /**
   * Resume a paused flow with the user's answer to an interrupt.
   *
   * @param string $interruptId
   *   The interrupt id the dispatch step emitted.
   * @param mixed $response
   *   The user's answer, shaped per the interrupt's schema (e.g. a string for a
   *   single-select ChoiceNode, an array for multi-select).
   * @param string $threadId
   *   The console thread the turn belongs to.
   *
   * @return \Generator<\Drupal\aincient_chat\Event\ChatEvent>
   *   Continuation events (NOT including DONE — the processor emits that).
   */
  public function resume(string $interruptId, mixed $response, string $threadId): \Generator;

}
