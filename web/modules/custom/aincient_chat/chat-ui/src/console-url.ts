import { consoleBase } from "./console-config";
import { COLLECTION_STUDIO, MEDIA_STUDIO, sectionRoom, type Room } from "./rooms-core";
import { isStudioAccessible, serverDefaultStudio, studioFromSlug, studioSlug } from "./studios";

// Re-exported so existing importers (`./console-url`) keep resolving — the base
// now comes from the server-injected `basePath` (see ./console-config).
export { consoleBase };

/**
 * The URL ↔ Room codec (plans/console-state-machine.md Phase 2, decision D3).
 *
 * Room owns the PATH; the active thread rides as a single `?thr=` query. The open
 * document is not a separate axis — a Content page/block or a Checks audit IS the
 * room, so its node id lives in the path, not a `?page=/?block=/?audit=` query.
 *
 *   /atelier                          → the server-default studio room (bare
 *                                        entry point; the projection canonicalises
 *                                        it to the explicit form below on load)
 *   /atelier/general                  → a studio-singleton room
 *   /atelier/design-system            → studio key `design_system`, hyphenated
 *   /atelier/content                  → the Content List room (the directory)
 *   /atelier/content/draft            → the New-page draft room (no node, no
 *                                        thread id yet)
 *   /atelier/content/draft/thr_x      → …that draft, addressed by its thread
 *                                        (the draft's identity is its thread, so
 *                                        it rides in the path, not ?thr=)
 *   /atelier/content/node/5           → a Content page node (aincient_page)
 *   /atelier/content/node/5/de        → …its `de` translation (source lang omits it)
 *   /atelier/content/block/9          → a Content block node (aincient_block)
 *   /atelier/checks/node/5            → a Checks audit room (read-only report)
 *   /atelier/library                  → the Library shelf (the media family's
 *                                        browse room; bare /media and the legacy
 *                                        /media/image/new land here too)
 *   /atelier/media/image/7            → a Media studio item (edit one image)
 *   …any of the above + ?thr=thr_x    → with a specific thread open in the room
 *
 * This module is pure over `window.location` + the studio catalog — no store or
 * runtime reads — so `console-nav` can seed the boot room from it and `url-sync`
 * can both project to it and parse from it. Forgiving on the way in (an unknown
 * studio / a non-numeric id falls back rather than 404s); canonical on the way out.
 */

/**
 * Only thr_* ids are URL-routable — it's what both ends mint. Threads with legacy
 * ids (pre-convention test data) still open from the sidebar; they just don't get
 * a `?thr=` query (the room path alone is their URL).
 */
const ROUTABLE_ID = /^thr_[A-Za-z0-9_-]+$/;

/**
 * The href a console deep-link anchor points at, built from a query string
 * ("thr=thr_x") and anchored at the current console base (subdir-install-safe).
 * Used as the real `href` of an in-place link so a modifier / middle click can
 * open the durable URL in a new tab natively.
 */
export function consoleHref(query: string): string {
  return `${consoleBase()}?${query}`;
}

/**
 * A click the OS reserves for opening in a new tab/window — cmd (mac) / ctrl
 * (win) / shift, or any non-primary button. In-place click handlers return early
 * on these so the browser follows the anchor's href natively.
 */
export function opensNewTab(e: {
  metaKey: boolean;
  ctrlKey: boolean;
  shiftKey: boolean;
  button: number;
}): boolean {
  return e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0;
}

/** A studio-singleton path resolves to the collection's browse room where one
 *  exists — the Content List, the media family's Library shelf — else the studio
 *  room. Exactly {@link sectionRoom}: the URL's studio segment and the header's
 *  section pick must land on the same canonical room. */
function studioOrList(studio: string): Room {
  return sectionRoom(studio);
}

/** The path segments after the console base, e.g. ["content", "node", "5"]. */
function roomSegments(): string[] {
  const base = consoleBase();
  const rest = window.location.pathname.slice(base.length);
  return rest.split("/").filter(Boolean);
}

/** Room → the room-owned path (no query), anchored at the current base. */
export function roomToPath(room: Room): string {
  const base = consoleBase();
  switch (room.kind) {
    case "studio":
      return `${base}/${studioSlug(room.studio)}`;
    case "list":
      return `${base}/content`;
    case "shelf":
      // The media family's browse room keeps the Library's owner-word address.
      return `${base}/library`;
    case "draft":
      return `${base}/content/draft${room.thread ? `/${room.thread}` : ""}`;
    case "audit":
      return `${base}/checks/node/${room.nid}`;
    case "media":
      // An id-less media room is the "new image" room → /media/image/new.
      return `${base}/media/image/${room.id ?? "new"}`;
    case "node": {
      const seg = room.doc === "block" ? "block" : "node";
      const lang = room.doc === "page" && room.langcode ? `/${room.langcode}` : "";
      return `${base}/content/${seg}/${room.nid}${lang}`;
    }
  }
}

/** Room + active thread → the full URL to show (path + `?thr=` for routable ids).
 *  The draft room carries its thread IN the path already, so it never takes a
 *  `?thr=` query (that would duplicate its identity). */
export function roomToUrl(room: Room, threadId: string | null): string {
  if (room.kind === "draft") return roomToPath(room);
  const q = threadId && ROUTABLE_ID.test(threadId) ? `?thr=${threadId}` : "";
  return roomToPath(room) + q;
}

/**
 * Parse the current location into the room it names + the thread in `?thr=`.
 * Forgiving: an unknown studio or a non-numeric node id falls back to the
 * server-default studio room; a legacy (non-thr_*) `?thr=` is dropped.
 */
export function parseUrl(): { room: Room; threadId: string | null } {
  const seg = roomSegments();
  const thr = new URLSearchParams(window.location.search).get("thr");
  const threadId = thr && ROUTABLE_ID.test(thr) ? thr : null;

  const fallback = studioOrList(serverDefaultStudio());
  if (seg.length === 0) return { room: fallback, threadId };

  // /atelier/library — the media family's browse room (the shelf). Resolved
  // BEFORE the studio-slug branch: `library` is still a server-side access key
  // (it would resolve to a ghost studio room with no registry entry), but its
  // address belongs to the shelf. Gated on entering the media studio.
  if (seg[0] === "library") {
    return { room: isStudioAccessible(MEDIA_STUDIO) ? { kind: "shelf" } : fallback, threadId };
  }

  const studio = studioFromSlug(seg[0]);
  if (!studio) return { room: fallback, threadId };

  // /atelier/<studio> — a studio-singleton (or the Content List).
  if (seg.length === 1) return { room: studioOrList(studio), threadId };

  // /atelier/content/draft[/<thread>] — the New-page draft room (no node id).
  // The thread is IN the path (the draft's identity), so it also becomes the
  // active thread; a bare /content/draft is a fresh draft with no thread yet.
  if (studio === COLLECTION_STUDIO && seg[1] === "draft") {
    const draftThread = seg[2] && ROUTABLE_ID.test(seg[2]) ? seg[2] : undefined;
    return {
      room: draftThread ? { kind: "draft", thread: draftThread } : { kind: "draft" },
      threadId: draftThread ?? threadId,
    };
  }

  // /atelier/media/image/<id> — a Media studio item. The bundle segment
  // (image, later document) sits between the studio slug and the id, keeping the
  // path clear of the flat /atelier/media/* JSON API. The legacy sentinel `new`
  // (the retired id-less "new image" room) and any missing/!numeric id land on
  // the family's browse room — the Library shelf, whose chat is the generate
  // path now (DECISIONS 0168).
  if (studio === MEDIA_STUDIO) {
    const id = Number(seg[2]);
    return seg[1] === "image" && Number.isInteger(id) && id > 0
      ? { room: { kind: "media", id }, threadId }
      : { room: { kind: "shelf" }, threadId };
  }

  // /atelier/<studio>/(node|block)/<nid>[/<lang>] — a document room.
  const kind = seg[1]; // "node" | "block"
  const nid = Number(seg[2]);
  if (!Number.isInteger(nid) || nid <= 0) return { room: studioOrList(studio), threadId };

  if (studio === "checks" && kind === "node") return { room: { kind: "audit", nid }, threadId };
  if (studio === COLLECTION_STUDIO && kind === "block") {
    return { room: { kind: "node", doc: "block", nid, langcode: null }, threadId };
  }
  if (studio === COLLECTION_STUDIO && kind === "node") {
    const langcode = seg[3] ?? null;
    return { room: { kind: "node", doc: "page", nid, langcode }, threadId };
  }
  // A doc segment under a studio that has no documents → its landing room.
  return { room: studioOrList(studio), threadId };
}
