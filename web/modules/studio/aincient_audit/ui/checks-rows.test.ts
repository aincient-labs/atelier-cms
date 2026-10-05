import { describe, expect, it } from "vitest";
import {
  auditedLabel,
  batchInstruction,
  buildRail,
  fieldOf,
  filterRail,
  fixInstruction,
  metaFieldFromRemediation,
  needsYou,
  stepRow,
  wordDiff,
  type AuditReport,
  type Finding,
} from "./checks-rows";

const finding = (id: string, severity: Finding["severity"], extra: Partial<Finding> = {}): Finding => ({
  id,
  severity,
  title: `${id} title`,
  detail: `${id} detail`,
  location: "",
  ...extra,
});

const metaFix = (field: string, extra: Partial<NonNullable<Finding["remediation"]>> = {}) => ({
  action: "edit_field" as const,
  aiFixable: true,
  field,
  ...extra,
});

const report = (checks: AuditReport["checks"]): AuditReport => ({
  node_id: "5",
  title: "Home",
  url: "https://example.test/",
  summary: { pass: 0, warn: 0, fail: 0, total: 0 },
  checks,
});

describe("buildRail", () => {
  it("orders actionable rows worst-first, keeping report order within a severity", () => {
    const rail = buildRail(
      report([
        {
          key: "seo",
          label: "SEO",
          findings: [finding("w1", "warn"), finding("p1", "pass"), finding("f1", "fail"), finding("w2", "warn"), finding("f2", "fail")],
        },
      ]),
    );
    expect(rail.sections[0].actionable.map((r) => r.finding.id)).toEqual(["f1", "f2", "w1", "w2"]);
  });

  it("folds passes into their own list", () => {
    const rail = buildRail(report([{ key: "seo", label: "SEO", findings: [finding("p1", "pass"), finding("f1", "fail")] }]));
    expect(rail.sections[0].passes.map((r) => r.finding.id)).toEqual(["p1"]);
  });

  it("keeps sections in report order", () => {
    const rail = buildRail(
      report([
        { key: "links", label: "Links", findings: [] },
        { key: "seo", label: "SEO", findings: [] },
      ]),
    );
    expect(rail.sections.map((s) => s.key)).toEqual(["links", "seo"]);
  });

  it("marks only non-pass findings with aiFixable remediation as fixable", () => {
    const rail = buildRail(
      report([
        {
          key: "seo",
          label: "SEO",
          findings: [
            finding("fix", "fail", { remediation: metaFix("description") }),
            finding("nofix", "fail", { remediation: { action: "none", aiFixable: false } }),
            finding("bare", "warn"),
            finding("passing", "pass", { remediation: metaFix("og_title") }),
          ],
        },
      ]),
    );
    const s = rail.sections[0];
    expect(s.fixable.map((f) => f.id)).toEqual(["fix"]);
    expect(s.actionable.find((r) => r.finding.id === "fix")?.fixable).toBe(true);
    expect(s.passes[0].fixable).toBe(false);
    expect(rail.anyFixable).toBe(true);
  });

  it("reports no fixable work on a clean report", () => {
    const rail = buildRail(report([{ key: "seo", label: "SEO", findings: [finding("p1", "pass")] }]));
    expect(rail.anyFixable).toBe(false);
  });

  it("lists every finding in report order for Fix all", () => {
    const rail = buildRail(
      report([
        { key: "a", label: "A", findings: [finding("a1", "warn"), finding("a2", "fail")] },
        { key: "b", label: "B", findings: [finding("b1", "pass")] },
      ]),
    );
    expect(rail.all.map((f) => f.id)).toEqual(["a1", "a2", "b1"]);
  });

  it("gives a title finding the title editor and a meta finding the meta editor", () => {
    const rail = buildRail(
      report([
        {
          key: "seo",
          label: "SEO",
          findings: [
            finding("title", "fail", { remediation: metaFix("title") }),
            finding("desc", "fail", { remediation: metaFix("description", { label: "Meta description" }) }),
            finding("link", "fail", { remediation: { action: "edit_prop", aiFixable: true } }),
          ],
        },
      ]),
    );
    const editors = Object.fromEntries(rail.sections[0].actionable.map((r) => [r.finding.id, r.editor]));
    expect(editors.title).toEqual({ kind: "title" });
    expect(editors.desc).toMatchObject({ kind: "meta", field: { key: "description", label: "Meta description" } });
    expect(editors.link).toBeNull();
  });
});

describe("metaFieldFromRemediation", () => {
  it("builds a counted placeholder from the check's bounds", () => {
    const f = finding("d", "fail", {
      remediation: metaFix("description", { label: "Meta description", input: "textarea", constraints: { min: 70, max: 160 } }),
    });
    expect(metaFieldFromRemediation(f)).toEqual({
      key: "description",
      label: "Meta description",
      placeholder: "A 70–160 character meta description",
      multiline: true,
      counter: [70, 160],
    });
  });

  it("uses a URL placeholder for url inputs and no counter without both bounds", () => {
    const f = finding("c", "warn", { remediation: metaFix("canonical_url", { input: "url", constraints: { max: 10 } }) });
    expect(metaFieldFromRemediation(f)).toMatchObject({ placeholder: "https://…", counter: undefined, label: "canonical_url" });
  });

  it("is null for the title field and non-edit_field remediations", () => {
    expect(metaFieldFromRemediation(finding("t", "fail", { remediation: metaFix("title") }))).toBeNull();
    expect(metaFieldFromRemediation(finding("x", "fail", { remediation: { action: "edit_prop", aiFixable: true } }))).toBeNull();
    expect(metaFieldFromRemediation(finding("y", "fail"))).toBeNull();
  });
});

describe("instructions", () => {
  it("a single finding gets the one-issue instruction, with its location", () => {
    const f = finding("a", "fail", { location: "section hero" });
    expect(batchInstruction([f])).toBe(fixInstruction(f));
    expect(fixInstruction(f)).toContain("a title: a detail (section hero).");
  });

  it("several findings are bulleted in one turn", () => {
    const text = batchInstruction([finding("a", "fail"), finding("b", "warn")]);
    expect(text).toContain("• a title: a detail\n• b title: b detail");
  });
});

describe("auditedLabel", () => {
  it("names the copy the report read", () => {
    const a = { revision_id: "1", langcode: "en" };
    expect(auditedLabel({ ...a, requested: "draft", revision: "draft" })).toBe("Checked: your saved draft");
    expect(auditedLabel({ ...a, requested: "live", revision: "draft" })).toContain("isn’t live yet");
    expect(auditedLabel({ ...a, requested: "draft", revision: "live" })).toContain("no unpublished draft");
    expect(auditedLabel({ ...a, requested: "live", revision: "live" })).toBe("Checked: the Live page");
    expect(auditedLabel({ ...a, requested: "draft", revision: "unsaved" })).toBe("Checked: your draft, including unsaved changes");
  });
});

describe("review loop (0453)", () => {
  const desc = (severity: Finding["severity"]) =>
    finding("seo.description", severity, severity === "pass" ? {} : { remediation: metaFix("description", { constraints: { min: 50, max: 160 } }) });
  const link = finding("links.broken:/gone", "fail", { remediation: { action: "edit_prop", aiFixable: true, target: { href: "/gone" } } });
  const audited = (revision: "draft" | "unsaved") => ({ requested: "draft" as const, revision, revision_id: "9", langcode: "en" });
  const saved = { ...report([
    { key: "seo", label: "SEO", findings: [desc("fail"), finding("seo.title", "warn", { remediation: metaFix("title") })] },
    { key: "links", label: "Links", findings: [link] },
  ]), audited: audited("draft") };
  const unsaved = { ...report([
    { key: "seo", label: "SEO", findings: [desc("pass"), finding("seo.title", "warn", { remediation: metaFix("title") })] },
    { key: "links", label: "Links", findings: [] },
  ]), audited: audited("unsaved") };
  const draft: Record<string, string> = { "meta.description": "A staged description", title: "Home" };
  const base: Record<string, string> = { title: "Home" };
  const ctx = {
    base: saved,
    draftValue: (p: string) => draft[p] ?? "",
    baselineValue: (p: string) => base[p] ?? "",
    origin: (p: string) => (p === "meta.description" ? ("agent" as const) : undefined),
  };

  it("marks a finding that failed in the saved draft and passes now as fixed", () => {
    const rail = buildRail(unsaved, ctx);
    const seo = rail.sections[0];
    expect(seo.actionable.map((r) => [r.finding.id, r.status])).toEqual([["seo.title", "warn"], ["seo.description", "fixed"]]);
    expect(seo.passes).toEqual([]);
    expect(rail.fixedCount).toBe(2);
  });

  it("keeps a vanished finding (a fixed link) as a fixed row", () => {
    const links = buildRail(unsaved, ctx).sections[1];
    expect(links.actionable.map((r) => [r.finding.id, r.status])).toEqual([["links.broken:/gone", "fixed"]]);
  });

  it("locates a fixed row by the saved finding's remediation and carries value, base and origin", () => {
    const row = buildRail(unsaved, ctx).sections[0].actionable[1];
    expect(row).toMatchObject({
      field: "meta.description",
      value: "A staged description",
      baseValue: "",
      origin: "agent",
      bounds: [50, 160],
      fixable: false,
      editor: { kind: "meta" },
    });
  });

  it("does not compute fixed rows for a saved-draft report", () => {
    const rail = buildRail(saved, ctx);
    expect(rail.fixedCount).toBe(0);
    expect(rail.sections[0].actionable.map((r) => r.status)).toEqual(["fail", "warn"]);
  });

  it("does not count a fixed row as fixable work", () => {
    expect(buildRail(unsaved, ctx).sections[0].fixable.map((f) => f.id)).toEqual(["seo.title"]);
  });

  it("Changed lists fixed and edited rows flat, counting every filter", () => {
    const rail = buildRail(unsaved, ctx);
    const changed = filterRail(rail, "changed", new Set());
    expect(changed.sections.map((s) => [s.key, s.rows.map((r) => r.finding.id)])).toEqual([
      ["seo", ["seo.description"]],
      ["links", ["links.broken:/gone"]],
    ]);
    expect(changed.counts).toEqual({ needs: 0, changed: 2, all: 3 });
    expect(changed.order.map((r) => r.finding.id)).toEqual(["seo.description", "links.broken:/gone"]);
  });

  it("Changed includes a row that passes but was edited", () => {
    const passing = { ...report([{ key: "seo", label: "SEO", findings: [finding("seo.title", "pass", { remediation: metaFix("title") })] }]) };
    const rail = buildRail(passing, { ...ctx, draftValue: (p) => (p === "title" ? "Welcome home" : "") });
    expect(filterRail(rail, "changed", new Set()).order.map((r) => r.finding.id)).toEqual(["seo.title"]);
  });

  it("Needs you = sent findings that still fail; a fixed one drops out", () => {
    const sent = new Set(["seo.description", "seo.title", "links.broken:/gone"]);
    const needs = needsYou(unsaved, sent);
    expect([...needs]).toEqual(["seo.title"]);
    const view = filterRail(buildRail(unsaved, ctx), "needs", needs);
    expect(view.sections.map((s) => s.key)).toEqual(["seo"]);
    expect(view.order.map((r) => r.finding.id)).toEqual(["seo.title"]);
    expect(view.counts.needs).toBe(1);
    expect(view.sections[0].fixable.map((f) => f.id)).toEqual(["seo.title"]);
  });

  it("nothing sent → nobody needs you", () => {
    expect(needsYou(unsaved, new Set()).size).toBe(0);
  });

  it("All keeps the actionable / passes split", () => {
    const rail = buildRail(saved, ctx);
    const all = filterRail(rail, "all", new Set());
    expect(all.sections.map((s) => s.rows.length)).toEqual([2, 1]);
    expect(all.order.map((r) => r.finding.id)).toEqual(["seo.description", "seo.title", "links.broken:/gone"]);
  });

  it("Previous / Next wrap, and start from the ends when nothing is selected", () => {
    const order = filterRail(buildRail(saved, ctx), "all", new Set()).order;
    expect(stepRow(order, null, 1)).toBe("seo.description");
    expect(stepRow(order, null, -1)).toBe("links.broken:/gone");
    expect(stepRow(order, "seo.title", 1)).toBe("links.broken:/gone");
    expect(stepRow(order, "links.broken:/gone", 1)).toBe("seo.description");
    expect(stepRow(order, "seo.description", -1)).toBe("links.broken:/gone");
    expect(stepRow(order, "gone-from-view", 1)).toBe("seo.description");
    expect(stepRow([], null, 1)).toBeNull();
  });
});

describe("fieldOf", () => {
  it("maps title, meta and section props to page-field paths", () => {
    expect(fieldOf(finding("t", "fail", { remediation: metaFix("title") }))).toBe("title");
    expect(fieldOf(finding("d", "fail", { remediation: metaFix("og_title") }))).toBe("meta.og_title");
    expect(fieldOf(finding("p", "fail", { remediation: { action: "edit_prop", aiFixable: true, target: { section: "abc1", prop: "cta_url" } } }))).toBe(
      "sections.abc1.props.cta_url",
    );
    expect(fieldOf(finding("l", "fail", { remediation: { action: "edit_prop", aiFixable: true, target: { href: "/x" } } }))).toBeNull();
    expect(fieldOf(finding("n", "pass"))).toBeNull();
  });
});

describe("wordDiff", () => {
  it("keeps shared words and marks the swap", () => {
    expect(wordDiff("Build your site", "Build your page")).toEqual([
      { kind: "same", text: "Build your " },
      { kind: "del", text: "site" },
      { kind: "ins", text: "page" },
    ]);
  });

  it("is all insert from empty, all delete to empty", () => {
    expect(wordDiff("", "New text")).toEqual([{ kind: "ins", text: "New text" }]);
    expect(wordDiff("Old text", "")).toEqual([{ kind: "del", text: "Old text" }]);
  });
});

describe("scale (0453 S4)", () => {
  const og = (field: string, severity: Finding["severity"]) => finding(`seo.${field}`, severity, { remediation: metaFix(field) });
  const seo = (findings: Finding[]) => report([{ key: "seo", label: "SEO", findings }]);

  it("folds the Open Graph findings into one Share card row, worst status, in the first one's place", () => {
    const rail = buildRail(seo([finding("seo.description", "fail", { remediation: metaFix("description") }), og("og_title", "warn"), og("og_image", "fail")]));
    const rows = rail.sections[0].actionable;
    expect(rows.map((r) => r.finding.id)).toEqual(["seo.description", "group:seo:share"]);
    const group = rows[1];
    expect(group).toMatchObject({ status: "fail", fixable: true, field: null, editor: null });
    expect(group.finding.title).toBe("Share card");
    expect(group.members?.map((m) => m.finding.id)).toEqual(["seo.og_image", "seo.og_title"]);
    expect(rail.sections[0].fixable.map((f) => f.id)).toEqual(["seo.description", "seo.og_image", "seo.og_title"]);
  });

  it("leaves a lone Open Graph finding as its own row", () => {
    const rows = buildRail(seo([og("og_title", "warn"), og("og_image", "pass")])).sections[0].actionable;
    expect(rows.map((r) => r.finding.id)).toEqual(["seo.og_title"]);
  });

  it("a group is fixed only when every member is, and changed / needs-you when any member is", () => {
    const saved = seo([og("og_title", "warn"), og("og_description", "warn")]);
    // A pass carries no remediation (the server's shape): the fixed row must
    // still group by the saved finding's field.
    const unsaved = { ...seo([finding("seo.og_title", "pass"), og("og_description", "warn")]), audited: { requested: "draft" as const, revision: "unsaved" as const, revision_id: "1", langcode: "en" } };
    const rail = buildRail(unsaved, { base: saved, draftValue: (p) => (p === "meta.og_title" ? "New" : ""), baselineValue: () => "" });
    const group = rail.sections[0].actionable[0];
    expect(group.status).toBe("warn");
    expect(group.members?.map((m) => m.status)).toEqual(["warn", "fixed"]);
    const needs = needsYou(unsaved, new Set(["seo.og_description"]));
    const view = filterRail(rail, "needs", needs);
    expect(view.order.map((r) => r.finding.id)).toEqual(["group:seo:share"]);
    expect(filterRail(rail, "changed", needs).counts).toEqual({ needs: 1, changed: 1, all: 1 });
    expect(view.sections[0].fixable.map((f) => f.id)).toEqual(["seo.og_description"]);
  });

  it("tells the agent every place a broken link is written, to fix in one edit", () => {
    const f = finding("links.broken:/gone", "fail", {
      remediation: {
        action: "edit_prop",
        aiFixable: true,
        target: {
          href: "/gone",
          occurrences: 2,
          locations: [
            { section: "hero", prop: "cta_url", href: "/gone" },
            { section: "faq", prop: "items.1.body", href: "/gone" },
          ],
        },
      },
    });
    const text = fixInstruction(f);
    expect(text).toContain('section "hero" prop "cta_url"; section "faq" prop "items.1.body"');
    expect(text).toContain("in 2 places");
    expect(text).toContain("in one edit");
    expect(batchInstruction([f, finding("x", "fail")])).toContain("in 2 places");
  });
});
