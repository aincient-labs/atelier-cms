import { describe, expect, it } from "vitest";
import { retiredReason, type RetiredSlotManifest } from "./retired-slot";

const manifest: RetiredSlotManifest = {
  sections: [
    { component: "hero", props: [{ name: "title" }, { name: "variant", enum: ["centered"] }, { name: "tone" }] },
    { component: "cta", props: [{ name: "tone", enum: ["default", "muted"] }] },
  ],
  retired: [{ component: "stats", props: [{ name: "items" }] }],
  tones: ["default", "muted", "inverted"],
  variants: { hero: ["centered"] },
  discovered_variants: { hero: ["centered", "split"], gallery: ["grid", "masonry"] },
};

describe("retiredReason", () => {
  it("is null for a fully offered slot", () => {
    expect(retiredReason({ component: "hero", props: { title: "Hi" } }, manifest)).toBeNull();
    expect(retiredReason({ component: "hero", props: { variant: "centered", tone: "inverted" } }, manifest)).toBeNull();
    expect(retiredReason({ component: "cta", props: { tone: "muted" } }, manifest)).toBeNull();
  });

  it("treats empty variant/tone as unset", () => {
    expect(retiredReason({ component: "hero", props: { variant: "", tone: "" } }, manifest)).toBeNull();
  });

  it("is null without a manifest", () => {
    expect(retiredReason({ component: "stats", props: {} }, null)).toBeNull();
  });

  it("flags a component the kind no longer offers", () => {
    expect(retiredReason({ component: "stats", props: {} }, manifest)).toBe("No longer offered");
  });

  it("flags a stored variant outside the narrowed enum", () => {
    expect(retiredReason({ component: "hero", props: { variant: "split" } }, manifest)).toBe(
      "Variant “split” no longer offered",
    );
  });

  it("flags a stored variant when the narrowed map offers the component none", () => {
    expect(retiredReason({ component: "gallery", props: { variant: "grid" } }, manifest)).toBe(
      "Variant “grid” no longer offered",
    );
  });

  it("flags a stored tone outside the def's tone enum", () => {
    expect(retiredReason({ component: "cta", props: { tone: "inverted" } }, manifest)).toBe(
      "Tone “inverted” no longer offered",
    );
  });

  it("falls back to the site-wide tone list when the def has no tone enum", () => {
    expect(retiredReason({ component: "hero", props: { tone: "loud" } }, manifest)).toBe(
      "Tone “loud” no longer offered",
    );
  });
});
