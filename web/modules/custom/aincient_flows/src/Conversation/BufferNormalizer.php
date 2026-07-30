<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Conversation;

/**
 * Enforces the conversation buffer's structural invariant.
 *
 * THE BUG THIS EXISTS FOR (DECISIONS 0269). One transient upstream 503 in the
 * middle of an agent loop left a thread's buffer unterminated: the loop had
 * already appended `assistant(tool_calls)` + `tool` results, but the Reason
 * node that would have closed the turn never ran, so "Append assistant turn"
 * never ran either. The next user turn then landed directly after a tool
 * result, producing a shape providers reject — and because the failure prevents
 * the closing assistant turn again, every retry appended ANOTHER orphan user
 * message. The thread was permanently bricked and degraded with each attempt
 * (observed trailing run growing `user:21` → `user:21, user:26`).
 *
 * The invariant, stated once:
 *
 * - A `tool` result exists only inside an assistant tool-use block: it must be
 *   preceded (through an unbroken run of tool results) by an `assistant` turn
 *   whose `tool_calls` contain its id.
 * - An `assistant` turn's `tool_calls` are either all answered by the following
 *   tool run, or the turn is the buffer TAIL (a legitimate mid-loop state:
 *   calls emitted, results not appended yet).
 * - A closed tool-use block is followed by an `assistant` turn, never straight
 *   by a `user`/`system` turn.
 * - No two consecutive `user` turns.
 *
 * Two entry points, because the write and read paths want different things
 * from the buffer TAIL:
 *
 * - {@see self::closeOpenTurn()} — the WRITE path. Called before a new user
 *   turn is appended, it closes an open tool-use block at the tail so an
 *   unterminated loop is never PERSISTED. This is the actual fix.
 * - {@see self::forInference()} — the READ path. Same repairs, but it leaves an
 *   open tail alone: mid-loop, an unanswered `assistant(tool_calls)` or a
 *   trailing tool run is exactly what Reason must see to continue. This
 *   recovers threads corrupted BEFORE the write guard landed, on their next
 *   turn, without losing history and without rewriting storage.
 *
 * Repairs are structural only — no message CONTENT is invented beyond one
 * clearly-labelled note when a block has to be closed, and nothing is
 * reordered. Turns that cannot be made well-formed are dropped rather than
 * guessed at.
 *
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationAppend
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationRead
 */
final class BufferNormalizer {

  /**
   * Roles a stored conversation message may carry.
   */
  private const ALLOWED_ROLES = ['user', 'assistant', 'system', 'tool'];

  /**
   * The one synthesized message: closes a tool-use block nothing else closed.
   *
   * Honest about what happened rather than pretending the step succeeded — the
   * model reads this, and "silently continue" invites it to claim work it never
   * did.
   */
  public const INTERRUPTED_NOTE = 'The previous step was interrupted before it finished. Do not assume it completed; continue from the conversation as it stands.';

  /**
   * Normalizes a buffer for provider inference (the READ path).
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   *
   * @return array<int, array<string, mixed>>
   *   A well-formed buffer, re-indexed. An open tool-use block at the tail is
   *   preserved: that is the mid-loop state Reason continues from.
   */
  public static function forInference(array $messages): array {
    return self::normalize($messages, FALSE);
  }

  /**
   * Normalizes a buffer and closes an open tool-use block (the WRITE path).
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   *
   * @return array<int, array<string, mixed>>
   *   A well-formed buffer whose tail is a closed turn, so appending a new user
   *   message cannot produce an unterminated loop.
   */
  public static function closeOpenTurn(array $messages): array {
    return self::normalize($messages, TRUE);
  }

  /**
   * Whether a buffer already satisfies the invariant.
   *
   * Lets callers skip a write when there is nothing to repair (normalizing is
   * cheap, but persisting a no-op change is not free and muddies the trail).
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   * @param bool $closeTail
   *   Whether an open tail counts as a violation (the write path's rule).
   *
   * @return bool
   *   TRUE when normalization would change nothing.
   */
  public static function isWellFormed(array $messages, bool $closeTail = FALSE): bool {
    return self::normalize($messages, $closeTail) === array_values($messages);
  }

  /**
   * Applies every repair in order.
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   * @param bool $closeTail
   *   TRUE to also close an open tool-use block at the tail.
   *
   * @return array<int, array<string, mixed>>
   *   The repaired buffer.
   */
  private static function normalize(array $messages, bool $closeTail): array {
    $messages = self::dropUnusable($messages);
    $messages = self::dedupeToolResults($messages);
    $messages = self::pairToolBlocks($messages, $closeTail);
    return self::coalesceUserRuns($messages);
  }

  /**
   * Drops entries that could never be sent: bad shape, role or missing id.
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   *
   * @return array<int, array<string, mixed>>
   *   Only messages with an allowed role (and, for tool turns, an id).
   */
  private static function dropUnusable(array $messages): array {
    $out = [];
    foreach ($messages as $message) {
      if (!is_array($message)) {
        continue;
      }
      $role = (string) ($message['role'] ?? '');
      if (!in_array($role, self::ALLOWED_ROLES, TRUE)) {
        continue;
      }
      if ($role === 'tool' && (string) ($message['tool_call_id'] ?? '') === '') {
        continue;
      }
      $out[] = $message;
    }
    return $out;
  }

  /**
   * Drops tool turns whose tool_call_id already appeared earlier.
   *
   * A tool result is uniquely keyed by tool_call_id, so a second one is always
   * a duplicate (a wave re-fire / resume artefact). Keeping the first preserves
   * the tool_use → tool_result pairing the provider validates.
   *
   * @param array<int, array<string, mixed>> $messages
   *   The buffer.
   *
   * @return array<int, array<string, mixed>>
   *   The buffer without duplicate tool results.
   */
  private static function dedupeToolResults(array $messages): array {
    $seen = [];
    $out = [];
    foreach ($messages as $message) {
      if (($message['role'] ?? '') === 'tool') {
        $id = (string) $message['tool_call_id'];
        if (isset($seen[$id])) {
          continue;
        }
        $seen[$id] = TRUE;
      }
      $out[] = $message;
    }
    return $out;
  }

  /**
   * Rebuilds the buffer as a sequence of well-formed tool-use blocks.
   *
   * Walks the buffer, treating every `assistant` turn that carries `tool_calls`
   * plus its following run of `tool` results as one block:
   *
   * - Tool results outside a block are orphans (their tool_use is gone) and are
   *   dropped — a provider rejects them outright.
   * - Calls the following run never answered are dropped from the assistant
   *   turn, so no `tool_use` is left dangling. If that empties the turn of both
   *   calls and content, the turn goes too.
   * - A block followed by a `user`/`system` turn gets {@see
   *   self::INTERRUPTED_NOTE} between them: that gap IS the 0269 bug.
   * - At the tail, an open block is left open unless $closeTail — mid-loop that
   *   state is correct, and only the writer (about to append a user turn) knows
   *   the turn is really over.
   *
   * @param array<int, array<string, mixed>> $messages
   *   The buffer.
   * @param bool $closeTail
   *   Whether to close an open block at the tail.
   *
   * @return array<int, array<string, mixed>>
   *   The buffer with every tool-use block paired and terminated.
   */
  private static function pairToolBlocks(array $messages, bool $closeTail): array {
    $out = [];
    $count = count($messages);
    $i = 0;

    while ($i < $count) {
      $message = $messages[$i];
      $role = (string) $message['role'];

      // An orphan tool result: any tool turn reached here is outside a block,
      // because a block consumes its own results below.
      if ($role === 'tool') {
        $i++;
        continue;
      }

      // Anything that is not an assistant tool-use turn passes through.
      if ($role !== 'assistant' || empty($message['tool_calls']) || !is_array($message['tool_calls'])) {
        $out[] = $message;
        $i++;
        continue;
      }

      // Collect the block's results: the unbroken run of tool turns after it.
      $next = $i + 1;
      $results = [];
      while ($next < $count && ($messages[$next]['role'] ?? '') === 'tool') {
        $results[] = $messages[$next];
        $next++;
      }
      $atTail = $next >= $count;

      // Mid-loop tail: calls emitted, results not appended yet. Legitimate on
      // the read path; on the write path the turn is over, so the unanswered
      // calls must go.
      if ($atTail && $results === []) {
        if (!$closeTail) {
          $out[] = $message;
        }
        elseif (trim((string) ($message['content'] ?? '')) !== '') {
          unset($message['tool_calls']);
          $out[] = $message;
        }
        $i = $next;
        continue;
      }

      $answered = [];
      foreach ($results as $result) {
        $answered[(string) $result['tool_call_id']] = TRUE;
      }
      $kept = array_values(array_filter(
        $message['tool_calls'],
        static fn (mixed $call): bool => is_array($call) && isset($answered[self::callId($call)]),
      ));
      $keptIds = [];
      foreach ($kept as $call) {
        $keptIds[self::callId($call)] = TRUE;
      }

      if ($kept === []) {
        // Nothing in this block can be paired. Keep only the assistant's own
        // words, if it had any; drop the unpairable results.
        if (trim((string) ($message['content'] ?? '')) !== '') {
          unset($message['tool_calls']);
          $out[] = $message;
        }
        $i = $next;
        continue;
      }

      $message['tool_calls'] = $kept;
      $out[] = $message;
      foreach ($results as $result) {
        if (isset($keptIds[(string) $result['tool_call_id']])) {
          $out[] = $result;
        }
      }

      // Terminate the block. Mid-buffer, anything other than an assistant turn
      // next means the closing turn is missing.
      $closes = $atTail
        ? $closeTail
        : ($messages[$next]['role'] ?? '') !== 'assistant';
      if ($closes) {
        $out[] = [
          'role' => 'assistant',
          'content' => self::INTERRUPTED_NOTE,
        ];
      }

      $i = $next;
    }

    return $out;
  }

  /**
   * Merges consecutive user turns into one.
   *
   * Two user turns with no assistant turn between them is the shape the bricked
   * thread accumulated, and providers reject it. Merging keeps every word the
   * user actually wrote — which dropping would not.
   *
   * @param array<int, array<string, mixed>> $messages
   *   The buffer.
   *
   * @return array<int, array<string, mixed>>
   *   The buffer with no consecutive user turns.
   */
  private static function coalesceUserRuns(array $messages): array {
    $out = [];
    foreach ($messages as $message) {
      $previous = $out === [] ? NULL : array_key_last($out);
      if ($previous !== NULL
        && ($message['role'] ?? '') === 'user'
        && ($out[$previous]['role'] ?? '') === 'user') {
        $head = trim((string) ($out[$previous]['content'] ?? ''));
        $tail = trim((string) ($message['content'] ?? ''));
        $out[$previous]['content'] = $head === '' || $tail === ''
          ? $head . $tail
          : $head . "\n\n" . $tail;
        continue;
      }
      $out[] = $message;
    }
    return array_values($out);
  }

  /**
   * The id a tool call will be answered under.
   *
   * @param array<string, mixed> $call
   *   One entry of an assistant turn's tool_calls.
   *
   * @return string
   *   The pairing id, or '' when the call carries none (unpairable).
   */
  private static function callId(array $call): string {
    $id = (string) ($call['tool_call_id'] ?? $call['id'] ?? '');
    return $id;
  }

}
