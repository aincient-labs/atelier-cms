// @vitest-environment jsdom
// jsdom: the module reads PAGE_LENS + changedPaths from the sdk at runtime,
// and the sdk loads the console graph, which touches `window` on import.
import { describe, expect, it } from "vitest";
import type { PageSchema } from "@console/sdk";
import { buildRail, type AuditReport, type Finding } from "./checks-rows";
import {
  SEARCH_LENS,
  SHARE_LENS,
  changedSections,
  lensForField,
  lensForRow,
  pinsFor,
  rowForSection,
  rowSections,
  rowsForLens,
} from "./checks-lenses";

const finding = (id: string, severity: Finding["severity"], remediation: Finding["remediation"] = null): Finding => ({
  id,
  severity,
  title: `${id} title`,
  detail: "",
  location: "",
  remediation,
});

const meta = (field: string): Finding["remediation"] => ({ action: "edit_field", aiFixable: true, field });
/** A link finding, written at `sections` (the server's `target.locations`). */
const link = (href: string, sections: string[] = []): Finding["remediation"] => ({
  action: "edit_prop",
  aiFixable: true,
  target: { href, locations: sections.map((section) => ({ section, prop: "url", href })), occurrences: sections.length },
});

const report = (findings: Finding[], revision: "draft" | "unsaved" = "draft"): AuditReport => ({
  node_id: "9",
  title: "Home",
  url: "https://example.test/",
  audited: { requested: "draft", revision, revision_id: "1", langcode: "en" },
  summary: { pass: 0, warn: 0, fail: 0, total: 0 },
  checks: [{ key: "all", label: "All", findings }],
});

const page = (sections: PageSchema["sections"], extra: Partial<PageSchema> = {}): PageSchema =>
  ({ type: "landing", title: "Home", sections, ...extra }) as PageSchema;

const hero = (props: Record<string, unknown>) => ({ id: "hero", component: "hero", props });
const cta = (props: Record<string, unknown>) => ({ id: "cta", component: "cta", props });

describe("lensForField", () => {
  it("puts title, description and canonical in the search result", () => {
    expect(lensForField("title")).toBe(SEARCH_LENS);
    expect(lensForField("meta.description")).toBe(SEARCH_LENS);
    expect(lensForField("meta.canonical_url")).toBe(SEARCH_LENS);
  });
  it("puts the Open Graph trio on the share card", () => {
    for (const f of ["meta.og_title", "meta.og_description", "meta.og_image"]) expect(lensForField(f)).toBe(SHARE_LENS);
  });
  it("shows section props on the page, and nothing for the rest", () => {
    expect(lensForField("sections.hero.props.title")).toBe("page");
    expect(lensForField("teaser.title")).toBeNull();
    expect(lensForField(null)).toBeNull();
  });
  it("shows a link finding on the page", () => {
    const rail = buildRail(report([finding("links.broken:/x", "fail", link("/x"))]));
    expect(lensForRow(rail.sections[0].actionable[0])).toBe("page");
  });
});

describe("rowSections", () => {
  it("reads a link finding's sections from the server's locations, once each", () => {
    const rail = buildRail(report([finding("links.broken:/gone", "fail", link("/gone", ["hero", "cta", "hero"]))]));
    expect(rowSections(rail.sections[0].actionable[0])).toEqual(["hero", "cta"]);
  });
  it("is empty for a link with no locations", () => {
    const rail = buildRail(report([finding("links.broken:/x", "fail", link("/x"))]));
    expect(rowSections(rail.sections[0].actionable[0])).toEqual([]);
  });
});

describe("changedSections", () => {
  it("lists sections whose props changed, never a removed one", () => {
    const base = page([hero({ title: "A" }), cta({ label: "Go" })]);
    const draft = page([hero({ title: "B" })]);
    expect(changedSections(draft, base)).toEqual(["hero"]);
  });
  it("is empty without a baseline", () => {
    expect(changedSections(page([hero({})]), null)).toEqual([]);
  });
});

describe("pinsFor", () => {
  it("pins each section once, worst tone, counted label, in page order", () => {
    const base = page([hero({ title: "A", cta_url: "/x" }), cta({ url: "/y" })]);
    const draft = page([hero({ title: "B", cta_url: "/x" }), cta({ url: "/y" })]);
    const rail = buildRail(
      report([
        finding("links.broken:/y", "warn", link("/y", ["cta"])),
        finding("links.broken:/x", "fail", link("/x", ["hero"])),
        finding("seo.description", "fail", meta("description")),
      ]),
    );
    expect(pinsFor(rail, draft, base)).toEqual([
      { section: "hero", tone: "fail", label: "1 fail · changed" },
      { section: "cta", tone: "warn", label: "1 warn" },
    ]);
  });

  it("pins a changed section with no finding as changed", () => {
    const rail = buildRail(report([]));
    expect(pinsFor(rail, page([hero({ title: "B" })]), page([hero({ title: "A" })]))).toEqual([
      { section: "hero", tone: "changed", label: "changed" },
    ]);
  });

  it("pins a link the unsaved draft fixed as fixed", () => {
    const base = page([hero({ cta_url: "/gone" })]);
    const draft = page([hero({ cta_url: "/about" })]);
    const saved = report([finding("links.broken:/gone", "fail", link("/gone", ["hero"]))]);
    const rail = buildRail(report([], "unsaved"), { base: saved });
    expect(pinsFor(rail, draft, base)).toEqual([{ section: "hero", tone: "fixed", label: "1 fixed · changed" }]);
  });

  it("drops a pin for a section the draft removed", () => {
    const rail = buildRail(report([finding("links.broken:/x", "fail", link("/x", ["gone", "hero"]))]));
    const schema = page([hero({})]);
    expect(pinsFor(rail, schema, schema)).toEqual([{ section: "hero", tone: "fail", label: "1 fail" }]);
  });
});

describe("rowsForLens / rowForSection", () => {
  it("lists a lens's actionable rows", () => {
    const rail = buildRail(
      report([finding("seo.og_title", "fail", meta("og_title")), finding("seo.description", "warn", meta("description")), finding("seo.title", "pass", meta("title"))]),
    );
    expect(rowsForLens(rail, SHARE_LENS).map((r) => r.finding.id)).toEqual(["seo.og_title"]);
    expect(rowsForLens(rail, SEARCH_LENS).map((r) => r.finding.id)).toEqual(["seo.description"]);
  });

  it("lists a grouped Share card's members one by one", () => {
    const rail = buildRail(report([finding("seo.og_title", "warn", meta("og_title")), finding("seo.og_image", "warn", meta("og_image"))]));
    expect(rail.sections[0].actionable).toHaveLength(1);
    expect(lensForRow(rail.sections[0].actionable[0])).toBe(SHARE_LENS);
    expect(rowsForLens(rail, SHARE_LENS).map((r) => r.finding.id)).toEqual(["seo.og_title", "seo.og_image"]);
  });

  it("opens the worst row in a section", () => {
    const rail = buildRail(report([finding("links.broken:/w", "warn", link("/w", ["hero"])), finding("links.broken:/f", "fail", link("/f", ["hero"]))]));
    expect(rowForSection(rail, "hero")?.finding.id).toBe("links.broken:/f");
    expect(rowForSection(rail, "nope")).toBeNull();
  });
});
