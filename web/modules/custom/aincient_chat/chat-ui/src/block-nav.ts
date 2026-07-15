import { activeRoom, consoleNav, deriveRoomFromStores } from "./console-nav";
import { startNewBlock } from "./page-state";
import { sameRoom, type Room } from "./rooms-core";

/**
 * Open a block editor IN THE CONSOLE — same tab, beside the chat.
 *
 * A global block edits in the Content studio's block-authoring mode, which owns
 * the page-studio machinery, the editor lock, and the moderation transitions.
 * This is a WITHIN-workspace move (surface-nav.ts: room ↔ room = same tab), so
 * it drives the console machine rather than opening a new tab: an existing block
 * enters its node room (Content page/block editor); a new block seeds a fresh,
 * node-less draft in the Content room (the same one-shot the `?new=block` deep
 * link consumes on load). The machine's dirty guard is the correct protection
 * for any unsaved parent context — it prompts before switching rooms — which is
 * what the old new-tab open was reaching for (DECISIONS 0034 revisited).
 *
 * Shared by the page studio's reference field and the Library destination so the
 * "edit / create a block from elsewhere" contract lives in exactly one place. A
 * modifier/middle click never reaches here (these are buttons, not anchors);
 * the durable block URL is still deep-linkable directly.
 */
export function openBlock(target: { id: string } | "new"): void {
  if (target === "new") {
    // Seed the node-less draft, then adopt the room the stores now imply
    // (thread-addressed) — exactly what url-sync does for a `?new=block` link.
    startNewBlock();
    consoleNav.adoptRoom(deriveRoomFromStores());
    return;
  }
  const nid = Number(target.id);
  if (!Number.isInteger(nid) || nid <= 0) return;
  const room: Room = { kind: "node", doc: "block", nid, langcode: null };
  if (sameRoom(activeRoom(), room)) return;
  consoleNav.enterRoom(room);
}
