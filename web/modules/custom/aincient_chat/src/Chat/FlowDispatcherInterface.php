<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

/**
 * Dispatches a routed turn to a non-agent backend flow (topology §3, §4a).
 *
 * The agent tier runs inline in {@see ChatProcessor}. Everything the router
 * sends elsewhere — async / long-running / human-in-the-loop work — goes
 * through this seam. The current implementation is a stub that simulates the
 * dispatch + progress-stream shape; the real implementation (FlowDrop, or a
 * Drupal AI flow — decision still open) swaps in by re-aliasing the service,
 * with no change to the processor.
 */
interface FlowDispatcherInterface {

  /**
   * Run a routed turn, yielding the same typed events as the agent tier.
   *
   * Implementations yield STATUS/TOKEN/TOOL_CALL/TOOL_RESULT/RESULT/ERROR
   * events but NOT DONE — {@see ChatProcessor::processTurn()} emits the DONE and
   * persists the assistant turn from the final RESULT.
   *
   * @param string $message
   *   The user's message.
   * @param string $threadId
   *   The thread the turn belongs to.
   * @param string $flow
   *   The flow id the router selected (e.g. 'flowdrop').
   * @param string|null $workflow
   *   Optional pre-validated FlowDrop workflow id the user picked for a NEW
   *   conversation. Only consulted when the thread's session doesn't exist
   *   yet — an existing session keeps its pinned workflow.
   * @param array<string, mixed> $clientContext
   *   Optional transient per-turn context from the client, keyed by the
   *   workflow input it seeds (e.g. `variables`, the studio's system-prompt
   *   template vars). Passed as a declared workflow input for nodes to read;
   *   never persisted to the conversation buffer.
   *
   * @return \Generator<\Drupal\aincient_chat\Event\ChatEvent>
   *   The turn's event stream.
   */
  public function dispatch(string $message, string $threadId, string $flow, ?string $workflow = NULL, array $clientContext = []): \Generator;

}
