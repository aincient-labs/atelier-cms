/**
 * Per-thread SEAL state for the console (the "wrapped up" concept — reserved word
 * per docs/console-state-model.md §1; "lock" now means only the editor pen).
 *
 * A published thread is SEALED (read-only): the composer is replaced by the
 * {@link ThreadEndState} celebration pane so a finished conversation can't keep
 * burning tokens. Server truth is the session's `ain_locked` metadata (the wire
 * field keeps its legacy name), fed by the /threads listing — but the seal also
 * flips LOCALLY the instant a wrap-up commits, so (unlike the sidebar-only
 * thread-meta) this store is SUBSCRIBABLE: the composer reacts without waiting
 * for a refetch.
 *
 * It also carries the transient "wrap-up just offered" request. Publish can't
 * seal on its own (it doesn't own the runtime, and the offer is cancelable), so
 * it asks the shell — which owns the runtime — to show the celebration and, if
 * the user proceeds, perform the actual seal + new-thread switch.
 */

/** The published page a wrapped-up thread produced (for the "View page" link). */
export type PublishedRef = { url?: string; node?: string };

const sealedThreads = new Map<string, boolean>();
const publishedRefs = new Map<string, PublishedRef>();
const listeners = new Set<() => void>();
let version = 0;

function emit(): void {
  version++;
  for (const l of listeners) l();
}

/** Subscribe to seal-state changes; returns an unsubscribe fn. */
export function subscribeSeals(cb: () => void): () => void {
  listeners.add(cb);
  return () => {
    listeners.delete(cb);
  };
}

/** Monotonic counter bumped on every seal change — a useSyncExternalStore snapshot
 *  for consumers (the room tree) that re-render on seal flips, not just the composer. */
export function sealVersion(): number {
  return version;
}

/**
 * Record a thread's sealed flag (+ optional published page). Server truth from
 * the /threads listing, or a local flip the moment a wrap-up commits. Notifies
 * only when something actually changed.
 */
export function rememberThreadSeal(threadId: string, sealed: boolean, published?: PublishedRef): void {
  const changed = sealedThreads.get(threadId) !== sealed;
  sealedThreads.set(threadId, sealed);
  if (published) {
    publishedRefs.set(threadId, published);
  }
  else if (!sealed) {
    publishedRefs.delete(threadId);
  }
  if (changed || published) emit();
}

/** Whether a thread is wrapped up (sealed / read-only). */
export function isThreadSealed(threadId: string | undefined): boolean {
  return threadId ? sealedThreads.get(threadId) === true : false;
}

/** The published page a wrapped-up thread produced, when known. */
export function threadPublished(threadId: string | undefined): PublishedRef | undefined {
  return threadId ? publishedRefs.get(threadId) : undefined;
}

/* ------------------------------------------------------- pending wrap-up */

/** A cancelable wrap-up offer raised by Publish, fulfilled by the shell. */
export type WrapupRequest = { threadId: string; published: PublishedRef };

let pendingWrapup: WrapupRequest | null = null;
const wrapupListeners = new Set<() => void>();

/** The pending wrap-up offer, or null (the default / after cancel|commit). */
export function getPendingWrapup(): WrapupRequest | null {
  return pendingWrapup;
}

/** Raise (or clear) the wrap-up offer. Publish raises it on a first publish; the
 *  shell clears it on commit ("Start a new thread") or cancel ("Keep editing"). */
export function requestWrapup(req: WrapupRequest | null): void {
  pendingWrapup = req;
  for (const l of wrapupListeners) l();
}

/** Subscribe to wrap-up-offer changes; returns an unsubscribe fn. */
export function subscribeWrapup(cb: () => void): () => void {
  wrapupListeners.add(cb);
  return () => {
    wrapupListeners.delete(cb);
  };
}

/**
 * Offer to wrap the active thread up after a deliberate studio Publish. Shared by
 * every studio (Content / Brand / Globals): a successful Publish is the natural
 * "done" beat, so we raise the cancelable celebration in the chat composer — but
 * only when there's a backend thread to seal (a chat actually happened).
 * `published` carries an optional View link: a page passes its URL/node; a
 * site-wide Brand/Globals publish (or a block) has no single page, so it passes
 * nothing and the pane just offers a fresh thread.
 */
export function offerWrapup(threadId: string | undefined, published: PublishedRef = {}): void {
  if (threadId) requestWrapup({ threadId, published });
}
