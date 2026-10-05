import { describe, expect, it } from "vitest";
import {
  cloneConstraintDraft,
  contactSheetItems,
  countDirty,
  filterEntries,
  groupEntries,
  humanizeName,
  isEnabled,
  isLastEnabledTone,
  isLastEnabledVariant,
  isOn,
  isPack,
  LAST_TONE_REASON,
  LAST_VARIANT_REASON,
  seedConstraintDraft,
  selectionItem,
  setComponentEnabled,
  setComponentToneEnabled,
  setToneEnabled,
  setVariantEnabled,
  SITE_TONE_REASON,
  statusLine,
  toneTicks,
  toSavePayload,
  variantTicks,
  compareFrames,
  DEFAULT_VIEW,
  filterBlocked,
  isOverrideOff,
  originalId,
  overrideBadge,
  overridePreview,
  setOverrideOff,
  viewForSelection,
  type BlockedComponent,
  countKindDirty,
  groupImpacts,
  impactHeading,
  isKindOn,
  isKindRowOn,
  isRecipe,
  KIND_AUTOMATIC_REASON,
  KIND_OFF_REASON,
  kindContactSheetItems,
  kindStatusLine,
  kindTicks,
  newImpacts,
  openerOptions,
  reseed,
  scopeMenu,
  scopeOptionLabel,
  seedKindDraft,
  cloneKindDraft,
  setIncludeNew,
  setKindComponentOn,
  setKindLimit,
  setKindNarrow,
  setKindOpener,
  SITE_OFF_REASON,
  toKindSavePayload,
  type ComponentEntry,
  type ConstraintManifest,
  type ImpactRow,
  type KindState,
  type ScopeInfo,
  componentSummary,
} from "./constraint-model";

/**
 * The studio's logic layer works in REMOVALS (the server's semantics): a checked
 * box means "not in the removals list". These tests pin what can go wrong on
 * this side — the enabled↔removed mapping, the dirty count, the last-tone /
 * last-variant guards that mirror the server's 422s (site-wide and per
 * component, the per-component tones honouring the site-wide removals), the
 * rail's grouping/filters/search/status line, the preview's request items, and
 * that a publish payload never carries an empty per-component entry.
 */

const TONES = ["neutral", "brand", "accent", "inverse"];

function entry(name: string, over: Partial<ComponentEntry> = {}): ComponentEntry {
  return {
    name,
    tier: "section",
    icon: "",
    use: "",
    provider: "aincient_pages",
    group: "other",
    variants: [],
    tones: [],
    examples: ["Default"],
    usage: { pages: 0, blocks: 0, variants: {}, tones: {} },
    ...over,
  };
}

const HERO = entry("hero", {
  group: "openers",
  use: "Big opener",
  variants: ["banner", "split", "minimal"],
  tones: ["neutral", "brand", "accent"],
  examples: ["Product launch", "Minimal"],
  usage: { pages: 3, blocks: 1, variants: {}, tones: {} },
});
const CTA = entry("cta", { group: "conversion", use: "Call to action", tones: ["neutral", "brand"] });
const DIVIDER = entry("divider", { group: "structure", variants: ["line", "space"] });
const BLOCK = entry("block", { provider: "", group: "from_your_site", examples: [] });
const PACK = entry("acme_ticker", { provider: "acme_pack", group: "mystery", use: "Scrolling ticker" });
const ENTRIES = [PACK, BLOCK, DIVIDER, CTA, HERO];

function manifest(overrides: Partial<ConstraintManifest["constraint"]> = {}): ConstraintManifest {
  return {
    version: 2,
    components: ENTRIES,
    constraint: { components: [], tones: [], variants: {}, component_tones: {}, ...overrides },
    vocabulary: {
      components: [],
      tones: TONES,
      variants: { hero: HERO.variants, divider: DIVIDER.variants },
    },
    effective: { placeable: ENTRIES.map((e) => e.name), tones: TONES, warnings: [] },
  };
}

describe("seedConstraintDraft", () => {
  it("seeds the stored constraint, keeping unknown component removals", () => {
    const draft = seedConstraintDraft(
      manifest({ components: ["hero", "gone_pack_thing"], tones: ["inverse"] }),
    );
    expect(draft.components).toEqual(["hero", "gone_pack_thing"]);
    expect(draft.tones).toEqual(["inverse"]);
  });

  it("dedupes lists and drops empty per-component entries", () => {
    const draft = seedConstraintDraft(
      manifest({
        components: ["hero", "hero"],
        variants: { hero: ["split", "split"], divider: [] },
        component_tones: { hero: ["brand"], cta: [] },
      }),
    );
    expect(draft.components).toEqual(["hero"]);
    expect(draft.variants).toEqual({ hero: ["split"] });
    expect(draft.component_tones).toEqual({ hero: ["brand"] });
  });

  it("tolerates a manifest without component_tones", () => {
    const m = manifest();
    delete m.constraint.component_tones;
    expect(seedConstraintDraft(m).component_tones).toEqual({});
  });
});

describe("toggling semantics (enabled ↔ removals)", () => {
  it("unchecking a component adds it to the removals; re-checking removes it", () => {
    const base = seedConstraintDraft(manifest());
    expect(isOn(base, "hero")).toBe(true);

    const off = setComponentEnabled(base, "hero", false);
    expect(off.components).toEqual(["hero"]);
    expect(isEnabled(off.components, "hero")).toBe(false);
    expect(isOn(off, "hero")).toBe(false);
    // The original draft is untouched (staged edits never mutate the baseline).
    expect(base.components).toEqual([]);

    expect(setComponentEnabled(off, "hero", true).components).toEqual([]);
  });

  it("disabling twice does not duplicate the removal", () => {
    const base = seedConstraintDraft(manifest());
    const twice = setComponentEnabled(setComponentEnabled(base, "hero", false), "hero", false);
    expect(twice.components).toEqual(["hero"]);
  });
});

describe("countDirty", () => {
  it("counts every toggled component, tone, variant value and component tone once", () => {
    const base = seedConstraintDraft(manifest({ components: ["divider"] }));
    let draft = cloneConstraintDraft(base);
    draft = setComponentEnabled(draft, "hero", false); // +1
    draft = setComponentEnabled(draft, "divider", true); // +1 (re-enabled)
    draft = setToneEnabled(draft, "inverse", false, TONES); // +1
    draft = setVariantEnabled(draft, "hero", "split", false, HERO.variants); // +1
    draft = setComponentToneEnabled(draft, HERO, "accent", false); // +1
    expect(countDirty(base, draft)).toBe(5);
  });

  it("is 0 when the draft matches the baseline regardless of order", () => {
    const base = seedConstraintDraft(manifest({ components: ["a", "b"] }));
    const draft = { ...cloneConstraintDraft(base), components: ["b", "a"] };
    expect(countDirty(base, draft)).toBe(0);
  });
});

describe("site-wide last-tone guard", () => {
  it("refuses to disable the last remaining tone (mirrors the server's 422)", () => {
    let draft = seedConstraintDraft(manifest());
    for (const tone of ["neutral", "brand", "accent"]) {
      draft = setToneEnabled(draft, tone, false, TONES);
    }
    expect(isLastEnabledTone(draft, "inverse", TONES)).toBe(true);
    // The refusal returns the SAME draft.
    expect(setToneEnabled(draft, "inverse", false, TONES)).toBe(draft);
    expect(draft.tones).toEqual(["neutral", "brand", "accent"]);
  });

  it("a tone that is not the last one can still be disabled", () => {
    const draft = seedConstraintDraft(manifest());
    expect(isLastEnabledTone(draft, "brand", TONES)).toBe(false);
    expect(setToneEnabled(draft, "brand", false, TONES).tones).toEqual(["brand"]);
  });
});

describe("per-component variants", () => {
  it("refuses to disable a component's last variant, with a reason", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setVariantEnabled(draft, "hero", "banner", false, HERO.variants);
    draft = setVariantEnabled(draft, "hero", "split", false, HERO.variants);
    expect(isLastEnabledVariant(draft, "hero", "minimal", HERO.variants)).toBe(true);
    expect(setVariantEnabled(draft, "hero", "minimal", false, HERO.variants)).toBe(draft);

    const ticks = variantTicks(draft, HERO);
    expect(ticks.map((t) => [t.value, t.on, t.locked])).toEqual([
      ["banner", false, false],
      ["split", false, false],
      ["minimal", true, true],
    ]);
    expect(ticks[2].reason).toBe(LAST_VARIANT_REASON);
  });

  it("re-enabling a component's only removal drops the map entry", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setVariantEnabled(draft, "hero", "split", false, HERO.variants);
    expect(draft.variants).toEqual({ hero: ["split"] });
    draft = setVariantEnabled(draft, "hero", "split", true, HERO.variants);
    expect(draft.variants).toEqual({});
  });
});

describe("per-component tones", () => {
  it("removes and restores a tone for one component only", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setComponentToneEnabled(draft, HERO, "brand", false);
    expect(draft.component_tones).toEqual({ hero: ["brand"] });
    expect(draft.tones).toEqual([]);
    draft = setComponentToneEnabled(draft, HERO, "brand", true);
    expect(draft.component_tones).toEqual({});
  });

  it("locks a tone removed site-wide OFF in the component, and refuses to touch it", () => {
    const draft = setToneEnabled(seedConstraintDraft(manifest()), "accent", false, TONES);
    const accent = toneTicks(draft, HERO).find((t) => t.value === "accent")!;
    expect(accent).toEqual({ value: "accent", on: false, locked: true, reason: SITE_TONE_REASON });
    expect(setComponentToneEnabled(draft, HERO, "accent", true)).toBe(draft);
  });

  it("refuses the last AVAILABLE tone, counting both layers", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setToneEnabled(draft, "neutral", false, TONES); // site-wide
    draft = setComponentToneEnabled(draft, HERO, "brand", false); // hero only
    // hero: neutral locked off site-wide, brand off for hero → accent is the last.
    const ticks = toneTicks(draft, HERO);
    expect(ticks.map((t) => [t.value, t.on, t.locked])).toEqual([
      ["neutral", false, true],
      ["brand", false, false],
      ["accent", true, true],
    ]);
    expect(ticks[2].reason).toBe(LAST_TONE_REASON);
    expect(setComponentToneEnabled(draft, HERO, "accent", false)).toBe(draft);
  });

  it("a component without tones has no tone ticks", () => {
    expect(toneTicks(seedConstraintDraft(manifest()), DIVIDER)).toEqual([]);
  });
});

describe("grouping, filters, search, status", () => {
  it("groups in the fixed order, unknown groups into Other, empty groups skipped", () => {
    const groups = groupEntries(ENTRIES);
    expect(groups.map((g) => g.id)).toEqual(["openers", "conversion", "structure", "from_your_site", "other"]);
    expect(groups.map((g) => g.label)).toEqual(["Openers", "Conversion", "Structure", "From your site", "Other"]);
    expect(groups[4].entries.map((e) => e.name)).toEqual(["acme_ticker"]);
  });

  it("isPack: '' and aincient_pages are built-in, anything else is a pack", () => {
    expect(isPack(HERO)).toBe(false);
    expect(isPack(BLOCK)).toBe(false);
    expect(isPack(PACK)).toBe(true);
  });

  it("filters unused, off and packs", () => {
    const draft = setComponentEnabled(seedConstraintDraft(manifest()), "cta", false);
    const names = (f: Parameters<typeof filterEntries>[2], q = "") =>
      filterEntries(ENTRIES, draft, f, q).map((e) => e.name);
    expect(names("all")).toHaveLength(5);
    expect(names("unused")).toEqual(["acme_ticker", "block", "divider", "cta"]);
    expect(names("off")).toEqual(["cta"]);
    expect(names("packs")).toEqual(["acme_ticker"]);
  });

  it("searches name and use, case-insensitively, combined with the filter", () => {
    const draft = seedConstraintDraft(manifest());
    expect(filterEntries(ENTRIES, draft, "all", "TICKER").map((e) => e.name)).toEqual(["acme_ticker"]);
    expect(filterEntries(ENTRIES, draft, "all", "call to").map((e) => e.name)).toEqual(["cta"]);
    expect(filterEntries(ENTRIES, draft, "packs", "hero")).toEqual([]);
  });

  it("reads the status line", () => {
    const draft = setComponentEnabled(seedConstraintDraft(manifest()), "cta", false);
    expect(statusLine(ENTRIES, draft, 0)).toBe("4 of 5 available");
    expect(statusLine(ENTRIES, draft, 2)).toBe("4 of 5 available · 2 unsaved");
  });

  it("humanizes names", () => {
    expect(humanizeName("social_proof")).toBe("Social proof");
    expect(humanizeName("cta")).toBe("Cta");
  });
});

describe("preview items", () => {
  it("contact sheet: every ON component except block, first example, group order", () => {
    const draft = setComponentEnabled(seedConstraintDraft(manifest()), "divider", false);
    expect(contactSheetItems(ENTRIES, draft)).toEqual([
      { component: "hero", example: 0 },
      { component: "cta", example: 0 },
      { component: "acme_ticker", example: 0 },
    ]);
  });

  it("selection clamps example, variant and tone to what the component has", () => {
    expect(selectionItem(HERO, { example: 1, variant: "split", tone: "brand", width: 1280, compare: "pack" })).toEqual({
      component: "hero",
      example: 1,
      variant: "split",
      tone: "brand",
    });
    expect(selectionItem(HERO, { example: 9, variant: "nope", tone: "inverse", width: 375, compare: "pack" })).toEqual({
      component: "hero",
      example: 0,
    });
  });
});

describe("toSavePayload", () => {
  it("publishes the whole slice, per-component maps only for components with removals", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setComponentEnabled(draft, "cta", false);
    draft = setToneEnabled(draft, "accent", false, TONES);
    draft = setVariantEnabled(draft, "divider", "line", false, DIVIDER.variants);
    draft = setComponentToneEnabled(draft, CTA, "brand", false);
    draft.variants["hero"] = []; // a stray empty entry must not be published
    draft.component_tones["hero"] = [];
    expect(toSavePayload(draft)).toEqual({
      components: ["cta"],
      tones: ["accent"],
      variants: { divider: ["line"] },
      component_tones: { cta: ["brand"] },
      overrides_off: [],
    });
  });
});

// ── P1b: scopes, kind drafts, impacts ───────────────────────────────────────

const SCOPES: ScopeInfo[] = [
  { id: "landing", label: "Landing page", mode: "composition", fragment: false, count: 4 },
  { id: "blog", label: "Blog post", mode: "recipe", fragment: false, count: 1 },
  { id: "block", label: "Block", mode: "composition", fragment: true, count: 3 },
];

describe("scopes", () => {
  it("splits the menu into page types and reusable fragments, never naming a kind", () => {
    const menu = scopeMenu(SCOPES);
    expect(menu.pages.map((s) => s.id)).toEqual(["landing", "blog"]);
    expect(menu.reusable.map((s) => s.id)).toEqual(["block"]);
    expect(scopeMenu(undefined)).toEqual({ pages: [], reusable: [] });
  });

  it("labels options with their counts", () => {
    expect(scopeOptionLabel(SCOPES[0])).toBe("Landing page · 4 pages");
    expect(scopeOptionLabel(SCOPES[1])).toBe("Blog post · 1 page");
    expect(scopeOptionLabel(SCOPES[2])).toBe("Block · 3");
  });

  it("isRecipe", () => {
    expect(isRecipe(SCOPES[1])).toBe(true);
    expect(isRecipe(SCOPES[0])).toBe(false);
    expect(isRecipe(null)).toBe(false);
  });

  it("reseed keeps a dirty draft only when asked, and always takes the fresh baseline", () => {
    const dirty = (a: string[], b: string[]) => (a.join() === b.join() ? 0 : 1);
    const clone = (d: string[]) => [...d];
    const prev = { baseline: ["a"], draft: ["a", "b"] };
    expect(reseed(prev, ["c"], true, dirty, clone)).toEqual({ baseline: ["c"], draft: ["a", "b"] });
    expect(reseed(prev, ["c"], false, dirty, clone)).toEqual({ baseline: ["c"], draft: ["c"] });
    // A clean draft follows the fresh baseline even when keeping dirty drafts.
    expect(reseed({ baseline: ["a"], draft: ["a"] }, ["c"], true, dirty, clone).draft).toEqual(["c"]);
    expect(reseed(undefined, ["c"], true, dirty, clone).draft).toEqual(["c"]);
  });
});

function kindState(over: Partial<KindState["kind"]> = {}, siteOff: string[] = []): KindState {
  return {
    kind: {
      id: "landing",
      label: "Landing page",
      mode: "composition",
      fragment: false,
      include_new: true,
      components: {},
      removed: [],
      opener: "",
      limits: {},
      ...over,
    },
    effective: { placeable: ["hero", "cta", "divider", "acme_ticker"], variants: {}, tones: {}, opener: null, limits: {}, warnings: [] },
    site_off: { components: siteOff, tones: [] },
    scopes: SCOPES,
  };
}

const NAMES = ENTRIES.map((e) => e.name);

describe("kind drafts", () => {
  it("seeds from the stored kind, turning PHP's empty [] maps into {}", () => {
    const draft = seedKindDraft(
      kindState({
        include_new: false,
        components: { hero: { variants: ["split"] }, cta: [] } as KindState["kind"]["components"],
        limits: [] as unknown as Record<string, number>,
      }),
    );
    expect(draft.components).toEqual({ hero: { variants: ["split"] }, cta: {} });
    expect(draft.limits).toEqual({});
    expect(isKindOn(draft, "hero")).toBe(true);
    expect(isKindOn(draft, "divider")).toBe(false);
  });

  it("automatic: a tick edits the deny list; hold: a tick edits the allow-list", () => {
    const auto = seedKindDraft(kindState());
    expect(setKindComponentOn(auto, "cta", false).removed).toEqual(["cta"]);
    expect(setKindComponentOn(auto, "cta", false).components).toEqual({});

    const hold = seedKindDraft(kindState({ include_new: false, components: { hero: { tones: ["brand"] } } }));
    const on = setKindComponentOn(hold, "cta", true);
    expect(on.components).toEqual({ hero: { tones: ["brand"] }, cta: {} });
    expect(setKindComponentOn(on, "hero", false).components).toEqual({ cta: {} });
    // The original is untouched.
    expect(hold.components).toEqual({ hero: { tones: ["brand"] } });
  });

  it("switching the regime converts the representation and keeps every tick", () => {
    const auto = setKindComponentOn(seedKindDraft(kindState()), "cta", false);
    const hold = setIncludeNew(auto, false, NAMES);
    expect(hold.include_new).toBe(false);
    expect(hold.removed).toEqual([]);
    expect(Object.keys(hold.components).sort()).toEqual(["acme_ticker", "block", "divider", "hero"]);
    for (const n of NAMES) expect(isKindOn(hold, n)).toBe(isKindOn(auto, n));

    // Back to automatic: allow-list empties, deny list = what is unticked, narrowing dropped.
    const narrowed = setKindNarrow(hold, "hero", "variants", "split", false, HERO.variants);
    const back = setIncludeNew(narrowed, true, NAMES);
    expect(back.components).toEqual({});
    expect(back.removed).toEqual(["cta"]);
    // Same regime → the same draft.
    expect(setIncludeNew(back, true, NAMES)).toBe(back);
  });

  it("counts dirt by meaning: the regime switch alone is one change", () => {
    const base = seedKindDraft(kindState());
    const hold = setIncludeNew(base, false, NAMES);
    expect(countKindDirty(base, hold, NAMES)).toBe(1);
    let draft = setKindComponentOn(hold, "cta", false); // +1
    draft = setKindNarrow(draft, "hero", "variants", "split", false, HERO.variants); // +1
    draft = setKindNarrow(draft, "hero", "variants", "banner", false, HERO.variants); // same list, still +1
    draft = setKindOpener(draft, "hero"); // +1
    draft = setKindLimit(draft, "cta", 2); // +1
    expect(countKindDirty(base, draft, NAMES)).toBe(5);
    expect(countKindDirty(base, cloneKindDraft(base), NAMES)).toBe(0);
    // A component named in neither draft's lists still counts when the regime flips it.
    expect(countKindDirty(base, { ...base, include_new: false }, NAMES)).toBe(1 + NAMES.length);
  });

  it("narrows variants/tones only in hold mode, for an offered component, never to none", () => {
    const auto = seedKindDraft(kindState());
    expect(setKindNarrow(auto, "hero", "variants", "split", false, HERO.variants)).toBe(auto);

    const hold = seedKindDraft(kindState({ include_new: false, components: { hero: {} } }));
    expect(setKindNarrow(hold, "cta", "tones", "brand", false, CTA.tones)).toBe(hold); // cta not offered
    let d = setKindNarrow(hold, "hero", "variants", "split", false, HERO.variants);
    expect(d.components.hero).toEqual({ variants: ["banner", "minimal"] });
    d = setKindNarrow(d, "hero", "variants", "banner", false, HERO.variants);
    expect(setKindNarrow(d, "hero", "variants", "minimal", false, HERO.variants)).toBe(d);
    // Re-offering every allowed value drops the narrowing.
    d = setKindNarrow(setKindNarrow(d, "hero", "variants", "banner", true, HERO.variants), "hero", "variants", "split", true, HERO.variants);
    expect(d.components.hero).toEqual({});
  });

  it("ticks: site-removed locked off; automatic locked with the switch hint; last one locked on", () => {
    const auto = seedKindDraft(kindState());
    const t = kindTicks(auto, "hero", "variants", HERO.variants, ["minimal"]);
    expect(t[2]).toEqual({ value: "minimal", on: false, locked: true, reason: SITE_OFF_REASON });
    expect(t[0]).toEqual({ value: "banner", on: true, locked: true, reason: KIND_AUTOMATIC_REASON });

    const hold = seedKindDraft(kindState({ include_new: false, components: { hero: { tones: ["accent"] } } }));
    expect(kindTicks(hold, "hero", "tones", HERO.tones, []).map((x) => [x.value, x.on, x.locked])).toEqual([
      ["neutral", false, false],
      ["brand", false, false],
      ["accent", true, true],
    ]);
    expect(kindTicks(hold, "cta", "tones", CTA.tones, [])[0].reason).toBe(KIND_OFF_REASON);
  });

  it("limits take positive whole numbers; anything else clears", () => {
    const d = seedKindDraft(kindState());
    expect(setKindLimit(d, "hero", 2.7).limits).toEqual({ hero: 2 });
    expect(setKindLimit(setKindLimit(d, "hero", 2), "hero", null).limits).toEqual({});
    expect(setKindLimit(d, "hero", 0).limits).toEqual({});
    expect(setKindLimit(d, "hero", Number.NaN).limits).toEqual({});
  });

  it("site-off rows are never ticked, offered as opener or shown on the sheet", () => {
    const state = kindState({}, ["cta"]);
    const d = seedKindDraft(state);
    expect(isKindRowOn(d, ["cta"], "cta")).toBe(false);
    expect(openerOptions(ENTRIES, d, ["cta"])).toEqual(["hero", "divider", "acme_ticker"]);
    expect(kindStatusLine(ENTRIES, d, ["cta"], 0)).toBe("4 of 5 offered here");
    expect(kindStatusLine(ENTRIES, d, ["cta"], 2)).toBe("4 of 5 offered here · 2 unsaved");
    // A stored opener that is no longer ticked stays selectable.
    expect(openerOptions(ENTRIES, setKindOpener(d, "cta"), ["cta"])[0]).toBe("cta");
  });

  it("the sheet is the saved palette adjusted by the staged ticks", () => {
    const state = kindState({ include_new: false, components: { hero: {}, cta: {} } });
    state.effective.placeable = ["hero", "cta"];
    const base = seedKindDraft(state);
    let d = setKindComponentOn(base, "cta", false);
    d = setKindComponentOn(d, "divider", true);
    expect(kindContactSheetItems(ENTRIES, base, d, [], state.effective.placeable)).toEqual([
      { component: "hero", example: 0 },
      { component: "divider", example: 0 },
    ]);
  });

  it("the save body: automatic sends no allow-list, hold sends no deny list", () => {
    const auto = setKindComponentOn(seedKindDraft(kindState()), "cta", false);
    expect(toKindSavePayload({ ...auto, components: { hero: {} } })).toEqual({
      include_new: true, components: {}, removed: ["cta"], opener: "", limits: {},
    });
    const hold = setIncludeNew(auto, false, NAMES);
    expect(toKindSavePayload({ ...hold, removed: ["x"] }).removed).toEqual([]);
  });
});

function row(nid: string, over: Partial<ImpactRow> = {}): ImpactRow {
  return {
    nid,
    title: `Page ${nid}`,
    langcode: "en",
    status: "published",
    kind: "landing",
    type: "page",
    slot: `s${nid}`,
    component: "hero",
    impact: "component no longer offered",
    detail: "",
    ...over,
  };
}

describe("impacts", () => {
  it("lists only what the change adds over the saved state", () => {
    const before = [row("1")];
    const after = [row("1"), row("2"), row("1", { slot: "s9", component: "cta" })];
    expect(newImpacts(before, after).map((r) => [r.nid, r.slot])).toEqual([["2", "s2"], ["1", "s9"]]);
  });

  it("groups by page translation in first-seen order", () => {
    const rows = [row("1"), row("2"), row("1", { slot: "s3" }), row("1", { langcode: "de", title: "Seite 1" }), row("7", { type: "block" })];
    const groups = groupImpacts(rows);
    expect(groups.map((g) => [g.key, g.rows.length])).toEqual([
      ["page:1:en", 2],
      ["page:2:en", 1],
      ["page:1:de", 1],
      ["block:7:en", 1],
    ]);
  });

  it("headings: sections kept but no longer offered; page rules alone read differently", () => {
    expect(impactHeading([row("1"), row("1", { slot: "s2" }), row("2")])).toBe(
      "3 sections on 2 pages will be kept but no longer offered",
    );
    expect(impactHeading([row("1"), row("7", { type: "block" })])).toBe(
      "2 sections on 1 page and 1 block will be kept but no longer offered",
    );
    expect(impactHeading([row("1", { slot: "-", impact: "over limit" })])).toBe(
      "1 page won’t meet the new rules — they’re kept as they are",
    );
  });
});

// ── P3 + P4b: pack overrides, "Use original instead", "Can't be used" ───────

const OVERRIDDEN = entry("hero", {
  group: "openers",
  replaced_by: { id: "acme_pack:fancy_hero", provider: "acme_pack", label: "Fancy hero" },
});

describe("overrides_off in the site draft", () => {
  it("seeds from the stored constraint, deduped, and defaults to empty", () => {
    expect(seedConstraintDraft(manifest()).overrides_off).toEqual([]);
    const m = manifest({ overrides_off: ["aincient_pages:hero", "aincient_pages:hero"] });
    expect(seedConstraintDraft(m).overrides_off).toEqual(["aincient_pages:hero"]);
  });

  it("the original's id is provider:name", () => {
    expect(originalId(OVERRIDDEN)).toBe("aincient_pages:hero");
  });

  it("stages the switch as a change: dirty, cloned, discarded with the draft", () => {
    const base = seedConstraintDraft(manifest());
    const off = setOverrideOff(base, OVERRIDDEN, true);
    expect(off).not.toBe(base);
    expect(base.overrides_off).toEqual([]);
    expect(isOverrideOff(off, OVERRIDDEN)).toBe(true);
    expect(countDirty(base, off)).toBe(1);
    const clone = cloneConstraintDraft(off);
    clone.overrides_off.push("x:y");
    expect(off.overrides_off).toEqual(["aincient_pages:hero"]);
    // Switching back is clean again, and never duplicates.
    expect(countDirty(base, setOverrideOff(off, OVERRIDDEN, false))).toBe(0);
    expect(setOverrideOff(off, OVERRIDDEN, true).overrides_off).toEqual(["aincient_pages:hero"]);
  });

  it("refuses a component no pack overrides", () => {
    const base = seedConstraintDraft(manifest());
    expect(setOverrideOff(base, HERO, true)).toBe(base);
    expect(isOverrideOff({ ...base, overrides_off: ["aincient_pages:hero"] }, HERO)).toBe(false);
  });

  it("is sent on Publish", () => {
    const draft = setOverrideOff(seedConstraintDraft(manifest()), OVERRIDDEN, true);
    expect(toSavePayload(draft).overrides_off).toEqual(["aincient_pages:hero"]);
  });
});

describe("override badge + preview mode", () => {
  it("badges only an overridden component", () => {
    expect(overrideBadge(HERO, false)).toBeNull();
    expect(overrideBadge(OVERRIDDEN, false)).toBe("Replaced by acme_pack");
    expect(overrideBadge(OVERRIDDEN, true)).toBe("Using original");
  });

  it("picks the preview mode from the draft and the saved site", () => {
    const base = seedConstraintDraft(manifest());
    const off = setOverrideOff(base, OVERRIDDEN, true);
    expect(overridePreview(HERO, base)).toBe("none");
    expect(overridePreview(OVERRIDDEN, base)).toBe("switch");
    expect(overridePreview(OVERRIDDEN, off)).toBe("original");
    // Off on the live site, staged back on: the server still renders the original.
    expect(overridePreview({ ...OVERRIDDEN, override_off: true }, base)).toBe("pending");
    expect(overridePreview({ ...OVERRIDDEN, override_off: true }, off)).toBe("original");
  });

  it("maps the mode + the view switch to the frames to render", () => {
    expect(compareFrames("none", "compare")).toEqual(["pack"]);
    expect(compareFrames("original", "pack")).toEqual(["original"]);
    expect(compareFrames("pending", "compare")).toEqual(["original"]);
    expect(compareFrames("switch", "pack")).toEqual(["pack"]);
    expect(compareFrames("switch", "original")).toEqual(["original"]);
    expect(compareFrames("switch", "compare")).toEqual(["pack", "original"]);
  });

  it("a new selection resets the view to Pack and keeps the width", () => {
    expect(DEFAULT_VIEW.compare).toBe("pack");
    const next = viewForSelection({ example: 2, variant: "split", tone: "brand", width: 375, compare: "compare" });
    expect(next).toEqual({ ...DEFAULT_VIEW, width: 375 });
  });
});

describe("filterBlocked", () => {
  const BLOCKED: BlockedComponent[] = [
    { name: "zeta", provider: "acme_pack", label: "Zeta strip", reason: "Its examples are malformed.", detail: "examples[0] …" },
    { name: "alpha", provider: "other_pack", label: "Alpha box", reason: "Made for a different version of Atelier.", detail: "atelier api version 9" },
  ];

  it("lists under All and Packs, sorted by label; never under Unused or Off", () => {
    expect(filterBlocked(BLOCKED, "all", "").map((b) => b.name)).toEqual(["alpha", "zeta"]);
    expect(filterBlocked(BLOCKED, "packs", "").length).toBe(2);
    expect(filterBlocked(BLOCKED, "unused", "")).toEqual([]);
    expect(filterBlocked(BLOCKED, "off", "")).toEqual([]);
    expect(filterBlocked(undefined, "all", "")).toEqual([]);
  });

  it("matches the search on name, label and pack", () => {
    expect(filterBlocked(BLOCKED, "all", "STRIP").map((b) => b.name)).toEqual(["zeta"]);
    expect(filterBlocked(BLOCKED, "all", "other_pack").map((b) => b.name)).toEqual(["alpha"]);
  });
});

describe("componentSummary", () => {
  it("gives a built-in its plain-language line, never the model-facing use", () => {
    const s = componentSummary({ name: "features", provider: "aincient_pages", description: "columns reflow the cards." });
    expect(s).toBe("A short intro and a row of cards, each naming one thing you offer.");
    expect(componentSummary({ name: "block", provider: "" })).toMatch(/reusable block/);
  });

  it("falls back to a pack component's own description, even on a built-in name", () => {
    expect(componentSummary({ name: "spotlight", provider: "acme_pack", description: "One product, big." })).toBe("One product, big.");
    expect(componentSummary({ name: "hero", provider: "acme_pack", description: "Acme hero." })).toBe("Acme hero.");
    expect(componentSummary({ name: "spotlight", provider: "acme_pack" })).toBe("");
  });
});
