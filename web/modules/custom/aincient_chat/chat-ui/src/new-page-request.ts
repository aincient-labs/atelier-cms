/**
 * A one-shot "open the New page form" request.
 *
 * The birth form is where a page's TYPE is chosen, and the type is fixed for the
 * life of the page (DECISIONS 0378) — so every path that starts a page has to go
 * through it, or it silently commits the user to a landing page they were never
 * asked about. That is exactly what the content browser's "New page" button used
 * to do: it called startNewPage(), which seeds a node-less LANDING draft, while
 * only the sidebar's `+` opened the form. A user who never touched the sidebar
 * could not create a blog post at all.
 *
 * The form is mounted once (in the Content sidebar, where its onCreated already
 * knows how to enter the new node's room), and the browser is a sibling several
 * levels away — so the request travels through this tiny store rather than
 * threading a callback through the tree. Same shape as the other console
 * stores: fire, subscribe, unsubscribe.
 */

const subscribers = new Set<() => void>();

/** Ask for the New page form (title + type) to open. */
export function requestNewPage(): void {
  for (const cb of subscribers) cb();
}

/** Subscribe to New-page requests; returns an unsubscribe fn. */
export function subscribeNewPageRequest(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}
