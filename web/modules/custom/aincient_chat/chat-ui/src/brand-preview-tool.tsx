import { useEffect } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { applyBrandPreviewOps, type BrandPreviewPayload } from "./brand-preview-ops";
import { brandPreviewCardText } from "./brand-preview-card";
import { ensureStudio } from "./flow";
import { consoleNav } from "./console-nav";

/**
 * Live brand preview tool — the branding agent's primary edit surface.
 *
 * The `aincient_brand:preview_brand` capability emits a
 * `{ "__widget__": "brand_preview", "payload": … }` envelope; the dispatcher
 * harvests it and renders this card inline. Unlike a persist, applying it writes
 * NOTHING to the live site: it seeds the shared override store (brand-state.ts)
 * — the SAME unsaved-draft the brand-studio sliders write — so the user's live
 * preview reskins instantly and the change shows as an unsaved edit in the
 * studio. The one deliberate global write stays the studio's Publish button.
 *
 * The payload speaks the brand DSL the backend validated:
 *   tokens — { <css_var>: <css_value> } to layer onto the preview draft
 *   fonts  — Google family names to load in the preview iframe
 *   reset  — clear the whole draft back to the saved brand
 *
 * Ops apply once per tool call (guarded below) and ONLY for a LIVE card — one the
 * agent just emitted as it acts. A HISTORICAL card (replayed from storage on load
 * or a thread-switch, tagged `__historical` by the adapter) is read-only history:
 * it applies NOTHING. Re-opening a thread must never mutate the live studio draft
 * or reskin the preview — a stale conversation would otherwise clobber the current
 * design. The studio loads the saved brand from the server, so a reload shows the
 * published brand rather than a draft reconstructed from chat scrollback.
 */

export type { BrandPreviewPayload };

/** Tool calls whose ops we've already applied this page-session (de-dupe). */
const applied = new Set<string>();

function BrandPreviewCard({ payload, toolCallId }: { payload: BrandPreviewPayload; toolCallId: string }) {
  // Apply once per tool call; opening the studio ensures the preview is visible.
  // A historical card (replayed from storage) is read-only: it must not re-apply
  // its ops onto the live draft or yank the user into the studio on load.
  useEffect(() => {
    if (payload.__historical) return;
    if (applied.has(toolCallId)) return;
    applied.add(toolCallId);
    applyBrandPreviewOps(payload);
    ensureStudio("design_system");
    // Catch the machine up to the studio the agent yanked to (no thread switch).
    consoleNav.adoptRoom({ kind: "studio", studio: "design_system" });
  }, [payload, toolCallId]);

  const { historical, label, note } = brandPreviewCardText(payload);

  return (
    <div className={`ain-brandprev${historical ? " is-historical" : ""}`}>
      <span className="ain-brandprev__dot" aria-hidden="true" />
      <div className="ain-brandprev__body">
        <span className="ain-brandprev__label">{label}</span>
        <span className="ain-brandprev__hint">{note}</span>
        {payload.rejected && payload.rejected.length > 0 && (
          <span className="ain-brandprev__rejected">Skipped invalid: {payload.rejected.join(", ")}</span>
        )}
      </div>
    </div>
  );
}

/**
 * Registers the live preview tool for the `brand_preview` tool name. Mount once
 * inside the AssistantRuntimeProvider; `args` is the payload the dispatcher
 * passed through, `toolCallId` keys the apply-once guard.
 */
export const BrandPreviewToolUI = makeSafeAssistantToolUI<BrandPreviewPayload, unknown>({
  toolName: "brand_preview",
  render: ({ args, toolCallId }) => <BrandPreviewCard payload={args ?? {}} toolCallId={toolCallId} />,
});
