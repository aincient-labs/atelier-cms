import { assign, createActor, setup, type Actor, type SnapshotFrom } from "xstate";
import { roomStudio, sameRoom, type Room } from "./rooms-core";
import type { StudioKey } from "./studios";

/**
 * The console navigation statechart (plans/console-state-machine.md §2).
 *
 * Room is the single source of truth: `ENTER_ROOM(room)` sets `context.room`
 * first, then the studio `S` and document `D` are DERIVED from it inside the same
 * transition sequence (`switching` → `settling` → `loadingDoc`/`idle`). No
 * reachable state has `roomStudio(room)` disagreeing with the active studio, or a
 * loaded document whose identity differs from `room` — the invariants INV‑1/2/3
 * (docs/console-state-model.md §6) hold by construction. A failed node load lands
 * in `deadEnd` INSIDE the target room (D4), never silently falling elsewhere.
 *
 * Phase 1 stands this up as the navigation authority ALONGSIDE today's stores:
 * the side-effecting steps (switch the runtime thread, set the active studio,
 * load the page, close the doc) are injected as {@link ConsoleActions} so the
 * shell wires them to the existing `runtime.threads.*` / `setActiveStudio` /
 * `loadPageIntoStudio` / `closeDocToListing`, while tests inject spies and drive
 * the machine synchronously. The runtime reports back via `THREAD_SETTLED` and
 * the async loader via `DOC_LOADED` / `DOC_FAILED(status)`.
 *
 * The `settling` transient absorbs the `emitNextTick` deferred-derive gap (§1);
 * the Phase 0 spike leaves it as the safe default (collapsible in Phase 4).
 */

/** The open-document `D`, derived from the room and the load outcome. */
export type DocState =
  | { status: "none" }
  | { status: "authoring" }
  | { status: "loading"; nid: number; langcode: string | null }
  | { status: "loaded"; nid: number; langcode: string | null }
  | { status: "deadEnd"; reason: "denied" | "gone"; nid: number; langcode: string | null };

export type ConsoleContext = {
  /** PRIMARY — the room we are in. `S` and `D` are derived from it. */
  room: Room;
  /** The active thread within the room (null = fresh, unsent). */
  threadId: string | null;
  /** Derived from the room: none | authoring | loading | loaded | deadEnd. */
  document: DocState;
  /** Open draft has unsaved edits (guards ENTER_ROOM through confirmDiscard). */
  dirty: boolean;
  /** Target stashed while a discard-confirm is open (committed on DISCARD). */
  pending: { room: Room; threadId: string | null } | null;
};

export type ConsoleInput = { room: Room; threadId?: string | null };

export type ConsoleEvent =
  /** The one navigation verb — enter a room, landing on its thread (or fresh). */
  | { type: "ENTER_ROOM"; room: Room; threadId?: string | null }
  /**
   * Switch to another thread WITHIN the current room (or start a fresh one when
   * `threadId` is null) — the sidebar thread-list row and the agent-picker's
   * "new chat". The room / studio / open document are unchanged, so this only
   * commits the runtime thread switch; no studio or doc re-derivation runs.
   */
  | { type: "SWITCH_THREAD"; threadId: string | null }
  /**
   * Adopt a room whose studio/document a writer OUTSIDE the machine already
   * changed, WITHOUT re-running the side effects (no thread switch, no doc
   * load/close — they already happened). The out-of-band writers call this
   * explicitly (Phase 4): an agent preview tool that yanked the studio mid-chat
   * (page/brand/chrome), a language reload, or a first Publish minting a node.
   * This replaces the Phase-1 automatic store-watching bridge — it is only ever
   * dispatched by an explicit `consoleNav.adoptRoom(...)` call, never auto-fired.
   */
  | { type: "ADOPT_ROOM"; room: Room; threadId?: string | null }
  /** Confirm/cancel the discard of an unsaved draft (dirty ENTER_ROOM). */
  | { type: "DISCARD" }
  | { type: "CANCEL" }
  /** The runtime reports the synchronous thread switch has committed. */
  | { type: "THREAD_SETTLED" }
  /** The async node loader resolved / failed (status = HTTP code). */
  | { type: "DOC_LOADED" }
  | { type: "DOC_FAILED"; status: number }
  /** Publish / Finish → seal + auto-archive + fresh thread in the same room (D8). */
  | { type: "SEAL" }
  /** Point-sampled dirty verdict from page-dirty (mirror in context). */
  | { type: "SET_DIRTY"; dirty: boolean }
  /** Browser back/forward — the resolved room from the URL (bypasses the guard). */
  | { type: "POPSTATE"; room: Room; threadId?: string | null }
  /** Editor-lock (pen) region — wired + tested in Phase 4 (INV‑5). */
  | { type: "LOCK_ACQUIRING" }
  | { type: "LOCK_ACQUIRED"; token: string }
  | { type: "LOCK_HELD_OTHER"; holder?: string }
  | { type: "LOCK_WRITE_409" }
  | { type: "LOCK_TAKE_OVER" }
  | { type: "LOCK_RELEASE" };

/**
 * The side effects the machine drives — injected so the shell binds them to the
 * live stores and tests bind spies. Every one is a no-op by default, so the bare
 * machine is inert and safe to model-check.
 */
export type ConsoleActions = {
  /** Switch the runtime to `threadId` (or start a fresh thread when null). */
  commitThreadSwitch: (room: Room, threadId: string | null) => void;
  /** Move the workspace to a studio (idempotent). */
  setActiveStudio: (studio: StudioKey) => void;
  /** Begin the async doc load for a page/block node OR a media item; must send
   *  back DOC_LOADED / DOC_FAILED. Routes page vs block by `room.doc`, and media
   *  by kind. */
  beginDocLoad: (room: Extract<Room, { kind: "node" | "media" }>) => void;
  /** Drop the open document back to the Content list. */
  closeDocToListing: () => void;
  /** Close the open MEDIA item (the media family's close-on-leave). Separate
   *  from `closeDocToListing` because the two doc families live in different
   *  stores (page-state vs media-state) and a navigation must close each family
   *  the target room doesn't own — a stale media item left open renders the
   *  image over the Library shelf's ledger. */
  closeMediaDoc: () => void;
  /** Set (or clear, with null) the Checks audit node — synchronous, no load. */
  setAuditNode: (nid: number | null) => void;
};

const NOOP_ACTIONS: ConsoleActions = {
  commitThreadSwitch: () => {},
  setActiveStudio: () => {},
  beginDocLoad: () => {},
  closeDocToListing: () => {},
  closeMediaDoc: () => {},
  setAuditNode: () => {},
};

/**
 * The (nid, langcode) a DOC-loadable room carries, or NULL for a room that holds
 * no async document. The two doc-loadable kinds are a Content `node` (page/block,
 * translatable) and a `media` studio item (id, language-neutral) — both flow the
 * same `loading → loaded / deadEnd` path, so every doc action derives its identity
 * HERE rather than re-branching on kind. Media reuses the `nid` slot for its id.
 */
function docIdentity(room: Room): { nid: number; langcode: string | null } | null {
  if (room.kind === "node") return { nid: room.nid, langcode: room.langcode };
  // A media room with NO id is the "new image" room — nothing minted yet, so no
  // document to load; it settles straight to idle, its chat rail generating.
  if (room.kind === "media") return room.id != null ? { nid: room.id, langcode: null } : null;
  return null;
}

/** The target document identity for a room the instant we commit to entering it. */
function targetDoc(room: Room): DocState {
  const d = docIdentity(room);
  return d ? { status: "loading", nid: d.nid, langcode: d.langcode } : { status: "none" };
}

/**
 * The document identity for an ADOPT_ROOM'd room — the out-of-band writer already
 * loaded it, so a doc room is `loaded` (not `loading`); everything else has none.
 */
function reconciledDoc(room: Room): DocState {
  const d = docIdentity(room);
  return d ? { status: "loaded", nid: d.nid, langcode: d.langcode } : { status: "none" };
}

/**
 * Build the console statechart with the given side-effect bindings. The root is
 * PARALLEL — the `nav` region (booting → inRoom) is the navigation authority;
 * the `lock` region (the editor pen) runs alongside it (plan §2 parallel region).
 */
export function consoleMachine(deps: Partial<ConsoleActions> = {}) {
  const fx: ConsoleActions = { ...NOOP_ACTIONS, ...deps };

  return setup({
    types: {
      context: {} as ConsoleContext,
      events: {} as ConsoleEvent,
      input: {} as ConsoleInput,
    },
    guards: {
      /** ENTER_ROOM / POPSTATE names a room other than the one we're in. */
      targetIsDifferent: ({ context, event }) =>
        (event.type === "ENTER_ROOM" || event.type === "POPSTATE") &&
        !sameRoom(event.room, context.room),
      /** ENTER_ROOM / POPSTATE names the room we're already in (swallow it). */
      targetIsSameRoom: ({ context, event }) =>
        (event.type === "ENTER_ROOM" || event.type === "POPSTATE") &&
        sameRoom(event.room, context.room),
      isDirty: ({ context }) => context.dirty,
      /** A DOC-loadable room (Content node OR media item) → run the async load. */
      isDocRoom: ({ context }) => docIdentity(context.room) !== null,
      /** A fatal doc load — 403 denied / 404 gone → dead-end (D4). */
      isFatalStatus: ({ event }) =>
        event.type === "DOC_FAILED" && (event.status === 403 || event.status === 404),
    },
    actions: {
      /** Set `room` (+ optional threadId) from an ENTER_ROOM / POPSTATE event. */
      assignTargetRoom: assign(({ event }) => {
        if (event.type !== "ENTER_ROOM" && event.type !== "POPSTATE") return {};
        return { room: event.room, threadId: event.threadId ?? null };
      }),
      /** Stash the ENTER_ROOM target while the discard-confirm is open. */
      stashPending: assign(({ event }) => {
        if (event.type !== "ENTER_ROOM") return {};
        return { pending: { room: event.room, threadId: event.threadId ?? null } };
      }),
      /** Commit the stashed target as the active room (on DISCARD). */
      commitPending: assign(({ context }) =>
        context.pending
          ? { room: context.pending.room, threadId: context.pending.threadId, pending: null }
          : { pending: null },
      ),
      clearPending: assign({ pending: null }),
      /** SEAL keeps the room, drops to a fresh thread (D8 auto-archive + new). */
      assignSealTarget: assign({ threadId: null }),
      /** SWITCH_THREAD sets the target thread (null = start a fresh one). */
      assignSwitchThread: assign(({ event }) =>
        event.type === "SWITCH_THREAD" ? { threadId: event.threadId } : {},
      ),
      /**
       * `switchingThread` entry: a same-room thread switch — commit ONLY the
       * runtime thread switch. The room / studio / open document are unchanged,
       * so no `deriveStudio` / `beginLoad` runs (re-loading the open page would
       * thrash the editor lock). A committed switch strands nothing → dirty
       * resets.
       */
      commitThreadOnly: assign(({ context }) => {
        fx.commitThreadSwitch(context.room, context.threadId);
        return { dirty: false };
      }),
      /**
       * ADOPT_ROOM: adopt an out-of-band-resolved room as context WITHOUT side
       * effects (the writer already switched the thread / set the studio / loaded
       * the doc). Threads through the room + a matching document identity so
       * `activeRoom()` stays truthful; keeps `threadId` unless the event names one.
       */
      assignAdopt: assign(({ event }) => {
        if (event.type !== "ADOPT_ROOM") return {};
        return {
          room: event.room,
          ...(event.threadId !== undefined ? { threadId: event.threadId } : {}),
          document: reconciledDoc(event.room),
        };
      }),
      /**
       * `switching` entry: run the synchronous runtime thread switch and set the
       * target document identity immediately, so `context.document` never trails
       * on the PREVIOUS room's doc (INV‑2). Dirty resets — a committed switch
       * strands nothing.
       *
       * Close-on-leave (INV‑5), PER DOC FAMILY: the target room keeps only the
       * doc family it owns; every other family's open doc closes, so no stale
       * document survives a navigation. Two families:
       *  - PAGE family (page-state): kept by a `node` room, an `audit` room
       *    (Checks shares the Content draft — the machine `document` is `none`
       *    but page-state tracks the audited page), and a `draft` room (the
       *    in-flight node-less page — closing it would wipe the composition).
       *    Closing frees the editor lock.
       *  - MEDIA family (media-state): kept only by a `media` room. Left open,
       *    a stale item renders the image over the Library shelf's ledger.
       * A browse/studio room keeps neither. Each close early-returns when
       * nothing of its family is open, so hops that never had a doc are no-ops.
       */
      commitSwitch: assign(({ context }) => {
        fx.commitThreadSwitch(context.room, context.threadId);
        const kind = context.room.kind;
        const keepsPageDoc = kind === "node" || kind === "audit" || kind === "draft";
        if (!keepsPageDoc) fx.closeDocToListing();
        if (kind !== "media") fx.closeMediaDoc();
        return { document: targetDoc(context.room), dirty: false };
      }),
      /** `settling` entry: derive the active studio from the room (INV‑1). */
      deriveStudio: ({ context }) => fx.setActiveStudio(roomStudio(context.room)),
      /**
       * `settling` entry: reconcile the Checks audit node to the room — set it for
       * an audit room, clear it for every other room so a stale audit selection
       * never leaks across a navigation. Synchronous (audit-state has no load).
       */
      reconcileAudit: ({ context }) =>
        fx.setAuditNode(context.room.kind === "audit" ? context.room.nid : null),
      /** `loadingDoc` entry: kick off the async node / media load. */
      beginLoad: ({ context }) => {
        if (context.room.kind === "node" || context.room.kind === "media") fx.beginDocLoad(context.room);
      },
      markDocLoaded: assign(({ context }) => {
        const d = docIdentity(context.room);
        return d ? { document: { status: "loaded", nid: d.nid, langcode: d.langcode } as DocState } : {};
      }),
      markDocDead: assign(({ context, event }) => {
        const d = docIdentity(context.room);
        if (!d || event.type !== "DOC_FAILED") return {};
        const reason = event.status === 403 ? "denied" : "gone";
        return {
          document: { status: "deadEnd", reason, nid: d.nid, langcode: d.langcode } as DocState,
        };
      }),
      setDirty: assign(({ event }) =>
        event.type === "SET_DIRTY" ? { dirty: event.dirty } : {},
      ),
    },
  }).createMachine({
    id: "console",
    type: "parallel",
    context: ({ input }) => ({
      room: input.room,
      threadId: input.threadId ?? null,
      document: targetDoc(input.room),
      dirty: false,
      pending: null,
    }),
    states: {
      /* ---------------------------------------------- navigation authority */
      nav: {
        initial: "booting",
        states: {
          // Resolve URL / deep link. Phase 1: the initial room arrives via input;
          // fleshed out into real URL resolution in Phase 2.
          booting: { always: { target: "inRoom" } },

          inRoom: {
            initial: "idle",
            // ENTER_ROOM is defined HERE (on the compound), so EVERY child state
            // inherits it and there is no path that skips the dirty guard — INV‑6
            // holds by construction. POPSTATE is the same, minus the guard (the
            // browser already navigated). SET_DIRTY just mirrors the flag.
            on: {
              ENTER_ROOM: [
                { guard: "targetIsSameRoom" }, // swallow — already here
                { guard: "isDirty", target: ".confirmDiscard", actions: "stashPending" },
                { target: ".switching", actions: "assignTargetRoom" },
              ],
              POPSTATE: [
                { guard: "targetIsSameRoom" },
                { target: ".switching", actions: "assignTargetRoom" },
              ],
              SEAL: { target: ".switching", actions: "assignSealTarget" },
              // A same-room thread switch (sidebar row / new chat): commit the
              // thread only, no studio/doc re-derivation. Also on the compound so
              // it's reachable from any substate (e.g. a dead-end's history).
              SWITCH_THREAD: { target: ".switchingThread", actions: "assignSwitchThread" },
              // Explicit out-of-band adoption: catch up to a room a writer already
              // resolved (agent preview tool / language reload / first publish),
              // landing back at rest. No side effects.
              ADOPT_ROOM: { target: ".idle", actions: "assignAdopt" },
              SET_DIRTY: { actions: "setDirty" },
            },
            states: {
              idle: {},

              confirmDiscard: {
                on: {
                  DISCARD: { target: "switching", actions: "commitPending" },
                  CANCEL: { target: "idle", actions: "clearPending" },
                },
              },

              // Thread switch committed synchronously here; studio/doc derivation
              // is deferred to `settling` (absorbs the emitNextTick gap, §1).
              switching: {
                entry: "commitSwitch",
                on: { THREAD_SETTLED: { target: "settling" } },
              },

              // Same-room thread switch: commit the runtime switch and fall
              // straight back to idle. No `settling` — the studio and open
              // document are unchanged, so there is nothing to derive.
              switchingThread: {
                entry: "commitThreadOnly",
                always: { target: "idle" },
              },

              settling: {
                entry: ["deriveStudio", "reconcileAudit"],
                always: [
                  { guard: "isDocRoom", target: "loadingDoc" },
                  // list / studio-singleton / audit — no async load. The doc (if
                  // any) was already closed in `commitSwitch`; an audit room's node
                  // was set synchronously by `reconcileAudit` on entry.
                  { target: "idle" },
                ],
              },

              loadingDoc: {
                entry: "beginLoad",
                on: {
                  DOC_LOADED: { target: "idle", actions: "markDocLoaded" },
                  DOC_FAILED: [
                    { guard: "isFatalStatus", target: "deadEnd", actions: "markDocDead" },
                    { target: "idle" }, // transient — swallowed, room still entered
                  ],
                },
              },

              // A denied/gone document — the room IS entered; the dead-end pane
              // shows within it (D4). Leaving via ENTER_ROOM is handled above.
              deadEnd: {},
            },
          },
        },
      },

      /* ------------------------------------------------- editor lock (pen)
       * The editor single-writer lock, reflected from page-lock by console-nav
       * (Phase 4). Acquire/release still live in page-state's load/close; console-
       * nav mirrors the resulting lock state into this region via `subscribeLock`.
       * To make that reflection trivial + idempotent, each SETTLED state (`idle`,
       * `mine`, `elsewhere`) is reachable in ONE event from any state — the
       * reflection just sends LOCK_RELEASE / LOCK_ACQUIRED / LOCK_HELD_OTHER for
       * page-lock's node-null / holds-token / other-holder. The granular
       * LOCK_ACQUIRING / LOCK_TAKE_OVER / LOCK_WRITE_409 edges model the full
       * lifecycle (and the unit tests walk them); `lost` is the hard-409 flavour
       * of "not mine" (composerMode treats it as `elsewhere`). */
      lock: {
        initial: "idle",
        states: {
          idle: { on: { LOCK_ACQUIRING: "acquiring", LOCK_ACQUIRED: "mine", LOCK_HELD_OTHER: "elsewhere" } },
          acquiring: {
            on: { LOCK_ACQUIRED: "mine", LOCK_HELD_OTHER: "elsewhere", LOCK_RELEASE: "idle" },
          },
          mine: {
            // WRITE_409 = our write hit the fence (we know we lost it, hard). But a
            // silent takeover — another session grabs the pen and our lock poll just
            // reports a non-mine holder, no 409 — must also leave `mine`, else the
            // composer stays editable over a pen we no longer hold (INV‑5 / M2).
            on: { LOCK_WRITE_409: "lost", LOCK_HELD_OTHER: "elsewhere", LOCK_RELEASE: "idle" },
          },
          elsewhere: {
            on: { LOCK_TAKE_OVER: "acquiring", LOCK_ACQUIRED: "mine", LOCK_RELEASE: "idle" },
          },
          lost: { on: { LOCK_ACQUIRED: "mine", LOCK_HELD_OTHER: "elsewhere", LOCK_RELEASE: "idle" } },
        },
      },
    },
  });
}

export type ConsoleMachine = ReturnType<typeof consoleMachine>;
export type ConsoleSnapshot = SnapshotFrom<ConsoleMachine>;
export type ConsoleActor = Actor<ConsoleMachine>;

/**
 * The thin typed wrapper the shell (and tests) drive: intention-revealing verbs
 * in, projections out, hiding the raw XState `send`/`getSnapshot` surface. The
 * five UI regions read {@link ConsoleNav.room}/`studio`/`document` — none can
 * describe a room another one disagrees with (plan §3).
 */
export interface ConsoleNav {
  readonly actor: ConsoleActor;
  start(): ConsoleNav;
  stop(): void;
  /** Subscribe to any state/context change (returns an unsubscribe). */
  subscribe(cb: (snapshot: ConsoleSnapshot) => void): () => void;
  /* intents */
  enterRoom(room: Room, threadId?: string | null): void;
  /** Same-room thread switch (sidebar row). */
  switchThread(threadId: string): void;
  /** Same-room fresh thread (agent-picker's "new chat"). */
  newThread(): void;
  /** Adopt an out-of-band-resolved room explicitly (no side effects, no thread
   *  switch) — an agent preview tool, a language reload, or a first publish. */
  adoptRoom(room: Room, threadId?: string | null): void;
  discard(): void;
  cancel(): void;
  threadSettled(): void;
  docLoaded(): void;
  docFailed(status: number): void;
  seal(): void;
  setDirty(dirty: boolean): void;
  popstate(room: Room, threadId?: string | null): void;
  /* projections */
  room(): Room;
  studio(): StudioKey;
  document(): DocState;
  dirty(): boolean;
  snapshot(): ConsoleSnapshot;
  /** True when the nav region is in `path` (dot-notation, e.g. "confirmDiscard"). */
  inNav(
    state:
      | "idle"
      | "confirmDiscard"
      | "switching"
      | "switchingThread"
      | "settling"
      | "loadingDoc"
      | "deadEnd",
  ): boolean;
  /** The editor-lock (pen) region's current state — the composer mode + the
   *  console-nav reflection read this (INV‑4 / INV‑5). */
  lockRegion(): LockRegion;
}

/** The editor-lock (pen) parallel-region states. */
export type LockRegion = "idle" | "acquiring" | "mine" | "elsewhere" | "lost";

/** The chat composer's mode — a pure projection of the active thread's status +
 *  the editor-lock region (INV‑4: no dependence on `threadId` timing). */
export type ComposerMode = "sealed" | "archived" | "pendingWrapup" | "noAgent" | "lockElsewhere" | "live";

/** The status facts a thread carries that gate the composer. */
export type ThreadComposerStatus = {
  sealed: boolean;
  archived: boolean;
  pendingWrapup: boolean;
  /** The room's studio has NO agent in the catalog (e.g. the image role came
   *  unbound and the server dropped the media agent) — nothing can take a turn,
   *  but the transcript must stay readable (gate the composer, never the pane). */
  noAgent: boolean;
};

/**
 * Derive the composer mode from the thread status + the lock region — the single
 * priority `sealed → archived → pendingWrapup → noAgent → lockElsewhere → live`
 * (INV‑4). Both `lost` and `elsewhere` mean "the pen is not ours", so both freeze
 * the composer (the studio's take-over banner is the way back). `noAgent` freezes
 * it too: with no agent in the studio's catalog a turn has nothing to run — the
 * conversation is read-only until a provider is (re)connected.
 */
export function composerMode(status: ThreadComposerStatus, lock: LockRegion): ComposerMode {
  if (status.sealed) return "sealed";
  if (status.archived) return "archived";
  if (status.pendingWrapup) return "pendingWrapup";
  if (status.noAgent) return "noAgent";
  if (lock === "elsewhere" || lock === "lost") return "lockElsewhere";
  return "live";
}

/** Create (but don't start) a {@link ConsoleNav} over a fresh actor. */
export function createConsoleNav(deps: Partial<ConsoleActions>, input: ConsoleInput): ConsoleNav {
  const actor = createActor(consoleMachine(deps), { input });
  const nav: ConsoleNav = {
    actor,
    start() {
      actor.start();
      return nav;
    },
    stop() {
      actor.stop();
    },
    subscribe(cb) {
      const sub = actor.subscribe(cb);
      return () => sub.unsubscribe();
    },
    enterRoom(room, threadId = null) {
      actor.send({ type: "ENTER_ROOM", room, threadId });
    },
    switchThread(threadId) {
      actor.send({ type: "SWITCH_THREAD", threadId });
    },
    newThread() {
      actor.send({ type: "SWITCH_THREAD", threadId: null });
    },
    adoptRoom(room, threadId) {
      actor.send({ type: "ADOPT_ROOM", room, threadId });
    },
    discard() {
      actor.send({ type: "DISCARD" });
    },
    cancel() {
      actor.send({ type: "CANCEL" });
    },
    threadSettled() {
      actor.send({ type: "THREAD_SETTLED" });
    },
    docLoaded() {
      actor.send({ type: "DOC_LOADED" });
    },
    docFailed(status) {
      actor.send({ type: "DOC_FAILED", status });
    },
    seal() {
      actor.send({ type: "SEAL" });
    },
    setDirty(dirty) {
      actor.send({ type: "SET_DIRTY", dirty });
    },
    popstate(room, threadId = null) {
      actor.send({ type: "POPSTATE", room, threadId });
    },
    room() {
      return actor.getSnapshot().context.room;
    },
    studio() {
      return roomStudio(actor.getSnapshot().context.room);
    },
    document() {
      return actor.getSnapshot().context.document;
    },
    dirty() {
      return actor.getSnapshot().context.dirty;
    },
    snapshot() {
      return actor.getSnapshot();
    },
    inNav(state) {
      return actor.getSnapshot().matches({ nav: { inRoom: state } });
    },
    lockRegion() {
      const snap = actor.getSnapshot();
      for (const s of ["mine", "elsewhere", "lost", "acquiring", "idle"] as const) {
        if (snap.matches({ lock: s })) return s;
      }
      return "idle";
    },
  };
  return nav;
}
