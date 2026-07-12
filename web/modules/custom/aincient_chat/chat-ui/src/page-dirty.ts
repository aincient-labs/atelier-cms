/**
 * Whether the open page/block draft has unsaved edits — the page studio's local
 * `dirty` verdict, lifted to a module so the SHELL can guard against losing it.
 *
 * A thread switch drops the open document (url-sync clear-on-switch), which throws
 * away an unsaved draft. The switch commits *before* url-sync sees it, so the guard
 * has to run at the switch INITIATORS (the room tree, the thread list, the New/
 * agent-picker buttons) — which live outside the studio and can't read its local
 * `dirty`. So the studio pushes its verdict here and the shell reads it
 * synchronously before switching (studio-navigation.md — dirty-guard follow-up).
 *
 * Deliberately a bare getter/setter, not reactive: the guard consults it once, at
 * click time. The confirm dialog it drives is ordinary React state in the shell.
 * Scope is the page/block studio (what the follow-up is about); the brand/globals
 * studios carry their own dirty-confirm on close and are out of scope here.
 */

let dirty = false;

/** The studio publishes its dirty verdict (and resets to false when it unmounts). */
export function setPageDirty(next: boolean): void {
  dirty = next;
}

/** Read synchronously at switch time — true iff the open draft has unsaved edits. */
export function isPageDirty(): boolean {
  return dirty;
}
