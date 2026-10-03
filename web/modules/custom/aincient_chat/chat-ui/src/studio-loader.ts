import { useEffect, useSyncExternalStore } from "react";
import type { ComponentType } from "react";
import type { StudioDef, StudioUiModule } from "./studio-module";
import { STUDIO_REGISTRY } from "./studio-registry";

/**
 * The studio loader — the one place a studio's lazy chunk is fetched and the
 * one place its result is held (Phase C of plans/studio-modules.md).
 *
 * A registry row's `load` is a dynamic `import()`; the build turns it into the
 * module's own chunk. This store calls it at most once per studio, remembers
 * the outcome (ready / failed), and lets React subscribe: `useStudioModule()`
 * renders the rail the moment the chunk lands, and `StudioToolUIs` in App
 * mounts every loaded studio's chat cards.
 *
 * Three moments load a studio:
 *
 *   1. BOOT — `main.tsx` awaits the URL's studio before the first render, so
 *      the room the user opened paints with its rail, not a skeleton.
 *   2. SWITCH — `useStudioModule()` loads the studio the user just entered; the
 *      rail shows a skeleton for the one round trip (a warm cache makes it
 *      instant), then the real thing.
 *   3. IDLE — after boot, `preloadStudios()` fetches every other studio's
 *      chunk in the background. Not for speed: a stored thread may hold a
 *      `media_result` card whose ToolUI lives in a chunk the user has never
 *      opened, and a card that renders as nothing is a broken transcript.
 *      So every studio's cards are registered within a few seconds of boot,
 *      with no studio-switch ever needing a chunk a second time.
 *
 * A failed load (the network, a deploy mid-session that removed a chunk) is
 * held as `failed` and retried on demand — never thrown into React, and never
 * retried in a loop.
 *
 * Built as a factory so the test can hand it fake rows; the console uses the
 * one instance bound to the registry, exported at the bottom.
 */

export type StudioLoadStatus = "none" | "loading" | "ready" | "failed";

type Entry =
  | { status: "loading"; promise: Promise<void> }
  | { status: "ready"; module: StudioUiModule }
  | { status: "failed"; error: unknown };

export type StudioLoader = ReturnType<typeof createStudioLoader>;

export function createStudioLoader(rows: () => Record<string, StudioDef>) {
  const entries = new Map<string, Entry>();
  const listeners = new Set<() => void>();
  /** Bumped on every state change — the stable snapshot `useSyncExternalStore` wants. */
  let version = 0;

  function notify(): void {
    version += 1;
    for (const listener of listeners) listener();
  }

  function subscribe(listener: () => void): () => void {
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  }

  function getVersion(): number {
    return version;
  }

  /** The loaded module, or undefined while loading / after a failure / for a chat-only studio. */
  function loaded(key: string | undefined): StudioUiModule | undefined {
    const entry = key === undefined ? undefined : entries.get(key);
    return entry?.status === "ready" ? entry.module : undefined;
  }

  function status(key: string | undefined): StudioLoadStatus {
    const entry = key === undefined ? undefined : entries.get(key);
    return entry?.status ?? "none";
  }

  /** The error a failed load held, for the notice; undefined otherwise. */
  function error(key: string | undefined): unknown {
    const entry = key === undefined ? undefined : entries.get(key);
    return entry?.status === "failed" ? entry.error : undefined;
  }

  /**
   * Loads a studio's chunk (once). Resolves to the module, or to undefined for
   * a chat-only studio, an unknown key, or a failed load — the failure is
   * readable through `status()` / `error()`, never thrown.
   */
  function load(key: string | undefined): Promise<StudioUiModule | undefined> {
    const def = key === undefined ? undefined : rows()[key];
    if (!def?.load || key === undefined) return Promise.resolve(undefined);
    const current = entries.get(key);
    if (current?.status === "ready") return Promise.resolve(current.module);
    if (current?.status === "failed") return Promise.resolve(undefined);
    if (current?.status === "loading") return current.promise.then(() => loaded(key));
    const promise = def.load().then(
      (module) => {
        entries.set(key, { status: "ready", module });
        notify();
      },
      (err: unknown) => {
        entries.set(key, { status: "failed", error: err });
        notify();
      },
    );
    entries.set(key, { status: "loading", promise });
    notify();
    return promise.then(() => loaded(key));
  }

  /** Forgets a failure and loads again. A no-op while loading or once ready. */
  function retry(key: string): Promise<StudioUiModule | undefined> {
    if (entries.get(key)?.status === "failed") entries.delete(key);
    return load(key);
  }

  /** Every preloadable studio with a chunk that has not been asked for yet. */
  function pending(): string[] {
    return Object.entries(rows())
      .filter(([key, def]) => def.load && def.preload !== false && !entries.has(key))
      .map(([key]) => key);
  }

  /**
   * Loads every not-yet-requested studio, one after another, when the browser
   * is idle (or on the next tick where `requestIdleCallback` is missing — a
   * jsdom test, Safari before 2024). Sequential on purpose: the point is the
   * cards in old threads, not a race for bandwidth against the open room.
   */
  function preloadAll(): void {
    const keys = pending();
    if (keys.length === 0) return;
    const idle =
      typeof requestIdleCallback === "function"
        ? (fn: () => void) => requestIdleCallback(() => fn(), { timeout: 2000 })
        : (fn: () => void) => setTimeout(fn, 0);
    const next = (): void => {
      const key = keys.shift();
      if (key === undefined) return;
      idle(() => {
        void load(key).finally(next);
      });
    };
    next();
  }

  /**
   * Every chat widget the LOADED studio modules bring (`StudioUiModule.ToolUIs`),
   * flat, each with a stable key — App mounts them beside the console's own tool
   * UIs. Not filtered by availability on purpose: a card in a stored thread must
   * still render for a user who may not enter the studio, and after the studio
   * was switched off.
   */
  function toolUIs(): { key: string; ToolUI: ComponentType }[] {
    return [...entries.entries()].flatMap(([id, entry]) =>
      entry.status === "ready"
        ? (entry.module.ToolUIs ?? []).map((ToolUI, i) => ({ key: `${id}:${i}`, ToolUI }))
        : [],
    );
  }

  /** Test seam: forget everything. */
  function reset(): void {
    entries.clear();
    notify();
  }

  return { subscribe, getVersion, loaded, status, error, load, retry, pending, preloadAll, toolUIs, reset };
}

/** The console's loader, bound to the studio registry. */
export const studioLoader = createStudioLoader(() => STUDIO_REGISTRY);

/** Boot (moment 1): `main.tsx` awaits the URL's studio before the first render. */
export const loadStudio = studioLoader.load;
/** Boot (moment 3): every other studio, at idle, for the cards in old threads. */
export const preloadStudios = studioLoader.preloadAll;

/**
 * A studio's lazy half for a component that renders it: kicks off the load on
 * first use (and on retry), re-renders when it lands. `module` is undefined
 * while loading, after a failure, and for a chat-only studio — read `status`
 * to tell those apart.
 */
export function useStudioModule(key: string | undefined): {
  status: StudioLoadStatus;
  module: StudioUiModule | undefined;
  error: unknown;
  retry: () => void;
} {
  useSyncExternalStore(studioLoader.subscribe, studioLoader.getVersion, studioLoader.getVersion);
  useEffect(() => {
    if (key !== undefined && studioLoader.status(key) === "none") void studioLoader.load(key);
  }, [key]);
  return {
    status: studioLoader.status(key),
    module: studioLoader.loaded(key),
    error: studioLoader.error(key),
    retry: () => {
      if (key !== undefined) void studioLoader.retry(key);
    },
  };
}

/** The loaded studios' chat widgets, re-read whenever a chunk lands. */
export function useLoadedStudioToolUIs(): { key: string; ToolUI: ComponentType }[] {
  useSyncExternalStore(studioLoader.subscribe, studioLoader.getVersion, studioLoader.getVersion);
  return studioLoader.toolUIs();
}
