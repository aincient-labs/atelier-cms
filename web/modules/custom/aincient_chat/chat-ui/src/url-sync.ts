import { useEffect } from "react";
import type { AssistantRuntime } from "@assistant-ui/react";
import { activeRoom, consoleNav, deriveRoomFromStores } from "./console-nav";
import { parseUrl, roomToUrl } from "./console-url";
import { startNewBlock, startNewPage } from "./page-state";
import { roomOfThread } from "./rooms";
import { roomId, sameRoom, type Room } from "./rooms-core";
import type { StudioKey } from "./studios";

/**
 * URL ⇄ console-machine binding (plans/console-state-machine.md Phase 2, D3).
 *
 * The console statechart (`console-machine.ts` via `console-nav.ts`) is the single
 * source of truth; the URL is a pure PROJECTION of its `room` + active thread, and
 * an INPUT only on load / back-forward. This module is the seam:
 *
 *  - **State → URL:** subscribe to the machine (room changes) and the runtime
 *    (thread / first-send remote-id) and mirror `roomToUrl(room, thread)` into the
 *    address bar. A room change is a navigation (pushState); a same-room, same-
 *    thread change (first-send URL claim, doc settle, title) is a replaceState.
 *  - **URL → state on load:** once the thread list is in, `parseUrl()` the deep
 *    link and drive the machine to that room via `enterRoom` (which derives the
 *    studio + loads the doc). Then canonicalise the URL in place and start observing.
 *  - **URL → state on popstate:** `parseUrl()` → `consoleNav.popstate(room, thread)`
 *    (the machine's POPSTATE skips the dirty guard — the browser already navigated).
 *
 * The room owns the path and the open document IS the room (a Content page/block
 * or a Checks audit), so there is no `?page=/?block=/?audit=` query — only `?thr=`.
 * The old three hooks + `clearOpenDoc` reconciler and the store-derived URL are
 * gone: the machine owns the doc across a switch (its `beginDocLoad` /
 * `closeDocToListing` / `setAuditNode` side effects), and `console-url` owns the
 * codec. The pure helpers (`consoleBase`/`consoleHref`/`opensNewTab`) live in
 * `console-url`; import them from there.
 */

/**
 * Open a console document IN PLACE — the programmatic entry for a link clicked
 * AFTER mount (an `open_page` row in a chat table). The open document is the room,
 * so this ENTERS that room through the machine (Content page node, or a Checks
 * audit room), which derives the studio + loads the doc and reflects the URL. A
 * fresh thread lands in the room; modifier / middle clicks never reach here (the
 * anchor opens the durable URL in a new tab natively).
 */
export function openPageInPlace(studio: StudioKey | undefined, node: string): void {
  const nid = Number(node);
  if (!Number.isInteger(nid) || nid <= 0) return;
  const room: Room =
    studio === "checks"
      ? { kind: "audit", nid }
      : { kind: "node", doc: "page", nid, langcode: null };
  if (sameRoom(activeRoom(), room)) return;
  consoleNav.enterRoom(room);
}

/**
 * The one hook the shell mounts: bind the URL to the console machine. Replaces the
 * former useUrlStudioSync / useUrlPageSync / useUrlThreadSync trio.
 */
export function useConsoleUrl(runtime: AssistantRuntime) {
  useEffect(() => {
    const threads = runtime.threads;
    let disposed = false;
    // Set while WE drive a transition from history (deep link on load / popstate)
    // so the projection doesn't echo the URL the browser already shows back into
    // history as a duplicate entry.
    let applyingHistory = false;
    // The last room the projection saw — a ROOM change is a navigation
    // (pushState); everything else (the thread arriving after the async runtime
    // switch, the first-send remote-id claim, a doc settle, a title) rewrites the
    // current entry in place (replaceState). Keying push on the room (not the
    // runtime thread) is deliberate: the runtime switch is async and lags the
    // machine's room transition, so a thread-keyed push would (a) briefly stamp
    // the OLD thread's ?thr= onto the new room's path and (b) add a second history
    // entry when the switch finally lands. Same-room thread switches replace, so
    // Back moves between rooms, not between threads within a room.
    let lastRoomId = roomId(consoleNav.room());
    let unsubRoom: (() => void) | undefined;
    let unsubThreads: (() => void) | undefined;

    /** The list knows this id (regular or archived)? Deleted/foreign ids don't. */
    const isKnown = (id: string) => {
      const s = threads.getState();
      return s.threadIds.includes(id) || s.archivedThreadIds.includes(id);
    };

    /** The active thread's backend id, or null while it's still fresh/unsaved. */
    const activeThreadId = (): string | null => {
      const s = threads.getState();
      return s.threadItems[s.mainThreadId]?.remoteId ?? null;
    };

    /**
     * Parse the URL into { room, threadId }, resolving the one ambiguous case:
     * a `list` path that names a known thread. The List never homes a thread, so
     * `/content?thr=X` means X belongs elsewhere — its real room comes from thread
     * METADATA (roomOfThread), not from loading its draft. Keeps resolution
     * explicit and canonicalises legacy `?thr=` links to their room's own path.
     */
    const resolveUrl = (): { room: Room; threadId: string | null } => {
      const parsed = parseUrl();
      const room =
        parsed.room.kind === "list" && parsed.threadId && isKnown(parsed.threadId)
          ? roomOfThread(parsed.threadId)
          : parsed.room;
      return { room, threadId: parsed.threadId };
    };

    const syncTitle = () => {
      const s = threads.getState();
      const title = s.threadItems[s.mainThreadId]?.title;
      document.title = title ? `${title} — Atelier` : "Atelier";
    };

    // State → URL. A room change → pushState (navigation, backable); a thread
    // switch (mainThreadId change) → pushState too; everything else in place →
    // replaceState (the first-send claim on a fresh thread keeps its mainThreadId,
    // so it replaces — no /aincient/content → …?thr=x history pair).
    const project = () => {
      syncTitle();
      const room = consoleNav.room();
      if (applyingHistory) {
        lastRoomId = roomId(room);
        return;
      }
      const desired = roomToUrl(room, activeThreadId());
      if (desired !== window.location.pathname + window.location.search) {
        const navigated = roomId(room) !== lastRoomId;
        history[navigated ? "pushState" : "replaceState"](null, "", desired);
      }
      lastRoomId = roomId(room);
    };

    // URL → state on back/forward. The browser moved the address bar; re-derive
    // the room + thread from it and drive the machine (POPSTATE skips the dirty
    // guard). Suppress the projection for the transition's synchronous switch so
    // it doesn't push the same URL back; the async doc settle a tick later
    // projects to a no-op (the URL already matches).
    const onPopState = () => {
      const { room, threadId } = resolveUrl();
      const landing = threadId && isKnown(threadId) ? threadId : null;
      applyingHistory = true;
      consoleNav.popstate(room, landing);
      setTimeout(() => {
        if (!disposed) applyingHistory = false;
      }, 0);
    };

    // Resolve the deep link only once the sidebar list is in (it's the source of
    // truth for which ids exist — switching to an unknown id would fabricate an
    // entry via our stub fetch()). Drive the machine to the URL's room, then
    // canonicalise in place (no history entry) and start observing.
    threads.getLoadThreadsPromise().then(() => {
      if (disposed) return;
      const { room, threadId } = resolveUrl();
      const landing = threadId && isKnown(threadId) ? threadId : null;
      // enterRoom sets context.room synchronously (studio/doc derive over the next
      // tick); if the URL names the boot room it's swallowed. Either way the room
      // is correct immediately, so we can canonicalise on the next tick.
      consoleNav.enterRoom(room, landing);
      // A one-shot creation intent (the reference field's "New block" new-tab, or
      // a "New page" link) seeds a fresh, node-less draft in the just-entered
      // Content room. Consumed AFTER the room settles (commitSwitch has already
      // closed any prior doc) so it isn't wiped; the ?new= query is then dropped.
      const create = new URLSearchParams(window.location.search).get("new");
      setTimeout(() => {
        if (disposed) return;
        if (create === "block") startNewBlock();
        else if (create === "page") startNewPage();
        // A node-less draft lives in the draft room — adopt the room the stores
        // now imply (thread-addressed) so the machine + canonical URL land on
        // /content/draft[/<thread>] instead of the listing's /content.
        if (create === "block" || create === "page") consoleNav.adoptRoom(deriveRoomFromStores());
        lastRoomId = roomId(consoleNav.room());
        const desired = roomToUrl(consoleNav.room(), activeThreadId());
        if (desired !== window.location.pathname + window.location.search) {
          history.replaceState(null, "", desired);
        }
        syncTitle();
        unsubRoom = consoleNav.subscribe(project);
        unsubThreads = threads.subscribe(project);
        window.addEventListener("popstate", onPopState);
      }, 0);
    });

    return () => {
      disposed = true;
      unsubRoom?.();
      unsubThreads?.();
      window.removeEventListener("popstate", onPopState);
    };
  }, [runtime]);
}
