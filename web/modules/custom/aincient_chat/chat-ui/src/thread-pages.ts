import { useSyncExternalStore } from "react";
import {
  ExportedMessageRepository,
  useAssistantRuntime,
  type ThreadMessageLike,
  type ThreadRuntime,
} from "@assistant-ui/react";
import { fetchThreadPage, type ThreadPage } from "./adapter";

/**
 * Windowed thread history.
 *
 * Opening a thread loads only its newest PAGE_SIZE turns (`loadLatestPage`,
 * called by the history adapter); older turns stream in page by page as the
 * user scrolls to the top (`loadOlderPage`, triggered by the LoadEarlier
 * sentinel in App.tsx). Long-running operator threads accumulate hundreds of
 * turns — loading them all up front costs payload, DOM, and time-to-open.
 *
 * The side store (run-status.ts pattern) tracks each thread's window edge:
 * `oldestId` is the `before` cursor for the next page up, `hasMore` drives
 * the sentinel's visibility. Prepending works on the LIVE message list
 * (`thread.getState().messages`), so turns sent after open — and their
 * session-ephemeral parts like node trails — survive the re-import.
 */

export const PAGE_SIZE = 30;

type WindowEdge = { hasMore: boolean; oldestId?: string; loading: boolean };

const edges = new Map<string, WindowEdge>();
const listeners = new Set<() => void>();

function emit() {
  for (const l of listeners) l();
}

function subscribe(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/** Record a freshly-fetched newest window as the thread's edge state. */
export function seedWindow(threadId: string, page: ThreadPage): void {
  edges.set(threadId, { hasMore: page.hasMore, oldestId: page.oldestId, loading: false });
  emit();
}

/** The newest window of a thread, for the history adapter's initial load. */
export async function loadLatestPage(threadId: string): Promise<ThreadMessageLike[]> {
  const page = await fetchThreadPage(threadId, { limit: PAGE_SIZE });
  seedWindow(threadId, page);
  return page.messages;
}

/**
 * Prepend the next page of older turns to the live thread. Returns how many
 * messages were added (0 when nothing older exists or a load is in flight).
 *
 * `onBeforeImport` runs synchronously right before the re-import mutates the
 * thread — the moment to capture scroll-anchor state. Capturing any earlier
 * (e.g. when the load was requested) goes stale: the fetch takes time and
 * the user keeps scrolling through it.
 */
export async function loadOlderPage(
  thread: ThreadRuntime,
  threadId: string,
  onBeforeImport?: () => void,
): Promise<number> {
  const edge = edges.get(threadId);
  if (!edge || !edge.hasMore || edge.loading || !edge.oldestId) return 0;
  // Replace (don't mutate) the edge object — useSyncExternalStore snapshots
  // compare by reference, so in-place mutation wouldn't re-render.
  edges.set(threadId, { ...edge, loading: true });
  emit();
  let next: WindowEdge = { ...edge, loading: false };
  try {
    const page = await fetchThreadPage(threadId, { limit: PAGE_SIZE, before: edge.oldestId });
    next = { hasMore: page.hasMore, oldestId: page.oldestId ?? edge.oldestId, loading: false };
    if (page.messages.length === 0) return 0;
    onBeforeImport?.();
    // Prepend to the CURRENT messages (not a cached window) so live turns
    // appended since open keep their state.
    thread.import(
      ExportedMessageRepository.fromArray([
        ...page.messages,
        ...(thread.getState().messages as readonly ThreadMessageLike[]),
      ]),
    );
    return page.messages.length;
  } finally {
    edges.set(threadId, next);
    emit();
  }
}

/** The active (main) thread's window edge, reactively — drives LoadEarlier. */
export function useActiveThreadWindowEdge(): WindowEdge | undefined {
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
      return remoteId ? edges.get(remoteId) : undefined;
    },
  );
}
