import { consoleBase } from "./console-url";

/**
 * Cross-surface navigation policy (plans/surface-switching.md).
 *
 * There are three operator-facing surfaces — the CONSOLE (the workspace, our
 * home), the LIVE SITE (the output the console produces), and `/admin` (the
 * System basement). Moving between them used to split tab behaviour by
 * direction and by call site; this module is the ONE place the policy lives:
 *
 *   | Route                                            | Rule          | Why                                    |
 *   |--------------------------------------------------|---------------|----------------------------------------|
 *   | workspace → output (console → live site)         | new tab       | protect unsaved drafts + open threads  |
 *   | within the workspace (console ↔ /admin, room ↔)  | same tab      | one admin context, one tab             |
 *   | output → workspace (site editor pill → console)  | same tab      | switching modes on the same object     |
 *
 * `openSurface` is the home for PROGRAMMATIC navigations. Declarative links may
 * stay plain anchors — a live-site link is `<a target="_blank" rel="noopener">`
 * (output), a console link is a same-tab `<a>` (workspace) — the anchor form
 * already encodes the rule and gives the browser native modifier/middle-click.
 * Reach for `openSurface` from an onClick handler where there is no anchor.
 *
 * NOTE: within-console ROOM navigation (opening a page/block beside the chat
 * without a page reload) is not this module's job — that goes through
 * `console-nav`'s `enterRoom` / `openPageInPlace`, which preserve the SPA and
 * run the dirty guard. `openSurface(url, 'workspace')` is a full-page load, for
 * workspace URLs that are NOT a plain console room (a `/admin` route, or a
 * console route that must boot with a query the SPA only reads on load, e.g.
 * `?onboarding=1`).
 */

export type SurfaceRoute = "output" | "workspace";

/**
 * Is `href` an internal console route (the `/atelier` base or a path under
 * it)? Same-origin only; anchored at the current console base so a subdir
 * install still recognises its own routes. Used by chat markdown links to pick
 * the tab rule: a console link is workspace (same tab), everything else output.
 */
export function isConsoleHref(href: string): boolean {
  let url: URL;
  try {
    url = new URL(href, window.location.origin);
  } catch {
    return false;
  }
  if (url.origin !== window.location.origin) return false;
  const base = consoleBase();
  return url.pathname === base || url.pathname.startsWith(base + "/");
}

/**
 * Navigate to a surface by its policy role. `output` opens the live site (or an
 * external URL) in a new tab, drafts and threads left untouched behind it;
 * `workspace` loads in the current tab. See the file header for the rules.
 */
export function openSurface(url: string, route: SurfaceRoute): void {
  if (route === "output") {
    window.open(url, "_blank", "noopener");
    return;
  }
  window.location.assign(url);
}
