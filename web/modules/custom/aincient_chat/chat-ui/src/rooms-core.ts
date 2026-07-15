import type { StudioKey } from "./studios";

/**
 * Pure room primitives — the Room type and the dependency-free helpers over it
 * (identity, comparison, the studio a room drives).
 *
 * Split out of `rooms.ts` so the console statechart (`console-machine.ts`) and
 * its tests can reason about rooms WITHOUT dragging in the React / assistant-ui
 * store graph that the rest of `rooms.ts` (`activeRoom`, `roomOfThread`,
 * `buildRoomTree`) depends on. `rooms.ts` re-exports everything here, so existing
 * `import { … } from "./rooms"` call sites are unchanged.
 *
 * Nothing in this module reads console state — a Room is a plain value.
 */

export type Room =
  | { kind: "studio"; studio: StudioKey }
  | { kind: "list" }
  /**
   * The Library SHELF — the media family's browse room (DECISIONS 0168), the
   * exact analogue of the Content `list`: the family's landing with no item
   * open. It drives the `media` studio (chat rail = the image agent; centre =
   * the shelf browser; rail = the media editor's "nothing open" state) and
   * addresses as `/atelier/library`. Like `list` it holds no document, so it
   * settles straight to idle. There is no separate `library` studio room any
   * more — the shelf IS the section's home.
   */
  | { kind: "shelf" }
  /**
   * The New-page room — an `aincient_page` being composed that has NO node yet
   * (never persisted). Its own kind, distinct from `list`, so an unsaved draft
   * gets its own address (`/atelier/content/draft/<thread>`) instead of
   * squatting on the listing's URL — which is what let a draft shadow the
   * directory. The first Save/Publish mints the node and the room becomes a
   * `node` room (adoptRoom). Drives the Content studio. Its IDENTITY is its
   * thread — a draft has no node id, and each conversation composes its own
   * page — so the thread rides IN the path (not `?thr=`), making resolution
   * explicit: the room is known from the URL, never by loading the thread. A
   * fresh, not-yet-sent draft has no thread id yet (`thread` omitted → the
   * singleton `/content/draft` form until its thread lands).
   */
  | { kind: "draft"; thread?: string }
  /**
   * A Content document room. `doc` distinguishes the two Content editables — an
   * `aincient_page` (`loadPageIntoStudio`, translatable so it carries a langcode)
   * or a global `aincient_block` (`loadBlockIntoStudio`, language-neutral). Both
   * edit in the Content studio.
   */
  | { kind: "node"; doc: "page" | "block"; nid: number; langcode: string | null; title?: string }
  /**
   * A Checks audit room — the read-only health report for a node. Its own kind
   * (not a `node` doc) because it drives the Checks studio, is set synchronously
   * (audit-state, no async load / no dead-end), and carries no langcode.
   */
  | { kind: "audit"; nid: number; title?: string }
  /**
   * A Media studio room — editing ONE image-media item, reached by opening it
   * from the Library shelf. Its own kind (not a `node` doc) because it drives the
   * `media` studio, not Content, and carries an `id` (no langcode — media is
   * language-neutral). Async-loaded like a node room (`loadMediaIntoStudio`), so
   * it shares the machine's doc-load path (loading → loaded / dead-end).
   *
   * A media room with NO `id` is the "new image" room — the analogue of the
   * Content `draft`: no media entity yet, so nothing to load (docIdentity is null,
   * it settles straight to idle). Its chat rail runs the image agent to generate a
   * first image from a prompt; the `generate_image` result adopts the minted id
   * (`adoptRoom`), turning it into a normal media room — exactly as a draft's first
   * Save mints a node. Only reachable when image generation is available (the
   * Library gates the "New image" entry on the media studio having an agent).
   */
  | { kind: "media"; id?: number; title?: string };

/** The collection studio whose rooms come into being with nodes (List + Nodes). */
export const COLLECTION_STUDIO: StudioKey = "content";

/** The studio the Checks audit rooms drive. */
export const AUDIT_STUDIO: StudioKey = "checks";

/** The studio a Media item room drives. */
export const MEDIA_STUDIO: StudioKey = "media";

/** A stable string key for a room (React key + active-room comparison). */
export function roomId(room: Room): string {
  if (room.kind === "studio") return `studio:${room.studio}`;
  if (room.kind === "list") return "content:list";
  if (room.kind === "shelf") return "media:shelf";
  if (room.kind === "draft") return room.thread ? `content:draft:${room.thread}` : "content:draft";
  if (room.kind === "audit") return `checks:audit:${room.nid}`;
  if (room.kind === "media") return room.id != null ? `media:${room.id}` : "media:new";
  return `content:${room.doc}:${room.nid}:${room.langcode ?? ""}`;
}

/** Whether two rooms are the same location (compared by identity, not title). */
export function sameRoom(a: Room, b: Room): boolean {
  return roomId(a) === roomId(b);
}

/**
 * The room a SECTION lands on when you pick it in the header (Phase B). A section
 * is a studio; picking it enters its canonical room — the Content section's home
 * is the List directory (Browse pages), the Media section's is the Library SHELF
 * (its browse room, DECISIONS 0168), every other section is its own singleton
 * studio room. A collection's directory (not a draft/node/item) is the neutral
 * landing: the section's in-progress work is reached from the sidebar's WIP list
 * and its pins, not by re-picking the section tab.
 */
export function sectionRoom(studio: StudioKey): Room {
  if (studio === COLLECTION_STUDIO) return { kind: "list" };
  if (studio === MEDIA_STUDIO) return { kind: "shelf" };
  return { kind: "studio", studio };
}

/**
 * A collection family's BROWSE room — the Content List, the Library shelf. A
 * browse room is a directory, not a workspace, so it is a NEUTRAL landing:
 * entering one never auto-resumes a conversation, even when the family's
 * threads BUCKET there (every media thread lives on the shelf, since media
 * threads don't home to items). Resuming in-progress work is the sidebar WIP
 * row's job — it names its thread explicitly. Pages earned this rule first
 * ("clicking Pages returns to the listing"); the predicate makes it structural
 * so every browse room gets it by construction, not per call site.
 */
export function isBrowseRoom(room: Room): boolean {
  return room.kind === "list" || room.kind === "shelf";
}

/**
 * The mono CONTEXT LABEL a WIP row wears (study 02, Plate 8), or null for none.
 * Names the page the thread touches — a New-page draft reads "New page", a
 * homed thread reads its page's title (never a machine id: "#15" is a debug
 * log's voice, not a ledger's). Studio / List / audit rooms carry no label —
 * their section already names them.
 */
export function roomBadge(room: Room): string | null {
  if (room.kind === "draft") return "New page";
  if (room.kind === "node") return room.title || `Page ${room.nid}`;
  return null;
}

/**
 * The studio a room drives — Checks for an audit room, Content for the List, the
 * New-page draft, and both Node docs (page/block), else the studio-room's own studio.
 */
export function roomStudio(room: Room): StudioKey {
  if (room.kind === "studio") return room.studio;
  if (room.kind === "audit") return AUDIT_STUDIO;
  if (room.kind === "media" || room.kind === "shelf") return MEDIA_STUDIO;
  return COLLECTION_STUDIO;
}
