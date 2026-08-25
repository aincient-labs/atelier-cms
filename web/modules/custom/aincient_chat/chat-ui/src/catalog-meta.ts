/**
 * Pure catalog-metadata helpers for the W6 manifest fields (`icon`, `provider`,
 * `kinds`) — kept UI-free so the icon-resolution, provenance-grouping and
 * kind-ordering rules are unit-testable (see catalog-meta.test.ts).
 *
 * The manifest (GET /atelier/page/manifest) is the single source; these helpers
 * only ever DEGRADE gracefully: a missing icon falls back to the local glyph
 * map, a missing provider counts as built-in, an empty `kinds` map yields []
 * so the caller keeps its hardcoded fallback pair.
 */

/** The built-in glyph map — the client-side fallback when a manifest entry
 *  carries no `icon`. A type glyph per placeable, so a collapsed section and a
 *  picker row read at a glance. */
export const SECTION_ICONS: Record<string, string> = {
  hero: "◆", banner: "▬", logos: "▤", stats: "▦", features: "⊞",
  content: "¶", gallery: "▤", testimonials: "❝", team: "☻", pricing: "$",
  faq: "?", newsletter: "✉", cta: "◈", divider: "—", embed: "⧉", block: "▣",
};

/** The glyph for a placeable: the server-declared icon wins when non-empty,
 *  then the local map, then a neutral block mark (forward-compatible). */
export function sectionIcon(component: string, serverIcon?: string): string {
  const s = (serverIcon ?? "").trim();
  if (s) return s;
  return SECTION_ICONS[component] ?? "▢";
}

/** The provider whose components are the default experience — rendered with no
 *  group header and no provenance chip. An entry with no provider at all is
 *  treated the same (the manifest sends '' for legacy defs). */
const BUILT_IN_PROVIDER = "aincient_pages";

/** Whether a manifest entry's provider is the built-in default (no provenance
 *  chrome in the picker). */
export function isBuiltInProvider(provider: string | undefined): boolean {
  const p = (provider ?? "").trim();
  return p === "" || p === BUILT_IN_PROVIDER;
}

/** Humanize a provider module name for its group header:
 *  "atelier_test_pack" → "Atelier test pack". */
export function humanizeProvider(provider: string): string {
  const s = provider.trim().replace(/[_-]+/g, " ").trim();
  if (!s) return "";
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export type ProviderGroup<T> = {
  /** The raw provider id ('' for the built-in group). */
  provider: string;
  /** Humanized header label — null for the built-in group (renders headerless,
   *  exactly as before provenance existed). */
  label: string | null;
  entries: T[];
};

/**
 * Group picker entries by provider: the built-ins (provider 'aincient_pages'
 * or '') come first as one label-less group; every other provider follows in
 * order of first appearance, under a humanized header. Grouping is
 * presentation-only — the caller's flat list keeps its own semantics (e.g.
 * Enter picks the first match of the FLAT filtered list).
 */
export function groupByProvider<T extends { provider?: string }>(entries: T[]): ProviderGroup<T>[] {
  const builtIn: ProviderGroup<T> = { provider: "", label: null, entries: [] };
  const others = new Map<string, ProviderGroup<T>>();
  for (const e of entries) {
    if (isBuiltInProvider(e.provider)) {
      builtIn.entries.push(e);
      continue;
    }
    const p = (e.provider ?? "").trim();
    let g = others.get(p);
    if (!g) {
      g = { provider: p, label: humanizeProvider(p), entries: [] };
      others.set(p, g);
    }
    g.entries.push(e);
  }
  const groups: ProviderGroup<T>[] = [];
  if (builtIn.entries.length > 0) groups.push(builtIn);
  groups.push(...others.values());
  return groups;
}

/** One create-page kind option, as offered by the birth form's picker. */
export type KindOption = {
  id: string;
  label: string;
  hint: string;
  /** 'composition' (landing-regime) or 'recipe' (blog-regime); optional so the
   *  form's id-based branching stays the fallback on older payloads. */
  mode?: string;
};

/** The server's `kinds` map entry shape (all fields optional on the wire). */
type KindWire = { label?: string; hint?: string; mode?: string };

/**
 * Order the manifest's `kinds` map into the birth form's option list: landing
 * first, then blog, then the rest alphabetically by id — stable for the
 * shipped pair, deterministic for add-ons. An empty/missing map yields [] so
 * the caller keeps its hardcoded fallback (never an empty picker).
 */
export function orderedKinds(kinds: Record<string, KindWire> | undefined | null): KindOption[] {
  if (!kinds) return [];
  const rank = (id: string): number => (id === "landing" ? 0 : id === "blog" ? 1 : 2);
  return Object.keys(kinds)
    .sort((a, b) => rank(a) - rank(b) || a.localeCompare(b))
    .map((id) => ({
      id,
      label: kinds[id].label ?? id,
      hint: kinds[id].hint ?? "",
      mode: kinds[id].mode,
    }));
}

/**
 * A kind's content regime — the branch key wherever the UI used to test the id:
 * `mode` wins when the server sent one ('recipe' kinds behave like blog,
 * 'composition' like landing); without it, the shipped id check is the floor.
 */
export function kindRegime(kind: { id: string; mode?: string }): "composition" | "recipe" {
  if (kind.mode === "recipe") return "recipe";
  if (kind.mode === "composition") return "composition";
  return kind.id === "blog" ? "recipe" : "composition";
}
