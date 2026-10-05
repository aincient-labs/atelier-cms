import {
  setComponentEnabled,
  setComponentToneEnabled,
  setIncludeNew,
  setKindComponentOn,
  setKindLimit,
  setKindNarrow,
  setKindOpener,
  setToneEnabled,
  setVariantEnabled,
  kindAllowed,
  type ComponentEntry,
  type ConstraintDraft,
  type KindDraft,
} from "./constraint-model";

/**
 * The Components agent's proposal (DECISIONS 0455, P2) — the `components_proposal`
 * widget payload the server's ComponentProposalApplier validated against the
 * discovered vocabulary. Explicit on/off operations, merged onto whatever the
 * owner has staged in that scope; never a whole constraint. Pure: no React, no
 * store.
 */
export type ComponentProposal = {
  /** "site" or a kind id. */
  scope: string;
  turn_off?: string[];
  turn_on?: string[];
  variants_off?: Record<string, string[]>;
  variants_on?: Record<string, string[]>;
  /** Site scope: the "*" key is the site-wide tone list. */
  tones_off?: Record<string, string[]>;
  tones_on?: Record<string, string[]>;
  include_new?: boolean;
  opener?: string;
  /** 0 clears a limit. */
  limits?: Record<string, number>;
  reason?: string;
};

/** A merged draft plus the operations the studio's own guards refused. */
export type Merged<D> = { draft: D; skipped: string[] };

function byName(entries: ComponentEntry[]): Map<string, ComponentEntry> {
  return new Map(entries.map((e) => [e.name, e]));
}

/** Run one guarded edit; a refusal (the same draft back) is recorded. */
function step<D>(draft: D, next: D, label: string, skipped: string[]): D {
  if (next === draft) skipped.push(label);
  return next;
}

/** Merge a site-scope proposal into the site draft. */
export function applySiteProposal(
  draft: ConstraintDraft,
  p: ComponentProposal,
  entries: ComponentEntry[],
  allTones: string[],
): Merged<ConstraintDraft> {
  const skipped: string[] = [];
  const known = byName(entries);
  let d = draft;
  for (const name of p.turn_off ?? []) d = setComponentEnabled(d, name, false);
  for (const name of p.turn_on ?? []) d = setComponentEnabled(d, name, true);
  for (const [on, map] of [[false, p.variants_off], [true, p.variants_on]] as const) {
    for (const [name, values] of Object.entries(map ?? {})) {
      const entry = known.get(name);
      if (!entry) continue;
      for (const v of values) d = step(d, setVariantEnabled(d, name, v, on, entry.variants), `${name} variant ${v}`, skipped);
    }
  }
  for (const [on, map] of [[false, p.tones_off], [true, p.tones_on]] as const) {
    for (const [name, values] of Object.entries(map ?? {})) {
      for (const t of values) {
        if (name === "*") {
          d = step(d, setToneEnabled(d, t, on, allTones), `${t} everywhere`, skipped);
          continue;
        }
        const entry = known.get(name);
        if (entry) d = step(d, setComponentToneEnabled(d, entry, t, on), `${name} tone ${t}`, skipped);
      }
    }
  }
  return { draft: d, skipped };
}

/**
 * Merge a kind-scope proposal into one kind's draft. The regime switch runs
 * first, so the on/off and narrowing ops land in the representation it picks.
 * `site` is the site draft: what it removes bounds what a kind may choose.
 */
export function applyKindProposal(
  draft: KindDraft,
  p: ComponentProposal,
  entries: ComponentEntry[],
  site: ConstraintDraft,
): Merged<KindDraft> {
  const skipped: string[] = [];
  const known = byName(entries);
  const names = entries.map((e) => e.name);
  let d = draft;
  if (typeof p.include_new === "boolean") d = setIncludeNew(d, p.include_new, names);
  for (const name of p.turn_off ?? []) d = setKindComponentOn(d, name, false);
  for (const name of p.turn_on ?? []) d = setKindComponentOn(d, name, true);
  for (const [key, on, map] of [
    ["variants", false, p.variants_off],
    ["variants", true, p.variants_on],
    ["tones", false, p.tones_off],
    ["tones", true, p.tones_on],
  ] as const) {
    for (const [name, values] of Object.entries(map ?? {})) {
      const entry = known.get(name);
      if (!entry) continue;
      const allowed = key === "variants"
        ? kindAllowed(entry.variants, site.variants[name] ?? [])
        : kindAllowed(entry.tones, [...site.tones, ...(site.component_tones?.[name] ?? [])]);
      for (const v of values) d = step(d, setKindNarrow(d, name, key, v, on, allowed), `${name} ${key === "variants" ? "variant" : "tone"} ${v}`, skipped);
    }
  }
  if (typeof p.opener === "string") d = setKindOpener(d, p.opener);
  for (const [name, max] of Object.entries(p.limits ?? {})) d = setKindLimit(d, name, max > 0 ? max : null);
  return { draft: d, skipped };
}

/** How many operations a proposal carries — the card's count. */
export function proposalSize(p: ComponentProposal): number {
  let n = (p.turn_off?.length ?? 0) + (p.turn_on?.length ?? 0);
  for (const map of [p.variants_off, p.variants_on, p.tones_off, p.tones_on]) {
    for (const values of Object.values(map ?? {})) n += values.length;
  }
  if (typeof p.include_new === "boolean") n++;
  if (typeof p.opener === "string") n++;
  n += Object.keys(p.limits ?? {}).length;
  return n;
}
