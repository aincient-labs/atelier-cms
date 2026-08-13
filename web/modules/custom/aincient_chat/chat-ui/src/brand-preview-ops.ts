/**
 * Applying a brand_preview payload to the studio's draft — one implementation.
 *
 * Two callers, deliberately: the `brand_preview` tool card (the authoritative
 * end-of-turn apply, replayed from storage on reload) and the adapter's
 * `preview` frame handler (the transient mid-turn repaints each specialist
 * emits). They differ in whether a card is rendered and whether anything is
 * persisted — NOT in what applying means. Two copies of "what applying means"
 * is how the two paths would drift into disagreeing about the same turn.
 */

import { setBrandOverride, setPendingFonts, resetBrandOverrides } from "./brand-state";

export type BrandPreviewPayload = {
  tokens?: Record<string, string>;
  fonts?: string[];
  reset?: boolean;
  rejected?: string[];
  /** A one-line framing sentence (also sent as the tool summary). */
  summary?: string;
  /** Set by the adapter on cards replayed from storage — read-only, applies nothing. */
  __historical?: boolean;
};

/** Apply one preview op to the shared draft store. Idempotent per cssVar. */
export function applyBrandPreviewOps(payload: BrandPreviewPayload): void {
  if (payload.reset) resetBrandOverrides();
  for (const [cssVar, value] of Object.entries(payload.tokens ?? {})) {
    if (typeof value === "string") setBrandOverride(cssVar, value);
  }
  // Only touch the staged fonts when the op carries some, so a token-only op
  // doesn't wipe fonts a previous op (or the studio) staged. A reset already
  // cleared them above.
  if (payload.fonts && payload.fonts.length) setPendingFonts(payload.fonts);
}
