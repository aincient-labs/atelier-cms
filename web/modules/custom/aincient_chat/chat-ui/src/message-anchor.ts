/**
 * Address one chat message from outside the transcript — the Checks rail's
 * "See Atelier's reply" on a finding that still needs you (DECISIONS 0453).
 *
 * The transcript stamps `data-ain-msg="<message id>"` on each assistant
 * message's root; {@link revealMessage} scrolls that message into view and
 * flashes it. Leaf module (no app imports), like `studio-field-anchor.ts`.
 */

/** The attribute the transcript stamps on an assistant message's root. */
export const MESSAGE_ANCHOR_ATTR = "data-ain-msg";

/** Scroll a message into view and mark it briefly. Returns whether it was found
 *  (an older page of the thread may not be loaded). */
export function revealMessage(id: string, root: ParentNode = document): boolean {
  const value = typeof CSS !== "undefined" ? CSS.escape(id) : id;
  const el = root.querySelector<HTMLElement>(`[${MESSAGE_ANCHOR_ATTR}="${value}"]`);
  if (!el) return false;
  el.scrollIntoView?.({ block: "center", behavior: "smooth" });
  el.setAttribute("data-revealed", "");
  window.setTimeout(() => el.removeAttribute("data-revealed"), 1600);
  return true;
}
