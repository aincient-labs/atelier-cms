import { describe, expect, it } from "vitest";
import {
  composerMode,
  createConsoleNav,
  type ConsoleActions,
  type DocState,
} from "./console-machine";
import { isBrowseRoom, roomId, roomStudio, sameRoom, sectionRoom, type Room } from "./rooms-core";
import type { StudioKey } from "./studios";

/**
 * Invariant harness for the console statechart (plan §4). Each test asserts a
 * coherence invariant from docs/console-state-model.md §6. Phase 1 gated on
 * INV‑1 / INV‑2 / INV‑6; Phase 4 adds INV‑3/4/5 (the reconciler retirement).
 *
 * The machine's side effects are captured by spies, so "the active studio" (INV‑1)
 * and "the loaded document" (INV‑2) are observed exactly as the shell would drive
 * them — the machine keeps them in step with `context.room` or the test fails.
 */

const GENERAL: Room = { kind: "studio", studio: "general" };
const DESIGN: Room = { kind: "studio", studio: "design_system" };
const CHECKS: Room = { kind: "studio", studio: "checks" };
const LIST: Room = { kind: "list" };
const DRAFT: Room = { kind: "draft", thread: "thr_d" };
const NODE_A: Room = { kind: "node", doc: "page", nid: 1, langcode: "en" };
const NODE_B: Room = { kind: "node", doc: "page", nid: 2, langcode: "en" };
const BLOCK: Room = { kind: "node", doc: "block", nid: 7, langcode: null };
const AUDIT: Room = { kind: "audit", nid: 5 };
const MEDIA: Room = { kind: "media", id: 42 };

/** A nav wired to spies: `studio()` mirrors the derived active studio (INV‑1). */
function harness(input: Room = GENERAL) {
  const loads: Room[] = [];
  const switches: Array<[Room, string | null]> = [];
  const audits: Array<number | null> = [];
  let activeStudio: StudioKey | null = null;
  let closes = 0;
  let mediaCloses = 0;

  const deps: Partial<ConsoleActions> = {
    setActiveStudio: (s) => {
      activeStudio = s;
    },
    beginDocLoad: (r) => {
      loads.push(r);
    },
    commitThreadSwitch: (r, t) => {
      switches.push([r, t]);
    },
    closeDocToListing: () => {
      closes += 1;
    },
    closeMediaDoc: () => {
      mediaCloses += 1;
    },
    setAuditNode: (nid) => {
      audits.push(nid);
    },
  };

  const nav = createConsoleNav(deps, { room: input }).start();
  return {
    nav,
    loads,
    switches,
    audits,
    get closes() {
      return closes;
    },
    get mediaCloses() {
      return mediaCloses;
    },
    get activeStudio() {
      return activeStudio;
    },
  };
}

/** Drive a full navigation to rest: switch → settle → (load) → idle/deadEnd. */
function navigate(h: ReturnType<typeof harness>, room: Room, opts?: { fail?: number }) {
  h.nav.enterRoom(room);
  // If a discard-confirm intercepted (dirty), the caller handles it.
  if (h.nav.inNav("confirmDiscard")) return;
  h.nav.threadSettled();
  if (h.nav.inNav("loadingDoc")) {
    if (opts?.fail) h.nav.docFailed(opts.fail);
    else h.nav.docLoaded();
  }
}

/** INV‑2 predicate: the document identity matches the current room. */
function docMatchesRoom(doc: DocState, room: Room): boolean {
  if (room.kind === "node") return "nid" in doc && doc.nid === room.nid && doc.langcode === room.langcode;
  // A media room is doc-loadable too — it reuses the `nid` slot for its id, no langcode.
  if (room.kind === "media") return "nid" in doc && doc.nid === room.id && doc.langcode === null;
  return doc.status === "none" || doc.status === "authoring";
}

describe("INV‑1 — view coherence: roomStudio(room) === activeStudio()", () => {
  it("holds after entering a studio-singleton room", () => {
    const h = harness();
    navigate(h, DESIGN);
    expect(h.activeStudio).toBe("design_system");
    expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
  });

  it("holds after entering the Content list room", () => {
    const h = harness();
    navigate(h, LIST);
    expect(h.activeStudio).toBe("content");
    expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
  });

  it("holds after entering (and loading) a Content node room", () => {
    const h = harness();
    navigate(h, NODE_A);
    expect(h.activeStudio).toBe("content");
    expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
  });

  it("stays coherent across a studio → node → studio burst", () => {
    const h = harness();
    for (const room of [DESIGN, NODE_A, LIST, GENERAL, NODE_B]) {
      navigate(h, room);
      expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
    }
  });
});

describe("INV‑2 — a thread pulls its own document (never a previous room's)", () => {
  it("sets the document identity to the target room the instant the switch commits", () => {
    const h = harness();
    navigate(h, NODE_A);
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 1 });

    // Enter B; even BEFORE its load resolves, the document must name B, not A.
    h.nav.enterRoom(NODE_B);
    expect(docMatchesRoom(h.nav.document(), NODE_B)).toBe(true);
    expect(h.nav.document()).not.toMatchObject({ nid: 1 });

    h.nav.threadSettled();
    h.nav.docLoaded();
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 2 });
  });

  it("leaving a node room for the list clears the document", () => {
    const h = harness();
    navigate(h, NODE_A);
    navigate(h, LIST);
    expect(h.nav.document()).toEqual({ status: "none" });
    expect(h.closes).toBe(1);
  });

  it("a denied load lands in a dead-end WITHIN the target room (D4), not elsewhere", () => {
    const h = harness();
    navigate(h, NODE_A, { fail: 403 });
    expect(h.nav.inNav("deadEnd")).toBe(true);
    expect(h.nav.document()).toMatchObject({ status: "deadEnd", reason: "denied", nid: 1 });
    // The room IS entered — studio still coherent (INV‑1 holds at the dead-end).
    expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
    // And you can navigate back out of it.
    navigate(h, LIST);
    expect(h.nav.inNav("idle")).toBe(true);
    expect(docMatchesRoom(h.nav.document(), LIST)).toBe(true);
  });

  it("a transient (500) load failure is swallowed — room stays entered", () => {
    const h = harness();
    navigate(h, NODE_A, { fail: 500 });
    expect(h.nav.inNav("idle")).toBe(true);
    expect(docMatchesRoom(h.nav.document(), NODE_A)).toBe(true);
  });
});

describe("INV‑6 — dirty ENTER_ROOM always routes through confirmDiscard (no bypass)", () => {
  it("a dirty switch stages the confirm and does NOT commit the thread switch", () => {
    const h = harness();
    navigate(h, NODE_A);
    const switchesBefore = h.switches.length;

    h.nav.setDirty(true);
    h.nav.enterRoom(NODE_B);
    expect(h.nav.inNav("confirmDiscard")).toBe(true);
    expect(h.switches.length).toBe(switchesBefore); // nothing committed yet
    expect(h.nav.room()).toEqual(NODE_A); // still in A

    h.nav.discard();
    expect(h.switches.length).toBe(switchesBefore + 1);
    expect(h.nav.room()).toEqual(NODE_B);
  });

  it("cancelling the confirm leaves you in the original room", () => {
    const h = harness();
    navigate(h, NODE_A);
    h.nav.setDirty(true);
    h.nav.enterRoom(NODE_B);
    h.nav.cancel();
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.nav.room()).toEqual(NODE_A);
  });

  it("no bypass: even mid-load, a dirty ENTER_ROOM is intercepted", () => {
    const h = harness();
    h.nav.enterRoom(NODE_A);
    h.nav.threadSettled(); // now in loadingDoc
    expect(h.nav.inNav("loadingDoc")).toBe(true);
    h.nav.setDirty(true);
    h.nav.enterRoom(NODE_B);
    expect(h.nav.inNav("confirmDiscard")).toBe(true);
  });
});

describe("navigation basics", () => {
  it("swallows ENTER_ROOM for the room you're already in", () => {
    const h = harness();
    navigate(h, DESIGN);
    const before = h.switches.length;
    h.nav.enterRoom(DESIGN);
    expect(h.switches.length).toBe(before);
    expect(h.nav.inNav("idle")).toBe(true);
  });

  it("SEAL keeps the room and drops to a fresh thread (D8)", () => {
    const h = harness();
    navigate(h, NODE_A);
    h.nav.seal();
    expect(h.nav.inNav("switching")).toBe(true);
    h.nav.threadSettled();
    h.nav.docLoaded();
    expect(h.nav.room()).toEqual(NODE_A);
    expect(h.switches[h.switches.length - 1]).toEqual([NODE_A, null]);
  });

  it("POPSTATE derives the room without the dirty guard", () => {
    const h = harness();
    navigate(h, NODE_A);
    h.nav.setDirty(true);
    h.nav.popstate(LIST);
    // Browser already navigated — no confirm; goes straight to switching.
    expect(h.nav.inNav("switching")).toBe(true);
    h.nav.threadSettled();
    expect(h.nav.room()).toEqual(LIST);
  });
});

describe("same-room thread switch (SWITCH_THREAD — sidebar row / new chat)", () => {
  it("commits the thread only — no studio or document re-derivation", () => {
    const h = harness();
    navigate(h, NODE_A);
    const loadsBefore = h.loads.length;
    const closesBefore = h.closes;
    const doc = h.nav.document();

    h.nav.switchThread("thr_x");
    // Back at rest immediately — no settling/loading for a same-room switch.
    expect(h.nav.inNav("idle")).toBe(true);
    // The runtime switch ran, targeting the named thread, in the SAME room.
    expect(h.switches[h.switches.length - 1]).toEqual([NODE_A, "thr_x"]);
    // The open page was neither reloaded nor closed (lock stays put), and the
    // room / document are unchanged.
    expect(h.loads.length).toBe(loadsBefore);
    expect(h.closes).toBe(closesBefore);
    expect(h.nav.room()).toEqual(NODE_A);
    expect(h.nav.document()).toEqual(doc);
  });

  it("newThread starts a fresh thread in the same room (threadId null)", () => {
    const h = harness();
    navigate(h, DESIGN);
    h.nav.newThread();
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.switches[h.switches.length - 1]).toEqual([DESIGN, null]);
    expect(h.nav.room()).toEqual(DESIGN);
    expect(roomStudio(h.nav.room())).toBe(h.activeStudio);
  });
});

describe("adoptRoom (explicit out-of-band adoption — no side effects, no thread switch)", () => {
  it("adopts a studio room without running any side effect (agent preview tool)", () => {
    const h = harness();
    navigate(h, GENERAL);
    const switchesBefore = h.switches.length;
    const loadsBefore = h.loads.length;
    const closesBefore = h.closes;

    // An agent brand-preview tool yanked the studio to design_system out of band.
    h.nav.adoptRoom(DESIGN);
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.nav.room()).toEqual(DESIGN);
    // No switch, no load, no close — the writer already did the studio change.
    expect(h.switches.length).toBe(switchesBefore);
    expect(h.loads.length).toBe(loadsBefore);
    expect(h.closes).toBe(closesBefore);
  });

  it("first publish: adopts the minted node room, marked loaded, keeping the thread", () => {
    // Authoring a new page in the list room; first Publish mints nid 2 in place.
    const h = harness(LIST);
    navigate(h, LIST);
    const switchesBefore = h.switches.length;

    h.nav.adoptRoom(NODE_B);
    expect(h.nav.room()).toEqual(NODE_B);
    // Loaded (the draft we just saved IS the doc), never re-loaded, no thread
    // switch — we stay in the conversation we published from (M1).
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 2 });
    expect(h.loads.length).toBe(0);
    expect(h.switches.length).toBe(switchesBefore);
  });

  it("language reload: adopts the new-langcode node room without a thread switch", () => {
    const h = harness();
    navigate(h, NODE_A); // en
    const switchesBefore = h.switches.length;
    const DE: Room = { kind: "node", doc: "page", nid: 1, langcode: "de" };

    // page-studio reloaded the DE translation itself, then adopts the room.
    h.nav.adoptRoom(DE);
    expect(h.nav.room()).toEqual(DE);
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 1, langcode: "de" });
    expect(h.switches.length).toBe(switchesBefore); // langcodes share homed threads
  });

  it("rescues a dead-end: adopting lands back at idle in the new room", () => {
    const h = harness();
    navigate(h, NODE_A, { fail: 403 });
    expect(h.nav.inNav("deadEnd")).toBe(true);
    h.nav.adoptRoom(GENERAL);
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.nav.room()).toEqual(GENERAL);
  });
});

describe("INV‑3 — the machine is the single writer of the document", () => {
  it("document identity tracks the room at every rest across a mixed burst", () => {
    const h = harness();
    // A burst mixing true navigations (enterRoom) and out-of-band adoptions.
    navigate(h, NODE_A);
    expect(docMatchesRoom(h.nav.document(), NODE_A)).toBe(true);

    h.nav.adoptRoom(DESIGN); // agent tool
    expect(docMatchesRoom(h.nav.document(), DESIGN)).toBe(true);

    navigate(h, NODE_B);
    expect(docMatchesRoom(h.nav.document(), NODE_B)).toBe(true);

    navigate(h, LIST);
    expect(h.nav.document()).toEqual({ status: "none" });

    navigate(h, AUDIT);
    // Audit rooms track the node via audit-state, not the machine document.
    expect(h.nav.document()).toEqual({ status: "none" });
    expect(h.audits[h.audits.length - 1]).toBe(5);
  });

  it("leaving any doc-bearing room for a studio-singleton closes the doc (lock freed)", () => {
    const h = harness();
    navigate(h, NODE_A);
    const closesBefore = h.closes;
    navigate(h, GENERAL); // node → chat studio
    expect(h.closes).toBe(closesBefore + 1);
    expect(h.nav.document()).toEqual({ status: "none" });

    // And leaving an audit room for a studio-singleton also closes (its page is
    // in page-state even though the machine document is `none`).
    navigate(h, AUDIT);
    const closesBeforeAudit = h.closes;
    navigate(h, DESIGN);
    expect(h.closes).toBe(closesBeforeAudit + 1);
  });
});

describe("draft room — a node-less New page has its own identity (never the List)", () => {
  it("entering the draft room HOLDS the doc (does not close the composing draft)", () => {
    const h = harness();
    // Compose in the draft room, then flip to another draft thread: neither entry
    // should close the doc (that would wipe the in-flight page). Contrast the List.
    const closesBefore = h.closes;
    navigate(h, DRAFT);
    navigate(h, { kind: "draft", thread: "thr_e" });
    expect(h.closes).toBe(closesBefore);
  });

  it("leaving the draft room for the List closes the doc (INV‑5 lock freed)", () => {
    const h = harness();
    navigate(h, DRAFT);
    const closesBefore = h.closes;
    navigate(h, LIST); // abandon the unsaved draft → directory
    expect(h.closes).toBe(closesBefore + 1);
    expect(h.nav.document()).toEqual({ status: "none" });
  });

  it("first mint: adopts the node room from the draft, keeping the thread", () => {
    const h = harness();
    navigate(h, DRAFT); // composing /content/draft/thr_d
    // Save/Publish mints nid 2 in place — the studio adoptRoom's the node room.
    h.nav.adoptRoom(NODE_B);
    expect(sameRoom(h.nav.room(), NODE_B)).toBe(true);
    expect(docMatchesRoom(h.nav.document(), NODE_B)).toBe(true);
    // No thread switch was committed by the adopt (it keeps the conversation).
    const switchesFromAdopt = h.switches.filter(([r]) => sameRoom(r, NODE_B));
    expect(switchesFromAdopt).toHaveLength(0);
  });

  it("each draft thread is a distinct room; a draft is never the List", () => {
    expect(roomId(DRAFT)).toBe("content:draft:thr_d");
    expect(sameRoom(DRAFT, { kind: "draft", thread: "thr_e" })).toBe(false);
    expect(sameRoom(DRAFT, LIST)).toBe(false);
    // A fresh, not-yet-sent draft (no thread) is the singleton draft form.
    expect(roomId({ kind: "draft" })).toBe("content:draft");
    // The draft drives the Content studio (like the List and node rooms).
    expect(roomStudio(DRAFT)).toBe("content");
  });
});

describe("INV‑4 — composer mode is a pure fn of thread status + lock region", () => {
  const NONE = { sealed: false, archived: false, pendingWrapup: false, noAgent: false };
  it("honours the priority sealed → archived → pendingWrapup → noAgent → lockElsewhere → live", () => {
    expect(composerMode({ ...NONE, sealed: true }, "mine")).toBe("sealed");
    expect(composerMode({ ...NONE, archived: true }, "mine")).toBe("archived");
    expect(composerMode({ ...NONE, sealed: true, archived: true }, "idle")).toBe("sealed");
    expect(composerMode({ ...NONE, pendingWrapup: true }, "elsewhere")).toBe("pendingWrapup");
    expect(composerMode(NONE, "elsewhere")).toBe("lockElsewhere");
    expect(composerMode(NONE, "mine")).toBe("live");
    expect(composerMode(NONE, "idle")).toBe("live");
  });

  it("treats `lost` and `elsewhere` identically (both freeze the composer)", () => {
    expect(composerMode(NONE, "lost")).toBe("lockElsewhere");
    expect(composerMode(NONE, "elsewhere")).toBe("lockElsewhere");
  });

  it("a studio with no agent freezes the composer (read-only), below thread end-states", () => {
    // No agent + live lock → read-only: the transcript stays, nothing can run.
    expect(composerMode({ ...NONE, noAgent: true }, "idle")).toBe("noAgent");
    expect(composerMode({ ...NONE, noAgent: true }, "mine")).toBe("noAgent");
    // Thread end-states still outrank it (a sealed thread reads as sealed)…
    expect(composerMode({ ...NONE, sealed: true, noAgent: true }, "idle")).toBe("sealed");
    expect(composerMode({ ...NONE, archived: true, noAgent: true }, "idle")).toBe("archived");
    // …and it outranks the lock (no agent means no turn, whoever holds the pen).
    expect(composerMode({ ...NONE, noAgent: true }, "elsewhere")).toBe("noAgent");
  });
});

describe("INV‑5 — lock `mine` ⇒ an open document in an editing context", () => {
  /** Read the lock region as page-lock would drive it via console-nav. */
  const acquire = (h: ReturnType<typeof harness>, token = "t1") => {
    h.nav.actor.send({ type: "LOCK_ACQUIRED", token });
  };

  it("the lock reaches `mine` only over a loaded node room", () => {
    const h = harness();
    navigate(h, NODE_A);
    acquire(h);
    expect(h.nav.lockRegion()).toBe("mine");
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 1 });
    expect(roomStudio(h.nav.room())).toBe("content"); // an editing studio
  });

  it("a silent takeover moves `mine` → `elsewhere` (M2, no 409 needed)", () => {
    const h = harness();
    navigate(h, NODE_A);
    acquire(h);
    expect(h.nav.lockRegion()).toBe("mine");
    // Our lock poll reports another holder without our write hitting the fence.
    h.nav.actor.send({ type: "LOCK_HELD_OTHER", holder: "sam" });
    expect(h.nav.lockRegion()).toBe("elsewhere");
  });

  it("releasing the lock (leaving the doc) returns the region to idle", () => {
    const h = harness();
    navigate(h, NODE_A);
    acquire(h);
    h.nav.actor.send({ type: "LOCK_RELEASE" });
    expect(h.nav.lockRegion()).toBe("idle");
  });

  it("each settled lock state is reachable in one event from anywhere (reflection)", () => {
    const h = harness();
    navigate(h, NODE_A);
    // idle → mine (no explicit acquiring step, as the reflection drives it)
    h.nav.actor.send({ type: "LOCK_ACQUIRED", token: "t1" });
    expect(h.nav.lockRegion()).toBe("mine");
    // mine → elsewhere → mine (regained) → idle
    h.nav.actor.send({ type: "LOCK_HELD_OTHER", holder: "x" });
    expect(h.nav.lockRegion()).toBe("elsewhere");
    h.nav.actor.send({ type: "LOCK_ACQUIRED", token: "t2" });
    expect(h.nav.lockRegion()).toBe("mine");
    h.nav.actor.send({ type: "LOCK_RELEASE" });
    expect(h.nav.lockRegion()).toBe("idle");
  });
});

describe("Content blocks + Checks audit rooms (folded into the path, D3)", () => {
  it("a block node room loads (routing is the shell's job) and stays in Content", () => {
    const h = harness();
    navigate(h, BLOCK);
    expect(h.activeStudio).toBe("content");
    expect(roomStudio(h.nav.room())).toBe("content");
    // The block went through the async doc load (settling → loadingDoc → idle).
    expect(h.loads[h.loads.length - 1]).toEqual(BLOCK);
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 7 });
  });

  it("entering an audit room drives Checks + sets the audit node, no doc load", () => {
    const h = harness();
    navigate(h, AUDIT);
    expect(h.activeStudio).toBe("checks");
    expect(roomStudio(h.nav.room())).toBe("checks");
    // Audit is synchronous — no async page/block load, and the node was set.
    expect(h.loads.length).toBe(0);
    expect(h.audits[h.audits.length - 1]).toBe(5);
    expect(h.nav.inNav("idle")).toBe(true);
  });

  it("clicking Checks while auditing drops to the studio landing + clears audit", () => {
    const h = harness();
    navigate(h, AUDIT);
    expect(h.audits[h.audits.length - 1]).toBe(5);
    navigate(h, CHECKS);
    // Every non-audit room reconciles the audit node to null on entry — even the
    // Checks studio room itself (its landing shows nothing audited).
    expect(h.audits[h.audits.length - 1]).toBeNull();
    expect(h.activeStudio).toBe("checks");
  });

  it("leaving audit for the Content list clears the audit node", () => {
    const h = harness();
    navigate(h, AUDIT);
    navigate(h, LIST);
    expect(h.audits[h.audits.length - 1]).toBeNull();
    expect(h.activeStudio).toBe("content");
  });

  it("page 5, block 5 and audit 5 are three distinct rooms (roomId disjoint)", () => {
    const h = harness();
    navigate(h, { kind: "node", doc: "page", nid: 5, langcode: null });
    navigate(h, AUDIT); // audit of the same nid
    expect(h.nav.room()).toEqual(AUDIT);
    // Coming from a page-5 room to audit-5 was a real navigation, not a swallow.
    expect(h.switches[h.switches.length - 1]).toEqual([AUDIT, null]);
  });
});

describe("editor-lock region (structural scaffold — INV‑5 exercised in Phase 4)", () => {
  it("runs in parallel with nav and walks idle → acquiring → mine → lost", () => {
    const h = harness();
    navigate(h, NODE_A);
    const snap = () => h.nav.snapshot();
    expect(snap().matches({ lock: "idle" })).toBe(true);
    h.nav.actor.send({ type: "LOCK_ACQUIRING" });
    expect(snap().matches({ lock: "acquiring" })).toBe(true);
    h.nav.actor.send({ type: "LOCK_ACQUIRED", token: "t1" });
    expect(snap().matches({ lock: "mine" })).toBe(true);
    h.nav.actor.send({ type: "LOCK_WRITE_409" });
    expect(snap().matches({ lock: "lost" })).toBe(true);
    // The lock region does not perturb the nav region.
    expect(h.nav.inNav("idle")).toBe(true);
  });
});

describe("media room — a Media studio item shares the doc-load path (node-parity)", () => {
  it("entering a media room derives the media studio and kicks off the async load", () => {
    const h = harness();
    h.nav.enterRoom(MEDIA);
    h.nav.threadSettled();
    // A doc-loadable room → it lands in loadingDoc and asked beginDocLoad to run.
    expect(h.nav.inNav("loadingDoc")).toBe(true);
    expect(h.loads.some((r) => sameRoom(r, MEDIA))).toBe(true);
    h.nav.docLoaded();
    // INV‑1: the media room drives the `media` studio.
    expect(h.activeStudio).toBe("media");
    expect(roomStudio(h.nav.room())).toBe("media");
    // INV‑2/3: the document identity matches the media item (id in the nid slot).
    expect(docMatchesRoom(h.nav.document(), MEDIA)).toBe(true);
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 42, langcode: null });
  });

  it("a denied media load lands in a dead-end WITHIN the media room (D4)", () => {
    const h = harness();
    navigate(h, MEDIA, { fail: 403 });
    expect(h.nav.inNav("deadEnd")).toBe(true);
    expect(sameRoom(h.nav.room(), MEDIA)).toBe(true);
    expect(h.nav.document()).toMatchObject({ status: "deadEnd", reason: "denied", nid: 42 });
  });

  it("a transient (500) media load failure is swallowed — room stays entered", () => {
    const h = harness();
    navigate(h, MEDIA, { fail: 500 });
    expect(h.nav.inNav("idle")).toBe(true);
    expect(sameRoom(h.nav.room(), MEDIA)).toBe(true);
  });

  it("leaving a media room for a studio-singleton closes the MEDIA doc (INV‑5 parity)", () => {
    const h = harness();
    navigate(h, MEDIA);
    const mediaClosesBefore = h.mediaCloses;
    navigate(h, GENERAL); // media → chat studio
    expect(h.mediaCloses).toBe(mediaClosesBefore + 1);
    expect(h.nav.document()).toEqual({ status: "none" });
  });

  it("a media room is its own identity, distinct from a same-numbered node", () => {
    expect(roomId(MEDIA)).toBe("media:42");
    expect(sameRoom(MEDIA, { kind: "node", doc: "page", nid: 42, langcode: null })).toBe(false);
    expect(roomStudio(MEDIA)).toBe("media");
  });
});

describe("new-image room — an id-less media room (generate from scratch)", () => {
  const NEW_MEDIA: Room = { kind: "media" };

  it("derives the media studio but loads NO document (settles straight to idle)", () => {
    const h = harness();
    h.nav.enterRoom(NEW_MEDIA);
    h.nav.threadSettled();
    // No doc to load — it is NOT a doc room, so it never enters loadingDoc.
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.loads.some((r) => sameRoom(r, NEW_MEDIA))).toBe(false);
    // INV‑1: it still drives the `media` studio (its chat rail generates).
    expect(h.activeStudio).toBe("media");
    expect(roomStudio(h.nav.room())).toBe("media");
    // No open document.
    expect(h.nav.document()).toEqual({ status: "none" });
  });

  it("has its own 'media:new' identity, distinct from any numbered media room", () => {
    expect(roomId(NEW_MEDIA)).toBe("media:new");
    expect(sameRoom(NEW_MEDIA, MEDIA)).toBe(false);
    expect(roomStudio(NEW_MEDIA)).toBe("media");
  });

  it("adopts the minted id on first generation → a real media room (draft→node parity)", () => {
    const h = harness();
    h.nav.enterRoom(NEW_MEDIA);
    h.nav.threadSettled();
    // The media_result widget adopts the created id (no side effects, no reload).
    h.nav.adoptRoom(MEDIA);
    expect(sameRoom(h.nav.room(), MEDIA)).toBe(true);
    // ADOPT_ROOM reconciles the doc as already-loaded (the widget loaded it).
    expect(docMatchesRoom(h.nav.document(), MEDIA)).toBe(true);
    expect(h.nav.document()).toMatchObject({ status: "loaded", nid: 42, langcode: null });
  });
});

describe("shelf room — the Library, the media family's browse state (0168)", () => {
  const SHELF: Room = { kind: "shelf" };

  it("derives the media studio but loads NO document (list-room parity)", () => {
    const h = harness();
    h.nav.enterRoom(SHELF);
    h.nav.threadSettled();
    // Like the Content List it holds no document — never enters loadingDoc.
    expect(h.nav.inNav("idle")).toBe(true);
    expect(h.loads.some((r) => sameRoom(r, SHELF))).toBe(false);
    // INV‑1: the shelf drives the `media` studio (chat rail + shelf browser).
    expect(h.activeStudio).toBe("media");
    expect(roomStudio(h.nav.room())).toBe("media");
    expect(h.nav.document()).toEqual({ status: "none" });
  });

  it("is the media SECTION's canonical room, with its own identity", () => {
    expect(roomId(SHELF)).toBe("media:shelf");
    expect(sameRoom(SHELF, { kind: "media" })).toBe(false);
    expect(sameRoom(SHELF, { kind: "studio", studio: "media" })).toBe(false);
    // Picking the section in the header lands on the shelf (Content → List parity).
    expect(sectionRoom("media")).toEqual(SHELF);
    expect(sectionRoom("content")).toEqual({ kind: "list" });
    expect(sectionRoom("globals")).toEqual({ kind: "studio", studio: "globals" });
  });

  it("shelf → media item → back closes the MEDIA doc (browse ↔ open round-trip)", () => {
    const h = harness();
    h.nav.enterRoom(SHELF);
    h.nav.threadSettled();
    // Open an image from the shelf: the doc-load path runs (node parity).
    h.nav.enterRoom(MEDIA);
    h.nav.threadSettled();
    expect(h.loads.some((r) => sameRoom(r, MEDIA))).toBe(true);
    h.nav.docLoaded();
    expect(docMatchesRoom(h.nav.document(), MEDIA)).toBe(true);
    // Back to the shelf: the open MEDIA item closes (its own family's close —
    // media-state, not page-state — so the ledger renders, never the stale image).
    const mediaClosesBefore = h.mediaCloses;
    h.nav.enterRoom(SHELF);
    h.nav.threadSettled();
    expect(h.mediaCloses).toBe(mediaClosesBefore + 1);
    expect(h.nav.document()).toEqual({ status: "none" });
  });

  it("is a NEUTRAL landing (isBrowseRoom) — List parity; workspaces are not", () => {
    // The two browse rooms: picking the section never auto-resumes a thread
    // (roomActiveThread starts fresh), even though media threads bucket on the
    // shelf. Workspaces (studio / node / media / draft) DO resume their own.
    expect(isBrowseRoom(SHELF)).toBe(true);
    expect(isBrowseRoom({ kind: "list" })).toBe(true);
    expect(isBrowseRoom(MEDIA)).toBe(false);
    expect(isBrowseRoom({ kind: "studio", studio: "general" })).toBe(false);
    expect(isBrowseRoom({ kind: "draft", thread: "thr_d" })).toBe(false);
    expect(isBrowseRoom(NODE_A)).toBe(false);
  });
});

describe("close-on-leave is PER DOC FAMILY (page-state vs media-state)", () => {
  const SHELF: Room = { kind: "shelf" };

  it("leaving a media room for a Content node closes ONLY the media doc", () => {
    const h = harness();
    navigate(h, MEDIA);
    const closesBefore = h.closes;
    const mediaClosesBefore = h.mediaCloses;
    navigate(h, NODE_A);
    // The node room OWNS the page family — page-state must not be closed (the
    // incoming load replaces it); the media family closes (stale item gone).
    expect(h.closes).toBe(closesBefore);
    expect(h.mediaCloses).toBe(mediaClosesBefore + 1);
  });

  it("leaving a node room for a media item closes ONLY the page doc (lock freed)", () => {
    const h = harness();
    navigate(h, NODE_A);
    const closesBefore = h.closes;
    const mediaClosesBefore = h.mediaCloses;
    navigate(h, MEDIA);
    // The media room owns the media family; the page doc closes so its editor
    // lock is released — a media hop must never strand a held pen.
    expect(h.closes).toBe(closesBefore + 1);
    expect(h.mediaCloses).toBe(mediaClosesBefore);
  });

  it("entering a browse room closes BOTH families (nothing stale over a ledger)", () => {
    const h = harness();
    navigate(h, MEDIA);
    const closesBefore = h.closes;
    const mediaClosesBefore = h.mediaCloses;
    navigate(h, SHELF);
    expect(h.closes).toBe(closesBefore + 1);
    expect(h.mediaCloses).toBe(mediaClosesBefore + 1);
  });
});
