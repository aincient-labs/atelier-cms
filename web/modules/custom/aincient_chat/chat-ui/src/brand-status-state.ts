/**
 * Shared brand design-intent status — a tiny pub/sub so the Brand Studio rail's
 * status control and the agent's HITL proposal card (two separate React trees)
 * stay in sync. Both write status through the same POST /aincient/brand/status
 * endpoint; whichever writes broadcasts the new value here so the other adopts
 * it without a page reload (e.g. applying an agent proposal updates the rail
 * badge live). This holds only the last-known value for reflection — the
 * persisted config remains the single source of truth (re-seeded on load).
 */

export type BrandStatus = { stage: string; locked: boolean };

let current: BrandStatus | null = null;
const subscribers = new Set<(status: BrandStatus) => void>();

/** The last broadcast status, or null before anything has seeded it. */
export function getBrandStatus(): BrandStatus | null {
  return current;
}

/** Broadcast a new status to all subscribers (idempotent for equal values). */
export function emitBrandStatus(status: BrandStatus): void {
  if (current && current.stage === status.stage && current.locked === status.locked) return;
  current = { stage: status.stage, locked: status.locked };
  for (const fn of subscribers) fn(current);
}

/** Subscribe to status changes; returns an unsubscribe. */
export function subscribeBrandStatus(fn: (status: BrandStatus) => void): () => void {
  subscribers.add(fn);
  return () => subscribers.delete(fn);
}
