import { describe, expect, it } from "vitest";
import {
  cloneConstraintDraft,
  countDirty,
  isEnabled,
  isLastEnabledTone,
  isLastEnabledVariant,
  seedConstraintDraft,
  setComponentEnabled,
  setToneEnabled,
  setVariantEnabled,
  toSavePayload,
  type ConstraintManifest,
} from "./constraint-model";

/**
 * The pane's logic layer works in REMOVALS (the server's semantics): a checked
 * box means "not in the removals list". These tests pin the four things that
 * can go wrong on this side — the enabled↔removed mapping, the dirty count,
 * the last-tone / last-variant guards that mirror the server's 422, and that a
 * publish payload never carries an empty variants entry.
 */

const TONES = ["neutral", "brand", "accent", "inverse"];

function manifest(overrides: Partial<ConstraintManifest["constraint"]> = {}): ConstraintManifest {
  return {
    constraint: { components: [], tones: [], variants: {}, ...overrides },
    vocabulary: {
      components: [
        { name: "hero", tier: "section", icon: "◆", use: "Big opener" },
        { name: "columns", tier: "layout", icon: "▥", use: "Side-by-side" },
        { name: "teasers", tier: "reference", icon: "⧉", use: "Page cards" },
      ],
      tones: TONES,
      variants: { hero: ["banner", "split", "minimal"], divider: ["line", "space"] },
    },
    effective: { placeable: ["hero", "columns", "teasers"], tones: TONES, warnings: [] },
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

  it("dedupes lists and drops empty variant entries", () => {
    const draft = seedConstraintDraft(
      manifest({ components: ["hero", "hero"], variants: { hero: ["split", "split"], divider: [] } }),
    );
    expect(draft.components).toEqual(["hero"]);
    expect(draft.variants).toEqual({ hero: ["split"] });
  });
});

describe("toggling semantics (enabled ↔ removals)", () => {
  it("unchecking a component adds it to the removals; re-checking removes it", () => {
    const base = seedConstraintDraft(manifest());
    expect(isEnabled(base.components, "hero")).toBe(true);

    const off = setComponentEnabled(base, "hero", false);
    expect(off.components).toEqual(["hero"]);
    expect(isEnabled(off.components, "hero")).toBe(false);
    // The original draft is untouched (staged edits never mutate the baseline).
    expect(base.components).toEqual([]);

    const on = setComponentEnabled(off, "hero", true);
    expect(on.components).toEqual([]);
  });

  it("disabling twice does not duplicate the removal", () => {
    const base = seedConstraintDraft(manifest());
    const twice = setComponentEnabled(setComponentEnabled(base, "hero", false), "hero", false);
    expect(twice.components).toEqual(["hero"]);
  });
});

describe("countDirty", () => {
  it("counts every toggled component, tone and variant value once", () => {
    const base = seedConstraintDraft(manifest({ components: ["teasers"] }));
    let draft = cloneConstraintDraft(base);
    draft = setComponentEnabled(draft, "hero", false); // +1
    draft = setComponentEnabled(draft, "teasers", true); // +1 (re-enabled)
    draft = setToneEnabled(draft, "inverse", false, TONES); // +1
    draft = setVariantEnabled(draft, "hero", "split", false, ["banner", "split", "minimal"]); // +1
    expect(countDirty(base, draft)).toBe(4);
  });

  it("is 0 when the draft matches the baseline regardless of order", () => {
    const base = seedConstraintDraft(manifest({ components: ["a", "b"] }));
    const draft = { ...cloneConstraintDraft(base), components: ["b", "a"] };
    expect(countDirty(base, draft)).toBe(0);
  });
});

describe("last-tone guard", () => {
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

describe("last-variant guard", () => {
  const HERO = ["banner", "split", "minimal"];

  it("refuses to disable a component's last variant", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setVariantEnabled(draft, "hero", "banner", false, HERO);
    draft = setVariantEnabled(draft, "hero", "split", false, HERO);
    expect(isLastEnabledVariant(draft, "hero", "minimal", HERO)).toBe(true);
    expect(setVariantEnabled(draft, "hero", "minimal", false, HERO)).toBe(draft);
  });

  it("re-enabling a component's only removal drops the map entry", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setVariantEnabled(draft, "hero", "split", false, HERO);
    expect(draft.variants).toEqual({ hero: ["split"] });
    draft = setVariantEnabled(draft, "hero", "split", true, HERO);
    expect(draft.variants).toEqual({});
  });
});

describe("toSavePayload", () => {
  it("publishes the whole slice, but variants only for components with removals", () => {
    let draft = seedConstraintDraft(manifest());
    draft = setComponentEnabled(draft, "columns", false);
    draft = setToneEnabled(draft, "accent", false, TONES);
    draft = setVariantEnabled(draft, "divider", "line", false, ["line", "space"]);
    draft.variants["hero"] = []; // a stray empty entry must not be published
    expect(toSavePayload(draft)).toEqual({
      components: ["columns"],
      tones: ["accent"],
      variants: { divider: ["line"] },
    });
  });
});
