/**
 * Shared working-diff between the brand editor controls and the live preview.
 *
 * The studio rail sets a token override here; the preview iframe subscribes and
 * repaints by writing the value as an inline custom property on its <html> (which
 * beats the page's own :root brand block, so the reskin is instant + ephemeral).
 *
 * Keyed by CSS-var name WITHOUT the leading "--" (e.g. "button-radius"), which is
 * exactly what the manifest's `css_var` field carries and what the preview feeds
 * to style.setProperty("--" + key, value). Nothing here is persisted — this is
 * preview only; saving is a separate write path (the studio's Publish button).
 *
 * Web fonts ride along separately: a preset chosen in the quick picker carries
 * font families that can't be previewed as a CSS var, so they're held here as a
 * pending list and sent on Publish (see brand-studio.tsx). Cleared together with
 * the overrides on discard.
 */

export type BrandOverrides = Record<string, string>;

let current: BrandOverrides = {};
let pendingFonts: string[] | null = null;
const subscribers = new Set<(overrides: BrandOverrides) => void>();
const reloadSubscribers = new Set<() => void>();

function emit(): void {
  for (const cb of subscribers) cb(current);
}

/** Set (or clear, with "") one token override and notify the preview. */
export function setBrandOverride(cssVar: string, value: string): void {
  const next = { ...current };
  if (value === "") delete next[cssVar];
  else next[cssVar] = value;
  current = next;
  emit();
}

/** Drop every override + pending font (revert the preview to the saved brand). */
export function resetBrandOverrides(): void {
  current = {};
  pendingFonts = null;
  emit();
}

/** Stage web fonts to publish alongside the override diff (null clears them). */
export function setPendingFonts(fonts: string[] | null): void {
  pendingFonts = fonts && fonts.length ? fonts : null;
  emit();
}

/** The fonts staged for the next Publish, if any. */
export function getPendingFonts(): string[] | null {
  return pendingFonts;
}

/** The current working diff (used by the preview to re-apply after an iframe reload). */
export function getBrandOverrides(): BrandOverrides {
  return current;
}

/** Subscribe to override changes; returns an unsubscribe fn. */
export function subscribeBrandOverrides(cb: (overrides: BrandOverrides) => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/**
 * Ask the live preview iframe to reload so it re-pulls the saved brand from the
 * server. Fired after a successful Publish: the override diff has just become
 * the saved brand, so the inline overlay is dropped (see resetBrandOverrides)
 * and the iframe reloads to render the freshly-persisted brand from source —
 * no stale overlay, and getBrandOverrides() is empty so the per-turn agent
 * context no longer reports a published value as an unsaved draft.
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
