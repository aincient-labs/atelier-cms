import { afterEach, describe, expect, it } from "vitest";
import { draftHasDiff, primePreviewFromDraft } from "./brand-preview";
import { brandPreviewCardText } from "./brand-preview-card";
import {
  getBrandOverrides,
  getPendingFonts,
  resetBrandOverrides,
  setBrandOverride,
  setPendingFonts,
} from "./brand-state";

/**
 * Issue #11a: the first brand turn of a session said "Applied to preview" and the
 * preview kept rendering the saved brand.
 *
 * The order is: the tool card stages tokens in the shared store, THEN calls
 * ensureStudio("design_system") — which is what mounts the preview. So the mount
 * runs with a draft already staged and no emit coming: the mount path has to adopt
 * it, not merely count it. That's what {@link primePreviewFromDraft} pins here.
 *
 * The test env is DOM-less (vitest `environment: "node"`), so neither the iframe
 * nor the card is rendered: `apply`/`syncFonts` are recorded as calls, and the
 * card is asserted at its props→text seam. What the frame does with the custom
 * properties, and the styling of a de-emphasised card, stay browser checks.
 */

afterEach(() => resetBrandOverrides());

/** A recorder standing in for the preview's iframe-writing callbacks. */
function spyDeps() {
  const applied: Record<string, string>[] = [];
  const flags: boolean[] = [];
  let fontSyncs = 0;
  return {
    applied,
    flags,
    fonts: () => fontSyncs,
    deps: {
      getOverrides: getBrandOverrides,
      getFonts: getPendingFonts,
      apply: (ov: Record<string, string>) => void applied.push(ov),
      syncFonts: () => void fontSyncs++,
      setHasDiff: (v: boolean) => void flags.push(v),
    },
  };
}

describe("primePreviewFromDraft", () => {
  it("applies a draft staged before the preview mounted", () => {
    setBrandOverride("brand-primary", "oklch(0.6 0.2 260)");
    setBrandOverride("font-display", '"Inter", sans-serif');

    const spy = spyDeps();
    primePreviewFromDraft(spy.deps);

    // The regression: the flag was set but nothing was ever painted.
    expect(spy.applied).toEqual([
      { "brand-primary": "oklch(0.6 0.2 260)", "font-display": '"Inter", sans-serif' },
    ]);
    expect(spy.fonts()).toBe(1);
    expect(spy.flags).toEqual([true]);
  });

  it("applies fonts-only drafts too (a preset stages families, not tokens)", () => {
    setPendingFonts(["Inter"]);

    const spy = spyDeps();
    primePreviewFromDraft(spy.deps);

    expect(spy.applied).toEqual([{}]);
    expect(spy.fonts()).toBe(1);
    expect(spy.flags).toEqual([true]);
  });

  it("is a harmless no-diff apply when nothing is staged", () => {
    const spy = spyDeps();
    primePreviewFromDraft(spy.deps);

    // Still applies: an empty override map is how a cleared draft is expressed,
    // and apply() removes the props it previously wrote.
    expect(spy.applied).toEqual([{}]);
    expect(spy.flags).toEqual([false]);
  });
});

describe("draftHasDiff", () => {
  it("counts tokens and pending fonts, each on its own", () => {
    expect(draftHasDiff({}, null)).toBe(false);
    expect(draftHasDiff({ "brand-primary": "red" }, null)).toBe(true);
    expect(draftHasDiff({}, ["Inter"])).toBe(true);
  });
});

describe("brandPreviewCardText", () => {
  it("marks a replayed card as no longer active", () => {
    const { historical, label, note } = brandPreviewCardText({
      tokens: { "brand-primary": "red" },
      __historical: true,
    });
    expect(historical).toBe(true);
    expect(label).toBe("Applied to preview · 1 change");
    expect(note).toBe("staged earlier — no longer active");
  });

  it("keeps the live card's Publish hint", () => {
    const { historical, label, note } = brandPreviewCardText({
      tokens: { "brand-primary": "red", "brand-accent": "blue" },
      fonts: ["Inter"],
    });
    expect(historical).toBe(false);
    expect(label).toBe("Applied to preview · 3 changes");
    expect(note).toBe("Preview only — Publish in the studio to apply it site-wide");
  });

  it("labels a reset without a change count", () => {
    expect(brandPreviewCardText({ reset: true }).label).toBe(
      "Reverted the preview to the saved brand",
    );
  });
});
