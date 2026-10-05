import type { DraftSource, PageMeta } from "@console/sdk";

/**
 * The Checks rail's row model — pure: an audit report in, the rows the rail
 * renders out. No React, no stores, no fetch, so the ordering, folding and
 * fixability rules are unit-testable (`checks-rows.test.ts`) and the studio
 * component ({@link ../checks-studio.tsx}) only renders.
 *
 * The review loop (DECISIONS 0453) joins here: with a {@link RailContext} each
 * row also carries its field's draft and saved values, who changed it, and —
 * when the report graded the unsaved draft — whether the staged change fixed it.
 * The context hands in accessors rather than the stores, so this stays pure.
 */

export type Severity = "pass" | "warn" | "fail";

/** The axis a finding touches (Phase 2, DECISIONS 0133). */
export type Dimension = "meta" | "content" | "structure";

/**
 * The declarative remediation descriptor a finding carries (Phase 2) — the
 * SINGLE source both this UI and the repair agent read; it is authored in the
 * PHP check. `edit_field` names a meta field to edit inline (or via the agent);
 * `edit_prop` is a content fix the agent applies (no inline editor in v1);
 * `aiFixable` gates the "Fix with AI" affordance. Replaces the old hardcoded
 * finding→field maps that lived in both the studio and the agent prompt.
 */
export type Remediation = {
  action: "edit_field" | "edit_prop" | "none";
  aiFixable: boolean;
  field?: string;
  input?: "text" | "textarea" | "url";
  label?: string;
  constraints?: { min?: number; max?: number };
  target?: {
    href?: string;
    section?: string;
    prop?: string;
    /** A link finding: every place the schema writes the href (0453 S4). */
    locations?: LinkLocation[];
    /** A link finding: how many links on the rendered page go there. */
    occurrences?: number;
  };
};

/** One place a page's schema writes a link: a section and a dotted prop path. */
export type LinkLocation = { section: string; prop: string; href: string };

export type Finding = {
  id: string;
  severity: Severity;
  title: string;
  detail: string;
  location: string;
  dimension?: Dimension;
  remediation?: Remediation | null;
};

export type Check = { key: string; label: string; findings: Finding[] };

/** Which copy of the page the report read (DECISIONS 0450). `revision` is the
 *  copy actually checked; it differs from `requested` when that copy doesn't
 *  exist (no pending draft → the live page; never published → the draft). */
export type Audited = {
  requested: "live" | "draft";
  /** `unsaved` = the studio's draft including unsaved changes (POST, 0453). */
  revision: "live" | "draft" | "unsaved";
  revision_id: string;
  langcode: string;
};

export type AuditReport = {
  node_id: string;
  title: string;
  url: string;
  audited?: Audited;
  summary: { pass: number; warn: number; fail: number; total: number };
  checks: Check[];
};

/** The one-line "what did this check read" note above the counts. */
export const auditedLabel = (a: Audited): string =>
  a.revision === "unsaved"
    ? "Checked: your draft, including unsaved changes"
    : a.revision === "draft"
    ? a.requested === "draft"
      ? "Checked: your saved draft"
      : "Checked: your saved draft — this page isn’t live yet"
    : a.requested === "draft"
      ? "Checked: the Live page — no unpublished draft"
      : "Checked: the Live page";

/**
 * Whether a finding has an AI write path — the finding's OWN remediation says so
 * (`remediation.aiFixable`, authored in the PHP check, DECISIONS 0133). The
 * repair agent stages the fix; the human Publishes.
 */
export function isAiFixable(f: Finding): boolean {
  return f.severity !== "pass" && f.remediation?.aiFixable === true;
}

/** A finding whose manual fix is the inline page-title field (binds draft.title)
 *  — an `edit_field` remediation targeting the special `title` field. */
export const isTitleFinding = (f: Finding): boolean =>
  f.remediation?.action === "edit_field" && f.remediation.field === "title";

/** An inline manual SEO field — the descriptor for a meta finding's own editor.
 *  `key` is the draft.meta override to write (a Metatag plugin id); `counter`,
 *  when set, shows a live [min, max] character gauge. */
export type MetaFieldDef = {
  key: keyof PageMeta;
  label: string;
  placeholder: string;
  multiline?: boolean;
  counter?: [number, number];
};

/**
 * Derive the inline meta-editor descriptor from a finding's remediation — an
 * `edit_field` action on a meta field OTHER than the page title (which has its
 * own editor). Returns null when there's no inline meta editor. Key, label,
 * input type and character bounds come from the remediation the check authored.
 */
export function metaFieldFromRemediation(f: Finding): MetaFieldDef | null {
  const rem = f.remediation;
  if (!rem || rem.action !== "edit_field" || !rem.field || rem.field === "title") return null;
  const { min, max } = rem.constraints ?? {};
  const counter = min != null && max != null ? ([min, max] as [number, number]) : undefined;
  const label = rem.label ?? rem.field;
  const placeholder = counter
    ? `A ${min}–${max} character ${label.toLowerCase()}`
    : rem.input === "url"
      ? "https://…"
      : label;
  return { key: rem.field as keyof PageMeta, label, placeholder, multiline: rem.input === "textarea", counter };
}

/**
 * Where a link finding's href is written, for the repair agent — so one edit
 * fixes every copy (0453 S4). Empty when the finding names no locations.
 */
function whereLinked(f: Finding): string {
  const t = f.remediation?.target;
  const locs = t?.locations ?? [];
  if (!t?.href || locs.length === 0) return "";
  const places = locs.map((l) => `section "${l.section}" prop "${l.prop}"`).join("; ");
  return locs.length === 1
    ? ` It is written at ${places}.`
    : ` It is written in ${locs.length} places — ${places}. Point every one of them at the same working target, in one edit.`;
}

/** One finding → a minimal-diff instruction for the repair agent. */
export function fixInstruction(f: Finding): string {
  return (
    `On the page currently open in Checks, fix this issue and change nothing else — ` +
    `${f.title}: ${f.detail}${f.location ? ` (${f.location})` : ""}.${whereLinked(f)} ` +
    `Make the smallest edit that clears it.`
  );
}

/** Several findings → one batched, still-minimal instruction. */
export function batchInstruction(findings: Finding[]): string {
  if (findings.length === 1) return fixInstruction(findings[0]);
  const items = findings.map((f) => `• ${f.title}: ${f.detail}${whereLinked(f)}`).join("\n");
  return (
    `On the page currently open in Checks, fix these issues with the smallest edits ` +
    `that clear them, and change nothing else:\n${items}`
  );
}

export const SEVERITY_LABEL: Record<Severity, string> = { fail: "Fail", warn: "Warn", pass: "Pass" };
// Worst-first: a reader scanning the rail should hit what needs action before what passed.
const SEVERITY_RANK: Record<Severity, number> = { fail: 0, warn: 1, pass: 2 };

/**
 * The draft side of the rail: the saved-draft report the unsaved one is
 * compared with, and accessors over the shared page-state store (field paths
 * as `page-fields.ts` names them; values encoded, "" = not set).
 */
export type RailContext = {
  /** The report on the SAVED draft — the "before" for Fixed in draft. */
  base?: AuditReport | null;
  /** A field's value in the working draft. */
  draftValue?: (path: string) => string;
  /** A field's value in the saved baseline. */
  baselineValue?: (path: string) => string;
  /** Who changed a field since the baseline. */
  origin?: (path: string) => DraftSource | undefined;
};

/** A row's state: its severity, or `fixed` — failing in the saved draft, gone
 *  or passing once the unsaved changes are graded. */
export type RowStatus = Severity | "fixed";

/** One rendered finding row. */
export type Row = {
  finding: Finding;
  status: RowStatus;
  /** The finding has an AI write path ("Fix with AI"). */
  fixable: boolean;
  /** The inline manual editor, when the finding has one. */
  editor: { kind: "title" } | { kind: "meta"; field: MetaFieldDef } | null;
  /** The draft field the finding is about (a page-fields path), or null. */
  field: string | null;
  /** The field's value in the draft / the saved baseline ("" = not set). */
  value: string;
  baseValue: string;
  /** Who changed the field since the baseline, if anyone. */
  origin: DraftSource | null;
  /** [min, max] characters, when the check bounds the value. */
  bounds: [number, number] | null;
  /** A grouped row (one edit target, e.g. the Share card): the findings it
   *  stands for, each its own row. Absent on a plain row. */
  members?: Row[];
};

/**
 * Rows that share one edit target fold into one row (0453 S4): the Open Graph
 * trio is one Share card. Keyed on the row's field path, client-side — the
 * findings themselves stay one per tag. The field, not the finding's own
 * remediation: a fixed row reads the passing finding, which carries none.
 */
const GROUPS: { key: string; prefix: string; title: string; location: string }[] = [
  { key: "share", prefix: "meta.og_", title: "Share card", location: "Meta: Open Graph" },
];

const groupOf = (r: Row) => GROUPS.find((g) => r.field?.startsWith(g.prefix)) ?? null;

/** The findings a row stands for: its members, or itself. */
export const rowFindings = (r: Row): Finding[] => (r.members ? r.members.map((m) => m.finding) : [r.finding]);

/** The rows a row stands for: its members, or itself. */
export const leafRows = (r: Row): Row[] => r.members ?? [r];

/** Whether a row shows a finding — as itself or as a group member. */
export const rowHolds = (r: Row, findingId: string): boolean =>
  r.finding.id === findingId || !!r.members?.some((m) => m.finding.id === findingId);

const STATUS_RANK: Record<RowStatus, number> = { fail: 0, warn: 1, fixed: 2, pass: 3 };

/** Fold each group's 2+ rows into one, in the place of its first member. */
function groupRows(rows: Row[], checkKey: string): Row[] {
  const byGroup = new Map<string, Row[]>();
  for (const r of rows) {
    const g = groupOf(r);
    if (g) byGroup.set(g.key, [...(byGroup.get(g.key) ?? []), r]);
  }
  const out: Row[] = [];
  for (const r of rows) {
    const g = groupOf(r);
    const members = g ? byGroup.get(g.key)! : [];
    if (!g || members.length < 2) {
      out.push(r);
      continue;
    }
    if (members[0] !== r) continue;
    const status = members.map((m) => m.status).sort((a, b) => STATUS_RANK[a] - STATUS_RANK[b])[0];
    const open = members.filter((m) => m.status === "fail" || m.status === "warn").length;
    const origins = new Set(members.map((m) => m.origin).filter((o): o is DraftSource => o !== null));
    out.push({
      finding: {
        id: `group:${checkKey}:${g.key}`,
        severity: status === "fixed" ? "pass" : status,
        title: g.title,
        detail: open > 0 ? `${open} of ${members.length} tags need attention.` : `All ${members.length} tags fixed in draft.`,
        location: g.location,
        dimension: members[0].finding.dimension,
        remediation: null,
      },
      status,
      fixable: members.some((m) => m.fixable),
      editor: null,
      field: null,
      value: "",
      baseValue: "",
      origin: origins.size === 1 ? [...origins][0] : null,
      bounds: null,
      members,
    });
  }
  return out;
}

/** One check's block in the rail. */
export type Section = {
  key: string;
  label: string;
  /** Warn + fail rows, worst first (stable within a severity), then the
   *  findings the unsaved draft fixed. */
  actionable: Row[];
  /** Passing rows, folded behind a "N passing" toggle. */
  passes: Row[];
  /** The actionable findings the agent can fix — "Fix this check". */
  fixable: Finding[];
};

/** The whole rail. */
export type Rail = {
  sections: Section[];
  /** Every finding in report order — "Fix all issues" filters it again. */
  all: Finding[];
  /** Whether any finding is AI-fixable (shows "Fix all issues"). */
  anyFixable: boolean;
  /** How many findings the unsaved draft fixed. */
  fixedCount: number;
};

/**
 * The draft field a finding is about, from its remediation: the page title,
 * a meta tag, or a section prop. Null for findings with no single field (a
 * broken link is a href, possibly in many places).
 */
export function fieldOf(f: Finding): string | null {
  const rem = f.remediation;
  if (!rem) return null;
  if (rem.action === "edit_field" && rem.field) return rem.field === "title" ? "title" : `meta.${rem.field}`;
  if (rem.action === "edit_prop" && rem.target?.section && rem.target.prop) {
    return `sections.${rem.target.section}.props.${rem.target.prop}`;
  }
  return null;
}

const boundsOf = (f: Finding): [number, number] | null => {
  const c = f.remediation?.constraints;
  return c?.min != null && c?.max != null ? [c.min, c.max] : null;
};

function toRow(finding: Finding, ctx: RailContext, status: RowStatus = finding.severity, about: Finding = finding): Row {
  const meta = metaFieldFromRemediation(about);
  const field = fieldOf(about);
  return {
    finding,
    status,
    fixable: status !== "fixed" && isAiFixable(finding),
    editor: isTitleFinding(about) ? { kind: "title" } : meta ? { kind: "meta", field: meta } : null,
    field,
    value: field ? (ctx.draftValue?.(field) ?? "") : "",
    baseValue: field ? (ctx.baselineValue?.(field) ?? "") : "",
    origin: (field && ctx.origin?.(field)) || null,
    bounds: boundsOf(about),
  };
}

/**
 * The rail for a report: one section per check, in report order; within a
 * check the actionable rows lead worst-first and the passes fold away — the
 * rail is an action list, so what needs doing comes before what passed.
 *
 * When the report graded the UNSAVED draft and `ctx.base` is the saved-draft
 * report, a finding that failed there and passes (or is gone) here is a
 * `fixed` row in the actionable list — the server's verdict, never a guess.
 */
export function buildRail(report: AuditReport, ctx: RailContext = {}): Rail {
  const unsaved = report.audited?.revision === "unsaved" && !!ctx.base;
  const baseChecks = new Map((ctx.base?.checks ?? []).map((c) => [c.key, c]));
  let fixedCount = 0;
  const sections = report.checks.map((check): Section => {
    const failing = check.findings
      .filter((f) => f.severity !== "pass")
      .sort((a, b) => SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity])
      .map((f) => toRow(f, ctx));
    const fixed: Row[] = [];
    const fixedIds = new Set<string>();
    if (unsaved) {
      const now = new Map(check.findings.map((f) => [f.id, f]));
      for (const was of baseChecks.get(check.key)?.findings ?? []) {
        if (was.severity === "pass") continue;
        const current = now.get(was.id);
        if (current && current.severity !== "pass") continue;
        // The row reads the CURRENT (passing) finding, located by the old one's
        // remediation — a pass carries none.
        fixed.push(toRow(current ?? was, ctx, "fixed", was));
        fixedIds.add(was.id);
      }
    }
    fixedCount += fixed.length;
    const actionable = groupRows([...failing, ...fixed], check.key);
    return {
      key: check.key,
      label: check.label,
      actionable,
      passes: check.findings.filter((f) => f.severity === "pass" && !fixedIds.has(f.id)).map((f) => toRow(f, ctx)),
      fixable: fixableFindings(actionable),
    };
  });
  return {
    sections,
    all: report.checks.flatMap((c) => c.findings),
    anyFixable: sections.some((s) => s.fixable.length > 0),
    fixedCount,
  };
}

/** The AI-fixable findings among rows — a group contributes its fixable members. */
export const fixableFindings = (rows: Row[]): Finding[] =>
  rows.flatMap(leafRows).filter((r) => r.fixable).map((r) => r.finding);

/** One run of a word diff. */
export type DiffPart = { kind: "same" | "del" | "ins"; text: string };

/**
 * A word-level diff of `before` → `after` (LCS over whitespace-kept tokens),
 * for the selected row's "what changed". Adjacent runs of a kind are merged.
 */
export function wordDiff(before: string, after: string): DiffPart[] {
  const a = before.split(/(\s+)/).filter((t) => t !== "");
  const b = after.split(/(\s+)/).filter((t) => t !== "");
  const lcs: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));
  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      lcs[i][j] = a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
    }
  }
  const parts: DiffPart[] = [];
  const push = (kind: DiffPart["kind"], text: string) => {
    const last = parts[parts.length - 1];
    if (last?.kind === kind) last.text += text;
    else parts.push({ kind, text });
  };
  let i = 0;
  let j = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      push("same", a[i]);
      i++;
      j++;
    } else if (lcs[i + 1][j] >= lcs[i][j + 1]) push("del", a[i++]);
    else push("ins", b[j++]);
  }
  while (i < a.length) push("del", a[i++]);
  while (j < b.length) push("ins", b[j++]);
  return parts;
}

/**
 * The review side of the rail (DECISIONS 0453, S2): which rows a filter shows,
 * the walk order Previous / Next (J / K) follows, and the "need you" set.
 */
export type RailFilter = "needs" | "changed" | "all";

/** A row whose field differs from the saved baseline, or that the unsaved
 *  draft fixed — what "Changed" lists and the reviewed meter counts. */
export const isChanged = (r: Row): boolean =>
  r.members ? r.members.some(isChanged) : r.status === "fixed" || (r.field !== null && r.value !== r.baseValue);

/** A row the person still has to deal with: sent in the last fix turn and
 *  still failing — for a group, any member that is. */
export const rowNeedsYou = (r: Row, needs: ReadonlySet<string>): boolean =>
  leafRows(r).some((m) => needs.has(m.finding.id) && m.status !== "fixed" && m.status !== "pass");

/**
 * The findings that still need the person: sent to the agent in the last fix
 * turn and still failing (or warning) in the current report. Client-side by
 * design — the sent ids are the studio's own memory of the turn, no server field.
 */
export function needsYou(report: AuditReport, sent: ReadonlySet<string>): Set<string> {
  const out = new Set<string>();
  if (sent.size === 0) return out;
  for (const check of report.checks) {
    for (const f of check.findings) if (sent.has(f.id) && f.severity !== "pass") out.add(f.id);
  }
  return out;
}

/** One check's rows under a filter ("all" keeps the actionable / passes split). */
export type FilteredSection = { key: string; label: string; rows: Row[]; passes: Row[]; fixable: Finding[] };

export type FilteredRail = {
  sections: FilteredSection[];
  counts: Record<RailFilter, number>;
  /** The rows Previous / Next walk, in rail order: every row the filter shows
   *  (under "all", the actionable rows — passes stay folded). */
  order: Row[];
};

/**
 * Apply a filter to the rail. "Needs you" and "Changed" list matching rows
 * flat (a changed row that now passes is still a change to review, so they
 * draw from the passes too); a check with no match drops out. "All" is the
 * rail as {@link buildRail} built it.
 */
export function filterRail(rail: Rail, filter: RailFilter, needs: ReadonlySet<string>): FilteredRail {
  const matches = (r: Row): boolean =>
    filter === "all" ? true : filter === "changed" ? isChanged(r) : rowNeedsYou(r, needs);
  const every = (s: Section) => [...s.actionable, ...s.passes];
  const counts: Record<RailFilter, number> = { needs: 0, changed: 0, all: 0 };
  for (const s of rail.sections) {
    counts.all += s.actionable.length;
    for (const r of every(s)) {
      if (isChanged(r)) counts.changed++;
      if (rowNeedsYou(r, needs)) counts.needs++;
    }
  }
  const sections: FilteredSection[] = [];
  for (const s of rail.sections) {
    const rows = filter === "all" ? s.actionable : every(s).filter(matches);
    if (filter !== "all" && rows.length === 0) continue;
    sections.push({
      key: s.key,
      label: s.label,
      rows,
      passes: filter === "all" ? s.passes : [],
      fixable: fixableFindings(rows),
    });
  }
  return { sections, counts, order: sections.flatMap((s) => s.rows) };
}

/**
 * The finding Previous / Next lands on: one step from `current` through
 * `order`, wrapping. With nothing selected (or the selection filtered out),
 * Next starts at the first row and Previous at the last.
 */
export function stepRow(order: Row[], current: string | null, dir: 1 | -1): string | null {
  if (order.length === 0) return null;
  const at = order.findIndex((r) => r.finding.id === current);
  if (at < 0) return order[dir === 1 ? 0 : order.length - 1].finding.id;
  return order[(at + dir + order.length) % order.length].finding.id;
}
