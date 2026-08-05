import type { StudioKey } from "./studios";

/**
 * The one-hop carry: a sentence the user just typed, staged for the composer of
 * the room they are being handed off to.
 *
 * WHY THIS EXISTS. The General room can't build pages — correctly, it hands off
 * to Pages. But `consoleNav.enterRoom` switches to a FRESH thread, so the ask
 * that caused the handoff did not travel: you arrived at an empty composer and
 * retyped your own sentence. This module carries it across that one hop.
 *
 * WHY A PREFILLED COMPOSER IS RIGHT HERE, having been wrong in the onboarding
 * wizard (see `onboarding-wizard.tsx`: "a pre-filled composer turns a choice
 * into a chore — you must read someone else's sentence and clear it"). That
 * verdict is about SOMEONE ELSE'S sentence — a sample ask the product chose,
 * dropped on top of one-click chips that were strictly faster. This is the
 * user's OWN sentence, typed seconds ago, still fully editable, and the
 * alternative is not one click — it is retyping. Same mechanism, opposite
 * ethics. (DECISIONS 0334.)
 *
 * NEVER AUTO-SENDS. Landing in a new room mid-turn is the user's decision to
 * make; we hand them their words back, not a running agent.
 *
 * CONSUMED ONCE, THEN GONE — the write/recall/forget discipline of
 * `page-lock.ts`, but stricter: {@link takeComposerPrefill} forgets the staged
 * sentence on EVERY call, match or not, so a hop to a different room than the
 * one staged drops it rather than leaving it to surface later. A stale sentence
 * reappearing is a worse bug than the empty composer this fixes.
 *
 * DELIBERATELY IN-MEMORY — no sessionStorage, no localStorage. The hop it serves
 * is a single SPA navigation, so module state is enough; and because nothing is
 * persisted, the sentence CANNOT survive a reload. That is the whole point: a
 * stored prefill would need a "was this consumed?" flag to avoid re-applying
 * itself after a refresh, which is precisely the cached-state pattern behind
 * issues #10/#11. There is no storage here to fail, so private mode, disabled
 * storage and a quota error all behave identically to a normal session.
 */

/** A sentence waiting for one specific room's composer. */
type StagedPrefill = { studio: StudioKey; text: string };

/**
 * A composer's worth of text. Long enough for any real ask; a cap at all so a
 * runaway paste can't be carried into another room.
 */
const MAX_LENGTH = 2000;

let staged: StagedPrefill | null = null;

/**
 * Stage the user's sentence for `studio`'s composer, replacing anything already
 * staged. Empty/whitespace text stages nothing (and clears a previous stage).
 *
 * Call this immediately BEFORE the navigation: the composer being filled belongs
 * to the fresh thread `enterRoom` opens, not to the thread we're leaving.
 */
export function stageComposerPrefill(studio: StudioKey, text: string): void {
  const trimmed = text.trim();
  staged = trimmed ? { studio, text: trimmed.slice(0, MAX_LENGTH) } : null;
}

/**
 * Take the sentence staged for `studio` — and forget the stage either way.
 *
 * @return the staged sentence, or null when nothing was staged for this room.
 */
export function takeComposerPrefill(studio: StudioKey): string | null {
  const held = staged;
  staged = null;
  return held && held.studio === studio ? held.text : null;
}

/** Drop anything staged (no navigation happened after all). */
export function forgetComposerPrefill(): void {
  staged = null;
}
