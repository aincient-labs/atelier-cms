import type { ComponentType, SVGProps } from "react";
import { studioOfThread } from "./flow";
import { activeRoom } from "./console-nav";
import { COLLECTION_STUDIO, MEDIA_STUDIO, isBrowseRoom, roomBadge, roomId, roomStudio, sameRoom, sectionRoom, type Room } from "./rooms-core";
import { studioDef } from "./studio-registry";
import { agentsForStudio } from "./studios";
import { threadActivity } from "./thread-meta";
import { threadWorkingNode } from "./thread-working-node";

// The pure room primitives live in `rooms-core.ts` (dependency-free, so the
// statechart + its tests can import them without this module's store graph).
// Re-exported here so existing `import … from "./rooms"` call sites are unchanged.
export { COLLECTION_STUDIO, MEDIA_STUDIO, isBrowseRoom, roomBadge, roomId, roomStudio, sameRoom, sectionRoom, type Room };
// `activeRoom()` now reads the console statechart's `context.room` (the single
// source of truth); its implementation + the store→machine bridge live in
// `console-nav.ts`. Re-exported here so existing call sites are unchanged.
export { activeRoom };

/**
 * The resource-first navigation model (studio-navigation.md §2).
 *
 * The console's navigation is a tree of ROOMS, not a flat thread list. A room is
 * where work on one thing happens; a thread is an ephemeral log inside a room.
 * Three shapes:
 *
 *  - a STUDIO room — one permanent singleton per studio (General, Design System,
 *    Globals, Checks). It exists from install and is never created or deleted.
 *  - the Content LIST room — the collection's landing (the page browser); the
 *    Content studio with no node open.
 *  - a Content NODE room — Node(nid, lang), one per page you've worked on. These
 *    come into being with nodes: a thread homes to a node (thread-working-node.ts)
 *    and that homing is what spawns the room.
 *
 * This module is the pure data layer (no React): it maps a thread to its room,
 * derives the active room from console state, and builds the grouped tree the
 * sidebar renders. Navigation (switching threads / loading a node) lives in the
 * shell, which owns the runtime and must respect the emitNextTick batching rule.
 */

/** The minimum a thread row needs to be placed + sorted in the tree. */
export type ThreadRow = { remoteId: string; sealed?: boolean; archived?: boolean };

/** The human label for a room (a Node room falls back to "Page {nid}"). */
export function roomLabel(room: Room): string {
  if (room.kind === "studio") return studioDef(room.studio)?.name ?? room.studio;
  if (room.kind === "list") return "Browse pages";
  if (room.kind === "shelf") return "Browse the Library";
  if (room.kind === "draft") return "New page";
  if (room.kind === "media") return room.title || (room.id != null ? `Image ${room.id}` : "New image");
  return room.title || `Page ${room.nid}`;
}

/** The glyph for a room — the studio's icon (Content's DocumentIcon for List/Node). */
export function roomIcon(room: Room): ComponentType<SVGProps<SVGSVGElement>> | undefined {
  return studioDef(roomStudio(room))?.Icon;
}

/** The agents a room offers (its studio's catalog) — drives the agent picker. */
export function roomAgents(room: Room) {
  return agentsForStudio(roomStudio(room));
}

/**
 * The room a thread belongs to. A Content-studio thread routes by its homing: a
 * homed thread → its Node room, an unhomed one → the New-page draft room (it's
 * composing a page with no node yet). Every other studio's threads → that
 * studio's singleton room.
 */
export function roomOfThread(remoteId: string | undefined): Room {
  const studio = studioOfThread(remoteId);
  // A media-family thread buckets on the Library SHELF — the family's home.
  // There is no media homing yet (a generation's adoptRoom moves only the live
  // session into the item room), so the shelf is where its threads live and
  // re-open: chat rail present, the shelf browser as the canvas.
  if (studio === MEDIA_STUDIO) return { kind: "shelf" };
  if (studio !== COLLECTION_STUDIO) return { kind: "studio", studio };
  const wn = threadWorkingNode(remoteId);
  // An un-homed Content thread is composing a page that has no node yet → the
  // New-page draft room, NOT the List. This is the structural fix: the List
  // (directory) never homes a thread, so a draft can never shadow it. The draft
  // is identified by its thread (it has no node id), so it rides in the path.
  // The thread homes to its Node room the moment its first Save/Publish mints
  // the node.
  if (!wn) return remoteId ? { kind: "draft", thread: remoteId } : { kind: "draft" };
  // A thread homes to a page (aincient_page) — blocks are edited standalone and
  // audits are transient, so neither is ever a thread's home.
  return { kind: "node", doc: "page", nid: wn.nid, langcode: wn.langcode, ...(wn.title ? { title: wn.title } : {}) };
}

/** Threads in a room, most-recent first (server activity truth). */
export function threadsInRoom(room: Room, rows: ThreadRow[]): ThreadRow[] {
  return rows
    .filter((r) => sameRoom(roomOfThread(r.remoteId), room))
    .sort((a, b) => (threadActivity(b.remoteId) ?? 0) - (threadActivity(a.remoteId) ?? 0));
}

/**
 * The thread to land on when entering a room: its most-recent LIVE (not sealed)
 * thread, or undefined to start a fresh one. Sealed threads are read-only history
 * and are never re-entered as live (studio-navigation.md §4).
 *
 * A BROWSE room ({@link isBrowseRoom} — the Content List, the Library shelf) is
 * a NEUTRAL landing and always starts fresh. Its family's threads may bucket
 * there (every media thread lives on the shelf), but picking the section must
 * land on the directory, not resume yesterday's conversation — resuming is the
 * sidebar WIP row's job, and it passes its thread explicitly.
 */
export function roomActiveThread(room: Room, rows: ThreadRow[]): string | undefined {
  if (isBrowseRoom(room)) return undefined;
  const live = threadsInRoom(room, rows).filter((r) => !r.sealed && !r.archived);
  return live[0]?.remoteId;
}
