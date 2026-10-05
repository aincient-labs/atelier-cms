import { changedPaths, PAGE_LENS, type PageSchema, type PreviewPin } from "@console/sdk";
import { leafRows, type Rail, type Row } from "./checks-rows";

/**
 * The Checks canvas, pure (DECISIONS 0453, S3): which preview lens a finding
 * belongs to, which sections of the page a finding or a staged change sits in,
 * and the pins those make. No React, no stores — the preview and the rail read
 * the same answers, and `checks-lenses.test.ts` pins them down.
 */

/** The search-result lens: page title, meta description, canonical. */
export const SEARCH_LENS = "search";
/** The share-card lens: the Open Graph trio. */
export const SHARE_LENS = "share";

export type ChecksLens = typeof PAGE_LENS | typeof SEARCH_LENS | typeof SHARE_LENS;

/** The lens that shows a field in context, or null when no lens does
 *  (a teaser field, a post field — the rail alone covers those). */
export function lensForField(field: string | null): ChecksLens | null {
  if (!field) return null;
  if (field === "title" || field === "meta.description" || field === "meta.canonical_url") return SEARCH_LENS;
  if (field.startsWith("meta.og_")) return SHARE_LENS;
  if (field.startsWith("sections.")) return PAGE_LENS;
  return null;
}

/** The lens a row opens: its field's (a group's first member's), or the
 *  page for a link finding. */
export function lensForRow(row: Row): ChecksLens | null {
  if (row.members) return lensForRow(row.members[0]);
  return lensForField(row.field) ?? (row.finding.remediation?.target?.href ? PAGE_LENS : null);
}

/** The section id in a `sections.<id>…` field path, or null. */
const sectionOfField = (field: string | null): string | null =>
  field?.startsWith("sections.") ? (field.split(".")[1] ?? null) || null : null;

/**
 * The sections a row is about: its field's section, or — for a link finding —
 * every section the server found the href written in (`target.locations`,
 * 0453 S4). A Fixed link row reads the saved report's finding, so it still
 * points where the link was.
 */
export function rowSections(row: Row): string[] {
  if (row.members) return [...new Set(row.members.flatMap(rowSections))];
  const own = sectionOfField(row.field);
  if (own) return [own];
  return [...new Set((row.finding.remediation?.target?.locations ?? []).map((l) => l.section))];
}

/** The sections a staged change touched that still render (a removed section has nowhere to pin). */
export function changedSections(draft: PageSchema | null, baseline: PageSchema | null): string[] {
  if (!draft || !baseline) return [];
  const present = new Set((draft.sections ?? []).map((s) => s.id).filter(Boolean));
  const out = new Set<string>();
  for (const path of changedPaths(baseline, draft)) {
    const id = sectionOfField(path);
    if (id && present.has(id)) out.add(id);
  }
  return [...out];
}

const TONE_RANK: Record<PreviewPin["tone"], number> = { fail: 0, warn: 1, fixed: 2, changed: 3 };

/** Every actionable row in the rail (fail / warn / fixed), in rail order. */
const actionableRows = (rail: Rail): Row[] => rail.sections.flatMap((s) => s.actionable);

/**
 * One pin per section the rail has something to say about: a failing, warning
 * or fixed finding in it, or a staged change to it. The pin wears the worst
 * tone; the label counts what is there ("2 fail · changed").
 */
export function pinsFor(rail: Rail, draft: PageSchema | null, baseline: PageSchema | null): PreviewPin[] {
  const tally = new Map<string, { fail: number; warn: number; fixed: number; changed: boolean }>();
  const at = (id: string) => {
    let t = tally.get(id);
    if (!t) tally.set(id, (t = { fail: 0, warn: 0, fixed: 0, changed: false }));
    return t;
  };
  for (const row of actionableRows(rail)) {
    if (row.status === "pass") continue;
    for (const id of rowSections(row)) at(id)[row.status]++;
  }
  for (const id of changedSections(draft, baseline)) at(id).changed = true;

  const order = (draft?.sections ?? []).map((s) => s.id);
  const pins: PreviewPin[] = [];
  for (const [section, t] of tally) {
    // A section the draft removed has nowhere to pin.
    if (!order.includes(section)) continue;
    const parts = [
      t.fail ? `${t.fail} fail` : "",
      t.warn ? `${t.warn} warn` : "",
      t.fixed ? `${t.fixed} fixed` : "",
      t.changed ? "changed" : "",
    ].filter(Boolean);
    const tone: PreviewPin["tone"] = t.fail ? "fail" : t.warn ? "warn" : t.fixed ? "fixed" : "changed";
    pins.push({ section, tone, label: parts.join(" · ") });
  }
  // Page order, so the pins read top to bottom like the page.
  return pins.sort((a, b) => order.indexOf(a.section) - order.indexOf(b.section) || TONE_RANK[a.tone] - TONE_RANK[b.tone]);
}

/** The rail's actionable findings a lens shows — its badge count and its
 *  list: a group's members one by one, each opening the group. */
export function rowsForLens(rail: Rail, lens: ChecksLens): Row[] {
  return actionableRows(rail).flatMap(leafRows).filter((r) => lensForField(r.field) === lens);
}

/** The row a pin opens: the first actionable one in that section, worst first. */
export function rowForSection(rail: Rail, section: string): Row | null {
  const rank = { fail: 0, warn: 1, fixed: 2, pass: 3 } as const;
  return (
    actionableRows(rail)
      .filter((r) => rowSections(r).includes(section))
      .sort((a, b) => rank[a.status] - rank[b.status])[0] ?? null
  );
}
