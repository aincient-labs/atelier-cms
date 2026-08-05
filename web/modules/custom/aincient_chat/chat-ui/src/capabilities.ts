/**
 * The capability data layer (no React) — what this install can DO, as the server
 * computed it.
 *
 * A LEAF module, like `console-config.ts`: it reads `window.aincientChat`
 * directly and imports nothing app-side, so the chip row, the Media studio and
 * the settings type can all depend on it without a cycle.
 *
 * THE BUNDLE DERIVES NOTHING. The three verbs (Write / Describe / Draw) are
 * computed once server-side (`Drupal\aincient_core\InstallCapabilities`), because
 * the SAME booleans also generate the capability block appended to an agent's
 * system prompt. Recomputing them here is how a lit chip and a model that says it
 * cannot draw come to disagree — which is the bug the chips exist to prevent, one
 * level up. So this module only reads and shapes.
 *
 * Each verb has exactly TWO states: available, or dim (with a reason). There is
 * deliberately no "probably works" — a lit chip is a promise. Dim comes from one
 * of two independent facts, and the row renders them differently: the install
 * has no provider (`hint`, fixable, links to the wizard), or the room's agent has
 * no tool for it (`unusedHint`, a closed door, links nowhere). WHICH verbs a room
 * shows at all is the studio's business — see `capability-chips.tsx`.
 */

/** One capability chip, exactly as the shell injected it. */
export type CapabilityChip = {
  /** Stable verb id: "write" | "describe" | "draw". */
  id: string;
  /** The verb, in product language ("Draw"). */
  label: string;
  /** What it means for the user — shown as the chip's title/tooltip. */
  means: string;
  /** Available (a promise this install keeps) or needs-setup. */
  available: boolean;
  /** Why the INSTALL can't, in a few words. Empty when available. */
  hint: string;
  /**
   * Why THIS ROOM doesn't — the other reason a chip is dim: the agent has no
   * tool that spends the verb. Independent of install state, so always sent.
   */
  unusedHint: string;
  /** Where an operator fixes it (the onboarding wizard). Empty when available. */
  setupUrl: string;
};

/**
 * The chips to render, in server order.
 *
 * Empty when the shell injected nothing — an un-rebuilt shell or a dev harness
 * with no settings. Rendering three dimmed chips there would be an alarm raised
 * from ignorance; saying nothing is the honest answer to a question we were not
 * given.
 */
export function capabilityChips(): CapabilityChip[] {
  const w = window as unknown as {
    aincientChat?: { capabilities?: CapabilityChip[] };
    drupalSettings?: { aincientChat?: { capabilities?: CapabilityChip[] } };
  };
  const chips =
    (w.aincientChat ?? w.drupalSettings?.aincientChat ?? {}).capabilities;
  return Array.isArray(chips) ? chips : [];
}

/**
 * Whether a verb is available here — for the few places that shape an INVITATION
 * around it (the Library shelf hint offering "ask the chat for a new image").
 *
 * Never a gate on reaching a room or taking a turn: capability decides display
 * only, and every tool reports its own failure. Unknown (no injected payload)
 * reads as unavailable, so we never invite someone to try something we cannot
 * vouch for.
 */
export function capabilityAvailable(id: string): boolean {
  return capabilityChips().some((c) => c.id === id && c.available);
}
