/**
 * The console's chat-runtime facade — the ONLY module allowed to import from
 * `@assistant-ui/*`.
 *
 * WHY THIS EXISTS
 *
 * We measured it (2026-09-23): 26 of 130 console files touched `@assistant-ui`,
 * and 21 of those touched it once or twice — almost always just to get the
 * runtime so they could send a turn. The chrome is already 100% ours (`styles.css`
 * has zero `aui-*` classes); what the library still supplies is the STATE MACHINE:
 * the local + remote-thread-list runtimes and their history adapter (streaming
 * store, lazy per-thread load, server-owned thread list), the message repository
 * (branching), the tool-widget factory behind our 6 widgets, and Web Speech
 * dictation.
 *
 * Two things followed from that:
 *
 *   1. The 0.15 migration was owed either way, and it is far cheaper to pay in ONE
 *      module than in 26. **0.15 deletes every context hook** —
 *      `useAssistantRuntime`, `useThreadRuntime`, `useThread`, `useMessage`,
 *      `useComposer`, `useComposerRuntime`, `useThreadList`, `useThreadListItem`,
 *      `useThreadListItemRuntime` are all gone (verified against 0.15.21, which
 *      drops 23 exports in total). Every primitive, both runtime constructors,
 *      the repository, the tool-widget factory and all types survive. So this file
 *      absorbed the entire migration and no caller changed.
 *   2. Once a client pack can ship a studio (DECISIONS 0424), whatever the console
 *      exposes BECOMES our public API. If that is `useThreadRuntime`, then
 *      assistant-ui's release notes are our breaking-change policy. It must not be.
 *
 * So: our vocabulary, not theirs. Everything below is named for what the CONSOLE
 * means, and the vendor names stop here.
 *
 * HANDLES ARE OURS, PAYLOADS ARE THEIRS
 *
 * {@link ConsoleChat} and {@link ConsoleThread} are interfaces WE declare — narrow,
 * and deliberately not aliases of `AssistantRuntime`/`ThreadRuntime`, which would
 * have let the vendor's object shape through the seam untouched (it did, until
 * this rewrite: `runtime.threads.mainItem.getState()` was spelled out at thirteen
 * call sites). Message payloads are a different matter: re-typing a thread message
 * would be re-typing the library, so the payload types below are re-exported under
 * our names and are the one place vendor shapes legitimately cross.
 *
 * Both handles are memoized per client and resolve their scope lazily, so a
 * captured handle is always current and never re-triggers an effect.
 *
 * THE ONE EXEMPTION
 *
 * `App.tsx` may keep importing the layout primitives (`ThreadPrimitive.*`,
 * `MessagePrimitive.*`, `ComposerPrimitive.*`, `ActionBarPrimitive.*`,
 * `ThreadListPrimitive.*`, `AssistantRuntimeProvider`, `MarkdownTextPrimitive`)
 * directly. Those are compound-component namespaces used for markup, re-exporting
 * them would buy nothing but indirection, and `App.tsx` is ours and is never
 * plugin-facing. That exemption is deliberate, it is recorded here and enforced by
 * `seam.test.ts`, and it does not extend to anything else — App.tsx takes its
 * HOOKS from this module like everyone else.
 *
 * `seam.test.ts` fails the build if any other file imports `@assistant-ui`.
 */

import { useMemo } from "react";
import { useAui, useAuiState, type AssistantClient, type AssistantState } from "@assistant-ui/react";

/**
 * Scope types are derived from the client, never imported by name.
 *
 * `@assistant-ui/react` exports `ThreadState`, `ComposerState` and
 * `ThreadListItemState` from its LEGACY runtime api, and those are NOT the same
 * types the store scopes carry (the legacy thread state has `threadId` and
 * `metadata`; the legacy composer is thread-only where the scope's is thread-or-
 * edit). Importing them by name compiles and then lies. Reading them back off
 * `AssistantState` and the client's own accessors cannot drift, and it needs no
 * vendor type import at all.
 */
/**
 * `AssistantState` carries the scopes plus an `optional` namespace that is not one
 * — excluding it keeps `Scope` indexing only real scopes.
 */
type ScopeName = Exclude<keyof AssistantState, "optional">;
type Scope<K extends ScopeName> = ReturnType<AssistantClient[K]>;
type ThreadSnapshot = AssistantState["thread"];
type TurnSnapshot = AssistantState["message"];
type ComposerSnapshot = AssistantState["composer"];
type ThreadEntrySnapshot = AssistantState["threadListItem"];

export {
  // Tool widgets: the generative-UI envelope renders through these.
  // → memory/generative-ui-widget-envelope.md
  makeAssistantToolUI as registerToolWidget,

  // Transcript surgery — branching and the lazy page loader both rebuild a
  // thread from an array of messages.
  ExportedMessageRepository as MessageRepository,

  // Runtime construction (used only by `runtime.tsx`, which wires the console's
  // one runtime; nothing else should need these).
  useLocalRuntime as useTurnRuntime,
  useRemoteThreadListRuntime as useServerThreadListRuntime,
  InMemoryThreadListAdapter as LocalThreadListAdapter,
  WebSpeechDictationAdapter as DictationAdapter,
} from "@assistant-ui/react";

export type {
  ThreadMessageLike as ConsoleMessage,
  ChatModelAdapter as TurnAdapter,
  ChatModelRunResult as TurnResult,
  ToolCallMessagePartProps as ToolWidgetProps,
  RemoteThreadListAdapter as ThreadListSource,
  ThreadHistoryAdapter as ThreadHistorySource,
} from "@assistant-ui/react";

/**
 * A thread as the console cares about it — the sidebar row and the URL's `?thr=`.
 *
 * `remoteId` is undefined until the first turn claims a backend id; that gap is
 * real and several call sites depend on seeing it.
 */
export type ThreadEntry = Pick<ThreadEntrySnapshot, "id" | "remoteId" | "title" | "status">;

/** The sidebar list, read as a snapshot. */
export type ThreadListSnapshot = {
  readonly activeId: string;
  readonly ids: readonly string[];
  readonly archivedIds: readonly string[];
  readonly isLoading: boolean;
};

/**
 * The console's handle on the whole conversation set: which thread is open, which
 * exist, and how to move between them.
 *
 * Deliberately smaller than what the library offers. Everything here is something
 * the console actually does; widening it is a decision, not a convenience.
 */
export interface ConsoleChat {
  /** The open thread, read NOW (not reactive — pair with {@link subscribe}). */
  activeThread(): ThreadEntry;
  /** Any thread by local id; undefined when the list doesn't know it. */
  threadById(id: string): ThreadEntry | undefined;
  /** The list, read NOW. */
  threadList(): ThreadListSnapshot;
  /**
   * Fires on any change to the list OR the open thread.
   *
   * One channel, where the legacy API had two (`threads.subscribe` and
   * `threads.mainItem.subscribe`). 0.15 collapsed them and so do we: every
   * consumer here feeds `useSyncExternalStore`, which compares the snapshot and
   * bails out, so a broader signal costs extra selector calls and zero renders.
   */
  subscribe(cb: () => void): () => void;
  switchToThread(id: string): void;
  switchToNewThread(): void;
  /** Resolves once the sidebar list has loaded — the deep-link gate in url-sync. */
  listLoaded(): Promise<void>;
}

/** The console's handle on the ONE conversation it is looking at. */
export interface ConsoleThread {
  append(message: Parameters<Scope<"thread">["append"]>[0]): void;
  getState(): ThreadSnapshot;
  import(repository: Parameters<Scope<"thread">["import"]>[0]): void;
}

/** The composer, for the few controls that drive it imperatively. */
export interface ConsoleComposer {
  setText(text: string): void;
  startDictation(): void;
  stopDictation(): void;
}

/** A sidebar row's own handle — it acts on ITS thread, not the open one. */
export interface ConsoleThreadEntry {
  getState(): ThreadEntry;
  /** Claim a backend id for a thread that has not sent a turn yet. */
  initialize(): ReturnType<Scope<"threadListItem">["initialize"]>;
}

/** The whole conversation set. Stable across renders; always reads fresh. */
export function useConsoleChat(): ConsoleChat {
  const aui = useAui();
  return useMemo<ConsoleChat>(
    () => ({
      activeThread: () => aui.threads().item("main").getState(),
      threadById: (id) => aui.threads().getState().threadItems.find((t) => t.id === id),
      threadList: () => {
        const s = aui.threads().getState();
        return {
          activeId: s.mainThreadId,
          ids: s.threadIds,
          archivedIds: s.archivedThreadIds,
          isLoading: s.isLoading,
        };
      },
      subscribe: (cb) => aui.subscribe(cb),
      // Both switches swallow a failed switch, which is where console-nav used to
      // do it: the legacy runtime returned `Promise<void>` and the call site wrote
      // `.catch(() => {})`; the scope API returns `void`, so that `.catch` would
      // have silently stopped existing. `Promise.resolve(…)` still runs the sync
      // part synchronously and handles a rejection under either signature — a
      // switch to a thread that has gone must not surface as an unhandled
      // rejection, the console-nav machine already re-derives the room.
      switchToThread: (id) => {
        void Promise.resolve(aui.threads().switchToThread(id)).catch(() => {});
      },
      switchToNewThread: () => {
        void Promise.resolve(aui.threads().switchToNewThread()).catch(() => {});
      },
      listLoaded: () => aui.threads().getLoadThreadsPromise(),
    }),
    [aui],
  );
}

/** The open conversation. Stable across renders; always reads fresh. */
export function useConsoleThread(): ConsoleThread {
  const aui = useAui();
  return useMemo<ConsoleThread>(
    () => ({
      append: (message) => aui.thread().append(message),
      getState: () => aui.thread().getState(),
      import: (repository) => aui.thread().import(repository),
    }),
    [aui],
  );
}

/** The composer. Stable across renders; always reads fresh. */
export function useComposerHandle(): ConsoleComposer {
  const aui = useAui();
  return useMemo<ConsoleComposer>(
    () => ({
      setText: (text) => aui.composer().setText(text),
      startDictation: () => aui.composer().startDictation(),
      stopDictation: () => aui.composer().stopDictation(),
    }),
    [aui],
  );
}

/**
 * THIS row's handle, inside a thread-list-item context.
 *
 * `runtime.tsx` leans on it hardest: the per-thread runtime factory renders inside
 * the item context and binds both the send adapter and the history adapter to that
 * one thread's id — no shared/ambient state, so switching threads is always exact.
 */
export function useThreadListEntryHandle(): ConsoleThreadEntry {
  const aui = useAui();
  return useMemo<ConsoleThreadEntry>(
    () => ({
      getState: () => aui.threadListItem().getState(),
      initialize: () => aui.threadListItem().initialize(),
    }),
    [aui],
  );
}

/** Reactive reads. Each re-renders only when its own slice changes. */
export const useThreadState = <T,>(select: (thread: ThreadSnapshot) => T): T =>
  useAuiState((s) => select(s.thread));

export const useTurnState = <T,>(select: (turn: TurnSnapshot) => T): T =>
  useAuiState((s) => select(s.message));

export const useComposerState = <T,>(select: (composer: ComposerSnapshot) => T): T =>
  useAuiState((s) => select(s.composer));

export const useThreadListState = <T,>(select: (list: ThreadListSnapshot) => T): T =>
  useAuiState((s) =>
    select({
      activeId: s.threads.mainThreadId,
      ids: s.threads.threadIds,
      archivedIds: s.threads.archivedThreadIds,
      isLoading: s.threads.isLoading,
    }),
  );

export const useThreadListEntry = <T,>(select: (thread: ThreadEntry) => T): T =>
  useAuiState((s) => select(s.threadListItem));

/**
 * Send the user's words as a turn.
 *
 * Three widgets had this exact object literal inlined. It is the same path the
 * composer takes, so a turn sent from a card is indistinguishable from one the
 * reader typed — which is the point: the transcript stays the single record of
 * what was asked.
 *
 * Deliberately no `metadata` argument. The sites that carry one (the interrupt
 * card's hitlAction, Checks' fixAction) are marking a turn as an ACTION rather
 * than typed words, which is a different thing to say; they keep `.append` on the
 * `ConsoleThread` handle until we know what that vocabulary should be called.
 */
export function sendTurn(thread: ConsoleThread, text: string): void {
  thread.append({ role: "user", content: [{ type: "text", text }] });
}

