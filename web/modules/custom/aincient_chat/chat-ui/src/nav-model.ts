import type { ComponentType, SVGProps } from "react";
import { WrenchIcon } from "./icons";
import { STUDIO_REGISTRY, studioAvailable, type StudioDef } from "./studio-registry";
import type { StudioKey } from "./studios";

/**
 * The tiered top-level nav model — the console's IA, decoupled from the
 * agent-backed {@link Studio} set (Library IA increment #3b).
 *
 * Today's `Studio` enum is a flat set of agent-mapped workspaces; the nav bar
 * used to render it 1:1. The re-tier breaks that mapping: the bar is a small set
 * of TIERS, each holding either a direct studio destination or a GROUP that folds
 * several studios behind one dropdown (Site Information → Design System + Globals).
 * A destination doesn't have to be a studio in principle, so this catalog is the
 * nav source of truth while `Studio.php` stays the agent-mapping + access one.
 *
 * Two studios are deliberately ABSENT from the bar:
 *  - General — it's the ambient HOME surface (reached by clicking the brand
 *    wordmark), not a peer competing with Pages/Library.
 *  - Checks — a governance VERB, contextual to Pages (and flag-hidden in v1),
 *    never a top-level tab.
 *
 * Visibility is derived, not hard-coded: {@link visibleTiers} filters every item
 * through {@link studioAvailable} (enabled-or-editor AND accessible), drops empty
 * groups, and drops empty tiers — so role-gating the TIER falls out of the
 * per-studio permissions for free (an editor with only Pages + Library access
 * simply never sees the Site Information tier).
 */

type IconType = ComponentType<SVGProps<SVGSVGElement>>;

/** A direct studio destination in the bar. */
type NavStudioRef = { kind: "studio"; key: StudioKey };
/** A dropdown that folds several studios behind one bar item. */
type NavGroupRef = {
  kind: "group";
  id: string;
  name: string;
  Icon: IconType;
  children: StudioKey[];
};
type NavItemRef = NavStudioRef | NavGroupRef;
type NavTierRef = { id: string; items: NavItemRef[] };

/**
 * The static IA. Tier order = display order; emphasis (Tier 1 peers vs the
 * de-emphasized Tier 2 config band) is a CSS concern keyed off `tier.id`.
 */
const NAV_MODEL: NavTierRef[] = [
  // Tier 1 — the daily work surfaces. Two honest peers, two different jobs.
  {
    id: "primary",
    items: [
      { kind: "studio", key: "content" }, // "Pages" — the site output.
      { kind: "studio", key: "media" }, // "Library" — the reusable-ingredient shelf (the media family's home).
    ],
  },
  // Tier 2 — configuration: setup-heavy, rare after, role-gated by its studios'
  // own permissions. Design System + Globals fold into one "Site" dropdown so
  // the bar stays tight. "Site", not "Site Information" (study 02, Plate 13):
  // a menu label must describe its contents, and Design System is not
  // "information".
  {
    id: "config",
    items: [
      {
        kind: "group",
        id: "site_information",
        name: "Site",
        Icon: WrenchIcon,
        children: ["design_system", "globals"],
      },
    ],
  },
];

/** A resolved studio destination — carries its registry def (name/Icon). */
export type ResolvedStudio = { kind: "studio"; key: StudioKey; def: StudioDef };
/** A resolved group — carries only the children the user may enter. */
export type ResolvedGroup = {
  kind: "group";
  id: string;
  name: string;
  Icon: IconType;
  children: { key: StudioKey; def: StudioDef }[];
};
export type ResolvedItem = ResolvedStudio | ResolvedGroup;
export type ResolvedTier = { id: string; items: ResolvedItem[] };

/** A studio ref → resolved entry, or NULL when unavailable/unknown. */
function resolveStudio(key: StudioKey): ResolvedStudio | null {
  const def = STUDIO_REGISTRY[key];
  return def && studioAvailable(key) ? { kind: "studio", key, def } : null;
}

/**
 * The nav model resolved for THIS user: unavailable studios filtered out, empty
 * groups dropped, empty tiers dropped. What the bar actually renders.
 */
export function visibleTiers(): ResolvedTier[] {
  const tiers: ResolvedTier[] = [];
  for (const tier of NAV_MODEL) {
    const items: ResolvedItem[] = [];
    for (const item of tier.items) {
      if (item.kind === "studio") {
        const resolved = resolveStudio(item.key);
        if (resolved) items.push(resolved);
        continue;
      }
      const children = item.children
        .map((key) => {
          const def = STUDIO_REGISTRY[key];
          return def && studioAvailable(key) ? { key, def } : null;
        })
        .filter((c): c is { key: StudioKey; def: StudioDef } => c !== null);
      if (children.length > 0) {
        items.push({ kind: "group", id: item.id, name: item.name, Icon: item.Icon, children });
      }
    }
    if (items.length > 0) tiers.push({ id: tier.id, items });
  }
  return tiers;
}

/** The total number of switchable destinations across all visible tiers. */
export function visibleDestinationCount(tiers: ResolvedTier[]): number {
  return tiers.reduce(
    (n, tier) =>
      n +
      tier.items.reduce(
        (m, item) => m + (item.kind === "studio" ? 1 : item.children.length),
        0,
      ),
    0,
  );
}
