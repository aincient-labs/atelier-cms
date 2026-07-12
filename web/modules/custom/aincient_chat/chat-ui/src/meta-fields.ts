/**
 * The editable SEO/meta fields of a page — the single source of the field
 * definitions shared by the Presence-facet editor ({@link SeoMetaGroup}) and,
 * later, the Checks findings mapping. Keys are the `PageMeta` keys (Metatag
 * plugin ids); each field stages into `draft.meta` and persists to
 * `field_metatag` as a per-page override (blank ⇒ inherit the site default).
 */
import type { PageMeta } from "./page-state";

export type MetaFieldDef = {
  key: keyof PageMeta;
  label: string;
  placeholder: string;
  /** Render a textarea instead of a single-line input. */
  multiline?: boolean;
  /** Inclusive [min, max] length for a live character gauge. */
  counter?: [number, number];
  /**
   * Render the shared media picker (search / upload / paste-a-token) instead of a
   * text input. The value is stored as a `media:<id>` token — resolved to an
   * absolute URL at render (see aincient_pages_metatags_alter) — or a raw URL.
   */
  image?: boolean;
};

export const META_FIELDS: MetaFieldDef[] = [
  {
    key: "description",
    label: "Meta description",
    placeholder: "A 50–160 character summary for search results and social cards",
    multiline: true,
    counter: [50, 160],
  },
  {
    key: "canonical_url",
    label: "Canonical URL",
    // Blank inherits the metatag default `[node:url]` — i.e. this page's own
    // alias — so the placeholder states the override semantics rather than a
    // fake example. SeoMetaGroup swaps in the concrete resolved URL once the
    // page has one (see its `canonical_url` placeholder override).
    placeholder: "Defaults to this page’s own URL — set to point canonical elsewhere",
  },
  {
    key: "og_title",
    label: "Open Graph title",
    placeholder: "Falls back to the page title when blank",
  },
  {
    key: "og_description",
    label: "Open Graph description",
    placeholder: "Falls back to the meta description when blank",
    multiline: true,
  },
  {
    key: "og_image",
    label: "Open Graph image",
    placeholder: "or paste a token or URL",
    image: true,
  },
];
