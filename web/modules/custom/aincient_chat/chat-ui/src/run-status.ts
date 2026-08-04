import { useSyncExternalStore } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";

/**
 * Live "what is the backend doing right now" text, per thread.
 *
 * The SSE protocol streams transient `status` frames ("Getting started…",
 * "Handing this to Page Studio…") that are progress, not message content — so,
 * like the flow pin in `flow.ts`, they live in a side map keyed by backend
 * thread id. The adapter writes on every status frame and clears when the run
 * ends; the assistant message's thinking indicator reads it reactively.
 *
 * The adapter also writes the owner words for each completed piece of real work
 * ("Generated an image" — see `step-vocabulary.ts`), so this store is the ONE
 * live region that narrates a turn: the work trail beside it is visual only,
 * and nothing is announced twice. Engine-only status frames (flagged `debug`)
 * are filtered out there unless the console runs with technical detail on.
 */

const listeners = new Set<() => void>();
const statusByThread = new Map<string, string>();

function emit() {
  for (const l of listeners) l();
}

function subscribe(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

export function setRunStatus(threadId: string, message: string): void {
  if (statusByThread.get(threadId) === message) return;
  statusByThread.set(threadId, message);
  emit();
}

export function clearRunStatus(threadId: string): void {
  if (!statusByThread.delete(threadId)) return;
  emit();
}

/** The active (main) thread's live status text, reactively. */
export function useActiveThreadRunStatus(): string | undefined {
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
    () => {
      const remoteId = runtime.threads.mainItem.getState().remoteId;
      return remoteId ? statusByThread.get(remoteId) : undefined;
    },
  );
}
