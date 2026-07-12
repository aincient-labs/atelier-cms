/**
 * Shared working draft between the Globals studio editor and its live preview —
 * the chrome parallel of brand-state.ts / page-state.ts.
 *
 * The Globals studio edits site-wide chrome: brand IDENTITY (name/tagline/logo/
 * footer note), the header/footer LAYOUT variants, and the two chrome MENUS
 * (`main`/`footer`). Unlike the brand draft (a thin CSS-var diff applied live in
 * the iframe) chrome is markup, not custom properties — so the preview is a full
 * server re-render: the iframe POSTs this whole draft to /aincient/chrome/preview
 * and shows the returned HTML (the page-preview pattern, not the brand one).
 *
 * Globals is EDITOR-ONLY (no chat agent yet), so unlike pages/brand nothing else
 * mutates this store — only the studio rail writes it. Nothing here is persisted;
 * the one deliberate write is the studio's Publish (POST /aincient/chrome/save).
 */

/** One editable link of a chrome menu (id absent ⇒ a new link). `children` holds
 *  the same shape recursively — the studio edits the menu as a nested tree and
 *  MenuRepository::sync persists the hierarchy (parent/weight) on Publish. */
export type ChromeMenuLink = {
  id?: number;
  title: string;
  url: string;
  enabled: boolean;
  children?: ChromeMenuLink[];
};

/** Header/footer layout variant settings (enum strings + booleans), keyed by the
 *  registry setting key (logo_position, sticky, nav_alignment / layout, show_tagline). */
export type ChromeLayout = {
  header: Record<string, string | boolean>;
  footer: Record<string, string | boolean>;
};

/** The brand identity the chrome renders (name/tagline + logo + footer note). */
export type ChromeIdentity = {
  guidelines: {
    name: string;
    tagline: string;
    description: string;
    tone: string;
    imagery_style: string;
    imagery_avoid: string;
  };
  footer_note: string;
  /** The brand logo as a `media:<id>` token (or '' for none) — picked/uploaded
   *  through the unified Library picker; the preview resolves it server-side. */
  logo: string;
  /** The favicon as a `media:<id>` token (or '' for none). */
  favicon: string;
};

/** Site-wide privacy posture. `font_delivery` is the one operator-set lever that
 *  turns the public GDPR consent banner on ('google') or off ('selfhost'). */
export type ChromePrivacy = {
  font_delivery: string;
};

/** The whole Globals working draft (what the preview renders, what Publish saves). */
export type ChromeDraft = {
  chrome: ChromeLayout;
  identity: ChromeIdentity;
  privacy: ChromePrivacy;
  menus: { main: ChromeMenuLink[]; footer: ChromeMenuLink[] };
};

let current: ChromeDraft | null = null;
const subscribers = new Set<(draft: ChromeDraft | null) => void>();
const reloadSubscribers = new Set<() => void>();
const resetSubscribers = new Set<() => void>();

function emit(): void {
  for (const cb of subscribers) cb(current);
}

/** Replace the working draft and notify the preview. */
export function setChromeDraft(draft: ChromeDraft | null): void {
  current = draft;
  emit();
}

/** The current working draft (read by the preview to POST it). */
export function getChromeDraft(): ChromeDraft | null {
  return current;
}

/** Drop the draft entirely (the preview clears until the studio re-seeds it). */
export function resetChromeDraft(): void {
  current = null;
  emit();
}

/** Subscribe to draft changes; returns an unsubscribe fn. */
export function subscribeChromeDraft(cb: (draft: ChromeDraft | null) => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/**
 * Ask the live preview to re-render. Fired after a successful Publish: the draft
 * has just become the saved chrome, so there's no visual change, but re-rendering
 * keeps the preview authoritative (mirrors the brand/page reload contract).
 */
export function reloadPreview(): void {
  for (const cb of reloadSubscribers) cb();
}

/** Subscribe to preview-reload requests; returns an unsubscribe fn. */
export function subscribePreviewReload(cb: () => void): () => void {
  reloadSubscribers.add(cb);
  return () => {
    reloadSubscribers.delete(cb);
  };
}

/**
 * Ask the studio to revert its working draft to the SAVED chrome (the baseline).
 * The chrome agent's `reset` op routes here: only the studio holds the saved
 * baseline (the draft store holds just the working copy), so the studio listens
 * and re-seeds — the chrome parallel of brand's resetBrandOverrides().
 */
export function requestChromeReset(): void {
  for (const cb of resetSubscribers) cb();
}

/** Subscribe to chrome-reset requests; returns an unsubscribe fn. */
export function subscribeChromeReset(cb: () => void): () => void {
  resetSubscribers.add(cb);
  return () => {
    resetSubscribers.delete(cb);
  };
}
