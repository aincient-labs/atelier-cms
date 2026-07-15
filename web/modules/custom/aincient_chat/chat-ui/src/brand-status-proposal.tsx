import { useState } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { CheckIcon, LockIcon, LockOpenIcon } from "./icons";
import { emitBrandStatus } from "./brand-status-state";
import { apiUrl } from "./console-config";

/**
 * Brand status proposal — the branding agent's HITL confirm card.
 *
 * The `aincient_brand:propose_brand_status` capability emits a
 * `{ "__widget__": "brand_status_proposal", "payload": … }` envelope; the
 * dispatcher harvests it out of the agent's tool results and renders this card
 * inline. The agent NEVER writes the status itself (the product invariant: "AI
 * proposes, you approve") — this card is the one seam. Confirm POSTs to
 * /atelier/brand/status, the IDENTICAL endpoint the studio's manual status
 * control uses, so the next turn's `brand_state` node reads the new value;
 * Decline is a pure no-op. Once the user acts, the card locks into a terminal
 * state so it can't be double-applied on a re-render.
 */

type BrandStatusPayload = {
  stage: string;
  locked: boolean;
  rationale?: string;
  /** What it would change FROM — drives the no-op guard. */
  current?: { stage: string; locked: boolean };
};

const STATUS_URL = apiUrl("/brand/status");

/** Human labels for the three stages (mirrors BrandRepository::STAGES). */
const STAGE_LABEL: Record<string, string> = {
  ideating: "Ideating",
  guided: "Guided",
  polish: "Polish",
};

function stageLabel(stage: string): string {
  return STAGE_LABEL[stage] ?? stage;
}

/** A compact read of a proposed status: "Polish · Locked". */
function statusLine(stage: string, locked: boolean): string {
  return locked ? `${stageLabel(stage)} · Locked` : stageLabel(stage);
}

function BrandStatusProposal(payload: BrandStatusPayload) {
  const { stage, locked, rationale, current } = payload;
  // idle → the confirm/decline choice; applied/declined → terminal; saving →
  // the POST is in flight. Kept local so a transcript re-render can't re-fire it.
  const [state, setState] = useState<"idle" | "saving" | "applied" | "declined">("idle");
  const [error, setError] = useState<string | null>(null);

  // A proposal that matches the live status changes nothing — offer it as
  // informational but disable Confirm (there's nothing to apply).
  const isNoop = !!current && current.stage === stage && current.locked === locked;

  const confirm = async () => {
    setState("saving");
    setError(null);
    try {
      const res = await fetch(STATUS_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ stage, locked }),
      });
      const result = await res.json().catch(() => null);
      if (!res.ok) throw new Error(result?.error ?? `HTTP ${res.status}`);
      // Broadcast so the studio rail's status control + badge update live.
      emitBrandStatus(result?.status ?? { stage, locked });
      setState("applied");
    } catch (e) {
      setError(`Couldn’t apply: ${e instanceof Error ? e.message : e}`);
      setState("idle");
    }
  };

  const done = state === "applied" || state === "declined";

  return (
    <div className="ain-statusprop" data-state={state}>
      <div className="ain-statusprop__head">
        <span className="ain-statusprop__ico" aria-hidden="true">
          {locked ? <LockIcon /> : <LockOpenIcon />}
        </span>
        <span className="ain-statusprop__title">Change brand status?</span>
      </div>

      <div className="ain-statusprop__change">
        {current && (current.stage !== stage || current.locked !== locked) ? (
          <>
            <span className="ain-statusprop__from">{statusLine(current.stage, current.locked)}</span>
            <span className="ain-statusprop__arrow" aria-hidden="true">→</span>
            <span className="ain-statusprop__to">{statusLine(stage, locked)}</span>
          </>
        ) : (
          <span className="ain-statusprop__to">{statusLine(stage, locked)}</span>
        )}
      </div>

      {rationale && <p className="ain-statusprop__why">{rationale}</p>}

      {state === "applied" && (
        <p className="ain-statusprop__result" data-ok>
          <CheckIcon /> Status set to {statusLine(stage, locked)}.
        </p>
      )}
      {state === "declined" && (
        <p className="ain-statusprop__result">Left the status unchanged.</p>
      )}
      {error && <p className="ain-statusprop__err">{error}</p>}

      {!done && (
        <div className="ain-statusprop__actions">
          <button
            type="button"
            className="ain-btn ain-topbtn"
            onClick={() => setState("declined")}
            disabled={state === "saving"}
          >
            Keep {current ? statusLine(current.stage, current.locked) : "current"}
          </button>
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={() => void confirm()}
            disabled={state === "saving" || isNoop}
            title={isNoop ? "This is already the current status" : undefined}
          >
            {state === "saving" ? "Applying…" : "Apply"}
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Registers the brand-status proposal card for the `brand_status_proposal` tool.
 * Mount once inside the AssistantRuntimeProvider; `args` is the payload the
 * dispatcher passed through as the tool call's arguments.
 */
export const BrandStatusProposalToolUI = makeSafeAssistantToolUI<BrandStatusPayload, unknown>({
  toolName: "brand_status_proposal",
  render: ({ args }) => <BrandStatusProposal {...args} />,
});
