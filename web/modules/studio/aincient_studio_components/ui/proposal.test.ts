import { describe, expect, it } from "vitest";
import type { ComponentEntry, ConstraintDraft, KindDraft } from "./constraint-model";
import { applyKindProposal, applySiteProposal, proposalSize } from "./proposal";

/**
 * The agent's proposal merges onto what the owner has staged (DECISIONS 0455,
 * P2): explicit ops through the studio's own guarded edits, never a whole
 * constraint, and a refused op is reported, not forced.
 */

const TONES = ["default", "muted", "brand", "inverted"];

function entry(name: string, variants: string[] = [], tones: string[] = TONES): ComponentEntry {
  return {
    name, tier: "section", icon: "", use: "", provider: "aincient_pages", group: "other",
    variants, tones, examples: [], usage: { pages: 0, blocks: 0, variants: {}, tones: {} },
  };
}

const ENTRIES = [entry("hero", ["centered", "split"]), entry("pricing"), entry("stats"), entry("faq")];
const SITE: ConstraintDraft = { components: ["faq"], tones: [], variants: {}, component_tones: {}, overrides_off: [] };
const KIND: KindDraft = { include_new: true, components: {}, removed: [], opener: "", limits: {} };

describe("applySiteProposal", () => {
  it("merges on/off onto the staged draft without dropping the owner's edits", () => {
    const { draft, skipped } = applySiteProposal(SITE, { scope: "site", turn_off: ["pricing"], turn_on: [] }, ENTRIES, TONES);
    expect(draft.components).toEqual(["faq", "pricing"]);
    expect(skipped).toEqual([]);
    expect(SITE.components).toEqual(["faq"]);
  });

  it("turns a component back on", () => {
    expect(applySiteProposal(SITE, { scope: "site", turn_on: ["faq"] }, ENTRIES, TONES).draft.components).toEqual([]);
  });

  it("narrows variants and tones; the last-variant guard refuses and reports", () => {
    const one = applySiteProposal(SITE, { scope: "site", variants_off: { hero: ["split"] }, tones_off: { stats: ["inverted"] } }, ENTRIES, TONES);
    expect(one.draft.variants).toEqual({ hero: ["split"] });
    expect(one.draft.component_tones).toEqual({ stats: ["inverted"] });
    const both = applySiteProposal(one.draft, { scope: "site", variants_off: { hero: ["centered"] } }, ENTRIES, TONES);
    expect(both.draft.variants).toEqual({ hero: ["split"] });
    expect(both.skipped).toEqual(["hero variant centered"]);
  });

  it("\"*\" is the site-wide tone list", () => {
    expect(applySiteProposal(SITE, { scope: "site", tones_off: { "*": ["inverted"] } }, ENTRIES, TONES).draft.tones).toEqual(["inverted"]);
  });
});

describe("applyKindProposal", () => {
  it("automatic: off goes to the deny list", () => {
    const { draft } = applyKindProposal(KIND, { scope: "landing", turn_off: ["pricing"] }, ENTRIES, SITE);
    expect(draft.removed).toEqual(["pricing"]);
  });

  it("switches the regime first, then narrows within the allow-list", () => {
    const { draft, skipped } = applyKindProposal(
      KIND,
      { scope: "landing", include_new: false, turn_off: ["stats"], variants_off: { hero: ["split"] } },
      ENTRIES,
      SITE,
    );
    expect(draft.include_new).toBe(false);
    // faq is off site-wide, a separate layer: the kind's own allow-list still lists it.
    expect(Object.keys(draft.components).sort()).toEqual(["faq", "hero", "pricing"]);
    expect(draft.components.hero).toEqual({ variants: ["centered"] });
    expect(skipped).toEqual([]);
  });

  it("narrowing in automatic mode is refused and reported", () => {
    expect(applyKindProposal(KIND, { scope: "landing", tones_off: { hero: ["brand"] } }, ENTRIES, SITE).skipped).toEqual(["hero tone brand"]);
  });

  it("sets the opener and limits; 0 clears a limit", () => {
    const set = applyKindProposal(KIND, { scope: "landing", opener: "hero", limits: { faq: 1 } }, ENTRIES, SITE).draft;
    expect(set.opener).toBe("hero");
    expect(set.limits).toEqual({ faq: 1 });
    expect(applyKindProposal(set, { scope: "landing", limits: { faq: 0 } }, ENTRIES, SITE).draft.limits).toEqual({});
  });
});

it("proposalSize counts every operation", () => {
  expect(proposalSize({ scope: "landing", turn_off: ["a", "b"], tones_off: { hero: ["x", "y"] }, opener: "", limits: { faq: 2 } })).toBe(6);
});
