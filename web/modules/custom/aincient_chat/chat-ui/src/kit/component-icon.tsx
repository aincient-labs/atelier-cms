import type { ReactNode } from "react";

/**
 * The icon for a page component (DECISIONS 0455 D10) — one simple line icon per
 * built-in placeable, and a generic mark for anything else (pack components, a
 * future built-in). Replaces the manifest's legacy Unicode glyphs, which the
 * console no longer displays: icons are inline SVG, never glyphs.
 *
 * 16px on a 16-unit grid, `currentColor` stroke at 1.4, decorative
 * (`aria-hidden`) — the component's name always sits beside it. Used by the
 * Components studio's rail and the Content studio's add-section picker.
 */
const PATHS: Record<string, ReactNode> = {
  hero: (
    <>
      <rect x="2" y="2.5" width="12" height="11" rx="1.5" />
      <path d="M5 7h6M6 9.5h4" />
    </>
  ),
  banner: (
    <>
      <rect x="1.5" y="5" width="13" height="6" rx="1" />
      <path d="M4 8h5" />
    </>
  ),
  content: <path d="M3 3.5h10M3 6.5h10M3 9.5h10M3 12.5h6" />,
  features: (
    <>
      <circle cx="3.5" cy="4.5" r="1.3" />
      <circle cx="8" cy="4.5" r="1.3" />
      <circle cx="12.5" cy="4.5" r="1.3" />
      <path d="M2 9h3M6.5 9h3M11 9h3M2 11.5h3M6.5 11.5h3M11 11.5h3" />
    </>
  ),
  markdown: (
    <>
      <rect x="1.5" y="3.5" width="13" height="9" rx="1.5" />
      <path d="M4 10.5V6l2 2.5L8 6v4.5M11.5 6v4.5M10 9l1.5 1.5L13 9" />
    </>
  ),
  accordion: (
    <>
      <rect x="2" y="2" width="12" height="3.5" rx="1" />
      <rect x="2" y="7" width="12" height="7" rx="1" />
      <path d="M4.5 9.5h5M4.5 11.5h3" />
    </>
  ),
  faq: (
    <>
      <circle cx="8" cy="8" r="6" />
      <path d="M6.3 6.4a1.8 1.8 0 1 1 2.5 1.6c-.5.3-.8.7-.8 1.3v.2M8 11.4v.1" />
    </>
  ),
  image: (
    <>
      <rect x="2" y="3" width="12" height="10" rx="1.5" />
      <circle cx="5.5" cy="6.3" r="1.1" />
      <path d="M2.5 12l3.5-3.5 2.5 2.5 2-2 3 3" />
    </>
  ),
  gallery: (
    <>
      <rect x="2" y="2" width="5" height="5" rx="1" />
      <rect x="9" y="2" width="5" height="5" rx="1" />
      <rect x="2" y="9" width="5" height="5" rx="1" />
      <rect x="9" y="9" width="5" height="5" rx="1" />
    </>
  ),
  testimonials: (
    <>
      <path d="M2.5 3h11v7.5h-6l-3 2.5v-2.5h-2z" />
      <path d="M6 5.8v1.6M8.5 5.8v1.6" />
    </>
  ),
  logos: (
    <>
      <circle cx="3.5" cy="8" r="1.8" />
      <rect x="6.3" y="6.2" width="3.4" height="3.6" rx=".6" />
      <path d="M12.5 6.1l2 3.7h-4z" />
    </>
  ),
  stats: <path d="M2 13.5h12M4 11.5V8M7 11.5V4M10 11.5V6.5M13 11.5V9.5" />,
  team: (
    <>
      <circle cx="6" cy="5.5" r="2" />
      <path d="M2.5 13c0-2 1.6-3.5 3.5-3.5s3.5 1.5 3.5 3.5" />
      <circle cx="11" cy="6" r="1.6" />
      <path d="M10.8 9.6c1.6 0 2.7 1.4 2.7 3.4" />
    </>
  ),
  cta: (
    <>
      <rect x="1.5" y="5" width="13" height="6" rx="3" />
      <path d="M6 8h4M8.5 6.5L10 8l-1.5 1.5" />
    </>
  ),
  pricing: (
    <>
      <path d="M8.5 2H14v5.5L7.5 14 2 8.5z" />
      <circle cx="11" cy="5" r="1" />
    </>
  ),
  newsletter: (
    <>
      <rect x="1.5" y="3.5" width="13" height="9" rx="1.5" />
      <path d="M2 4.5l6 4.5 6-4.5" />
    </>
  ),
  grid: (
    <>
      <rect x="2" y="2.5" width="12" height="11" rx="1.5" />
      <path d="M6 2.5v11M10 2.5v11" />
    </>
  ),
  divider: <path d="M2 8h12M5 4.5h6M5 11.5h6" />,
  collection: (
    <>
      <rect x="2" y="5" width="9" height="9" rx="1" />
      <path d="M5 2.5h8.5V11" />
    </>
  ),
  embed: <path d="M5.5 4.5L2 8l3.5 3.5M10.5 4.5L14 8l-3.5 3.5M9 3.5l-2 9" />,
  block: (
    <>
      <path d="M8 1.8l5.5 3v6.4L8 14.2l-5.5-3V4.8z" />
      <path d="M2.5 4.8L8 7.8l5.5-3M8 7.8v6.4" />
    </>
  ),
};

/** The generic mark for a component the map doesn't name (packs, new built-ins). */
const FALLBACK = (
  <>
    <rect x="2" y="2" width="12" height="12" rx="2" />
    <rect x="5.5" y="5.5" width="5" height="5" rx="1" />
  </>
);

/** The built-in component names that have their own icon (gallery + tests). */
export const COMPONENT_ICON_NAMES: readonly string[] = Object.keys(PATHS);

export function ComponentIcon({ name, className }: { name: string; className?: string }) {
  return (
    <svg
      className={className}
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.4}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      data-component-icon={PATHS[name] ? name : "generic"}
    >
      {PATHS[name] ?? FALLBACK}
    </svg>
  );
}
