/**
 * The "you can't open this document" terminal state — the deep-link parallel of
 * page-state / audit-state, but for a load that FAILED.
 *
 * A ?page=/?block=/?audit= deep link can outlive a document's accessibility: it
 * may be a draft owned by someone else (403) or since deleted (404). The loaders
 * used to throw and every caller swallowed it (`.catch(() => {})`), leaving the
 * studio silently empty. Instead the failing loader records the attempt here;
 * the shell renders the shared {@link ThreadEndState} pane over the workspace,
 * and url-sync keeps the ?page= query (so a refresh re-checks access rather than
 * canonicalising the attempt away). Ephemeral — nothing is persisted; cleared
 * when a document loads, the thread switches, or the user takes a next action.
 */

export type DocEndKind = "denied" | "gone";

export type DocEnd = {
  /** denied = 403 (no access); gone = 404 (missing/deleted). */
  kind: DocEndKind;
  /** Which deep-link axis failed (drives the kept ?page=/?block=/?audit= query). */
  docKind: "page" | "block" | "audit" | "media";
  /** The node id the deep link named. */
  id: string;
};

let current: DocEnd | null = null;
const subscribers = new Set<() => void>();

/** The current document dead-end, or null when none. */
export function getDocEnd(): DocEnd | null {
  return current;
}

/** Set (or clear) the document dead-end; no-op when unchanged. */
export function setDocEnd(end: DocEnd | null): void {
  if (
    current === end ||
    (current &&
      end &&
      current.kind === end.kind &&
      current.docKind === end.docKind &&
      current.id === end.id)
  ) {
    return;
  }
  current = end;
  for (const cb of subscribers) cb();
}

/** Clear the dead-end (a document loaded, the user navigated, or took action). */
export function clearDocEnd(): void {
  setDocEnd(null);
}

/** Subscribe to dead-end changes; returns an unsubscribe fn. */
export function subscribeDocEnd(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}
