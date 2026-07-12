<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * Processes one chat turn, yielding a stream of ChatEvents.
 */
interface ChatProcessorInterface {

  /**
   * Run a turn.
   *
   * @param string $message
   *   The user's message.
   * @param string $threadId
   *   The conversation thread id (opaque string).
   * @param string|null $flowOverride
   *   Optional pinned flow (hybrid override).
   * @param string|null $workflow
   *   Optional FlowDrop workflow id the user picked for a NEW conversation.
   *   Callers must validate it against the exposed catalog first
   *   ({@see \Drupal\aincient_chat\Chat\WorkflowCatalog}); an existing thread
   *   keeps the workflow its session was created with.
   * @param array<string, mixed> $clientContext
   *   Optional transient per-turn context from the client, keyed by the
   *   workflow input it seeds (e.g. `variables` = the studio's template vars:
   *   the page draft + translation directive, or the brand preview draft).
   *   Passed as a declared workflow input for this turn only; never persisted
   *   to the conversation buffer.
   *
   * @return \Generator<\Drupal\aincient_chat\Event\ChatEvent>
   *   The turn's events, in order.
   */
  public function processTurn(string $message, string $threadId, ?string $flowOverride = NULL, ?string $workflow = NULL, array $clientContext = []): \Generator;

  /**
   * Resume a paused human-in-the-loop turn with the user's answer.
   *
   * @param string $interruptId
   *   The interrupt id emitted by the paused turn's INTERRUPT event.
   * @param mixed $response
   *   The user's answer (shaped per the interrupt's schema).
   * @param string $threadId
   *   The conversation thread id.
   *
   * @return \Generator<\Drupal\aincient_chat\Event\ChatEvent>
   *   The continuation's events, in order (ending in DONE).
   */
  public function resumeInterrupt(string $interruptId, mixed $response, string $threadId): \Generator;

}
