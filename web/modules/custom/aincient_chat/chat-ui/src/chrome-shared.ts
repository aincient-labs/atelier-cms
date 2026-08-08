import type { ChromeDraft, ChromeLayout, ChromeMenuLink } from "./globals-state";

/**
 * Shared chrome-studio primitives (DECISIONS 0372).
 *
 * The old Globals studio split into three surfaces — Identity (design_system),
 * Navigation & Pages (globals) and Settings (settings) — that all read the same
 * /atelier/chrome/manifest and stage the same {@link ChromeDraft}. This module
 * holds what they share: the manifest shape, and the seed/clone helpers that
 * build a working draft from the manifest (or a save response — same shape). Each
 * studio renders, counts and publishes only its OWN slice of the draft; the save
 * endpoint merges by provided key, so a partial publish never clobbers another
 * surface's fields.
 */

/** One header/footer layout setting as the manifest describes it for the rail. */
export type RegistrySetting = {
  key: string;
  label: string;
  type: "enum" | "bool";
  enum: string[] | null;
  default: string | boolean;
};

/** The chrome state + editing vocabulary the studios render from. */
export type ChromeManifest = {
  chrome: ChromeLayout;
  registry: { header: RegistrySetting[]; footer: RegistrySetting[] };
  identity: {
    guidelines: {
      name: string;
      tagline: string;
      description: string;
      tone: string;
      imagery_style: string;
      imagery_avoid: string;
    };
    footer_note: string;
    /** `media:<id>` tokens (or '' for none) — the unified picker's value. */
    logo: string;
    favicon: string;
    /** Site information (mail + front/403/404 `entity:node:<id>` tokens). */
    site: { mail: string; front: string; page_403: string; page_404: string };
  };
  privacy: {
    font_delivery: string;
    /** The delivery modes the backend accepts, in display order. */
    options: string[];
    /** Whether the SAVED state shows a consent banner (a cross-check for the rail). */
    banner_active: boolean;
  };
  menus: { main: ChromeMenuLink[]; footer: ChromeMenuLink[] };
};

/** The font-delivery mode that shows a consent banner (must match BrandRepository). */
export const DELIVERY_GOOGLE = "google";

/** The site-information keys (mirrors SiteIdentity::SITE_KEYS). */
export const SITE_KEYS = ["mail", "front", "page_403", "page_404"] as const;

/** A plain-JSON deep clone — the draft is pure data, so this is safe + cheap. */
export const cloneChromeDraft = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

/** Title-case a raw enum value ("nav_alignment" → "Nav alignment", "left" → "Left"). */
export function titleCase(s: string): string {
  const t = s.replace(/[_-]+/g, " ").trim();
  return t.charAt(0).toUpperCase() + t.slice(1);
}

/** Build a working draft from the manifest (or the save response — same shape). */
export function seedChromeDraft(
  data: Pick<ChromeManifest, "chrome" | "identity" | "privacy" | "menus">,
): ChromeDraft {
  const g = data.identity?.guidelines ?? ({} as ChromeManifest["identity"]["guidelines"]);
  return {
    chrome: cloneChromeDraft(data.chrome ?? { header: {}, footer: {} }),
    identity: {
      guidelines: {
        name: String(g.name ?? ""),
        tagline: String(g.tagline ?? ""),
        description: String(g.description ?? ""),
        tone: String(g.tone ?? ""),
        imagery_style: String(g.imagery_style ?? ""),
        imagery_avoid: String(g.imagery_avoid ?? ""),
      },
      footer_note: String(data.identity?.footer_note ?? ""),
      logo: String(data.identity?.logo ?? ""),
      favicon: String(data.identity?.favicon ?? ""),
      site: {
        mail: String(data.identity?.site?.mail ?? ""),
        front: String(data.identity?.site?.front ?? ""),
        page_403: String(data.identity?.site?.page_403 ?? ""),
        page_404: String(data.identity?.site?.page_404 ?? ""),
      },
    },
    privacy: {
      font_delivery: String(data.privacy?.font_delivery ?? DELIVERY_GOOGLE),
    },
    menus: {
      main: cloneChromeDraft(data.menus?.main ?? []),
      footer: cloneChromeDraft(data.menus?.footer ?? []),
    },
  };
}
