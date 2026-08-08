import { makeSafeAssistantToolUI } from "./error-boundary";
import { ImageIcon, ArrowRightIcon } from "./icons";
import { focusStudioField } from "./studio-field-anchor";
import { ensureStudio } from "./flow";
import { consoleNav } from "./console-nav";

/**
 * Logo handoff card — routes an attached image to Identity → Logo (DECISIONS
 * 0372, Phase 3).
 *
 * The loud dead end: an operator drags a logo/brand mark into chat and says
 * "use this as my logo". The image is described to the model then its bytes are
 * discarded, and there is NO tool that sets a logo/favicon image — a chat turn
 * cannot place a file into a media field. The `aincient_brand:route_logo_image`
 * capability recognises the ask and emits a
 * `{ "__widget__": "logo_handoff", "payload": { asset } }` envelope; the
 * dispatcher harvests it and renders THIS card. It PLACES nothing — its only
 * action is a client-side navigation the human then completes.
 *
 * The deep link is resolved AT THE CONSOLE, not baked into agent text: the card
 * opens the Identity studio (`design_system`) through {@link consoleNav} — same
 * in-place hop the design-token admission card uses, keeping the current
 * conversation — then focuses the field by its stable anchor via
 * {@link focusStudioField} (`identity.logo` / `identity.favicon`, the ids the
 * Identity rail stamps; see `studio-field-anchor.ts`). No path string, no base:
 * the studio registry + the field anchor own the destination.
 *
 * A historical card replayed from storage on reload/thread-switch (tagged
 * `__historical` by the adapter) is read-only — it still offers the button
 * because navigating to a studio is idempotent and side-effect-free.
 */

export type LogoHandoffPayload = {
  /** Which brand image the operator wanted to place. */
  asset?: "logo" | "favicon";
  __historical?: boolean;
};

/** The Identity studio (the "Identity" surface still rides the design_system id). */
const IDENTITY_STUDIO = "design_system" as const;

export function LogoHandoff(payload: LogoHandoffPayload) {
  const asset = payload.asset === "favicon" ? "favicon" : "logo";
  const fieldKey = asset === "favicon" ? "identity.favicon" : "identity.logo";
  const label = asset === "favicon" ? "Favicon" : "Logo";

  const open = () => {
    // In-place hop that keeps this conversation (the studio-side change is the
    // human's, not a new thread), mirroring the design-token admission card.
    ensureStudio(IDENTITY_STUDIO);
    consoleNav.adoptRoom({ kind: "studio", studio: IDENTITY_STUDIO });
    // The Identity rail also self-focuses from `?field=` on a fresh mount; on an
    // in-place hop we drive it directly. A late mount is retried once.
    if (!focusStudioField(fieldKey)) {
      window.setTimeout(() => focusStudioField(fieldKey), 400);
    }
  };

  return (
    <div className="ain-logohandoff">
      <div className="ain-logohandoff__head">
        <span className="ain-logohandoff__ico" aria-hidden="true">
          <ImageIcon />
        </span>
        <div className="ain-logohandoff__heading">
          <span className="ain-logohandoff__title">Set your {asset} in Identity</span>
          <span className="ain-logohandoff__sub">
            Images can&rsquo;t be placed from chat — open the {label} field and drop the file in.
          </span>
        </div>
      </div>
      <div className="ain-logohandoff__actions">
        <button
          type="button"
          className="ain-btn ain-topbtn ain-topbtn--primary"
          onClick={open}
        >
          Open Identity &rarr; {label} <ArrowRightIcon />
        </button>
      </div>
    </div>
  );
}

/**
 * Registers the logo-handoff card for the `logo_handoff` tool. Mount once inside
 * the AssistantRuntimeProvider; `args` is the payload the dispatcher passed
 * through as the tool call's arguments.
 */
export const LogoHandoffToolUI = makeSafeAssistantToolUI<LogoHandoffPayload, unknown>({
  toolName: "logo_handoff",
  render: ({ args }) => <LogoHandoff {...args} />,
});
