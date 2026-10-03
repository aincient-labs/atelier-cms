import { useEffect, useRef, useState } from "react";
import { useConsoleThread, sendTurn } from "../aui";
import { apiUrl } from "../console-config";
import { consoleBase, consoleHref } from "../console-url";
import { IconButton } from "../kit/button";
import * as kitIcons from "../kit/icons";
import { Notice } from "../kit/notice";
import { PanelBar } from "../kit/panel-bar";
import { StudioActionsPortal, useStudioUI } from "../kit/studio-ui";
import type { IconType, StudioDef, StudioUiModule } from "../studio-module";
import { enabledStudioKeys } from "../studios";
import {
  STUDIO_MOUNT_API_VERSION,
  type StudioMountContext,
  type StudioMountHandle,
  type StudioMountModule,
} from "./contract";

/**
 * The host side of the pack-studio mount boundary (contract: `./contract.ts`,
 * plans/console-extension-point.md Phase 4, DECISIONS 0448).
 *
 * The server lists the pack studios this user may enter in `packStudios`
 * (ConsoleController::packStudios — the same gate as `studioAccess`). Each
 * becomes an ordinary registry row whose `load` imports the pack's script and
 * returns a {@link StudioUiModule} whose `Studio` is OUR rail around the
 * pack's `mount(el, ctx)`. So the rest of the console — nav, loader, the
 * skeleton, the "could not be loaded" retry — treats a pack studio exactly like
 * a built-in one, and only this file knows the difference.
 *
 * Degrade, never crash: a failed import is the loader's existing failed state
 * (with retry); a module with no `mount`, the wrong `apiVersion`, or a `mount`
 * that throws renders a named placeholder INSIDE the rail.
 */

/** One `packStudios` entry from the shell. */
export type PackStudioSetting = { name: string; icon?: string | null; script: string; style?: string };

type PackSettings = { packStudios?: Record<string, PackStudioSetting>; csrfTokenUrl?: string };

function packSettings(): PackSettings {
  // Read when the registry is built, at import — which a node-environment test
  // of any registry consumer does with no window at all.
  if (typeof window === "undefined") return {};
  const w = window as unknown as { aincientChat?: PackSettings; drupalSettings?: { aincientChat?: PackSettings } };
  return w.aincientChat ?? w.drupalSettings?.aincientChat ?? {};
}

/** How a script URL becomes a module; a seam so the test needs no network. */
export type ModuleImporter = (url: string) => Promise<unknown>;

const browserImport: ModuleImporter = (url) => import(/* @vite-ignore */ url);

/** `shield-check` → the kit's `ShieldCheckIcon`, or the chat glyph. */
export function kitIcon(name: string | null | undefined): IconType {
  const icons = kitIcons as unknown as Record<string, IconType | undefined>;
  const exportName =
    (name ?? "")
      .split("-")
      .filter(Boolean)
      .map((part) => part[0].toUpperCase() + part.slice(1))
      .join("") + "Icon";
  return icons[exportName] ?? kitIcons.ChatBubbleIcon;
}

/** Why a loaded module cannot be mounted, or null when it can. */
export function mountProblem(mod: unknown): string | null {
  const m = mod as Partial<StudioMountModule> | null;
  if (!m || typeof m.mount !== "function") return "its script does not export a mount() function";
  if (m.apiVersion !== STUDIO_MOUNT_API_VERSION) {
    return `it was built for studio API version ${String(m.apiVersion ?? "(none)")}, and this Atelier speaks version ${STUDIO_MOUNT_API_VERSION}`;
  }
  return null;
}

/** Adds a pack studio's stylesheet once, before its script runs. */
function ensureStylesheet(href: string): void {
  if (document.querySelector(`link[data-ain-pack-style="${CSS.escape(href)}"]`)) return;
  const link = document.createElement("link");
  link.rel = "stylesheet";
  link.href = href;
  link.dataset.ainPackStyle = href;
  document.head.appendChild(link);
}

/** Imports a pack studio and wraps it as the loader's lazy half. */
export async function loadPackStudio(
  id: string,
  setting: PackStudioSetting,
  importer: ModuleImporter = browserImport,
): Promise<StudioUiModule> {
  if (setting.style) ensureStylesheet(setting.style);
  const mod = await importer(setting.script);
  const problem = mountProblem(mod);
  const Studio = ({ onClose }: { onClose: () => void }) => (
    <PackStudioRail
      id={id}
      name={setting.name}
      module={problem === null ? (mod as StudioMountModule) : null}
      problem={problem}
      onClose={onClose}
    />
  );
  return { Studio };
}

/**
 * The registry rows for the shell's pack studios. Built-in ids win a collision
 * (the server's id-prefix rule means none can happen), and `preload: false`
 * keeps a pack's code from running at idle: it has no chat cards to register,
 * so it loads only when someone opens it.
 */
export function packStudioRows(
  settings: Record<string, PackStudioSetting> | undefined = packSettings().packStudios,
  importer: ModuleImporter = browserImport,
): Record<string, StudioDef> {
  const rows: Record<string, StudioDef> = {};
  for (const [id, setting] of Object.entries(settings ?? {})) {
    if (!setting || typeof setting.script !== "string" || setting.script === "") continue;
    rows[id] = {
      name: setting.name || id,
      Icon: kitIcon(setting.icon),
      load: () => loadPackStudio(id, setting, importer),
      preload: false,
    };
  }
  return rows;
}

/** A same-origin URL, or null for anything that would leave the origin. */
function sameOrigin(path: string): string | null {
  const resolved = new URL(path, window.location.href);
  return resolved.origin === window.location.origin ? resolved.href : null;
}

let csrfToken: Promise<string> | null = null;

/** Drupal's session CSRF token, fetched once per page. */
function sessionToken(): Promise<string> {
  const url = packSettings().csrfTokenUrl;
  if (!url) return Promise.resolve("");
  csrfToken ??= fetch(url, { credentials: "same-origin" }).then(
    (res) => (res.ok ? res.text() : ""),
    () => "",
  );
  return csrfToken;
}

/** Test seam: forget the cached token. */
export function resetPackCsrfToken(): void {
  csrfToken = null;
}

/** `ctx.api.fetch`: see the contract. */
async function packFetch(
  path: string,
  init: RequestInit & { json?: unknown } = {},
  signal: AbortSignal,
): Promise<Response> {
  const isRelative = !/^([a-z][a-z0-9+.-]*:|\/)/i.test(path);
  const url = sameOrigin(isRelative ? apiUrl(path) : path);
  if (url === null) throw new TypeError(`Pack studios may only fetch same-origin URLs, not ${path}`);
  const { json, headers: given, ...rest } = init;
  const headers = new Headers(given);
  let body = rest.body;
  if (json !== undefined) {
    body = JSON.stringify(json);
    if (!headers.has("Content-Type")) headers.set("Content-Type", "application/json");
  }
  const method = (rest.method ?? (body !== undefined ? "POST" : "GET")).toUpperCase();
  if (method !== "GET" && method !== "HEAD" && !headers.has("X-CSRF-Token")) {
    const token = await sessionToken();
    if (token) headers.set("X-CSRF-Token", token);
  }
  return fetch(url, { ...rest, method, body, headers, credentials: "same-origin", signal: rest.signal ?? signal });
}

/** `ctx.nav.open`: a console URL in place, anything else as a navigation. */
function openHref(href: string): void {
  const url = new URL(href, window.location.href);
  const base = consoleBase();
  const inConsole =
    url.origin === window.location.origin && (url.pathname === base || url.pathname.startsWith(`${base}/`));
  if (!inConsole) {
    window.location.assign(url.href);
    return;
  }
  // The console's URL sync owns history: push the entry, then let it apply
  // the room exactly as it would for Back/Forward (url-sync.ts popstate).
  window.history.pushState(null, "", url.pathname + url.search + url.hash);
  window.dispatchEvent(new PopStateEvent("popstate", { state: null }));
}

/**
 * The rail a pack studio mounts into. The chrome (top-bar leave control, panel
 * bar with the studio's name, the sheet dismiss on narrow screens) is ours, so
 * every studio leaves and collapses the same way; the body is the pack's.
 */
function PackStudioRail({
  id,
  name,
  module,
  problem,
  onClose,
}: {
  id: string;
  name: string;
  module: StudioMountModule | null;
  problem: string | null;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const thread = useConsoleThread();
  const { closeSheets } = useStudioUI();
  const [failure, setFailure] = useState<string | null>(problem);
  // The context is built once per mount, so read the latest props through refs.
  const live = useRef({ thread, onClose });
  live.current = { thread, onClose };

  useEffect(() => {
    const el = ref.current;
    if (!el || !module) return;
    const controller = new AbortController();
    const ctx: StudioMountContext = Object.freeze({
      apiVersion: STUDIO_MOUNT_API_VERSION,
      studio: Object.freeze({ id, name }),
      api: Object.freeze({
        url: (path: string) => apiUrl(path),
        fetch: (path: string, init?: RequestInit & { json?: unknown }) => packFetch(path, init, controller.signal),
      }),
      chat: Object.freeze({
        send: (text: string) => {
          if (!enabledStudioKeys().includes(id) || text.trim() === "") return false;
          sendTurn(live.current.thread, text);
          return true;
        },
      }),
      nav: Object.freeze({ href: (query: string) => consoleHref(query), open: openHref }),
      close: () => live.current.onClose(),
      signal: controller.signal,
    });
    let handle: StudioMountHandle | void = undefined;
    try {
      handle = module.mount(el, ctx);
      setFailure(null);
    } catch (err) {
      console.error(`[atelier] The ${name} studio failed to mount.`, err);
      setFailure("it stopped with an error while opening");
    }
    return () => {
      controller.abort();
      try {
        handle?.unmount?.();
      } catch (err) {
        console.error(`[atelier] The ${name} studio failed to unmount.`, err);
      }
      el.replaceChildren();
    };
  }, [id, name, module]);

  return (
    <div className="ain-studio__rail ain-pack-studio" data-testid="studio-rail" data-studio={id}>
      <StudioActionsPortal>
        <IconButton
          className="ain-topbar__leave"
          onClick={onClose}
          label={`Close ${name} studio`}
          title={`Leave ${name} studio`}
        >
          <kitIcons.XIcon />
        </IconButton>
      </StudioActionsPortal>
      <PanelBar
        title={name}
        actions={
          <IconButton className="ain-studio__sheetclose" onClick={closeSheets} label="Hide editor" title="Hide editor">
            <kitIcons.XIcon />
          </IconButton>
        }
      />
      {failure !== null && (
        <Notice tone="error" panel>
          The {name} studio can’t open here: {failure}. Ask whoever maintains the {name} pack for a version that
          matches this Atelier.
        </Notice>
      )}
      <div ref={ref} className="ain-pack-studio__mount" hidden={failure !== null} />
    </div>
  );
}
