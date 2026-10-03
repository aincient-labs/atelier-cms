/**
 * The Components governance pane's pure logic layer (plans/byo-components.md
 * W1b) — deliberately DOM-free so vitest covers it in the node environment.
 *
 * Semantics mirror the server (`ConstraintController`): every stored list is a
 * list of REMOVALS. A checked box in the pane means "available", i.e. NOT in
 * the removals list — the pane can only narrow what discovery admitted, never
 * re-admit what the gate rejected. Two guards mirror the server's one hard
 * refusal (removing every tone would 422) plus its per-component analogue
 * (removing every variant would silently degrade): the LAST enabled tone and
 * the LAST enabled variant of a component cannot be disabled.
 */

/** One discovered component in the vocabulary, as the manifest lists it. */
export type VocabularyComponent = {
  name: string;
  tier: string;
  icon: string;
  use: string;
};

/** The full manifest payload from GET /atelier/constraint/manifest. */
export type ConstraintManifest = {
  constraint: {
    components: string[];
    tones: string[];
    variants: Record<string, string[]>;
  };
  vocabulary: {
    components: VocabularyComponent[];
    tones: string[];
    variants: Record<string, string[]>;
  };
  effective: {
    placeable: string[];
    tones: string[];
    warnings: string[];
  };
};

/**
 * The staged draft — the constraint slice only (removals, exactly the shape the
 * save endpoint takes). The vocabulary/effective halves are read-only context.
 */
export type ConstraintDraft = {
  /** Component names REMOVED from every kind. */
  components: string[];
  /** Tones REMOVED site-wide. */
  tones: string[];
  /** Per-component variant values REMOVED. */
  variants: Record<string, string[]>;
};

/** Dedupe + keep order — the stored lists are sets in spirit. */
function uniq(list: string[]): string[] {
  return [...new Set(list)];
}

/**
 * Seed a draft from the manifest's stored constraint. Unknown component names
 * are KEPT (a pack may be temporarily uninstalled; its removal must survive a
 * round-trip through this pane — the server stores them anyway). Empty variant
 * lists are dropped: they carry no removal.
 */
export function seedConstraintDraft(manifest: ConstraintManifest): ConstraintDraft {
  const variants: Record<string, string[]> = {};
  for (const [name, removed] of Object.entries(manifest.constraint.variants ?? {})) {
    if (Array.isArray(removed) && removed.length > 0) variants[name] = uniq(removed);
  }
  return {
    components: uniq(manifest.constraint.components ?? []),
    tones: uniq(manifest.constraint.tones ?? []),
    variants,
  };
}

/** A deep copy, so staged edits never mutate the baseline. */
export function cloneConstraintDraft(draft: ConstraintDraft): ConstraintDraft {
  return {
    components: [...draft.components],
    tones: [...draft.tones],
    variants: Object.fromEntries(
      Object.entries(draft.variants).map(([k, v]) => [k, [...v]]),
    ),
  };
}

/** Order-insensitive set difference count (|a△b|). */
function symmetricDiff(a: string[], b: string[]): number {
  const sa = new Set(a);
  const sb = new Set(b);
  let n = 0;
  for (const x of sa) if (!sb.has(x)) n++;
  for (const x of sb) if (!sa.has(x)) n++;
  return n;
}

/**
 * The changed-item count for the dirty badge: every component, tone, or variant
 * value whose availability differs from the baseline counts as one change.
 */
export function countDirty(base: ConstraintDraft, draft: ConstraintDraft): number {
  let n = symmetricDiff(base.components, draft.components);
  n += symmetricDiff(base.tones, draft.tones);
  const names = new Set([...Object.keys(base.variants), ...Object.keys(draft.variants)]);
  for (const name of names) {
    n += symmetricDiff(base.variants[name] ?? [], draft.variants[name] ?? []);
  }
  return n;
}

/** Whether an item is available (checked): NOT in the removals list. */
export function isEnabled(removals: string[], name: string): boolean {
  return !removals.includes(name);
}

/** Toggle a removals list: enabled ⇒ drop from removals, disabled ⇒ add. */
function toggleRemoval(removals: string[], name: string, enabled: boolean): string[] {
  if (enabled) return removals.filter((n) => n !== name);
  return removals.includes(name) ? removals : [...removals, name];
}

/** Enable/disable a component — returns a NEW draft. */
export function setComponentEnabled(
  draft: ConstraintDraft,
  name: string,
  enabled: boolean,
): ConstraintDraft {
  return { ...cloneConstraintDraft(draft), components: toggleRemoval(draft.components, name, enabled) };
}

/**
 * Whether a tone is the LAST enabled one — the UI disables its checkbox,
 * mirroring the server's 422 on removing every tone.
 */
export function isLastEnabledTone(
  draft: ConstraintDraft,
  tone: string,
  allTones: string[],
): boolean {
  const enabled = allTones.filter((t) => isEnabled(draft.tones, t));
  return enabled.length === 1 && enabled[0] === tone;
}

/**
 * Enable/disable a tone — returns a NEW draft, or the SAME draft (refused)
 * when disabling would remove the last remaining tone.
 */
export function setToneEnabled(
  draft: ConstraintDraft,
  tone: string,
  enabled: boolean,
  allTones: string[],
): ConstraintDraft {
  if (!enabled && isLastEnabledTone(draft, tone, allTones)) return draft;
  return { ...cloneConstraintDraft(draft), tones: toggleRemoval(draft.tones, tone, enabled) };
}

/** Whether a variant value is the LAST enabled one for its component. */
export function isLastEnabledVariant(
  draft: ConstraintDraft,
  component: string,
  value: string,
  allValues: string[],
): boolean {
  const removed = draft.variants[component] ?? [];
  const enabled = allValues.filter((v) => isEnabled(removed, v));
  return enabled.length === 1 && enabled[0] === value;
}

/**
 * Enable/disable one variant value — returns a NEW draft, or the SAME draft
 * (refused) when disabling would remove a component's last variant. A component
 * whose removals empty out is dropped from the map entirely.
 */
export function setVariantEnabled(
  draft: ConstraintDraft,
  component: string,
  value: string,
  enabled: boolean,
  allValues: string[],
): ConstraintDraft {
  if (!enabled && isLastEnabledVariant(draft, component, value, allValues)) return draft;
  const next = cloneConstraintDraft(draft);
  const removed = toggleRemoval(next.variants[component] ?? [], value, enabled);
  if (removed.length > 0) next.variants[component] = removed;
  else delete next.variants[component];
  return next;
}

/**
 * The publish body — the whole staged slice, every key present so each REPLACES
 * its stored list. The variants map only carries components that actually have
 * removals (an empty list is no constraint, and the server drops it anyway).
 */
export function toSavePayload(draft: ConstraintDraft): {
  components: string[];
  tones: string[];
  variants: Record<string, string[]>;
} {
  const variants: Record<string, string[]> = {};
  for (const [name, removed] of Object.entries(draft.variants)) {
    if (removed.length > 0) variants[name] = [...removed];
  }
  return { components: [...draft.components], tones: [...draft.tones], variants };
}
