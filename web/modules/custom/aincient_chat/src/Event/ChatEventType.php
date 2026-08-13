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
  // A transient repaint of a studio's live preview while the turn is still
  // working — NOT a transcript entry. Same payload shape as TOOL_CALL
  // (name + arguments), different meaning: the console applies it to the
  // studio's draft store and renders no card, because the authoritative
  // widget arrives at end-of-turn and is the one that persists. Sending
  // these as TOOL_CALL is what put two identical "Applied to preview"
  // cards on every brand turn: one type, two meanings, and the console
  // had no way to tell them apart (DECISIONS 0381).
  case PREVIEW = 'preview';
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
  // (relayed from aincient_core's UsageRecordedEvent). The console accumulates
  // these into a per-turn footer and a running session total.
  case USAGE = 'usage';
  // The studio named the thread after its first exchange (ThreadNamer): the
  // console renames the sidebar row live instead of waiting for the next
  // /threads refresh. Emitted at most once per thread, after DONE.
  case THREAD_TITLE = 'thread_title';
  case ERROR = 'error';
  case DONE = 'done';
}
