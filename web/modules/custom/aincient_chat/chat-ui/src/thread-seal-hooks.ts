/**
 * React bindings for the per-thread SEAL store (`thread-seal.ts`).
 *
 * The seal store knows *which threads* are wrapped up; it has no concept of
 * which thread is ACTIVE, and it emits only on seal writes. So "is the thread
 * I'm looking at sealed?" has TWO inputs — the active thread's backend id and
 * the seal store — and any consumer that subscribes to only one of them goes
 * stale on the other.
 *
 * That is a staleness *class*, not a single bug: caching the verdict in
 * `useState` fed by a `subscribeSeals` effect keeps a `true` under a brand-new,
 * unsealed thread on EVERY way of leaving a sealed thread (wrap-up commit,
 * sidebar click, `?thr=` deep link, archive-then-switch). The fix is to never
 * cache it: derive it from both inputs on each render, so switching threads
 * re-derives by construction.
 *
 * `useSyncExternalStore` is called without a `getServerSnapshot` here, matching
 * every other store binding in this bundle — the console is a client-only island
 * mounted from Drupal, never server-rendered.
 */

import { useMemo, useSyncExternalStore } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { isThreadSealed, sealVersion, subscribeSeals } from "./thread-seal";

/**
 * The active (main) thread's backend id, reactively — `""` for a fresh/unsent
 * thread that has no server-side session yet. Subscribes to the runtime's main
 * thread item, so a thread switch re-renders the caller.
 */
export function useThreadRemoteId(): string {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().remoteId ?? "",
  );
}

/** The seal store's monotonic version — a stable snapshot to memoize on. */
export function useSealVersion(): number {
  return useSyncExternalStore(subscribeSeals, sealVersion);
}

/**
 * Whether a specific thread is wrapped up, derived (never cached) from the seal
 * store. Pass the id of the thread you're rendering; a row renders its own
 * thread, so it feeds its own id in.
 */
export function useThreadSealed(threadId: string | undefined): boolean {
  const sealVer = useSealVersion();
  return useMemo(() => isThreadSealed(threadId), [threadId, sealVer]);
}

/**
 * Whether the ACTIVE conversation is wrapped up — the read-only axis the
 * studios and the composer key off. Two inputs, one derivation, no local state.
 */
export function useActiveThreadSealed(): boolean {
  return useThreadSealed(useThreadRemoteId());
}
