/**
 * Per-thread editorial state — the sidebar row's Draft / In review / Live
 * marker (content-workflow.md Phase 4, milestone A6).
 *
 * A thread homed to a page carries that page's moderation state IN THE THREAD'S
 * LANGUAGE, resolved fresh by the backend at list time (`editorialState` on each
 * /threads row, via aincient_pages' PageModerationState — the same reader the
 * operator's list_pages table uses). Read model only: nothing here writes or
 * transitions. Like `thread-working-node.ts` it lives in a reactive side map
 * keyed by backend thread id, fed from the listing in runtime.tsx.
 */

/** The backend shape: the latest revision's state id, its label, and whether a published copy serves. */
export type EditorialState = { state: string; label: string; live: boolean };

/** What the row shows: the state key (drives the chip tint), the word, and the full detail. */
export type EditorialMarker = { state: "draft" | "needs_review" | "published"; text: string; detail: string };

/**
 * Map a backend state to the row marker. Only the three states with an agreed
 * marker render; anything else (archived, an unknown custom state, no state)
 * shows nothing rather than a guessed word. `detail` keeps the server label so a
 * forward draft over a live page still says "Draft · published copy live".
 * Published reads "Live" — the owner's word, as in the Content browser (0451).
 */
export function editorialMarker(es: EditorialState | null | undefined): EditorialMarker | null {
  if (!es) return null;
  switch (es.state) {
    case "draft":
      return { state: "draft", text: "Draft", detail: es.label || "Draft" };
    case "needs_review":
      return { state: "needs_review", text: "In review", detail: es.label || "Needs review" };
    case "published":
      return { state: "published", text: "Live", detail: !es.label || es.label === "Published" ? "Live" : es.label };
    default:
      return null;
  }
}

const states = new Map<string, EditorialState>();
const listeners = new Set<() => void>();
let version = 0;

/** Subscribe to editorial-state changes. */
export function subscribeEditorialStates(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/** Monotonic counter bumped on every change — a useSyncExternalStore snapshot. */
export function editorialStateVersion(): number {
  return version;
}

/**
 * Record a thread's editorial state from the listing (server truth). Unlike the
 * home-once working node this is LIVE state, so a newer listing always wins and
 * a null clears it (the page was deleted or the thread is unhomed).
 */
export function rememberThreadEditorialState(remoteId: string | undefined, es: EditorialState | null | undefined): void {
  if (!remoteId) return;
  const prev = states.get(remoteId);
  if (!es) {
    if (prev) {
      states.delete(remoteId);
      version++;
      for (const l of listeners) l();
    }
    return;
  }
  if (prev && prev.state === es.state && prev.label === es.label && prev.live === es.live) return;
  states.set(remoteId, { state: es.state, label: es.label, live: es.live });
  version++;
  for (const l of listeners) l();
}

/** A thread's editorial state, or undefined when it has none. */
export function threadEditorialState(remoteId: string | undefined): EditorialState | undefined {
  return remoteId ? states.get(remoteId) : undefined;
}
