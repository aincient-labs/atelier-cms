import { useEffect } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { getPageDraft, setPageDraft, EMPTY_PAGE, type PageSchema } from "./page-state";
import { activeStudioKey, ensureStudio } from "./flow";
import { consoleNav, deriveRoomFromStores } from "./console-nav";
import { apiUrl } from "./console-config";

/**
 * Live page preview tool — the page agent's only edit surface.
 *
 * The `aincient_pages:preview_page` capability emits a
 * `{ "__widget__": "page_preview", "payload": { ops, rejected } }` envelope; the
 * dispatcher harvests it and renders this card inline. A capability tool can't
 * read the turn's draft (no `__data__` access), so it ships the OPS, not a final
 * schema — the browser holds the authoritative draft and applies the ops here by
 * POSTing { schema, ops } to /atelier/page/apply (which runs
 * PageStore::applyOps + validate, the same guardrail Publish uses). The returned
 * validated schema becomes the new draft, so the preview recomposes instantly
 * and shows as an unsaved edit in the studio. Nothing persists — the one
 * deliberate write stays the studio's Publish button.
 *
 * Page ops (add_section, reorder, …) are NOT idempotent, so replaying them onto a
 * non-empty draft double-applies. Whether a reloaded thread SHOULD replay depends
 * on how its preview is otherwise sourced:
 *   - HOMED thread (ever saved) → its node room loads the saved page from the
 *     server on entry (loadPageIntoStudio), so the historical cards are read-only
 *     (guarded below via `__homed`) — replaying would double-apply + reskin.
 *   - UN-HOMED draft (never saved) → no server copy, so replaying its ops from the
 *     empty draft is the ONLY thing that rebuilds the preview, and must run.
 * A live card (the agent acting now) is never `__historical`, so it always applies.
 * When a replay does run it is:
 *   - guarded once per tool call (de-dupe within a session), and
 *   - SERIALISED through a promise chain, so replaying a thread's ops in order
 *     from the empty draft deterministically reconstructs the cumulative draft
 *     regardless of per-request latency (the POSTs can't race out of order).
 */
const APPLY_URL = apiUrl("/page/apply");

export type PageOp = Record<string, unknown>;

export type PagePreviewPayload = {
  ops?: PageOp[];
  rejected?: string[];
  /** A one-line framing sentence (also sent as the tool summary). */
  summary?: string;
  /** Set by the adapter on a card replayed from storage (not the live stream). */
  __historical?: boolean;
  /**
   * Set by the adapter alongside `__historical`: this thread homes to a saved
   * page. A homed thread's saved page is loaded from the server when its node
   * room is entered, so replaying these ops on top would double-apply (page ops
   * aren't idempotent) and reskin — a historical+homed card is therefore
   * read-only. An un-homed (never-saved) draft has no server copy, so its replay
   * is the only thing that rebuilds the preview and must still run.
   */
  __homed?: boolean;
};

/** Tool calls whose ops we've already applied this page-session (de-dupe). */
const applied = new Set<string>();

/** Serialises applies so ops land in thread order even if a POST is slow. */
let chain: Promise<unknown> = Promise.resolve();

/** POST the ops against the current draft and write the validated result back. */
async function applyOps(ops: PageOp[]): Promise<void> {
  if (!ops || ops.length === 0) return;
  const schema = getPageDraft() ?? EMPTY_PAGE;
  const res = await fetch(APPLY_URL, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ schema, ops }),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = (await res.json()) as { schema?: PageSchema };
  if (data.schema) setPageDraft(data.schema);
}

function PagePreviewCard({ payload, toolCallId }: { payload: PagePreviewPayload; toolCallId: string }) {
  // Apply once per tool call; opening a page studio ensures the preview is
  // visible. Keep the current page-editing studio — the Checks repair agent
  // stages into the SAME shared draft, so switching it to Content would yank the
  // user out of the fix loop. Only open Content when the agent ran from a
  // non-page studio (e.g. General chat), where there's no preview showing yet.
  useEffect(() => {
    // A historical card whose thread homes to a saved page is read-only: entering
    // the thread's node room already loaded the authoritative saved page from the
    // server, so re-applying these ops would double-apply (page ops aren't
    // idempotent) and yank the studio. Only an un-homed draft's replay rebuilds
    // the preview (no server copy) — and a LIVE card (not historical) always
    // applies, so the agent's in-flight edits still land. See adapter.ts (__homed).
    if (payload.__historical && payload.__homed) return;
    if (applied.has(toolCallId)) return;
    applied.add(toolCallId);
    if (activeStudioKey() !== "checks") ensureStudio("content");
    // Catch the machine up to wherever the studio+draft now sit WITHOUT switching
    // the conversation or reloading — but only AFTER the ops apply. deriveRoomFromStores
    // reads the draft's sections to tell a node-less draft (draft room) from an
    // empty listing; adopting before applyOps resolves would see sections:0 and
    // wrongly land on the List, clobbering the draft room (incl. on thread reload,
    // where replaying these ops is what rebuilds the draft).
    chain = chain
      .then(() => applyOps(payload.ops ?? []))
      .then(() => consoleNav.adoptRoom(deriveRoomFromStores()))
      .catch(() => {});
  }, [payload, toolCallId]);

  const count = payload.ops?.length ?? 0;
  const label = `Applied to preview · ${count} edit${count === 1 ? "" : "s"}`;

  return (
    <div className="ain-brandprev">
      <span className="ain-brandprev__dot" aria-hidden="true" />
      <div className="ain-brandprev__body">
        <span className="ain-brandprev__label">{label}</span>
        <span className="ain-brandprev__hint">Preview only — Publish in the studio to save the page</span>
        {payload.rejected && payload.rejected.length > 0 && (
          <span className="ain-brandprev__rejected">Skipped: {payload.rejected.join(" ")}</span>
        )}
      </div>
    </div>
  );
}

/**
 * Registers the live preview tool for the `page_preview` tool name. Mount once
 * inside the AssistantRuntimeProvider; `args` is the payload the dispatcher
 * passed through, `toolCallId` keys the apply-once guard.
 */
export const PagePreviewToolUI = makeSafeAssistantToolUI<PagePreviewPayload, unknown>({
  toolName: "page_preview",
  render: ({ args, toolCallId }) => <PagePreviewCard payload={args ?? {}} toolCallId={toolCallId} />,
});
