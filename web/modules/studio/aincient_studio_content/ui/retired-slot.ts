/**
 * "No longer offered" detection for a kept page section (DECISIONS 0455, D5).
 *
 * When the site or a page kind stops offering a component — or one of its
 * variants / tones — the server keeps existing slots that use it (they still
 * render), but never offers it for new placement. The studio marks such a slot
 * and offers a Replace action. Pure: no React, no fetch.
 */

/** The slice of a section this helper reads. */
export type RetiredSlotSection = {
  component: string;
  props: Record<string, unknown>;
};

/** The slice of a section def this helper reads (the `tone` prop's enum). */
export type RetiredSlotDef = {
  component: string;
  props: { name: string; enum?: string[] }[];
};

/** The slice of the Content studio manifest this helper reads. */
export type RetiredSlotManifest = {
  sections?: RetiredSlotDef[];
  reference?: RetiredSlotDef[];
  layout?: RetiredSlotDef[];
  retired?: RetiredSlotDef[];
  /** The narrowed site-wide tone list. */
  tones?: string[];
  /** The narrowed per-component variant enums. */
  variants?: Record<string, string[]>;
  /** The un-narrowed (discovered) per-component variant enums. */
  discovered_variants?: Record<string, string[]>;
};

/** A stored enum value as a non-empty string, else null (unset). */
function stored(value: unknown): string | null {
  if (typeof value !== "string" && typeof value !== "number") return null;
  const s = String(value);
  return s === "" ? null : s;
}

/** The offered (non-retired) def for a component, if any. */
function offeredDef(component: string, manifest: RetiredSlotManifest): RetiredSlotDef | undefined {
  for (const list of [manifest.sections, manifest.reference, manifest.layout]) {
    const hit = list?.find((d) => d.component === component);
    if (hit) return hit;
  }
  return undefined;
}

/**
 * Why a section is no longer offered, in plain language — or null when it is
 * still fully offered (or the manifest can't tell).
 */
export function retiredReason(section: RetiredSlotSection, manifest: RetiredSlotManifest | null | undefined): string | null {
  if (!manifest) return null;
  const { component } = section;
  const props = section.props ?? {};

  if (manifest.retired?.some((d) => d.component === component)) {
    return "No longer offered";
  }

  const variant = stored(props.variant);
  if (variant !== null && manifest.variants) {
    // The narrowed enum; a component the discovery knows variants for but the
    // narrowed map lacks offers none of them any more.
    const allowed =
      manifest.variants[component] ?? (manifest.discovered_variants?.[component] ? [] : undefined);
    if (allowed && !allowed.includes(variant)) {
      return `Variant “${variant}” no longer offered`;
    }
  }

  const tone = stored(props.tone);
  if (tone !== null) {
    const toneEnum = offeredDef(component, manifest)?.props.find((p) => p.name === "tone")?.enum;
    const allowed = toneEnum ?? manifest.tones;
    if (allowed && !allowed.includes(tone)) {
      return `Tone “${tone}” no longer offered`;
    }
  }

  return null;
}
