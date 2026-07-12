/**
 * Per-thread working-node (the resource-first navigation linchpin, frontend half).
 *
 * A thread is HOMED to a resource — a Node(nid, lang) — the first time it takes a
 * page/audit turn on a saved node (studio-navigation.md §3.4, backend
 * `ain_working_node`). That homing is what buckets the thread under its Node room
 * in the sidebar tree ({@see rooms.ts}). Like the flow pin in `flow.ts` and the
 * activity time in `thread-meta.ts`, it lives in a side map keyed by backend
 * thread id — the thread-list runtime only models id/title/status.
 *
 * Two feeds:
 *  - the /threads listing (server truth, carries a resolved `title`), applied in
 *    runtime.tsx on load, and
 *  - an optimistic client stamp when a homed turn is sent (adapter.ts), so a
 *    freshly-homed thread jumps into its Node room without waiting for the next
 *    /threads refetch.
 *
 * HOME-ONCE, mirroring the backend: the first real nid sticks and never migrates;
 * a later stamp with a DIFFERENT nid is ignored. A new title for the SAME node
 * refreshes the room label. Unlike a bare side map this store is REACTIVE
 * (subscribe/version) — a new homing spawns a Node room, so the tree must
 * re-render when one lands.
 */

export type WorkingNode = { nid: number; langcode: string | null; title?: string };

const nodes = new Map<string, WorkingNode>();
const listeners = new Set<() => void>();
let version = 0;

function emit(): void {
  version++;
  for (const l of listeners) l();
}

/** Subscribe to homing changes (a new Node room, or a resolved room label). */
export function subscribeWorkingNodes(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/** Monotonic counter bumped on every homing change — a useSyncExternalStore snapshot. */
export function workingNodeVersion(): number {
  return version;
}

/**
 * Record a thread's working node (server truth or an optimistic client stamp).
 * Home-once: a null/empty homing never un-homes a thread, and a different nid is
 * ignored once one is set — a thread stays in the room it was born in. A newer
 * title for the same node updates the label. No-op when nothing changed.
 */
export function rememberThreadWorkingNode(
  remoteId: string | undefined,
  wn: WorkingNode | null | undefined,
): void {
  if (!remoteId || !wn || !wn.nid) return;
  const prev = nodes.get(remoteId);
  // Home-once — a thread never migrates rooms.
  if (prev && prev.nid !== wn.nid) return;
  const title = wn.title ?? prev?.title;
  if (prev && prev.nid === wn.nid && prev.langcode === (wn.langcode ?? null) && prev.title === title) {
    return;
  }
  nodes.set(remoteId, { nid: wn.nid, langcode: wn.langcode ?? null, ...(title ? { title } : {}) });
  emit();
}

/** The resource a thread is homed to, or undefined when it never homed. */
export function threadWorkingNode(remoteId: string | undefined): WorkingNode | undefined {
  return remoteId ? nodes.get(remoteId) : undefined;
}
