/**
 * The Components studio's pure logic layer (plans/byo-components.md W1b,
 * DECISIONS 0455) — deliberately DOM-free so vitest covers it in the node
 * environment. The rail, the preview and the store all read through it.
 *
 * Semantics mirror the server (`ConstraintController`): every stored list is a
 * list of REMOVALS. A checked box means "available", i.e. NOT in the removals
 * list — the studio can only narrow what discovery admitted, never re-admit
 * what the gate rejected. The guards mirror the server's 422s: the LAST tone
 * site-wide, the LAST variant of a component and the LAST tone of a component
 * cannot be removed. A component's tones honour BOTH layers: a tone removed
 * site-wide is locked off in every component's tones.
 */

/** One discovered component in the v1 vocabulary (kept one release server-side). */
export type VocabularyComponent = {
  name: string;
  tier: string;
  icon: string;
  use: string;
};

/** Where a component is used: page/block counts + per-variant/tone counts. */
export type ComponentUsage = {
  pages: number;
  blocks: number;
  variants: Record<string, number>;
  tones: Record<string, number>;
};

/** One manifest-v2 entry — everything the rail shows about a component. */
export type ComponentEntry = {
  name: string;
  tier: string;
  /** Legacy glyph — never displayed (the console's ComponentIcon is). */
  icon: string;
  /** Model-facing guidance — the agent's, never shown in the rail. */
  use: string;
  /** The SDC's own description (may be absent from an older manifest). */
  description?: string;
  /** '' = virtual built-in, 'aincient_pages' = built-in, else a pack module. */
  provider: string;
  group: string;
  variants: string[];
  /** Empty when the component takes no tone. */
  tones: string[];
  /** Example names; the index is the example number. */
  examples: string[];
  usage: ComponentUsage;
  /** A pack component that overrides this one through SDC `replaces:` (P3). */
  replaced_by?: ComponentReplacement | null;
  /** The SAVED site switched this override back to the original (P4b). */
  override_off?: boolean;
};

/** The pack component that renders in place of a built-in. */
export type ComponentReplacement = { id: string; provider: string; label: string };

/** A pack component the admission gate refused — the rail's "Can't be used" group. */
export type BlockedComponent = {
  name: string;
  provider: string;
  label: string;
  /** Plain language, for the owner. */
  reason: string;
  /** The raw gate text, for the pack developer (a tooltip). */
  detail: string;
};

/** The full manifest payload from GET /atelier/constraint/manifest (v2). */
export type ConstraintManifest = {
  version?: number;
  components: ComponentEntry[];
  groups?: string[];
  blocked?: BlockedComponent[];
  constraint: {
    /** Original SDC ids (`provider:name`) switched back from their pack override. */
    overrides_off?: string[];
    components: string[];
    tones: string[];
    variants: Record<string, string[]>;
    component_tones?: Record<string, string[]>;
  };
  vocabulary: {
    components: VocabularyComponent[];
    tones: string[];
    variants: Record<string, string[]>;
  };
  effective: {
    placeable: string[];
    tones: string[];
    warnings: string[];
  };
  /** The "Applies to" menu: every kind (pages + fragments), from the registry. */
  scopes?: ScopeInfo[];
};

/**
 * The staged draft — the constraint slice only (removals, exactly the shape the
 * save endpoint takes). The manifest's other halves are read-only context.
 */
export type ConstraintDraft = {
  /** Component names REMOVED from every kind. */
  components: string[];
  /** Tones REMOVED site-wide. */
  tones: string[];
  /** Per-component variant values REMOVED. */
  variants: Record<string, string[]>;
  /** Per-component tones REMOVED (on top of the site-wide removals). */
  component_tones: Record<string, string[]>;
  /** Original SDC ids whose pack override is switched OFF ("Use original instead"). */
  overrides_off: string[];
};

/** Dedupe + keep order — the stored lists are sets in spirit. */
function uniq(list: string[]): string[] {
  return [...new Set(list)];
}

/** A per-component removals map, deduped, with empty entries dropped. */
function seedMap(map: Record<string, string[]> | undefined): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  for (const [name, removed] of Object.entries(map ?? {})) {
    if (Array.isArray(removed) && removed.length > 0) out[name] = uniq(removed);
  }
  return out;
}

function cloneMap(map: Record<string, string[]>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(map).map(([k, v]) => [k, [...v]]));
}

/**
 * Seed a draft from the manifest's stored constraint. Unknown component names
 * are KEPT (a pack may be temporarily uninstalled; its removal must survive a
 * round-trip through the studio — the server stores them anyway). Empty
 * per-component lists are dropped: they carry no removal.
 */
export function seedConstraintDraft(manifest: ConstraintManifest): ConstraintDraft {
  return {
    components: uniq(manifest.constraint.components ?? []),
    tones: uniq(manifest.constraint.tones ?? []),
    variants: seedMap(manifest.constraint.variants),
    component_tones: seedMap(manifest.constraint.component_tones),
    overrides_off: uniq(manifest.constraint.overrides_off ?? []),
  };
}

/** A deep copy, so staged edits never mutate the baseline. */
export function cloneConstraintDraft(draft: ConstraintDraft): ConstraintDraft {
  return {
    components: [...draft.components],
    tones: [...draft.tones],
    variants: cloneMap(draft.variants),
    component_tones: cloneMap(draft.component_tones ?? {}),
    overrides_off: [...(draft.overrides_off ?? [])],
  };
}

/** Order-insensitive set difference count (|a△b|). */
function symmetricDiff(a: string[], b: string[]): number {
  const sa = new Set(a);
  const sb = new Set(b);
  let n = 0;
  for (const x of sa) if (!sb.has(x)) n++;
  for (const x of sb) if (!sa.has(x)) n++;
  return n;
}

function mapDiff(a: Record<string, string[]>, b: Record<string, string[]>): number {
  let n = 0;
  for (const name of new Set([...Object.keys(a), ...Object.keys(b)])) {
    n += symmetricDiff(a[name] ?? [], b[name] ?? []);
  }
  return n;
}

/**
 * The changed-item count for the dirty badge: every component, tone, variant
 * value or per-component tone whose availability differs from the baseline
 * counts as one change, and so does every override switched back or forth.
 */
export function countDirty(base: ConstraintDraft, draft: ConstraintDraft): number {
  return (
    symmetricDiff(base.components, draft.components) +
    symmetricDiff(base.tones, draft.tones) +
    mapDiff(base.variants, draft.variants) +
    mapDiff(base.component_tones ?? {}, draft.component_tones ?? {}) +
    symmetricDiff(base.overrides_off ?? [], draft.overrides_off ?? [])
  );
}

/** Whether an item is available (checked): NOT in the removals list. */
export function isEnabled(removals: string[], name: string): boolean {
  return !removals.includes(name);
}

/** Whether a component is ON in the draft (not removed site-wide). */
export function isOn(draft: ConstraintDraft, name: string): boolean {
  return isEnabled(draft.components, name);
}

/** Toggle a removals list: enabled ⇒ drop from removals, disabled ⇒ add. */
function toggleRemoval(removals: string[], name: string, enabled: boolean): string[] {
  if (enabled) return removals.filter((n) => n !== name);
  return removals.includes(name) ? removals : [...removals, name];
}

/** Write one component's removals into a map, dropping it when it empties. */
function withRemovals(
  map: Record<string, string[]>,
  component: string,
  removed: string[],
): Record<string, string[]> {
  const next = cloneMap(map);
  if (removed.length > 0) next[component] = removed;
  else delete next[component];
  return next;
}

/** Enable/disable a component — returns a NEW draft. */
export function setComponentEnabled(
  draft: ConstraintDraft,
  name: string,
  enabled: boolean,
): ConstraintDraft {
  return { ...cloneConstraintDraft(draft), components: toggleRemoval(draft.components, name, enabled) };
}

// ── Site-wide tones ─────────────────────────────────────────────────────────

/**
 * Whether a tone is the LAST enabled one site-wide — the UI disables its
 * checkbox, mirroring the server's 422 on removing every tone.
 */
export function isLastEnabledTone(
  draft: ConstraintDraft,
  tone: string,
  allTones: string[],
): boolean {
  const enabled = allTones.filter((t) => isEnabled(draft.tones, t));
  return enabled.length === 1 && enabled[0] === tone;
}

/**
 * Enable/disable a tone site-wide — returns a NEW draft, or the SAME draft
 * (refused) when disabling would remove the last remaining tone.
 */
export function setToneEnabled(
  draft: ConstraintDraft,
  tone: string,
  enabled: boolean,
  allTones: string[],
): ConstraintDraft {
  if (!enabled && isLastEnabledTone(draft, tone, allTones)) return draft;
  return { ...cloneConstraintDraft(draft), tones: toggleRemoval(draft.tones, tone, enabled) };
}

// ── Per-component variants ──────────────────────────────────────────────────

/** Whether a variant value is the LAST enabled one for its component. */
export function isLastEnabledVariant(
  draft: ConstraintDraft,
  component: string,
  value: string,
  allValues: string[],
): boolean {
  const removed = draft.variants[component] ?? [];
  const enabled = allValues.filter((v) => isEnabled(removed, v));
  return enabled.length === 1 && enabled[0] === value;
}

/**
 * Enable/disable one variant value — returns a NEW draft, or the SAME draft
 * (refused) when disabling would remove a component's last variant. A component
 * whose removals empty out is dropped from the map entirely.
 */
export function setVariantEnabled(
  draft: ConstraintDraft,
  component: string,
  value: string,
  enabled: boolean,
  allValues: string[],
): ConstraintDraft {
  if (!enabled && isLastEnabledVariant(draft, component, value, allValues)) return draft;
  const removed = toggleRemoval(draft.variants[component] ?? [], value, enabled);
  return { ...cloneConstraintDraft(draft), variants: withRemovals(draft.variants, component, removed) };
}

// ── Per-component tones ─────────────────────────────────────────────────────

/** A component's tones that are actually available: not removed site-wide, not removed for it. */
export function availableComponentTones(draft: ConstraintDraft, entry: ComponentEntry): string[] {
  const own = draft.component_tones?.[entry.name] ?? [];
  return entry.tones.filter((t) => isEnabled(draft.tones, t) && isEnabled(own, t));
}

/**
 * Enable/disable one tone for one component — returns a NEW draft, or the SAME
 * draft (refused) when the tone is removed site-wide (locked) or when disabling
 * would leave the component with no available tone.
 */
export function setComponentToneEnabled(
  draft: ConstraintDraft,
  entry: ComponentEntry,
  tone: string,
  enabled: boolean,
): ConstraintDraft {
  if (!isEnabled(draft.tones, tone)) return draft;
  if (!enabled) {
    const available = availableComponentTones(draft, entry);
    if (available.length === 1 && available[0] === tone) return draft;
  }
  const removed = toggleRemoval(draft.component_tones?.[entry.name] ?? [], tone, enabled);
  return {
    ...cloneConstraintDraft(draft),
    component_tones: withRemovals(draft.component_tones ?? {}, entry.name, removed),
  };
}

/** One tick in a component's Variants / Tones list. */
export type Tick = {
  value: string;
  /** Checked: available for this component. */
  on: boolean;
  /** The box can't be changed; `reason` says why. */
  locked: boolean;
  reason: string | null;
};

export const LAST_VARIANT_REASON = "Each component keeps at least one variant.";
export const LAST_TONE_REASON = "Each component keeps at least one tone.";
export const SITE_TONE_REASON = "Removed site-wide — turn it back on under Tones everywhere.";

/** The variant ticks for one component, with the last-variant guard. */
export function variantTicks(draft: ConstraintDraft, entry: ComponentEntry): Tick[] {
  const removed = draft.variants[entry.name] ?? [];
  return entry.variants.map((value) => {
    const last = isLastEnabledVariant(draft, entry.name, value, entry.variants);
    return { value, on: isEnabled(removed, value), locked: last, reason: last ? LAST_VARIANT_REASON : null };
  });
}

/**
 * The tone ticks for one component: a tone removed site-wide is locked OFF;
 * the last available one is locked ON.
 */
export function toneTicks(draft: ConstraintDraft, entry: ComponentEntry): Tick[] {
  const own = draft.component_tones?.[entry.name] ?? [];
  const available = availableComponentTones(draft, entry);
  return entry.tones.map((value) => {
    if (!isEnabled(draft.tones, value)) {
      return { value, on: false, locked: true, reason: SITE_TONE_REASON };
    }
    const last = available.length === 1 && available[0] === value;
    return { value, on: isEnabled(own, value), locked: last, reason: last ? LAST_TONE_REASON : null };
  });
}

// ── Grouping, filters, search, status ───────────────────────────────────────

/** The rail's fixed group order (DECISIONS 0455 D12) and their labels. */
export const GROUP_ORDER = [
  "openers",
  "story",
  "media",
  "social_proof",
  "conversion",
  "structure",
  "from_your_site",
  "other",
] as const;

export const GROUP_LABELS: Record<string, string> = {
  openers: "Openers",
  story: "Story",
  media: "Media",
  social_proof: "Social proof",
  conversion: "Conversion",
  structure: "Structure",
  from_your_site: "From your site",
  other: "Other",
};

export type EntryGroup = { id: string; label: string; entries: ComponentEntry[] };

/** Group entries in the fixed order; an unknown group falls into `other`; empty groups are skipped. */
export function groupEntries(entries: ComponentEntry[]): EntryGroup[] {
  const buckets = new Map<string, ComponentEntry[]>(GROUP_ORDER.map((g) => [g, []]));
  for (const e of entries) {
    const g = buckets.has(e.group) ? e.group : "other";
    buckets.get(g)!.push(e);
  }
  return GROUP_ORDER.filter((g) => buckets.get(g)!.length > 0).map((g) => ({
    id: g,
    label: GROUP_LABELS[g],
    entries: buckets.get(g)!,
  }));
}

/** Entries in group order — the order the contact sheet and the rail share. */
export function entriesInGroupOrder(entries: ComponentEntry[]): ComponentEntry[] {
  return groupEntries(entries).flatMap((g) => g.entries);
}

/** A pack component: contributed by a module other than the built-ins. */
export function isPack(entry: ComponentEntry): boolean {
  const p = (entry.provider ?? "").trim();
  return p !== "" && p !== "aincient_pages";
}

/** Pages + blocks that use the component. */
export function usageTotal(entry: ComponentEntry): number {
  return (entry.usage?.pages ?? 0) + (entry.usage?.blocks ?? 0);
}

export type ComponentFilter = "all" | "unused" | "off" | "packs";

export const FILTERS: { id: ComponentFilter; label: string }[] = [
  { id: "all", label: "All" },
  { id: "unused", label: "Unused" },
  { id: "off", label: "Off" },
  { id: "packs", label: "Packs" },
];

/** Whether an entry passes a filter, given whether it is on in the scope's draft. */
export function matchesFilterWith(entry: ComponentEntry, on: boolean, filter: ComponentFilter): boolean {
  switch (filter) {
    case "unused":
      return usageTotal(entry) === 0;
    case "off":
      return !on;
    case "packs":
      return isPack(entry);
    default:
      return true;
  }
}

/** Whether an entry passes a filter (site scope). */
export function matchesFilter(entry: ComponentEntry, draft: ConstraintDraft, filter: ComponentFilter): boolean {
  return matchesFilterWith(entry, isOn(draft, entry.name), filter);
}

/** Case-insensitive search over the name (raw and humanized) and the use line. */
export function matchesQuery(entry: ComponentEntry, query: string): boolean {
  const q = query.trim().toLowerCase();
  if (!q) return true;
  return [entry.name, humanizeName(entry.name), entry.use ?? ""].some((s) => s.toLowerCase().includes(q));
}

export function filterEntries(
  entries: ComponentEntry[],
  draft: ConstraintDraft,
  filter: ComponentFilter,
  query: string,
): ComponentEntry[] {
  return filterEntriesBy(entries, (e) => isOn(draft, e.name), filter, query);
}

/** `filterEntries` for any scope: `on` says whether a row is ticked there. */
export function filterEntriesBy(
  entries: ComponentEntry[],
  on: (entry: ComponentEntry) => boolean,
  filter: ComponentFilter,
  query: string,
): ComponentEntry[] {
  return entries.filter((e) => matchesFilterWith(e, on(e), filter) && matchesQuery(e, query));
}

/** "23 of 26 available", plus " · 2 unsaved" when the draft differs. */
export function statusLine(entries: ComponentEntry[], draft: ConstraintDraft, dirty: number): string {
  const on = entries.filter((e) => isOn(draft, e.name)).length;
  const base = `${on} of ${entries.length} available`;
  return dirty > 0 ? `${base} · ${dirty} unsaved` : base;
}

/** "social_proof" → "Social proof", "cta" → "Cta". */
export function humanizeName(name: string): string {
  const s = name.replace(/[_-]+/g, " ").trim();
  return s ? s.charAt(0).toUpperCase() + s.slice(1) : name;
}

// ── Preview requests ────────────────────────────────────────────────────────

/** One item of a POST /components/render body. */
export type RenderItem = { component: string; example?: number; variant?: string; tone?: string };

/** The view-only strip state — it never touches the draft. */
export type PreviewView = {
  example: number;
  variant: string;
  tone: string;
  width: 375 | 768 | 1280;
  /** An overridden component: the pack's version, the original, or both side by side (P3). */
  compare: CompareMode;
};

export type CompareMode = "pack" | "original" | "compare";

export const DEFAULT_VIEW: PreviewView = { example: 0, variant: "", tone: "", width: 1280, compare: "pack" };

/** The view for a new selection: example/variant/tone/compare reset, the width kept. */
export function viewForSelection(prev: PreviewView): PreviewView {
  return { ...DEFAULT_VIEW, width: prev.width };
}

/**
 * The contact sheet: every component ON in the draft (excluding the virtual
 * `block`, which has no example), first example each, in group order.
 */
export function contactSheetItems(entries: ComponentEntry[], draft: ConstraintDraft): RenderItem[] {
  return entriesInGroupOrder(entries)
    .filter((e) => e.name !== "block" && isOn(draft, e.name) && (e.examples?.length ?? 0) > 0)
    .map((e) => ({ component: e.name, example: 0 }));
}

/**
 * The selection: one component with the strip's example/variant/tone, each
 * clamped to what the component actually has (an unknown value is dropped so
 * the server renders its default).
 */
export function selectionItem(entry: ComponentEntry, view: PreviewView): RenderItem {
  const item: RenderItem = { component: entry.name };
  const examples = entry.examples?.length ?? 0;
  item.example = view.example >= 0 && view.example < examples ? view.example : 0;
  if (entry.variants.includes(view.variant)) item.variant = view.variant;
  if (entry.tones.includes(view.tone)) item.tone = view.tone;
  return item;
}

/**
 * The publish body — the whole staged slice, every key present so each REPLACES
 * its stored list. The per-component maps only carry components that actually
 * have removals (an empty list is no constraint, and the server drops it anyway).
 */
export function toSavePayload(draft: ConstraintDraft): {
  components: string[];
  tones: string[];
  variants: Record<string, string[]>;
  component_tones: Record<string, string[]>;
  overrides_off: string[];
} {
  const compact = (map: Record<string, string[]>) => {
    const out: Record<string, string[]> = {};
    for (const [name, removed] of Object.entries(map ?? {})) {
      if (removed.length > 0) out[name] = [...removed];
    }
    return out;
  };
  return {
    components: [...draft.components],
    tones: [...draft.tones],
    variants: compact(draft.variants),
    component_tones: compact(draft.component_tones ?? {}),
    overrides_off: [...(draft.overrides_off ?? [])],
  };
}

// ── Pack overrides + blocked components (P3, P4b) ───────────────────────────

/** The original's SDC id — what `overrides_off` stores. */
export function originalId(entry: ComponentEntry): string {
  return `${entry.provider}:${entry.name}`;
}

/** Whether the draft switches this component's pack override off (back to the original). */
export function isOverrideOff(draft: ConstraintDraft, entry: ComponentEntry): boolean {
  return Boolean(entry.replaced_by) && (draft.overrides_off ?? []).includes(originalId(entry));
}

/**
 * Stage "Use original instead" — returns a NEW draft, or the SAME draft for a
 * component no pack overrides (nothing to switch).
 */
export function setOverrideOff(draft: ConstraintDraft, entry: ComponentEntry, off: boolean): ConstraintDraft {
  if (!entry.replaced_by) return draft;
  return { ...cloneConstraintDraft(draft), overrides_off: toggleRemoval(draft.overrides_off ?? [], originalId(entry), !off) };
}

/**
 * The row's override badge: "Replaced by acme_pack" / "Using original", or
 * null. `off` is the override's state in the scope shown (the site draft, or
 * the saved site in a kind scope).
 */
export function overrideBadge(entry: ComponentEntry, off: boolean): string | null {
  if (!entry.replaced_by) return null;
  return off ? "Using original" : `Replaced by ${entry.replaced_by.provider}`;
}

/**
 * How the preview shows a selected component. `none`: no override, render as
 * usual. `original`: the override is off in the draft — the original only.
 * `pending`: off on the live site but staged back on — the server still
 * renders the original until Publish, so the pack's version can't be shown
 * yet. `switch`: the Pack | Original | Compare switch.
 */
export type OverridePreview = "none" | "original" | "pending" | "switch";

export function overridePreview(entry: ComponentEntry, draft: ConstraintDraft): OverridePreview {
  if (!entry.replaced_by) return "none";
  if (isOverrideOff(draft, entry)) return "original";
  return entry.override_off ? "pending" : "switch";
}

/**
 * The render requests one selection needs: the pack's (a normal render) and/or
 * the original's (`original: true`), in display order.
 */
export function compareFrames(mode: OverridePreview, compare: CompareMode): ("pack" | "original")[] {
  if (mode === "none") return ["pack"];
  if (mode === "original" || mode === "pending") return ["original"];
  return compare === "compare" ? ["pack", "original"] : [compare];
}

/** The "Can't be used" rows: under All or Packs only, matching the search, by label. */
export function filterBlocked(blocked: BlockedComponent[] | undefined, filter: ComponentFilter, query: string): BlockedComponent[] {
  if (filter !== "all" && filter !== "packs") return [];
  const q = query.trim().toLowerCase();
  return (blocked ?? [])
    .filter((b) => !q || [b.name, b.label, b.provider].some((s) => s.toLowerCase().includes(q)))
    .sort((a, b) => a.label.localeCompare(b.label));
}

// ── Scopes: "Applies to" (P1b) ──────────────────────────────────────────────

/** The site-wide scope's id — every other scope id is a kind id from the registry. */
export const SITE_SCOPE = "site";

/** One "Applies to" entry: a kind, its regime, and how many pages (or blocks) it has. */
export type ScopeInfo = {
  id: string;
  label: string;
  /** `composition` (placeable sections) or `recipe` (a fixed layout). */
  mode: string;
  /** A reusable fragment (the block kind), not a page type. */
  fragment: boolean;
  count: number;
};

/** The menu's two groups below "Everywhere": page types, then reusable fragments. */
export function scopeMenu(scopes: ScopeInfo[] | undefined): { pages: ScopeInfo[]; reusable: ScopeInfo[] } {
  const list = scopes ?? [];
  return { pages: list.filter((s) => !s.fragment), reusable: list.filter((s) => s.fragment) };
}

/** "Landing page · 4 pages"; a fragment kind counts its items without a noun. */
export function scopeOptionLabel(scope: ScopeInfo): string {
  if (scope.fragment) return `${scope.label} · ${scope.count}`;
  return `${scope.label} · ${scope.count} page${scope.count === 1 ? "" : "s"}`;
}

/** A recipe kind has a fixed layout — nothing to place, nothing to narrow. */
export function isRecipe(scope: { mode: string } | null | undefined): boolean {
  return scope?.mode === "recipe";
}

/** One scope's staged draft against the baseline it was seeded from. */
export type Staged<D> = { baseline: D; draft: D };

/**
 * Re-seed one scope from fresh server state: the baseline always; the draft
 * too, unless `keepDirty` and the previous draft had staged changes — then
 * those survive (a background refresh, switching scopes and back).
 */
export function reseed<D>(
  prev: Staged<D> | undefined,
  baseline: D,
  keepDirty: boolean,
  dirty: (base: D, draft: D) => number,
  clone: (d: D) => D,
): Staged<D> {
  const wasDirty = prev ? dirty(prev.baseline, prev.draft) > 0 : false;
  return { baseline, draft: keepDirty && wasDirty && prev ? prev.draft : clone(baseline) };
}

// ── Kind scope ──────────────────────────────────────────────────────────────

/** A per-kind narrowing of one component: the values it may still take (absent = all). */
export type KindNarrow = { variants?: string[]; tones?: string[] };

/** One kind's state from GET /constraint/kind/{kind} (and its save). */
export type KindState = {
  kind: {
    id: string;
    label: string;
    mode: string;
    fragment: boolean;
    include_new: boolean;
    /** PHP sends an empty narrowing as `[]`. */
    components: Record<string, KindNarrow | unknown[]>;
    removed: string[];
    opener: string;
    limits: Record<string, number>;
  };
  effective: {
    placeable: string[];
    variants: Record<string, string[]>;
    tones: Record<string, string[]>;
    opener: string | null;
    limits: Record<string, number>;
    warnings: string[];
  };
  /** What the SAVED site layer turned off — locked in a kind scope. */
  site_off: { components: string[]; tones: string[] };
  scopes?: ScopeInfo[];
};

/**
 * A kind's staged draft — exactly the shape its save takes. Two regimes
 * (`include_new`): AUTOMATIC (true) = every component is offered unless it is
 * in `removed` (the deny list), `components` stays empty; HOLD (false) = only
 * the `components` keys are offered (the allow-list), each optionally narrowing
 * its own variants/tones. The server refuses narrowing in automatic mode.
 */
export type KindDraft = {
  include_new: boolean;
  components: Record<string, KindNarrow>;
  removed: string[];
  /** '' = no required opener. */
  opener: string;
  /** Max placements per page, by component. */
  limits: Record<string, number>;
};

function cleanNarrow(narrow: unknown): KindNarrow {
  const out: KindNarrow = {};
  if (!narrow || typeof narrow !== "object" || Array.isArray(narrow)) return out;
  for (const key of ["variants", "tones"] as const) {
    const list = (narrow as KindNarrow)[key];
    if (Array.isArray(list) && list.length > 0) out[key] = uniq(list.map(String));
  }
  return out;
}

function cloneNarrow(narrow: KindNarrow): KindNarrow {
  const out: KindNarrow = {};
  if (narrow.variants) out.variants = [...narrow.variants];
  if (narrow.tones) out.tones = [...narrow.tones];
  return out;
}

/** Seed a kind draft from its stored settings (PHP's `[]` maps become `{}`). */
export function seedKindDraft(state: KindState): KindDraft {
  const k = state.kind;
  const components: Record<string, KindNarrow> = {};
  if (k.components && typeof k.components === "object" && !Array.isArray(k.components)) {
    for (const [name, narrow] of Object.entries(k.components)) components[name] = cleanNarrow(narrow);
  }
  const limits: Record<string, number> = {};
  if (k.limits && typeof k.limits === "object" && !Array.isArray(k.limits)) {
    for (const [name, max] of Object.entries(k.limits)) {
      const n = Math.floor(Number(max));
      if (n > 0) limits[name] = n;
    }
  }
  return {
    include_new: Boolean(k.include_new),
    components,
    removed: uniq(k.removed ?? []),
    opener: k.opener ?? "",
    limits,
  };
}

export function cloneKindDraft(draft: KindDraft): KindDraft {
  return {
    include_new: draft.include_new,
    components: Object.fromEntries(Object.entries(draft.components).map(([k, v]) => [k, cloneNarrow(v)])),
    removed: [...draft.removed],
    opener: draft.opener,
    limits: { ...draft.limits },
  };
}

/** Whether a component is offered by the kind itself (the site layer aside). */
export function isKindOn(draft: KindDraft, name: string): boolean {
  return draft.include_new ? !draft.removed.includes(name) : name in draft.components;
}

/** Whether a kind row is ticked: offered by the kind AND not turned off site-wide. */
export function isKindRowOn(draft: KindDraft, siteOff: string[], name: string): boolean {
  return !siteOff.includes(name) && isKindOn(draft, name);
}

function sameList(a: string[] | undefined, b: string[] | undefined): boolean {
  if (a === undefined || b === undefined) return a === b;
  return symmetricDiff(a, b) === 0;
}

/**
 * The kind's changed-item count. Counted by MEANING, not by representation:
 * switching the regime counts one (the conversion keeps every row's tick), each
 * component whose on/off differs counts one, each changed variant or tone
 * narrowing of a component on in both counts one, the opener one, each changed
 * limit one. `names` is every component the rail lists: a component named in
 * neither draft's lists still flips when the regime does (on by default in
 * automatic, off in hold).
 */
export function countKindDirty(base: KindDraft, draft: KindDraft, names: string[] = []): number {
  let n = base.include_new !== draft.include_new ? 1 : 0;
  const all = new Set([
    ...names,
    ...Object.keys(base.components),
    ...base.removed,
    ...Object.keys(draft.components),
    ...draft.removed,
  ]);
  for (const name of all) {
    const was = isKindOn(base, name);
    if (was !== isKindOn(draft, name)) {
      n++;
    } else if (was) {
      const a = base.components[name] ?? {};
      const b = draft.components[name] ?? {};
      if (!sameList(a.variants, b.variants)) n++;
      if (!sameList(a.tones, b.tones)) n++;
    }
  }
  if (base.opener !== draft.opener) n++;
  for (const name of new Set([...Object.keys(base.limits), ...Object.keys(draft.limits)])) {
    if (base.limits[name] !== draft.limits[name]) n++;
  }
  return n;
}

/** Offer / stop offering one component in the kind — returns a NEW draft. */
export function setKindComponentOn(draft: KindDraft, name: string, on: boolean): KindDraft {
  const next = cloneKindDraft(draft);
  if (draft.include_new) {
    next.removed = toggleRemoval(draft.removed, name, on);
  } else if (on) {
    next.components[name] = next.components[name] ?? {};
  } else {
    delete next.components[name];
  }
  return next;
}

/**
 * Switch the regime, converting the representation so every row keeps its
 * tick: to AUTOMATIC → the allow-list empties and `removed` becomes every
 * currently-unticked component (any variant/tone narrowing is dropped — the
 * server refuses it there); to HOLD → `components` becomes every
 * currently-ticked component and `removed` empties. `names` is every component
 * the rail lists.
 */
export function setIncludeNew(draft: KindDraft, includeNew: boolean, names: string[]): KindDraft {
  if (draft.include_new === includeNew) return draft;
  const next = cloneKindDraft(draft);
  if (includeNew) {
    return { ...next, include_new: true, components: {}, removed: names.filter((n) => !isKindOn(draft, n)) };
  }
  const components: Record<string, KindNarrow> = {};
  for (const name of names) {
    if (isKindOn(draft, name)) components[name] = next.components[name] ?? {};
  }
  return { ...next, include_new: false, components, removed: [] };
}

export const KIND_AUTOMATIC_REASON = "Switch to “Off until I allow them” to narrow variants per page type.";
export const KIND_OFF_REASON = "Offer the component here first.";
export const SITE_OFF_REASON = "Off everywhere — change it under Everywhere.";

type NarrowKey = keyof KindNarrow;

/** The values a kind may choose from: the component's own, minus what the site removed. */
export function kindAllowed(all: string[], siteRemoved: string[]): string[] {
  return all.filter((v) => !siteRemoved.includes(v));
}

/** The values a kind currently offers for one component (narrowing applied). */
function kindChosen(draft: KindDraft, name: string, key: NarrowKey, allowed: string[]): string[] {
  const narrow = draft.components[name]?.[key];
  return narrow ? allowed.filter((v) => narrow.includes(v)) : allowed;
}

/**
 * A component's Variants or Tones ticks in a kind scope. A value removed
 * site-wide is locked OFF; in automatic mode, or while the component is not
 * offered here, every tick is locked; the last chosen value is locked ON.
 */
export function kindTicks(
  draft: KindDraft,
  name: string,
  key: NarrowKey,
  all: string[],
  siteRemoved: string[],
): Tick[] {
  const allowed = kindAllowed(all, siteRemoved);
  const chosen = kindChosen(draft, name, key, allowed);
  const on = isKindOn(draft, name);
  return all.map((value) => {
    if (siteRemoved.includes(value)) return { value, on: false, locked: true, reason: SITE_OFF_REASON };
    const ticked = chosen.includes(value);
    if (draft.include_new) return { value, on: ticked, locked: true, reason: KIND_AUTOMATIC_REASON };
    if (!on) return { value, on: ticked, locked: true, reason: KIND_OFF_REASON };
    const last = ticked && chosen.length === 1;
    const reason = key === "variants" ? LAST_VARIANT_REASON : LAST_TONE_REASON;
    return { value, on: ticked, locked: last, reason: last ? reason : null };
  });
}

/**
 * Narrow one variant/tone of one component in a kind — returns a NEW draft, or
 * the SAME draft (refused) in automatic mode, for a component not offered here,
 * for a value outside `allowed`, or when it would leave none. A narrowing that
 * offers every allowed value is dropped (no narrowing).
 */
export function setKindNarrow(
  draft: KindDraft,
  name: string,
  key: NarrowKey,
  value: string,
  on: boolean,
  allowed: string[],
): KindDraft {
  if (draft.include_new || !isKindOn(draft, name) || !allowed.includes(value)) return draft;
  const chosen = kindChosen(draft, name, key, allowed);
  const picked = on ? [...chosen, value] : chosen.filter((v) => v !== value);
  const kept = allowed.filter((v) => picked.includes(v));
  if (kept.length === 0) return draft;
  const next = cloneKindDraft(draft);
  const narrow = next.components[name] ?? {};
  if (kept.length === allowed.length) delete narrow[key];
  else narrow[key] = kept;
  next.components[name] = narrow;
  return next;
}

/** Set (a positive whole number) or clear (anything else) one component's per-page limit. */
export function setKindLimit(draft: KindDraft, name: string, max: number | null): KindDraft {
  const next = cloneKindDraft(draft);
  const n = max === null ? 0 : Math.floor(max);
  if (Number.isFinite(n) && n > 0) next.limits[name] = n;
  else delete next.limits[name];
  return next;
}

/** Require an opener ('' = none). */
export function setKindOpener(draft: KindDraft, opener: string): KindDraft {
  return { ...cloneKindDraft(draft), opener };
}

/** The opener choices: every component ticked here (not the virtual `block`), plus the stored one. */
export function openerOptions(entries: ComponentEntry[], draft: KindDraft, siteOff: string[]): string[] {
  const names = entriesInGroupOrder(entries)
    .filter((e) => e.name !== "block" && isKindRowOn(draft, siteOff, e.name))
    .map((e) => e.name);
  return draft.opener && !names.includes(draft.opener) ? [draft.opener, ...names] : names;
}

/**
 * The kind's save body. Automatic sends an empty allow-list (narrowing is
 * refused there); hold sends an empty deny list. Every key present, so each
 * REPLACES the stored one.
 */
export function toKindSavePayload(draft: KindDraft): KindDraft {
  const out = cloneKindDraft(draft);
  if (out.include_new) out.components = {};
  else out.removed = [];
  return out;
}

/** "14 of 26 offered here", plus " · 2 unsaved". */
export function kindStatusLine(entries: ComponentEntry[], draft: KindDraft, siteOff: string[], dirty: number): string {
  const on = entries.filter((e) => isKindRowOn(draft, siteOff, e.name)).length;
  const base = `${on} of ${entries.length} offered here`;
  return dirty > 0 ? `${base} · ${dirty} unsaved` : base;
}

/**
 * A kind's contact sheet: what its saved palette offers (`placeable`), adjusted
 * by the staged ticks — a row unticked drops out, a row newly ticked joins.
 * Never a component turned off site-wide or the virtual `block`.
 */
export function kindContactSheetItems(
  entries: ComponentEntry[],
  base: KindDraft,
  draft: KindDraft,
  siteOff: string[],
  placeable: string[],
): RenderItem[] {
  return entriesInGroupOrder(entries)
    .filter((e) => e.name !== "block" && (e.examples?.length ?? 0) > 0)
    .filter((e) => isKindRowOn(draft, siteOff, e.name))
    .filter((e) => placeable.includes(e.name) || !isKindOn(base, e.name))
    .map((e) => ({ component: e.name, example: 0 }));
}

// ── Impact list before Publish (D6) ─────────────────────────────────────────

/** One affected section (or page-level rule) from POST /constraint/check. */
export type ImpactRow = {
  nid: string;
  title: string;
  langcode: string;
  status: string;
  kind: string;
  type: "page" | "block";
  /** '-' for a page-level rule (opener, limit). */
  slot: string;
  component: string;
  /** Plain words: "component no longer offered", "over limit", … */
  impact: string;
  detail: string;
};

export type CheckResult = { scope: string; impacts: number; rows: ImpactRow[] };

function impactKey(r: ImpactRow): string {
  return [r.type, r.nid, r.langcode, r.slot, r.component, r.impact].join("\u0000");
}

/**
 * The impacts the staged change ADDS: the check runs over every page, so rows
 * already true under the saved settings (an earlier removal) are subtracted —
 * only what this Publish changes is listed.
 */
export function newImpacts(before: ImpactRow[], after: ImpactRow[]): ImpactRow[] {
  const seen = new Set(before.map(impactKey));
  return after.filter((r) => !seen.has(impactKey(r)));
}

export type ImpactGroup = {
  key: string;
  nid: string;
  title: string;
  langcode: string;
  type: "page" | "block";
  rows: ImpactRow[];
};

/** Impacts grouped by page (or block) translation, in first-seen order. */
export function groupImpacts(rows: ImpactRow[]): ImpactGroup[] {
  const groups = new Map<string, ImpactGroup>();
  for (const r of rows) {
    const key = `${r.type}:${r.nid}:${r.langcode}`;
    let g = groups.get(key);
    if (!g) {
      g = { key, nid: r.nid, title: r.title, langcode: r.langcode, type: r.type, rows: [] };
      groups.set(key, g);
    }
    g.rows.push(r);
  }
  return [...groups.values()];
}

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

/**
 * The panel's heading. Sections are KEPT and keep rendering — they are only no
 * longer offered (D5): "3 sections on 2 pages will be kept but no longer
 * offered". Page-level rules (opener, limit) alone read as pages that won't
 * meet the new rules.
 */
export function impactHeading(rows: ImpactRow[]): string {
  const groups = groupImpacts(rows);
  const blocks = groups.filter((g) => g.type === "block").length;
  const pages = groups.length - blocks;
  const where =
    blocks === 0 ? plural(pages, "page", "pages")
    : pages === 0 ? plural(blocks, "block", "blocks")
    : `${plural(pages, "page", "pages")} and ${plural(blocks, "block", "blocks")}`;
  const sections = rows.filter((r) => r.slot !== "-").length;
  if (sections > 0) return `${plural(sections, "section", "sections")} on ${where} will be kept but no longer offered`;
  return `${where} won’t meet the new rules — they’re kept as they are`;
}

/**
 * What each built-in is FOR, in the owner's words — the rail's summary line.
 * The SDC `description` and `use` are written for developers and the agent
 * (props, "no JS", "the agent picks"); a pack component falls back to its own
 * description, the same console-side pattern as the SVG icon map (D10).
 */
const BUILTIN_SUMMARIES: Record<string, string> = {
  hero: "The opening of a page: a headline, a line of intro and a button, often with an image.",
  banner: "One bold sentence across the full width, with an optional button.",
  content: "A block of text beside an image, or text on its own.",
  features: "A short intro and a row of cards, each naming one thing you offer.",
  markdown: "Long-form reading text — articles, policies, instructions.",
  accordion: "Panels that open and close, each holding text, images or lists.",
  faq: "Questions and answers that open one at a time.",
  image: "One image on its own, with an optional caption.",
  gallery: "A grid of photos or screenshots.",
  testimonials: "Quotes from customers, with names and photos.",
  logos: "A quiet row of partner or customer logos.",
  stats: "A row of headline numbers.",
  team: "The people behind the business — photos, names and roles.",
  cta: "A closing invitation to act — call, book, sign up.",
  pricing: "Plans and prices side by side, one of them highlighted.",
  newsletter: "An email sign-up: one field and one button.",
  grid: "A grid of equal tiles, each with a title, text and an optional link.",
  divider: "A pause between sections — a line, space or a small label.",
  collection: "A live list of your latest posts that updates itself.",
  embed: "Something already on your site — a page, article or media item — shown in place.",
  block: "A reusable block you made once and place on many pages.",
};

/** The rail's one-line summary of a component (built-in map, else the SDC's description). */
export function componentSummary(entry: Pick<ComponentEntry, "name" | "provider" | "description">): string {
  const builtin = entry.provider === "" || entry.provider === "aincient_pages";
  return (builtin ? BUILTIN_SUMMARIES[entry.name] : undefined) ?? entry.description ?? "";
}
