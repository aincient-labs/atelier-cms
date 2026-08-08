/**
 * The pending-attachments store — images the user has attached to the composer
 * but not yet sent. One-shot per turn: the adapter reads the refs at send time,
 * folds them into the POST body as `context_refs`, then clears the store.
 *
 * A plain module-singleton store (the media-state pattern), NOT zustand/context:
 * the composer UI writes it, the send adapter reads it, and neither can reach the
 * other through React. React reads via `useSyncExternalStore(subscribeAttachments,
 * getAttachments)`; the adapter reads {@link getAttachments} directly at send time.
 * Dependency-free by design.
 */

/** One uploaded-but-unsent attachment: the server ref plus what the chip renders. */
export interface PendingAttachment {
  /** The `context:<id>` handle the server folds into the turn. */
  ref: string;
  /** Original filename, shown (truncated) on the chip. */
  filename: string;
  /** What kind of attachment this is; drives the chip's icon-vs-thumb render. */
  kind: "image" | "document";
  /** A small preview URL for an image chip; absent for a document. */
  thumb?: string;
  /** Byte size, shown on a document chip. */
  size?: number;
}

let pending: PendingAttachment[] = [];
const subscribers = new Set<() => void>();

function emit(): void {
  for (const cb of subscribers) cb();
}

/** Subscribe to attachment-store changes (add / remove / clear). */
export function subscribeAttachments(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/** The currently pending attachments (a stable array between mutations). */
export function getAttachments(): PendingAttachment[] {
  return pending;
}

/** Append a freshly uploaded attachment. */
export function addAttachment(a: PendingAttachment): void {
  pending = [...pending, a];
  emit();
}

/** Drop the attachment with this ref (the chip's remove button). */
export function removeAttachment(ref: string): void {
  pending = pending.filter((a) => a.ref !== ref);
  emit();
}

/** Empty the store — the adapter calls this once a turn is dispatched. */
export function clearAttachments(): void {
  if (pending.length === 0) return;
  pending = [];
  emit();
}
