import { useEffect } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import {
  getChromeDraft,
  setChromeDraft,
  requestChromeReset,
  type ChromeDraft,
} from "./globals-state";
import { ensureStudio } from "./flow";
import { consoleNav } from "./console-nav";

/**
 * Live chrome preview tool — the Globals (chrome) agent's primary edit surface,
 * the chrome parallel of brand-preview-tool / page-preview-tool.
 *
 * The `aincient_pages:preview_chrome` capability emits a
 * `{ "__widget__": "chrome_preview", "payload": … }` envelope; the dispatcher
 * harvests it and renders this card inline. Applying it writes NOTHING to the
 * live site: it MERGES the partial into the SAME unsaved chrome draft the Globals
 * rail edits (globals-state.ts), so the header/footer preview re-renders instantly
 * and the change shows as an unsaved edit in the studio. The one deliberate global
 * write stays the studio's Publish button.
 *
 * The payload is a true PARTIAL (only the fields the agent set):
 *   identity — { guidelines?: { name?, tagline?, description?, tone? }, footer_note? }
 *   layout   — { header?: { logo_position?, sticky?, nav_alignment? },
 *               footer?: { layout?, show_tagline? } }
 *   reset    — revert the whole draft to the saved chrome
 *
 * MENUS are not in the agent's scope (the inline menu editor owns them), so this
 * never touches `draft.menus`. Merged once per tool call (guarded). The studio
 * subscribes to draft changes, so the agent's edit also flows into the rail
 * fields + the unsaved-change count.
 */

export type ChromePreviewPayload = {
  identity?: {
    guidelines?: Partial<ChromeDraft["identity"]["guidelines"]>;
    footer_note?: string;
  };
  layout?: {
    header?: Record<string, string | boolean>;
    footer?: Record<string, string | boolean>;
  };
  reset?: boolean;
  rejected?: string[];
  /** A one-line framing sentence (also sent as the tool summary). */
  summary?: string;
};

/** Tool calls whose ops we've already applied this page-session (de-dupe). */
const applied = new Set<string>();

/** A minimal empty draft, used only if the agent edits before the studio seeds. */
function emptyDraft(): ChromeDraft {
  return {
    chrome: { header: {}, footer: {} },
    identity: {
      guidelines: { name: "", tagline: "", description: "", tone: "", imagery_style: "", imagery_avoid: "" },
      footer_note: "",
      logo: "",
      favicon: "",
      // Site information is editor-only (like menus) — the agent never sets it,
      // but the skeleton must carry the full ChromeIdentity shape.
      site: { mail: "", front: "", page_403: "", page_404: "" },
    },
    privacy: { font_delivery: "google" },
    menus: { main: [], footer: [] },
  };
}

/** Merge one chrome preview op into the shared draft store. */
function applyOps(payload: ChromePreviewPayload): void {
  if (payload.reset) {
    // Only the studio holds the saved baseline — ask it to revert.
    requestChromeReset();
    return;
  }
  // Build on the current draft (the studio seeds it on mount); fall back to a
  // skeleton on the rare cold path (the studio adopts it once it mounts).
  const cur = getChromeDraft() ?? emptyDraft();
  const next: ChromeDraft = JSON.parse(JSON.stringify(cur));

  const g = payload.identity?.guidelines;
  if (g) {
    for (const k of ["name", "tagline", "description", "tone"] as const) {
      if (typeof g[k] === "string") next.identity.guidelines[k] = g[k] as string;
    }
  }
  if (typeof payload.identity?.footer_note === "string") {
    next.identity.footer_note = payload.identity.footer_note;
  }

  for (const sec of ["header", "footer"] as const) {
    const incoming = payload.layout?.[sec];
    if (incoming) next.chrome[sec] = { ...next.chrome[sec], ...incoming };
  }

  setChromeDraft(next);
}

/** Count the leaf changes the payload carries, for the card label. */
function changeCount(payload: ChromePreviewPayload): number {
  let n = 0;
  const g = payload.identity?.guidelines;
  if (g) n += Object.keys(g).length;
  if (typeof payload.identity?.footer_note === "string") n++;
  for (const sec of ["header", "footer"] as const) {
    const s = payload.layout?.[sec];
    if (s) n += Object.keys(s).length;
  }
  return n;
}

function ChromePreviewCard({ payload, toolCallId }: { payload: ChromePreviewPayload; toolCallId: string }) {
  // Apply once per tool call; opening the studio ensures the preview is visible.
  useEffect(() => {
    if (applied.has(toolCallId)) return;
    applied.add(toolCallId);
    ensureStudio("globals");
    applyOps(payload);
    // Catch the machine up to the studio the agent yanked to (no thread switch).
    consoleNav.adoptRoom({ kind: "studio", studio: "globals" });
  }, [payload, toolCallId]);

  const count = changeCount(payload);
  const label = payload.reset
    ? "Reverted the preview to the saved chrome"
    : `Applied to preview · ${count} change${count === 1 ? "" : "s"}`;

  return (
    <div className="ain-brandprev">
      <span className="ain-brandprev__dot" aria-hidden="true" />
      <div className="ain-brandprev__body">
        <span className="ain-brandprev__label">{label}</span>
        <span className="ain-brandprev__hint">Preview only — Publish in the studio to apply it site-wide</span>
        {payload.rejected && payload.rejected.length > 0 && (
          <span className="ain-brandprev__rejected">Skipped invalid: {payload.rejected.join(", ")}</span>
        )}
      </div>
    </div>
  );
}

/**
 * Registers the live preview tool for the `chrome_preview` tool name. Mount once
 * inside the AssistantRuntimeProvider; `args` is the payload the dispatcher
 * passed through, `toolCallId` keys the apply-once guard.
 */
export const ChromePreviewToolUI = makeSafeAssistantToolUI<ChromePreviewPayload, unknown>({
  toolName: "chrome_preview",
  render: ({ args, toolCallId }) => <ChromePreviewCard payload={args ?? {}} toolCallId={toolCallId} />,
});
