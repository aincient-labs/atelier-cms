/**
 * Per-thread display metadata for the sidebar.
 *
 * The /threads listing carries each thread's last-activity time, but the
 * thread-list runtime only models id/title/status — so, like the flow pin in
 * `flow.ts`, the extra metadata lives in a side map keyed by backend thread id
 * (fed once per listing; the sidebar re-renders off the runtime's own list
 * updates, which the listing triggers, so no separate subscription is needed).
 */

const activity = new Map<string, number>();

/** Record a thread's last-activity time (epoch seconds, server truth). */
export function rememberThreadActivity(threadId: string, epochSeconds: number): void {
  activity.set(threadId, epochSeconds);
}

/** A thread's last-activity time, when the listing reported one. */
export function threadActivity(threadId: string | undefined): number | undefined {
  return threadId ? activity.get(threadId) : undefined;
}

/*
 * Studio-given thread titles (study 02, Plate 8). The server mints a name after
 * the first exchange and streams it as a `thread_title` frame; the runtime's
 * list title only refreshes on the next /threads fetch, so this override map
 * renames the sidebar row LIVE. Subscribable because the frame lands outside
 * React (the SSE adapter).
 */
const titles = new Map<string, string>();
let titleV = 0;
const titleListeners = new Set<() => void>();

/** Record a studio-given title (from the `thread_title` frame or the listing). */
export function rememberThreadTitle(threadId: string, title: string): void {
  if (titles.get(threadId) === title) return;
  titles.set(threadId, title);
  titleV++;
  titleListeners.forEach((l) => l());
}

/** The studio-given title override, when one has arrived this session. */
export function threadTitle(threadId: string | undefined): string | undefined {
  return threadId ? titles.get(threadId) : undefined;
}

export function subscribeThreadTitles(listener: () => void): () => void {
  titleListeners.add(listener);
  return () => titleListeners.delete(listener);
}

export function threadTitleVersion(): number {
  return titleV;
}
