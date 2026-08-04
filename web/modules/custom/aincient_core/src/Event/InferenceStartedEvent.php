<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * A model call is about to go out — dispatched BEFORE the platform is invoked.
 *
 * The one progress signal nobody else can give us. A chat turn spends most of
 * its time inside a single reasoning node (~50s is ordinary), and FlowDrop's
 * orchestrator dispatches only *completion* events — `JobCompletedEvent`, never
 * a job-started one (see StateGraphOrchestrator, which manages its own loop).
 * So from the console's side the longest part of a turn was silent: the last
 * status frame sat frozen while the request was in flight.
 *
 * We own the call site, so we own that signal. `aincient_chat` relays this onto
 * the open SSE stream as a status frame, which is why the model/provider ride
 * along: an operator debugging a slow turn wants to know WHICH model is taking
 * the minute. Purely informational — nothing may change behaviour on it, and a
 * failed dispatch must never fail a turn.
 */
final class InferenceStartedEvent extends Event {

  /**
   * The event name. Subscribers bind by this constant, as with
   * {@see UsageRecordedEvent} — the event is ours, so the name is one an IDE
   * can follow.
   */
  public const EVENT_NAME = 'aincient_core.inference_started';

  /**
   * @param string $providerId
   *   The provider serving the call (e.g. "anthropic", "openai_compatible").
   * @param string $modelId
   *   The resolved model id.
   * @param string $operationType
   *   What the call is for — an AIncient model role ("reasoning", "task", …) or
   *   an operation type, as the caller resolved it.
   * @param bool $toolAware
   *   Whether tools were declared on this call (an agent step, rather than a
   *   one-shot completion).
   */
  public function __construct(
    public readonly string $providerId,
    public readonly string $modelId,
    public readonly string $operationType,
    public readonly bool $toolAware = FALSE,
  ) {}

}
