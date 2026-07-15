/**
 * Console URL bases — the single source of truth for WHERE the SPA is mounted
 * and WHERE its JSON API lives. Both are injected by the shell (aincient_chat
 * ConsoleController) from Drupal's route table, so no path prefix is ever
 * hardcoded in the bundle: rename the route (e.g. /atelier) and it flows here.
 *
 * Two INDEPENDENT bases, so the backend can be decoupled from the front-end:
 *  - basePath — the client-route base the SPA is served at (e.g. "/atelier").
 *    Deep links, room paths and same-surface link detection anchor here.
 *  - apiBase  — the prefix every backend fetch is built against. Defaults to
 *    basePath (the API ships under the same segment today), but can point at a
 *    different prefix or origin, so the console can run against a relocated
 *    backend without the SPA moving.
 *
 * This is a LEAF module: it reads `window.aincientChat` directly and imports
 * nothing app-side, so the URL codec, the chat adapter and the studios can all
 * depend on it without a cycle. Keep it dependency-free.
 */

type UrlSettings = { basePath?: string; apiBase?: string };

function urlSettings(): UrlSettings {
  const w = window as unknown as {
    aincientChat?: UrlSettings;
    drupalSettings?: { aincientChat?: UrlSettings };
  };
  return w.aincientChat ?? w.drupalSettings?.aincientChat ?? {};
}

/**
 * The console's client-route base ("…/atelier"), subdir-install-safe. Prefer the
 * server-injected `basePath`; fall back to sniffing it out of the current path
 * (an un-rebuilt shell, or a dev harness with no injected settings).
 */
export function consoleBase(): string {
  const injected = urlSettings().basePath;
  if (injected) return injected;
  const m = window.location.pathname.match(/^(.*\/atelier)(?:\/|$)/);
  return m ? m[1] : "/atelier";
}

/**
 * The JSON-API prefix — where console fetches go. Decoupled from
 * {@link consoleBase}: defaults to it (same segment), but an operator can point
 * the console at a relocated backend via `aincient_chat.settings:api_base`.
 */
export function apiBase(): string {
  return urlSettings().apiBase ?? consoleBase();
}

/**
 * Build a backend URL from an API-relative path, anchored at {@link apiBase}.
 * `apiUrl("/chat")`, `apiUrl(`/media/${id}/schema`)` — the ONE place a path
 * segment meets the API prefix, so no `/atelier` literal lives in any fetch.
 */
export function apiUrl(path: string): string {
  const p = path.startsWith("/") ? path : `/${path}`;
  return `${apiBase()}${p}`;
}
