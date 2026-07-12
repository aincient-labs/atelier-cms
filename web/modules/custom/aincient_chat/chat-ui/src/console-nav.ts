import type { AssistantRuntime } from "@assistant-ui/react";
import { createConsoleNav, type ConsoleActions } from "./console-machine";
import { activeStudioKey, setActiveStudio } from "./flow";
import {
  closeDocToListing,
  DocLoadError,
  getAuthoringNew,
  getPageDraft,
  getPageKind,
  getPageLang,
  getPageNode,
  loadBlockIntoStudio,
  loadPageIntoStudio,
} from "./page-state";
import { getAuditNode, setAuditNode as setAuditNodeStore } from "./audit-state";
import { closeMedia, getMediaId, loadMediaIntoStudio } from "./media-state";
import { lockState, subscribeLock } from "./page-lock";
import { clearDocEnd, setDocEnd } from "./doc-end-state";
import { AUDIT_STUDIO, COLLECTION_STUDIO, MEDIA_STUDIO, type Room } from "./rooms-core";

/**
 * The console-navigation singleton — the shell's live binding of the statechart
 * (`console-machine.ts`) to the running app (plan `console-state-machine.md`).
 * One actor per SPA mount; `Room` is the single source of truth.
 *
 * This module is the seam between the pure, testable machine and the existing
 * stores:
 *
 *  - The machine's side effects ({@link ConsoleActions}) are bound to the LIVE
 *    stores + runtime here — `commitThreadSwitch` drives the assistant-ui
 *    runtime, `setActiveStudio` / `beginDocLoad` / `closeDocToListing` drive the
 *    flow + page-state stores. Tests bind spies instead (see the machine's
 *    harness), so the machine stays runtime-free.
 *  - `THREAD_SETTLED` is fired one tick AFTER the synchronous thread switch, so
 *    the studio/doc derivation (`deriveStudio`, `beginLoad`) lands off the
 *    switch's React batch — the `emitNextTick` console-crash rule (§1) lives
 *    here now, not scattered across initiators.
 *  - {@link activeRoom} reads `context.room`, so every UI region projects from
 *    the machine (plan §3). {@link subscribeRoom}/{@link roomVersion} let
 *    `useSyncExternalStore` re-render on room changes.
 *  - The editor lock (page-lock) is REFLECTED into the machine's `lock` region
 *    here ({@link reflectLock} over `subscribeLock`), so the composer mode + the
 *    INV‑5 invariant read one source of truth. Acquire/release still live in
 *    page-state's load/close (the machine drives those), so the sessionStorage
 *    lock-handover is untouched.
 *
 * Phase 4 retired the automatic store-watching reconcile bridge: every writer
 * that changes the room now routes through the machine explicitly —
 * `consoleNav.enterRoom` for a true navigation (switches the thread), or
 * `consoleNav.adoptRoom` for an out-of-band change that must keep the current
 * conversation (an agent preview tool, a language reload, first publish).
 */

/** The runtime, bound by the shell on mount (the machine drives thread switches
 *  through it). Null until {@link bindRuntime} runs — no ENTER_ROOM can arrive
 *  before then (they're all user-driven, post-mount). */
let runtime: AssistantRuntime | null = null;

/** The HTTP status of a doc-load failure, else 0 (transient). */
function loadStatus(e: unknown): number {
  return e instanceof DocLoadError ? e.status : 0;
}

/** The active thread's backend id, or null (fresh/unsent thread, or pre-bind). */
function currentThreadId(): string | null {
  try {
    return runtime?.threads.mainItem.getState().remoteId ?? null;
  } catch {
    return null;
  }
}

/**
 * The room implied by the legacy stores — the pre-machine `activeRoom()` rule
 * (studio + open page). The boot-time seed AND what the reconcile bridge adopts.
 */
export function deriveRoomFromStores(): Room {
  const studio = activeStudioKey();
  // Checks: the audited node (if one is picked) is an audit room, else the studio.
  if (studio === AUDIT_STUDIO) {
    const audit = getAuditNode();
    return audit ? { kind: "audit", nid: Number(audit) } : { kind: "studio", studio };
  }
  // Media: an open item is a media room; nothing open is the family's home —
  // the Library SHELF (0168: the bare media studio room retired with the
  // `library` registry studio; the shelf IS the section with no item open).
  if (studio === MEDIA_STUDIO) {
    const id = getMediaId();
    return id ? { kind: "media", id: Number(id) } : { kind: "shelf" };
  }
  if (studio !== COLLECTION_STUDIO) return { kind: "studio", studio };
  const node = getPageNode();
  // No node open: an in-flight New-page draft → the draft room; otherwise the
  // List (directory). "In-flight" = an explicit New (authoringNew) OR a draft
  // that already carries composed sections (the agent built a page without a
  // node yet — it doesn't set authoringNew, so we detect content). Either way it
  // gets /content/new and stays off the listing's URL.
  if (!node) {
    const composing = getAuthoringNew() || (getPageDraft()?.sections?.length ?? 0) > 0;
    if (!composing) return { kind: "list" };
    const thread = currentThreadId();
    return thread ? { kind: "draft", thread } : { kind: "draft" };
  }
  const doc = getPageKind() === "block" ? "block" : "page";
  const title = getPageDraft()?.title;
  return { kind: "node", doc, nid: Number(node), langcode: getPageLang(), ...(title ? { title } : {}) };
}

/** Room-change fan-out for `useSyncExternalStore` (the room tree + projections). */
const roomListeners = new Set<() => void>();
let roomV = 0;
function bumpRoom() {
  roomV += 1;
  for (const l of roomListeners) l();
}
export function subscribeRoom(cb: () => void): () => void {
  roomListeners.add(cb);
  return () => roomListeners.delete(cb);
}
export function roomVersion(): number {
  return roomV;
}

/**
 * The live navigation singleton. Boot-time studio derivation: the initial room
 * is read from the stores (whatever the server default / SSR seed left), and the
 * studio it implies already matches — deep links resolve a tick later in effects
 * and are adopted by the reconcile bridge.
 */
export const consoleNav = createConsoleNav(
  {
    commitThreadSwitch: (_room, threadId) => {
      // Starting a navigation clears any standing deep-link dead-end; a fresh
      // node load re-sets it on failure (beginDocLoad below). Replaces the old
      // url-sync clearOpenDoc, which wiped the dead-end on every thread change.
      clearDocEnd();
      if (!runtime) return;
      const target = threadId
        ? runtime.threads.switchToThread(threadId)
        : runtime.threads.switchToNewThread();
      void target.catch(() => {});
      // Defer the settle one tick so the studio/doc derivation runs OFF the
      // switch's React batch (the emitNextTick console-crash rule, §1). The 4b
      // gate PROVED this deferral is load-bearing: a synchronous settle throws
      // `tapClientLookup: Index N out of bounds` on a real preview-applier widget
      // (DECISIONS 0123). Do NOT collapse this into a synchronous transition.
      setTimeout(() => consoleNav.threadSettled(), 0);
    },
    setActiveStudio: (studio) => setActiveStudio(studio),
    beginDocLoad: (room) => {
      // Only reached for a doc-bearing room (docIdentity non-null) — a media room
      // here always carries an id (the id-less "new image" room never loads).
      const mediaId = room.kind === "media" ? (room.id as number) : 0;
      const load =
        room.kind === "media"
          ? loadMediaIntoStudio(mediaId)
          : room.doc === "block"
            ? loadBlockIntoStudio(String(room.nid))
            : loadPageIntoStudio(String(room.nid), room.langcode);
      const docKind = room.kind === "media" ? "media" : room.doc;
      const id = room.kind === "media" ? String(mediaId) : String(room.nid);
      load
        .then(() => consoleNav.docLoaded())
        .catch((e: unknown) => {
          // A fatal load (403/404) becomes the dead-end pane (App reads
          // doc-end-state); the machine also lands in `deadEnd` INSIDE this room
          // (D4), so the URL stays on the node/media path and a refresh re-checks.
          const status = loadStatus(e);
          if (status === 403) setDocEnd({ kind: "denied", docKind, id });
          else if (status === 404) setDocEnd({ kind: "gone", docKind, id });
          consoleNav.docFailed(status);
        });
    },
    closeDocToListing: () => closeDocToListing(),
    closeMediaDoc: () => closeMedia(),
    setAuditNode: (nid) => setAuditNodeStore(nid === null ? null : String(nid)),
  },
  { room: deriveRoomFromStores() },
).start();

// Any machine transition that moves the room re-renders the room-aware views.
consoleNav.subscribe(bumpRoom);

/**
 * Reflect the editor lock (page-lock) into the machine's `lock` region so the
 * composer mode + INV‑5 read one truth. page-lock's acquire/release live inside
 * page-state's load/close (which the machine drives), so here we only MIRROR the
 * resulting state: each settled page-lock state maps to a single event that
 * reaches the matching region state from anywhere (see the lock region's edges).
 * A silent takeover (token dropped, another holder, no 409 on our write) maps to
 * `elsewhere` — the `mine → elsewhere` edge the region gained in Phase 4 (M2).
 */
function reflectLock(): void {
  const st = lockState();
  if (st.node !== null && st.token !== null) consoleNav.actor.send({ type: "LOCK_ACQUIRED", token: st.token });
  else if (st.node !== null && st.holder !== null) consoleNav.actor.send({ type: "LOCK_HELD_OTHER", holder: st.holder.name });
  else consoleNav.actor.send({ type: "LOCK_RELEASE" });
}
subscribeLock(reflectLock);
reflectLock();

/** Bind the assistant-ui runtime the machine drives thread switches through. */
export function bindRuntime(rt: AssistantRuntime): void {
  runtime = rt;
}

/**
 * The room the console is in — `context.room`, the single source of truth (plan
 * §3). A node room missing its title (e.g. seeded before the draft loaded) is
 * enriched from the live draft so labels read nicely.
 */
export function activeRoom(): Room {
  const room = consoleNav.room();
  if (room.kind === "node" && !room.title) {
    const title = getPageDraft()?.title;
    if (title) return { ...room, title };
  }
  return room;
}
