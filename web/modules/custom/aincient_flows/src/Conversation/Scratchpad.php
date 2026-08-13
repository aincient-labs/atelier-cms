<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Conversation;

/**
 * The address of the per-turn agent scratchpad (tier B). DECISIONS 0379.
 *
 * Agent context lives in two tiers, split by LIFETIME:
 *
 * - Tier A, the transcript: user prose + assistant prose, `scope: session`,
 *   durable. Configured on the append/read node instances as it always was.
 * - Tier B, this scratchpad: the turn's `tool_calls` and tool results, which the
 *   agent needs only until it closes its own loop.
 *
 * Why the constants live here rather than in either node: the append side
 * reaches this address through node CONFIG (a workflow points its tool-result
 * appenders at `scope: pipeline`, `key: scratchpad`), while the read side reaches
 * it in code — {@see ConversationRead} always concatenates it after the
 * transcript. Two callers, one address; a drifted copy would silently split the
 * store in half, with the agent writing where nothing reads.
 *
 * `pipeline` is the turn: one console turn is one pipeline, and it holds across
 * a HITL approve → resume request boundary, which is what lets a paused turn
 * resume with its tool traffic intact. ToolInvoke has relied on exactly this
 * address for its per-run ledger since before the tiers existed.
 *
 * The store is therefore SELF-INVALIDATING, which is the property the whole fix
 * rests on: the next turn is a new pipeline, so it resolves to a new scope id and
 * cannot see this turn's tool traffic. Last turn's scratchpad is *unreachable*,
 * not stale. No cleanup is load-bearing for correctness — an unprompted message,
 * a Stop and a stale recovery all land on a fresh address whether anything tidied
 * up or not; the TTL sweeps what they leave behind.
 *
 * The bug this closes: `update_section` on a repeatable prop must resend the
 * whole array, so a session-scoped buffer accumulated full page-state snapshots.
 * A later turn then carried two contradicting copies of the same content — the
 * stale snapshot adjacent to the question, the correct LIVE PAGE STATE further
 * away — and the model anchored on the near one, reverting user edits it had
 * never been asked to touch. See cms#29.
 */
final class Scratchpad {

  /**
   * Memory scope of the scratchpad. `pipeline` = one console turn.
   */
  public const SCOPE = 'pipeline';

  /**
   * Memory key of the scratchpad, distinct from the transcript's `conversation`.
   */
  public const KEY = 'scratchpad';

  /**
   * Backend. `entity` so a resumed turn still sees what the first segment ran.
   */
  public const BACKEND = 'entity';

  /**
   * Lifetime of a scratchpad record.
   *
   * A turn is done in seconds; a day is slack for crashed turns, whose records
   * nothing will ever read again.
   */
  public const TTL = 86400;

  /**
   * Whether a configured address IS the scratchpad's.
   *
   * Guards the one hand-edit that would break the read: pointing a read node's
   * transcript params at the scratchpad, which would otherwise concatenate the
   * turn's tool traffic onto itself.
   */
  public static function isScratchpadAddress(string $scope, string $key): bool {
    return $scope === self::SCOPE && $key === self::KEY;
  }

}
