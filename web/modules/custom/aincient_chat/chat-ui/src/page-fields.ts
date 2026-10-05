import type { PageSchema, PageSection } from "./page-state";

/**
 * A page-schema as a flat map of FIELD PATHS — the unit the review loop talks
 * about (DECISIONS 0453): which fields a change touched, who touched them, and
 * what each one held before. Pure, so page-state's origin map, the Checks rail
 * and their tests share one definition of "a field".
 *
 * Paths:
 *   title · meta.<key> · teaser.<key>
 *   sections                      the section ORDER (slot ids, joined)
 *   sections.<id>                 a section's presence + component
 *   sections.<id>.props.<prop>    one prop of a section
 *   <key>                         any other top-level key (type, blog fields)
 *
 * A section is addressed by its stable slot id (PageStore::validate stamps
 * one); an id-less section falls back to its position, as the server's
 * summariser does.
 */

/** The path's value as a stable string, "" when absent — what diffs compare. */
type Flat = Map<string, string>;

const encode = (v: unknown): string => (v === undefined || v === null ? "" : typeof v === "string" ? v : JSON.stringify(v));

const sectionKey = (s: PageSection, pos: number): string => (s.id ? s.id : `#${pos}`);

/** Flatten a schema to path → encoded value (absent and empty are both ""). */
export function flattenFields(schema: PageSchema | null): Flat {
  const out: Flat = new Map();
  if (!schema) return out;
  for (const [key, value] of Object.entries(schema)) {
    if (key === "sections" || key === "meta" || key === "teaser") continue;
    out.set(key, encode(value));
  }
  for (const block of ["meta", "teaser"] as const) {
    const map = (schema[block] ?? {}) as Record<string, unknown>;
    for (const [key, value] of Object.entries(map)) out.set(`${block}.${key}`, encode(value));
  }
  const sections = schema.sections ?? [];
  out.set("sections", sections.map(sectionKey).join(","));
  sections.forEach((s, pos) => {
    const id = sectionKey(s, pos);
    out.set(`sections.${id}`, s.component);
    for (const [prop, value] of Object.entries(s.props ?? {})) out.set(`sections.${id}.props.${prop}`, encode(value));
  });
  return out;
}

/** The paths whose value differs between two schemas (either side may lack it). */
export function changedPaths(before: PageSchema | null, after: PageSchema | null): string[] {
  const a = flattenFields(before);
  const b = flattenFields(after);
  const out: string[] = [];
  for (const path of new Set([...a.keys(), ...b.keys()])) {
    if ((a.get(path) ?? "") !== (b.get(path) ?? "")) out.push(path);
  }
  return out;
}

/** One path's value in a schema, encoded ("" when absent). */
export function fieldValue(schema: PageSchema | null, path: string): string {
  return flattenFields(schema).get(path) ?? "";
}

/**
 * A copy of `schema` with one leaf field set to `value` — "" (or undefined)
 * removes it, so the page inherits again. Leaf paths only (title, meta.*,
 * teaser.*, sections.<id>.props.*, top-level scalars): a section's presence
 * or the order is not a single value to write back.
 */
export function withFieldValue(schema: PageSchema, path: string, value: unknown): PageSchema {
  const empty = value === undefined || value === null || value === "";
  const [head, ...rest] = path.split(".");
  if ((head === "meta" || head === "teaser") && rest.length === 1) {
    const map = { ...((schema[head] ?? {}) as Record<string, unknown>) };
    if (empty) delete map[rest[0]];
    else map[rest[0]] = value;
    return { ...schema, [head]: map };
  }
  if (head === "sections" && rest.length === 3 && rest[1] === "props") {
    const [id, , prop] = rest;
    return {
      ...schema,
      sections: (schema.sections ?? []).map((s, pos) => {
        if (sectionKey(s, pos) !== id) return s;
        const props = { ...s.props };
        if (empty) delete props[prop];
        else props[prop] = value;
        return { ...s, props };
      }),
    };
  }
  if (rest.length === 0 && head !== "sections") {
    if (head === "title") return { ...schema, title: empty ? "" : String(value) };
    const next = { ...schema };
    if (empty) delete next[head];
    else next[head] = value;
    return next;
  }
  throw new Error(`Not a writable field path: ${path}`);
}

/** Meta keys → the studio's SEO field labels (mirrors PageSchemaSummariser). */
const META_LABELS: Record<string, string> = {
  description: "Meta description",
  canonical_url: "Canonical URL",
  og_title: "Open Graph title",
  og_description: "Open Graph description",
  og_image: "Open Graph image",
};

/** Teaser keys → the "Teaser card" field labels. */
const TEASER_LABELS: Record<string, string> = {
  title: "Teaser title",
  description: "Teaser description",
  image: "Teaser image",
};

/** Blog-post keys → the post editor labels. */
const POST_LABELS: Record<string, string> = {
  category: "Category",
  lead: "Lead",
  author: "Author",
  author_bio: "Author bio",
  date: "Date",
  cover: "Cover image",
  body_md: "Body",
};

/** "cta_url" / "hero-banner" → "Cta url" / "Hero banner". */
const humanize = (key: string): string => {
  const words = key.replace(/[-_]+/g, " ").trim();
  return words ? words[0].toUpperCase() + words.slice(1) : key;
};

/** One field a change touched, in the operator's words. */
export type FieldChange = { path: string; label: string };

/**
 * What changed between two schemas, one entry per thing the operator would
 * name — the chat card's "N fields changed" and the Checks banner's "N changes
 * staged" count these, never ops (DECISIONS 0453). A section added, removed or
 * swapped is ONE change (its props aren't itemised again), and the section
 * order is only a change of its own when no section came or went. Listed in
 * reading order (title, SEO, teaser, post fields, sections). Labels use
 * the summariser's vocabulary (`PageSchemaSummariser`), so the card, the rail
 * and the revision message name a field the same way.
 */
export function fieldChanges(before: PageSchema | null, after: PageSchema | null): FieldChange[] {
  const paths = changedPaths(before, after);
  const a = flattenFields(before);
  const b = flattenFields(after);
  // Sections whose presence or component changed: their props fold into them.
  const whole = new Set(paths.filter((p) => /^sections\.[^.]+$/.test(p)).map((p) => p.slice("sections.".length)));
  const name = (id: string): string => {
    const component = b.get(`sections.${id}`) || a.get(`sections.${id}`) || "";
    return component ? `${humanize(component)} section` : "Section";
  };
  const out: FieldChange[] = [];
  for (const path of paths) {
    const [head, ...rest] = path.split(".");
    if (path === "sections") {
      if (whole.size === 0) out.push({ path, label: "Section order" });
      continue;
    }
    if (head === "sections") {
      const id = rest[0];
      if (rest.length === 1) {
        const label = !a.has(path) ? `Added ${name(id)}` : !b.has(path) ? `Removed ${name(id)}` : name(id);
        out.push({ path, label });
      } else if (!whole.has(id) && rest[1] === "props" && rest.length === 3) {
        out.push({ path, label: `${name(id)} · ${humanize(rest[2])}` });
      }
      continue;
    }
    if (path === "title") out.push({ path, label: "Page title" });
    else if (head === "meta" && rest.length === 1) out.push({ path, label: META_LABELS[rest[0]] ?? humanize(rest[0]) });
    else if (head === "teaser" && rest.length === 1) out.push({ path, label: TEASER_LABELS[rest[0]] ?? humanize(rest[0]) });
    else out.push({ path, label: POST_LABELS[path] ?? humanize(path) });
  }
  // Reading order: page title, SEO, teaser, post fields, then the sections.
  const rank = (path: string): number =>
    path === "title" ? 0 : path.startsWith("meta.") ? 1 : path.startsWith("teaser.") ? 2 : path.startsWith("sections") ? 4 : 3;
  return out.map((c, i) => ({ c, i })).sort((x, y) => rank(x.c.path) - rank(y.c.path) || x.i - y.i).map(({ c }) => c);
}
