import { useState } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { DocumentIcon, PaletteIcon } from "./icons";
import { setBrandOverride, setPendingFonts } from "./brand-state";
import { ensureStudio } from "./flow";
import { consoleNav } from "./console-nav";

/**
 * Design-token admission card — the confirm seam for an attached design file.
 *
 * When the operator attaches a `design.md`, the turn preparer folds its text
 * into the turn as fenced DATA; the design agent maps it to design tokens and
 * calls `aincient_brand:propose_design_tokens`, which emits a
 * `{ "__widget__": "design_token_admission", "payload": … }` envelope. The
 * dispatcher harvests it and renders THIS card. Nothing is applied automatically
 * (DECISIONS 0350/0368 — a design file's content is inherently instruction-
 * shaped, so admission is proposal-only): the operator drives it.
 *
 *   Preview        → seed the shared unsaved-draft store (brand-state.ts), the
 *                    SAME draft the studio sliders write, so the preview reskins
 *                    live. Writes nothing to the site. Repeatable.
 *   Just this turn → dismiss without previewing; the tokens already reached the
 *                    model as this turn's context.
 *
 * There is deliberately NO "apply/publish" button here: the site has exactly ONE
 * global write — the studio's Publish button — and this card must not become a
 * second one. Preview stages the tokens as the studio draft; the human Publishes
 * from the studio as they would any other change (DECISIONS 0369).
 *
 * A historical card (replayed from storage on load/thread-switch, tagged
 * `__historical` by the adapter) is read-only history — it applies nothing and
 * offers no actions, so re-opening a thread never mutates the live draft.
 */

export type DesignTokenAdmissionPayload = {
  /** css_var-keyed tokens for the live preview draft. */
  tokens?: Record<string, string>;
  fonts?: string[];
  count?: number;
  rejected?: string[];
  /** The file these tokens were extracted from, for the card heading. */
  source_filename?: string;
  summary?: string;
  /** Set by the adapter on replayed cards — read-only, applies nothing. */
  __historical?: boolean;
};

export function DesignTokenAdmission(payload: DesignTokenAdmissionPayload) {
  const { tokens, fonts, rejected, source_filename } = payload;
  const count = payload.count ?? (Object.keys(tokens ?? {}).length + (fonts?.length ?? 0));
  const historical = payload.__historical === true;

  // idle → previewed keeps Preview repeatable; dismissed is terminal. Local so a
  // transcript re-render can't re-fire it.
  const [state, setState] = useState<"idle" | "previewed" | "dismissed">("idle");

  const nothingToApply = count === 0;

  const preview = () => {
    for (const [cssVar, value] of Object.entries(tokens ?? {})) {
      if (typeof value === "string") setBrandOverride(cssVar, value);
    }
    if (fonts && fonts.length) setPendingFonts(fonts);
    ensureStudio("design_system");
    consoleNav.adoptRoom({ kind: "studio", studio: "design_system" });
    setState("previewed");
  };

  const label = source_filename && source_filename !== "the attached file"
    ? source_filename
    : "Attached design file";

  return (
    <div className="ain-tokenadmit" data-state={state} data-historical={historical ? "" : undefined}>
      <div className="ain-tokenadmit__head">
        <span className="ain-tokenadmit__ico" aria-hidden="true">
          <DocumentIcon />
        </span>
        <div className="ain-tokenadmit__heading">
          <span className="ain-tokenadmit__title">{label}</span>
          <span className="ain-tokenadmit__count">
            {count} design token{count === 1 ? "" : "s"} found
          </span>
        </div>
      </div>

      {rejected && rejected.length > 0 && (
        <p className="ain-tokenadmit__rejected">Skipped invalid: {rejected.join(", ")}</p>
      )}

      {state === "previewed" && (
        <p className="ain-tokenadmit__result">Previewing in the studio — click <strong>Publish</strong> there to save.</p>
      )}
      {state === "dismissed" && (
        <p className="ain-tokenadmit__result">Kept for this turn only — nothing was previewed.</p>
      )}

      {!historical && state !== "dismissed" && (
        <div className="ain-tokenadmit__actions">
          <button
            type="button"
            className="ain-btn ain-topbtn"
            onClick={() => setState("dismissed")}
          >
            Just this turn
          </button>
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={preview}
            disabled={nothingToApply}
          >
            <PaletteIcon /> {state === "previewed" ? "Previewing" : "Preview"}
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Registers the design-token admission card for the `design_token_admission`
 * tool. Mount once inside the AssistantRuntimeProvider; `args` is the payload the
 * dispatcher passed through as the tool call's arguments.
 */
export const DesignTokenAdmissionToolUI = makeSafeAssistantToolUI<DesignTokenAdmissionPayload, unknown>({
  toolName: "design_token_admission",
  render: ({ args }) => <DesignTokenAdmission {...args} />,
});
