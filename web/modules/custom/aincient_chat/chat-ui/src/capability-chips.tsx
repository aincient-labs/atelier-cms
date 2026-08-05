import type { ReactNode, SVGProps } from "react";
import { capabilityChips, type CapabilityChip } from "./capabilities";
import { EyeIcon, ImageIcon, PenIcon } from "./icons";

/**
 * The capability row above the composer — what THIS room can DO.
 *
 * PRESENT IN EVERY ROOM, ALWAYS — not only when something is missing, and not
 * only in Media. That is the whole design: "rooms have capabilities" is a
 * learnable part of the console, and a user only learns it on a HEALTHY install,
 * where the chips are lit and calm. A dimmed chip later reads as information
 * instead of an error, because the row was already familiar.
 *
 * THREE INPUTS, in this order — the row answers a question about the room you
 * are standing in, not about the site:
 *
 *  1. THE STUDIO decides which verbs appear at all (`studioVerbs`, the union of
 *     its agents'). General has no image tool anywhere in it, so it never raises
 *     the subject of pictures — it used to show "Draw — needs an image provider",
 *     which asked the user to go fix something that would change nothing here.
 *  2. THE AGENT decides whether an appearing chip is live: the assistant you are
 *     actually talking to either wires a tool that spends the verb or does not
 *     (`agentVerbs`). Dim, with `unusedHint`, and linking nowhere — there is
 *     nothing to fix, it is a fact about the room.
 *  3. THE INSTALL decides the rest: no provider/model behind the verb means dim
 *     with `hint`, and (for an admin) a link to the wizard that fixes it.
 *
 * Two visual states, never three: lit, or dim-with-an-explanation. What varies
 * between reasons 2 and 3 is the sentence and whether it is a link.
 *
 * Unscoped input (an un-rebuilt shell sending no verbs) renders the whole row —
 * hiding chips from ignorance would be the same mistake in the other direction.
 *
 * Icons are inline SVGs from `icons.tsx`, never emoji (project rule).
 */

const GLYPH: Record<string, (p: SVGProps<SVGSVGElement>) => ReactNode> = {
  write: PenIcon,
  describe: EyeIcon,
  draw: ImageIcon,
};

function Chip({ chip, usable }: { chip: CapabilityChip; usable: boolean }) {
  const Glyph = GLYPH[chip.id];
  // The agent's silence outranks the install's: telling someone to connect a
  // provider for a tool this room does not have is a fix that changes nothing.
  const hint = !usable ? chip.unusedHint : chip.available ? "" : chip.hint;
  const live = usable && chip.available;
  // The two dim reasons LOOK different, because they mean different things to
  // the person reading them: struck through = this room will never do it, so
  // stop waiting for it; merely faded = the site could, once someone connects a
  // provider — a "not yet", which is why it is the state that carries a link.
  const className =
    "ain-cap " +
    (live ? "ain-cap--on" : !usable ? "ain-cap--unused" : "ain-cap--setup");
  // The tooltip is the meaning when the promise holds, and the reason when it
  // does not — one line, never both, so a hover is never a paragraph.
  const title = live ? chip.means : `${chip.means} — ${hint}`;
  const body = (
    <>
      {Glyph ? <Glyph className="ain-cap__glyph" /> : null}
      <span className="ain-cap__label">{chip.label}</span>
      {live ? null : <span className="ain-cap__hint"> — {hint}</span>}
    </>
  );
  // Only an install gap is fixable, and only by an admin (the server sends an
  // empty setupUrl to everyone else).
  if (!live && usable && chip.setupUrl) {
    return (
      <a className={className} href={chip.setupUrl} title={title}>
        {body}
      </a>
    );
  }
  return (
    <span className={className} title={title}>
      {body}
    </span>
  );
}

/**
 * The row. Renders nothing when the shell injected no capabilities (an
 * un-rebuilt shell / dev harness) — see `capabilities.ts` — and nothing when the
 * studio raises no verbs at all.
 *
 * @param studioVerbs
 *   The verbs this studio shows; undefined means unscoped (show everything).
 * @param agentVerbs
 *   The verbs the room's own agent spends; undefined means unscoped (every
 *   shown chip is the agent's business, so only install state dims it).
 */
export function CapabilityChips({
  studioVerbs,
  agentVerbs,
}: {
  studioVerbs?: string[] | undefined;
  agentVerbs?: string[] | undefined;
}) {
  const shown = capabilityChips().filter(
    (c) => studioVerbs === undefined || studioVerbs.includes(c.id),
  );
  if (shown.length === 0) return null;
  return (
    <div className="ain-caps" role="group" aria-label="What this room can do">
      {shown.map((chip) => (
        <Chip
          key={chip.id}
          chip={chip}
          usable={agentVerbs === undefined || agentVerbs.includes(chip.id)}
        />
      ))}
    </div>
  );
}
