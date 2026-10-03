/**
 * The pack-studio mount contract — the ONE browser API a client pack's studio
 * binds to (plans/console-extension-point.md Phase 4, DECISIONS 0448).
 *
 * A built-in studio is compiled source: its `ui.entry` exports React components
 * and our build bundles them (`studio-module.ts`). A PACK studio is code our
 * build never sees — it lives in a derived image — so it gets a boundary
 * instead: the manifest names a built ES module (`ui.script`), the console
 * `import()`s it and calls
 *
 *     export const apiVersion = 1;
 *     export function mount(el, ctx) { …; return { unmount() { … } }; }
 *
 * React never crosses the boundary. The pack renders into `el` with whatever it
 * likes (plain DOM, Preact, Svelte, its own bundled React) and talks to the
 * console only through `ctx`. That makes a pack studio an island, on purpose:
 * no shared page draft, no editor lock, no preview pane, no chat cards — a
 * narrow contract we can widen beats a wide one we must keep.
 *
 * Styling: the rail sits in the console's DOM, so the system `--ain-*` tokens
 * and the light/dark mode are inherited for free. Read SYSTEM tokens only
 * (`--ain-surface`, `--ain-text`, …) — never `--ain-ref-*` and never the kit's
 * classes; those are ours to move.
 *
 * WHAT WE PROMISE NOT TO BREAK within `apiVersion` 1: the `mount` / `unmount`
 * signature and every member of {@link StudioMountContext} below. Anything not
 * in this file is internal. A breaking change bumps {@link STUDIO_MOUNT_API_VERSION};
 * a studio built against another version renders a named placeholder instead
 * of mounting — it never breaks the console.
 *
 * This file is dependency-free on purpose: it is the source of the `.d.ts` the
 * pack template ships, so it may not import anything from the console.
 */

/** The contract version this console speaks. */
export const STUDIO_MOUNT_API_VERSION = 1;

/** What the console hands `mount()`. */
export type StudioMountContext = {
  /** The contract version the console speaks (equal to the studio's own, or it would not be mounted). */
  readonly apiVersion: number;
  /** Which studio this is: its id (the manifest key) and its display name. */
  readonly studio: { readonly id: string; readonly name: string };
  /** Same-origin HTTP. */
  readonly api: {
    /**
     * A URL on the console's JSON API (`url("/pages")` → `…/atelier/pages`).
     * For a route your pack defines, use its own path.
     */
    url(path: string): string;
    /**
     * `fetch` with the session cookie, a JSON body when `json` is given, and —
     * for anything but GET/HEAD — Drupal's `X-CSRF-Token` header, so a route
     * declaring `_csrf_request_header_token: 'TRUE'` accepts it. A relative
     * path (`"pages"`) resolves on the console API; an absolute path
     * (`"/my-pack/items"`) is used as-is. Cross-origin URLs are refused.
     */
    fetch(path: string, init?: RequestInit & { json?: unknown }): Promise<Response>;
  };
  /** The room's conversation. */
  readonly chat: {
    /**
     * Sends a user turn to this studio's agent, as if typed. Returns false
     * (and sends nothing) when the studio has no agent.
     */
    send(text: string): boolean;
  };
  /** Console navigation. */
  readonly nav: {
    /** A console URL for a query string (`href("page=12")` → `/atelier?page=12`). */
    href(query: string): string;
    /** Opens a URL: a console URL in place (no reload), anything else as a normal navigation. */
    open(href: string): void;
  };
  /** Leaves the studio, back to the console's default room. */
  close(): void;
  /** Aborted when the studio unmounts — hand it to your fetches and listeners. */
  readonly signal: AbortSignal;
};

/** What `mount()` may return. */
export type StudioMountHandle = {
  /** Undo `mount()`. The console also empties `el` afterwards. */
  unmount?(): void;
};

/** The shape of a pack studio's module. */
export type StudioMountModule = {
  readonly apiVersion: number;
  mount(el: HTMLElement, ctx: StudioMountContext): StudioMountHandle | void;
};
