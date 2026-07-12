import { useSyncExternalStore } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";

/**
 * Running AI token-usage + cost total for a conversation, per thread.
 *
 * The SSE protocol streams a `usage` frame per metered AI call (relayed from
 * ai_metering; see UsageStreamSubscriber). Each turn's footer sums its own
 * frames locally, but the SESSION total has to outlive a single run — so, like
 * the run-status text and the flow pin, it lives in a side map keyed by backend
 * thread id. The adapter adds each frame here; a chip near the composer reads
 * it reactively.
 *
 * Live, in-session only: this accumulates across the turns you run in this tab
 * and resets on reload (it is not re-hydrated from the server — the per-turn
 * trail is live-only too). The authoritative lifetime total lives in
 * ai_metering's own dashboard.
 */

/** A token + cost tally. `cost` is summed only from calls that reported one. */
export type UsageTotal = {
  input: number;
  output: number;
  cached: number;
  /** Estimated USD cost. 0 when no metered call had pricing (then hide $). */
  cost: number;
  /** Whether any call contributed a non-zero cost (drives "show $?"). */
  hasCost: boolean;
  /** Number of metered AI calls folded in. */
  calls: number;
};

/** One metered call as it arrives off the stream. */
export type UsageDelta = {
  input: number;
  output: number;
  cached: number;
  cost: number;
};

export const EMPTY_USAGE: UsageTotal = {
  input: 0,
  output: 0,
  cached: 0,
  cost: 0,
  hasCost: false,
  calls: 0,
};

/** Fold one call's usage into a running total (pure). */
export function addUsage(total: UsageTotal, d: UsageDelta): UsageTotal {
  return {
    input: total.input + d.input,
    output: total.output + d.output,
    cached: total.cached + d.cached,
    cost: total.cost + d.cost,
    hasCost: total.hasCost || d.cost > 0,
    calls: total.calls + 1,
  };
}

const listeners = new Set<() => void>();
const usageByThread = new Map<string, UsageTotal>();

function emit() {
  for (const l of listeners) l();
}

function subscribe(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/** Add one metered call to a thread's running session total. */
export function addSessionUsage(threadId: string, d: UsageDelta): void {
  usageByThread.set(threadId, addUsage(usageByThread.get(threadId) ?? EMPTY_USAGE, d));
  emit();
}

/** A thread's running session total (EMPTY_USAGE until the first call). */
export function sessionUsage(threadId: string | undefined): UsageTotal {
  return (threadId && usageByThread.get(threadId)) || EMPTY_USAGE;
}

/** The active (main) thread's running session usage, reactively. */
export function useActiveThreadUsage(): UsageTotal {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => {
      const unsubStore = subscribe(cb);
      const unsubItem = runtime.threads.mainItem.subscribe(cb);
      return () => {
        unsubStore();
        unsubItem();
      };
    },
    () => sessionUsage(runtime.threads.mainItem.getState().remoteId),
  );
}
