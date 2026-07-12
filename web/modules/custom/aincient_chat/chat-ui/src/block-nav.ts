import { consoleBase } from "./console-url";

/**
 * Open a block editor in a NEW browser tab.
 *
 * A global block edits in the Content studio's block-authoring mode, which owns
 * the page-studio machinery, the editor lock, and the moderation transitions.
 * Opening it in its own tab keeps any live, unsaved parent context alive — the
 * page whose reference field launched the edit, or the Library browse the user
 * is scanning (DECISIONS 0034 revisited). An existing block opens at its room
 * path (/content/block/<id>); a new block (no nid yet) opens the Content list
 * with a one-shot `?new=block` creation intent consumed on load. Built off the
 * current console base so it survives a sub-path mount.
 *
 * Shared by the page studio's reference field and the Library destination so the
 * "edit / create a block from elsewhere" URL contract lives in exactly one place.
 */
export function openBlockTab(target: { id: string } | "new"): void {
  const base = consoleBase();
  const url =
    target === "new"
      ? `${base}/content?new=block`
      : `${base}/content/block/${encodeURIComponent(target.id)}`;
  window.open(url, "_blank", "noopener,noreferrer");
}
