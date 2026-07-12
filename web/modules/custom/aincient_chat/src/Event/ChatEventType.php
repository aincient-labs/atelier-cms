<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Event;

/**
 * The typed event protocol streamed to the assistant-ui console (topology §4a).
 *
 * Fast agent turns and long-running flows emit the SAME event types, so the UI
 * renders them uniformly.
 */
enum ChatEventType: string {
  case STATUS = 'status';
  case TOKEN = 'token';
  case TOOL_CALL = 'tool_call';
  case TOOL_RESULT = 'tool_result';
  // One workflow node finished executing (FlowDrop's JobCompletedEvent,
  // relayed mid-run by NodeProgressSubscriber). Carries the node's
  // label/type/status so the console can render a live execution trail
  // while the turn is still working.
  case NODE = 'node';
  // A human-in-the-loop pause: the backend flow is waiting on user input
  // (e.g. a FlowDrop ChoiceNode). Carries the interrupt id + a JSON-Schema
  // describing the choices so the console can render a widget and resolve it.
  case INTERRUPT = 'interrupt';
  case RESULT = 'result';
  // Token usage + estimated cost for one metered AI call within the turn
  // (relayed from ai_metering's record-created event). The console accumulates
  // these into a per-turn footer and a running session total.
  case USAGE = 'usage';
  // The studio named the thread after its first exchange (ThreadNamer): the
  // console renames the sidebar row live instead of waiting for the next
  // /threads refresh. Emitted at most once per thread, after DONE.
  case THREAD_TITLE = 'thread_title';
  case ERROR = 'error';
  case DONE = 'done';
}
