/**
 * The Checks studio's "which node is being audited" — the audit parallel of
 * page-state's open-document identity, kept here (not in the ChecksStudio
 * component) so it can be read and driven from OUTSIDE the component:
 *   - url-sync reads it to reflect ?audit=<nid> into the URL, and writes it on
 *     back/forward so the report follows history;
 *   - ChecksStudio mirrors its own selection here and adopts external changes.
 *
 * Audit-only and ephemeral — like page-state, nothing here is persisted; it's
 * just the in-context node. null = no page picked (the URL then carries no
 * ?audit, which is itself the "nothing in context" signal).
 */

let currentNode: string | null = null;
const subscribers = new Set<() => void>();

/** The node currently being audited, or null when none is picked. */
export function getAuditNode(): string | null {
  return currentNode;
}

/** Set the audited node and notify listeners. No-op (no notify) when unchanged,
 *  so the ChecksStudio mirror ⇄ url-sync reflection loop converges in one pass. */
export function setAuditNode(node: string | null): void {
  if (node === currentNode) return;
  currentNode = node;
  for (const cb of subscribers) cb();
}

/** Subscribe to audited-node changes; returns an unsubscribe fn. */
export function subscribeAuditNode(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}
