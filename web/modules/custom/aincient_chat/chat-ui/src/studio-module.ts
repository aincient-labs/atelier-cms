import type { ComponentType, SVGProps } from "react";
import type { AnyCommandSurface } from "./sdk/commands";

/**
 * The contract a studio module's front end satisfies (DECISIONS 0430), in two
 * halves that load at two different times.
 *
 * THE EAGER HALF is the manifest. A studio module under
 * `web/modules/studio/<module>/` declares, in its `<module>.studios.yml`, a `ui:`
 * map: `entry` (the file below, relative to the module), `name` (the console's
 * crumb name; defaults to the label) and `icon` (a kit icon name). The generated
 * registry turns that into a {@link StudioDef} row in the CONSOLE chunk, so the
 * nav can list every studio — name, icon, "does it open a rail?" — on first
 * paint, before any studio code has loaded.
 *
 * THE LAZY HALF is `ui.entry`'s named exports — `Studio`, and optionally
 * `Preview`, `ToolUIs` and `Commands` — which the build compiles into that module's own
 * chunk (Phase C of plans/studio-modules.md; `vite.chunks.ts`). The chunk is
 * fetched the first time the studio opens, or at idle for its chat cards
 * (`studio-loader.ts`). A studio with no `ui.entry` is chat-only and has no
 * lazy half at all. The entry's exports are the contract — not a default-
 * exported object — so a missing `Studio` is a type error at build time.
 *
 * The console SDK re-exports these types; a studio author imports them from
 * there.
 */

/** An inline-SVG glyph component (never an emoji — the console's icon rule). */
export type IconType = ComponentType<SVGProps<SVGSVGElement>>;

/** The lazy half: what `ui.entry` exports. */
export type StudioUiModule = {
  /** The editor rail. Every studio with an entry has one; it is why the entry exists. */
  Studio: ComponentType<{ onClose: () => void }>;
  /** The live-preview pane (the centre canvas) — omitted for a rail-only studio. */
  Preview?: ComponentType;
  /**
   * Chat widgets (assistant-ui tool UIs) the studio's agent renders — e.g. the
   * Library studio's `media_result` card. The console mounts every LOADED
   * studio's ToolUIs beside its own, whatever studio is active, and preloads
   * every studio at idle so a card in an old thread still renders after its
   * studio was switched off.
   */
  ToolUIs?: readonly ComponentType[];
  /**
   * The studio's typed command set over its own draft (plans/studio-commands.md,
   * DECISIONS 0447) — built with the sdk's `createCommandSurface`. The loader
   * collects it keyed by studio id for the adapters (chat frames, WebMCP). A
   * studio never imports another's commands (the import fence).
   */
  Commands?: AnyCommandSurface;
};

/**
 * One studio's registry row: the eager half, plus the door to the lazy one.
 * General (the console's own, chat-only) and a manifest with no `ui.entry`
 * have no `load`; `studioHasEditor()` reads exactly that.
 */
export type StudioDef = {
  /** Display name in the breadcrumb (the backend owns its own admin label). */
  name: string;
  /** Inline-SVG glyph for the studio crumb. */
  Icon: IconType;
  /** Fetches the studio's chunk; absent for a chat-only studio. */
  load?: () => Promise<StudioUiModule>;
  /**
   * `false` keeps the loader from fetching this studio at idle — a pack studio
   * (`mount/pack-studios.tsx`) has no chat cards to register, so its code runs
   * only when someone opens it. Absent = preloaded like every built-in.
   */
  preload?: false;
};
