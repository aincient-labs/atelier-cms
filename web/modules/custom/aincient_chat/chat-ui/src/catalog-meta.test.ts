import { describe, expect, it } from "vitest";
import {
  groupByProvider,
  humanizeProvider,
  isBuiltInProvider,
  kindRegime,
  orderedKinds,
  sectionIcon,
} from "./catalog-meta";

/**
 * Pins the W6 catalog-metadata rules: icon resolution (server glyph → local
 * map → neutral mark), provenance grouping (built-ins untouched and first,
 * add-ons headed + humanized), and the kind picker's ordering + never-empty
 * fallback contract.
 */

describe("sectionIcon", () => {
  it("prefers a non-empty server icon over the local map", () => {
    expect(sectionIcon("hero", "≡")).toBe("≡");
  });

  it("falls back to the local map when the server icon is empty or missing", () => {
    expect(sectionIcon("hero", "")).toBe("◆");
    expect(sectionIcon("hero")).toBe("◆");
    expect(sectionIcon("hero", "  ")).toBe("◆");
  });

  it("falls back to the neutral block mark for an unmapped component", () => {
    expect(sectionIcon("mystery_widget")).toBe("▢");
    expect(sectionIcon("mystery_widget", "")).toBe("▢");
  });
});

describe("provider provenance", () => {
  it("treats aincient_pages and empty/missing providers as built-in", () => {
    expect(isBuiltInProvider("aincient_pages")).toBe(true);
    expect(isBuiltInProvider("")).toBe(true);
    expect(isBuiltInProvider(undefined)).toBe(true);
    expect(isBuiltInProvider("atelier_test_pack")).toBe(false);
  });

  it("humanizes a module name for the group header", () => {
    expect(humanizeProvider("atelier_test_pack")).toBe("Atelier test pack");
    expect(humanizeProvider("acme")).toBe("Acme");
  });

  it("groups built-ins first with no label, add-ons after under humanized headers", () => {
    const groups = groupByProvider([
      { component: "hero", provider: "aincient_pages" },
      { component: "widget", provider: "atelier_test_pack" },
      { component: "legacy", provider: "" },
      { component: "gizmo", provider: "atelier_test_pack" },
      { component: "other", provider: "acme_kit" },
    ]);
    expect(groups.map((g) => g.label)).toEqual([null, "Atelier test pack", "Acme kit"]);
    expect(groups[0].entries.map((e) => e.component)).toEqual(["hero", "legacy"]);
    expect(groups[1].entries.map((e) => e.component)).toEqual(["widget", "gizmo"]);
  });

  it("emits no empty built-in group when every entry is an add-on", () => {
    const groups = groupByProvider([{ component: "widget", provider: "acme_kit" }]);
    expect(groups).toHaveLength(1);
    expect(groups[0].label).toBe("Acme kit");
  });
});

describe("orderedKinds", () => {
  it("orders landing first, blog second, others alphabetically", () => {
    const kinds = orderedKinds({
      zeta: { label: "Zeta", hint: "" },
      blog: { label: "Blog post", hint: "An article." },
      alpha: { label: "Alpha", hint: "" },
      landing: { label: "Landing page", hint: "A composed page." },
    });
    expect(kinds.map((k) => k.id)).toEqual(["landing", "blog", "alpha", "zeta"]);
    expect(kinds[0]).toMatchObject({ label: "Landing page", hint: "A composed page." });
  });

  it("yields [] for a missing or empty map (the caller keeps its fallback)", () => {
    expect(orderedKinds(undefined)).toEqual([]);
    expect(orderedKinds(null)).toEqual([]);
    expect(orderedKinds({})).toEqual([]);
  });

  it("degrades a sparse wire entry to id / empty hint and carries mode through", () => {
    const [k] = orderedKinds({ docs: { mode: "composition" } });
    expect(k).toEqual({ id: "docs", label: "docs", hint: "", mode: "composition" });
  });
});

describe("kindRegime", () => {
  it("keys off mode when the server sent one", () => {
    expect(kindRegime({ id: "docs", mode: "composition" })).toBe("composition");
    expect(kindRegime({ id: "docs", mode: "recipe" })).toBe("recipe");
  });

  it("falls back to the shipped id checks without a mode", () => {
    expect(kindRegime({ id: "blog" })).toBe("recipe");
    expect(kindRegime({ id: "landing" })).toBe("composition");
    expect(kindRegime({ id: "unknown" })).toBe("composition");
  });
});
