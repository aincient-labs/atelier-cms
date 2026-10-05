import { describe, expect, it } from "vitest";
import { changedPaths, fieldChanges, fieldValue, flattenFields, withFieldValue } from "./page-fields";
import type { PageSchema } from "./page-state";

const page = (over: Partial<PageSchema> = {}): PageSchema => ({
  type: "landing",
  title: "Home",
  sections: [
    { id: "hero1", component: "hero", props: { heading: "Hi", image: "media:1" } },
    { id: "cta22", component: "cta", props: { cta_url: "/about" } },
  ],
  ...over,
});

describe("flattenFields", () => {
  it("addresses title, meta, teaser, sections by id, and top-level keys", () => {
    const flat = flattenFields(page({ meta: { description: "D" }, teaser: { title: "T" }, lead: "L" }));
    expect(flat.get("title")).toBe("Home");
    expect(flat.get("type")).toBe("landing");
    expect(flat.get("lead")).toBe("L");
    expect(flat.get("meta.description")).toBe("D");
    expect(flat.get("teaser.title")).toBe("T");
    expect(flat.get("sections")).toBe("hero1,cta22");
    expect(flat.get("sections.hero1")).toBe("hero");
    expect(flat.get("sections.cta22.props.cta_url")).toBe("/about");
  });

  it("falls back to position for an id-less section", () => {
    const flat = flattenFields(page({ sections: [{ component: "hero", props: { heading: "x" } }] }));
    expect(flat.get("sections.#0.props.heading")).toBe("x");
  });

  it("is empty for no schema", () => {
    expect(flattenFields(null).size).toBe(0);
  });
});

describe("changedPaths", () => {
  it("names exactly the fields that changed", () => {
    const before = page();
    const after = page({
      title: "Home!",
      meta: { og_title: "OG" },
      sections: [
        { id: "hero1", component: "hero", props: { heading: "Hello", image: "media:1" } },
        { id: "cta22", component: "cta", props: { cta_url: "/about" } },
      ],
    });
    expect(changedPaths(before, after).sort()).toEqual(["meta.og_title", "sections.hero1.props.heading", "title"]);
  });

  it("treats an absent key and an empty value as the same", () => {
    expect(changedPaths(page({ meta: {} }), page({ meta: { description: "" } }))).toEqual([]);
  });

  it("reports a reorder and an added section", () => {
    const before = page();
    const after = page({ sections: [...[...before.sections!].reverse(), { id: "new01", component: "text", props: {} }] });
    expect(changedPaths(before, after).sort()).toEqual(["sections", "sections.new01"]);
  });
});

describe("withFieldValue", () => {
  it("sets and clears meta / teaser keys", () => {
    const set = withFieldValue(page(), "meta.description", "New");
    expect(set.meta).toEqual({ description: "New" });
    expect(withFieldValue(set, "meta.description", "").meta).toEqual({});
  });

  it("sets a section prop by id without touching the others", () => {
    const next = withFieldValue(page(), "sections.cta22.props.cta_url", "/contact");
    expect(fieldValue(next, "sections.cta22.props.cta_url")).toBe("/contact");
    expect(next.sections![0]).toEqual(page().sections![0]);
    expect(changedPaths(page(), next)).toEqual(["sections.cta22.props.cta_url"]);
  });

  it("sets the title and top-level keys, and round-trips a revert", () => {
    const base = page();
    const edited = withFieldValue(withFieldValue(base, "title", "Other"), "lead", "Lead");
    const reverted = withFieldValue(withFieldValue(edited, "title", base.title), "lead", "");
    expect(changedPaths(base, reverted)).toEqual([]);
  });

  it("refuses a non-leaf path", () => {
    expect(() => withFieldValue(page(), "sections", "x")).toThrow();
    expect(() => withFieldValue(page(), "sections.hero1", "x")).toThrow();
  });
});

describe("fieldChanges", () => {
  it("names each changed field in the summariser's words", () => {
    const before = page();
    const after = page({
      title: "Welcome",
      meta: { description: "D", og_title: "OG" },
      sections: [
        { id: "hero1", component: "hero", props: { heading: "Hello", image: "media:1" } },
        { id: "cta22", component: "cta", props: { cta_url: "/about" } },
      ],
    });
    expect(fieldChanges(before, after)).toEqual([
      { path: "title", label: "Page title" },
      { path: "meta.description", label: "Meta description" },
      { path: "meta.og_title", label: "Open Graph title" },
      { path: "sections.hero1.props.heading", label: "Hero section · Heading" },
    ]);
  });

  it("counts an added section once, not once per prop, and drops the implied order change", () => {
    const before = page();
    const after = page({
      sections: [
        ...(page().sections ?? []),
        { id: "faq9", component: "faq-list", props: { heading: "Q", items: [] } },
      ],
    });
    expect(fieldChanges(before, after)).toEqual([{ path: "sections.faq9", label: "Added Faq list section" }]);
  });

  it("names a removed section and a pure reorder", () => {
    const [hero, cta] = page().sections ?? [];
    expect(fieldChanges(page(), page({ sections: [hero] }))).toEqual([
      { path: "sections.cta22", label: "Removed Cta section" },
    ]);
    expect(fieldChanges(page(), page({ sections: [cta, hero] }))).toEqual([{ path: "sections", label: "Section order" }]);
  });

  it("is empty when nothing changed", () => {
    expect(fieldChanges(page(), page())).toEqual([]);
  });
});
