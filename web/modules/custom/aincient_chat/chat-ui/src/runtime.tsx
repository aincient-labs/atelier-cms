import { useMemo } from "react";
import {
  useLocalRuntime,
  useRemoteThreadListRuntime,
  useThreadListItemRuntime,
  ExportedMessageRepository,
  InMemoryThreadListAdapter,
  WebSpeechDictationAdapter,
  type RemoteThreadListAdapter,
  type ThreadHistoryAdapter,
} from "@assistant-ui/react";
import {
  mockAdapter,
  makeHttpAdapter,
  fetchThreads,
  archiveThread,
  deleteThread,
  isMock,
} from "./adapter";
import { rememberThreadWorkflow } from "./flow";
import { rememberThreadActivity } from "./thread-meta";
import { rememberThreadWorkingNode } from "./thread-working-node";
import { rememberThreadSeal } from "./thread-seal";
import { loadLatestPage } from "./thread-pages";

/**
 * Voice-to-text for the composer, via the browser's native Web Speech API —
 * no model, no API key, nothing routed through our chat backend. The mic
 * button in App.tsx drives this through `composer.startDictation()`; results
 * stream into the composer text and the user reviews + sends manually.
 *
 * Built once (browser support is fixed for the session) and left undefined
 * where the API is missing (e.g. Firefox), which hides the mic button.
 */
const dictation = WebSpeechDictationAdapter.isSupported()
  ? new WebSpeechDictationAdapter({ continuous: true, interimResults: true })
  : undefined;

/**
 * Server-owned thread history for the console.
 *
 * The sidebar lists ALL of the user's threads (metadata only, one cheap query
 * via `fetchThreads`). A thread's messages load on demand — only when it's
 * opened — through the per-thread history adapter's `load()`. Sending a turn
 * goes through our SSE adapter, bound to whichever thread is active.
 *
 * Built on assistant-ui's remote-thread-list runtime. `runtimeHook` is the
 * per-thread runtime factory and renders inside the thread-list-item context,
 * so we read the active thread's id there (via `useThreadListItemRuntime`) and
 * bind BOTH the send adapter and the history adapter to that specific thread —
 * no shared/ambient state, so switching or starting a new chat is always exact.
 */

/** Mint a backend-compatible thread id (the backend accepts a provided id). */
function mintThreadId(): string {
  const raw = crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  return "thr_" + raw.replace(/-/g, "").slice(0, 16);
}

const mock = isMock();

/** The thread-list adapter: lists threads and mints ids for new ones. */
const threadListAdapter: RemoteThreadListAdapter = Object.assign(new InMemoryThreadListAdapter(), {
  // The sidebar list — lightweight metadata for every thread.
  list: async () => {
    if (mock) return { threads: [] };
    const threads = await fetchThreads();
    // Server truth for each thread's pinned flow (caption + picker state)
    // and last activity (sidebar grouping + relative times).
    for (const t of threads) {
      if (t.workflow) rememberThreadWorkflow(t.remoteId, t.workflow);
      if (t.lastActivity) rememberThreadActivity(t.remoteId, t.lastActivity);
      if (t.workingNode) rememberThreadWorkingNode(t.remoteId, t.workingNode);
      if (typeof t.locked === "boolean") {
        rememberThreadSeal(t.remoteId, t.locked, t.published ?? undefined);
      }
    }
    return {
      threads: threads.map((t) => ({
        status: t.status === "archived" ? ("archived" as const) : ("regular" as const),
        remoteId: t.remoteId,
        externalId: t.remoteId,
        title: t.title,
      })),
    };
  },
  // A new thread mints its backend id up front; the backend honours it.
  initialize: async () => {
    const remoteId = mintThreadId();
    return { remoteId, externalId: remoteId };
  },
  fetch: async (threadId: string) => ({
    status: "regular" as const,
    remoteId: threadId,
    externalId: threadId,
    title: undefined,
  }),
  archive: async (threadId: string) => {
    if (!mock) await archiveThread(threadId, true);
  },
  unarchive: async (threadId: string) => {
    if (!mock) await archiveThread(threadId, false);
  },
  delete: async (threadId: string) => {
    if (!mock) await deleteThread(threadId);
  },
});

/**
 * Per-thread runtime: resolve THIS thread's backend id from its list-item, then
 * bind the SSE send adapter and the lazy history adapter to it.
 */
function useThreadRuntime() {
  const item = useThreadListItemRuntime();

  const resolveThreadId = async (): Promise<string> => {
    const state = item.getState();
    if (state.remoteId) return state.remoteId;
    const { remoteId } = await item.initialize();
    return remoteId;
  };

  const sendAdapter = useMemo(
    () => (mock ? mockAdapter : makeHttpAdapter(resolveThreadId)),
    [item],
  );

  const history = useMemo<ThreadHistoryAdapter>(
    () => ({
      // Lazy AND windowed: only the opened thread's newest page is fetched;
      // older turns load on demand as the user scrolls up (thread-pages.ts).
      load: async () => {
        const state = item.getState();
        if (mock || !state.remoteId) return { messages: [] };
        const messages = await loadLatestPage(state.remoteId);
        return ExportedMessageRepository.fromArray(messages);
      },
      // The server persists turns during the POST; nothing to do here.
      append: async () => {},
    }),
    [item],
  );

  return useLocalRuntime(sendAdapter, { adapters: { history, dictation } });
}

export function useAincientRuntime() {
  return useRemoteThreadListRuntime({
    runtimeHook: useThreadRuntime,
    adapter: threadListAdapter,
    allowNesting: true,
  });
}
