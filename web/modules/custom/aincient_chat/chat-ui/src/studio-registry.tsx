import { ChatBubbleIcon } from "./kit/icons";
import { enabledStudioKeys, isStudioAccessible, type StudioKey } from "./studios";
import type { StudioDef } from "./studio-module";
import { GENERATED_STUDIOS } from "./studio-registry.generated";
import { packStudioRows } from "./mount/pack-studios";

/**
 * The studio component registry — the front-end half of the studio concept.
 *
 * Each studio's NAME, ICON and — for a studio with a rail — the `load` that
 * fetches its editor/preview chunk live here, keyed by the studio key shared
 * with the backend `Studio` plugin type and the server's studio catalog
 * ({@see studios.ts}). General has no `load` → it renders as full-width chat;
 * every other studio brings its split-pane through the generated registry, and
 * `studio-loader.ts` is what calls `load` and holds the result.
 *
 * A studio may be EDITOR-ONLY (Settings, Components): it has editor components
 * but no agent in the server catalog. Capability is NOT what makes a studio editor-only
 * — Media used to lose its agent whenever the image role was unbound, and no
 * longer does (see `capabilities.ts`): a rail that can only handle words still
 * earns its place, and the chips say what is missing.
 *
 * An editor-only studio offers no NEW chat.
 * {@see enabledStudios} surfaces it from its editor presence alone;
 * App gates the chat COMPOSER off when the active studio has no agent — the chat
 * column itself still renders wherever the section holds live conversations, so
 * history never becomes unreachable when an agent is dropped.
 *
 * Adding a studio (Forms, Homepage, …) = a studio MODULE under
 * `web/modules/studio/<module>/` with a `<module>.studios.yml` manifest whose
 * `ui:` map names the crumb (`name`, `icon`) and, for a studio with a rail, the
 * `entry` file exporting {@link StudioUiModule}'s fields (DECISIONS 0430).
 * Nothing here changes: `npm run gen:studios` (run by prebuild/pretest/
 * pretypecheck) writes the row into `studio-registry.generated.ts`, the build
 * gives the entry its own chunk, and the server discovers the same manifest.
 * The built-ins that predated the tier have all migrated (Phase E: Components
 * 0431, the Library family 0432, Checks 0433, the Site studios 0434, Content
 * 0435, Identity 0436); only General remains here, by design.
 */

/**
 * One studio's front-end row — the eager half of the studio-module contract
 * (`studio-module.ts`), so General's row and a generated one are the same type
 * by construction.
 */
export type { StudioDef };

/**
 * The console's own half: General, the open catch-all — full-width chat, no
 * editor components, the fallback every read ends in. It is the one studio
 * with no module to live in (core may not carry a manifest), so its row is
 * here, exactly as its server definition is the StudioManager's own (0436).
 * Every other studio arrives through GENERATED_STUDIOS.
 */
const BUILT_IN_STUDIOS: Record<StudioKey, StudioDef> = {
  general: { name: "General", Icon: ChatBubbleIcon },
};

/**
 * General first, then the generated studio modules — spread in that order so a
 * module would WIN an id collision (none can: the server refuses a manifest
 * that redeclares `general`) — then the shell's PACK studios
 * (`mount/pack-studios.tsx`, Phase 4 of plans/console-extension-point.md),
 * which never replace one of ours (pack-validate holds a pack's ids to its own
 * prefix; this is the belt to that brace). Key order is display order
 * ({@link enabledStudios}): module studios follow General, sorted by id, and
 * pack studios follow them. The nav model, not this map, is where a deliberate
 * order belongs.
 */
const OURS: Record<StudioKey, StudioDef> = {
  ...BUILT_IN_STUDIOS,
  ...GENERATED_STUDIOS,
};

export const STUDIO_REGISTRY: Record<StudioKey, StudioDef> = {
  ...OURS,
  ...Object.fromEntries(Object.entries(packStudioRows()).filter(([key]) => !(key in OURS))),
};

/** The registry entry for a studio key (undefined for an unknown key). */
export function studioDef(key: StudioKey | undefined): StudioDef | undefined {
  return key ? STUDIO_REGISTRY[key] : undefined;
}

/**
 * Whether a studio renders an editor/preview split-pane (vs full-width chat) —
 * known from the manifest (`ui.entry` present ⇒ a `load`), so the answer is
 * synchronous whether or not the studio's chunk has arrived.
 */
export function studioHasEditor(key: StudioKey | undefined): boolean {
  return !!studioDef(key)?.load;
}

/**
 * Whether a studio is OFFERED to this user: it EITHER has a configured agent
 * (present in the server catalog) OR brings its own editor components, AND the
 * user may enter it. The editor arm is what surfaces an EDITOR-ONLY studio
 * (Settings/Components — no chat agent) without a config row; the access arm gates
 * every studio (incl. editor-only ones) by the server's `studioAccess`.
 *
 * The single availability predicate — used by both {@link enabledStudios} (the
 * flat list) and the tiered nav model ({@link ./nav-model}), so the two can
 * never disagree on which studios exist for a user.
 */
export function studioAvailable(key: StudioKey): boolean {
  return (
    (new Set(enabledStudioKeys()).has(key) || studioHasEditor(key)) &&
    isStudioAccessible(key)
  );
}

/**
 * The available studios in REGISTRY (display) order (see {@link studioAvailable}).
 *
 * @return list of {key, def}.
 */
export function enabledStudios(): { key: StudioKey; def: StudioDef }[] {
  return Object.keys(STUDIO_REGISTRY)
    .filter((key) => studioAvailable(key))
    .map((key) => ({ key, def: STUDIO_REGISTRY[key] }));
}
