/**
 * The copy on the brand `preview_brand` card — derived, and deliberately kept in
 * its own DOM-free module so it can be unit-tested without dragging in the card's
 * console-navigation imports (the console shell reads `window` at module load).
 *
 * A HISTORICAL card (replayed from storage on load or a thread switch) is a record
 * of something that WAS staged, not a claim about live state: the draft it staged
 * lived in memory only, so after a reload it is gone while the transcript still
 * reads "Applied to preview". Saying so on the card is the point — silently looking
 * live is what made the transcript lie. It stays read-only (no re-stage
 * affordance), which is the same policy as the `applyOps` skip in the card itself.
 */

export type BrandCardCopySource = {
  /** The typed form (plans/studio-commands.md P1); counted instead of the fields below. */
  commands?: readonly { verb: string; args?: unknown }[];
  tokens?: Record<string, string>;
  fonts?: string[];
  reset?: boolean;
  __historical?: boolean;
};

export function brandPreviewCardText(payload: BrandCardCopySource): {
  historical: boolean;
  label: string;
  note: string;
} {
  const historical = payload.__historical === true;
  const { count, reset } = Array.isArray(payload.commands)
    ? countCommands(payload.commands)
    : // remove after 0.18 — old {tokens,fonts,reset} payload (stored cards)
      { count: Object.keys(payload.tokens ?? {}).length + (payload.fonts?.length ?? 0), reset: payload.reset };
  return {
    historical,
    label: reset
      ? "Reverted the preview to the saved brand"
      : `Applied to preview · ${count} change${count === 1 ? "" : "s"}`,
    note: historical
      ? "staged earlier — no longer active"
      : "Preview only — Publish in the studio to apply it site-wide",
  };
}

/** Changes in a command batch, counted the way the legacy payload was: one per token, one per font. */
function countCommands(commands: readonly { verb: string; args?: unknown }[]): { count: number; reset: boolean } {
  let count = 0;
  let reset = false;
  for (const c of commands) {
    const args = (c.args ?? {}) as { tokens?: Record<string, unknown>; fonts?: unknown[] };
    if (c.verb === "reset") reset = true;
    else if (c.verb === "set_tokens") count += Object.keys(args.tokens ?? {}).length;
    else if (c.verb === "set_fonts") count += Array.isArray(args.fonts) ? args.fonts.length : 0;
    else if (c.verb === "step_token") count += 1;
  }
  return { count, reset };
}
