import { useEffect, useState } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { loadMediaIntoStudio, setEditSource, stageAltProposal, stageNameProposal } from "./media-state";
import { consoleNav } from "./console-nav";
import { ImageIcon } from "./icons";

/**
 * Media AI result card — the Media studio's one AI widget, in three modes.
 *
 * The `generate_image` capability (text→image / image→image via Nano Banana)
 * mints a NEW `image` media item; `generate_alt_text` (vision) SUGGESTS alt text
 * without saving. Both emit a `{ "__widget__": "media_result", "payload": … }`
 * envelope; the dispatcher unwraps it into a `media_result` tool-call frame whose
 * `arguments` ARE the payload.
 *
 * Following the product invariant ("AI proposes, you approve"), the card routes the
 * result INTO the human's editing surface rather than leaving it as a chat-only
 * artifact — mirroring the page agent staging a draft the human Publishes:
 *   - generate/edit: on arrival, auto-opens the new item into the editor rail (so
 *     its name/alt are populated and Save-ready); for an edit it also records the
 *     source token, which lights up the rail's "Replace original" action. The image
 *     is already saved (generation is expensive — never discarded); the human
 *     refines, Replaces the original, or Deletes it from the rail.
 *   - alt_text: on arrival, stages the suggestion into the OPEN item's alt field
 *     (dirty, unsaved) for the human to review and Save. Nothing is persisted.
 *
 * The card itself is a compact record of what happened. Defensive: the payload is
 * workflow-shaped, so it renders only what's present and bails to null without a
 * usable preview (the summary text still stands in).
 */

export type MediaResultPayload = {
  id?: string;
  token?: string;
  name?: string;
  alt?: string;
  /** Display-sized source URL (MediaRepository::detail preview), else the thumb. */
  preview?: string;
  thumb?: string;
  width?: number;
  height?: number;
  mime?: string;
  /** "generate"/"edit" (make pixels), "alt_text"/"propose_name" (read pixels). */
  mode?: "generate" | "edit" | "alt_text" | "propose_name";
  /** The source token when this was an edit / alt-text / naming pass, else null. */
  source?: string | null;
  /** The written alt text, present in "alt_text" mode. */
  alt_text?: string;
  /** The proposed name, present in "propose_name" mode. */
  proposed_name?: string;
  /** Set by the adapter on a card replayed from storage (not the live stream). */
  __historical?: boolean;
};

/** Tool calls already routed into the studio this page-session (de-dupe). */
const applied = new Set<string>();

function MediaResultCard({ payload, toolCallId }: { payload: MediaResultPayload; toolCallId: string }) {
  const src = payload.preview || payload.thumb;
  const id = payload.id;
  const [opening, setOpening] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Route the result into the human's editing surface ONCE, on LIVE arrival only.
  // A HISTORICAL card (stamped by the adapter when a stored transcript is replayed
  // on load / thread-switch) must never re-route: re-opening a conversation is
  // reading history, not re-running it — auto-opening the old item would yank the
  // console out of whatever room the user just navigated to (clicking "Library"
  // used to land inside the last generated image this way). The manual "Open in
  // Media studio" button below stays the deliberate way back in. De-dupe is keyed
  // on toolCallId at module level (survives remounts), as page-preview-tool does.
  useEffect(() => {
    if (payload.__historical || !payload.id) return;
    if (applied.has(toolCallId)) return;
    applied.add(toolCallId);
    if (payload.mode === "alt_text") {
      // Suggestion → the open item's alt field (dirty, unsaved). Never persisted.
      if (payload.alt_text) stageAltProposal(payload.id, payload.alt_text);
      return;
    }
    if (payload.mode === "propose_name") {
      // Suggestion → the open item's Name field (dirty, unsaved). Never persisted.
      if (payload.proposed_name) stageNameProposal(payload.id, payload.proposed_name);
      return;
    }
    // generate / edit → open the new (already-saved) asset into the editor rail so
    // its name/alt are populated and Save-ready; for an edit, remember its source so
    // the rail can offer "Replace original". Failures fall back to the manual button.
    const pid = payload.id;
    const source = payload.source;
    const isEditMode = payload.mode === "edit";
    void loadMediaIntoStudio(pid)
      .then(() => {
        consoleNav.adoptRoom({ kind: "media", id: Number(pid) });
        if (isEditMode && source) setEditSource(pid, source);
      })
      .catch(() => {});
  }, [payload, toolCallId]);

  if (!src || !id) return null;

  const isEdit = payload.mode === "edit";
  const isAltText = payload.mode === "alt_text";
  const isProposeName = payload.mode === "propose_name";
  // "read pixels" modes stage a suggestion into the open item rather than mint one.
  const isSuggestion = isAltText || isProposeName;
  const title = isProposeName ? "Name suggested" : isAltText ? "Alt text suggested" : isEdit ? "Edited image" : "Generated image";

  return (
    <div className="ain-mediaresult">
      <div className="ain-mediaresult__head">
        <ImageIcon className="ain-mediaresult__icon" />
        <span className="ain-mediaresult__title">{title}</span>
        <span className="ain-mediaresult__badge">{isSuggestion ? "Added to editor" : "In your Library"}</span>
      </div>

      <div className="ain-mediaresult__stage">
        <img className="ain-mediaresult__img" src={src} alt={payload.alt || payload.name || "Image"} />
      </div>

      {isAltText && payload.alt_text && (
        <blockquote className="ain-mediaresult__alt">“{payload.alt_text}”</blockquote>
      )}
      {isProposeName && payload.proposed_name && (
        <blockquote className="ain-mediaresult__alt">“{payload.proposed_name}”</blockquote>
      )}

      <dl className="ain-mediaresult__facts">
        {payload.name && (
          <div className="ain-mediaresult__fact">
            <dt>Name</dt>
            <dd>{payload.name}</dd>
          </div>
        )}
        {typeof payload.width === "number" && payload.width > 0 && typeof payload.height === "number" && payload.height > 0 && (
          <div className="ain-mediaresult__fact">
            <dt>Dimensions</dt>
            <dd>{payload.width}×{payload.height}</dd>
          </div>
        )}
        {payload.token && (
          <div className="ain-mediaresult__fact">
            <dt>Token</dt>
            <dd><code>{payload.token}</code></dd>
          </div>
        )}
      </dl>

      <div className="ain-mediaresult__actions">
        <button
          type="button"
          className="ain-btn ain-topbtn ain-topbtn--primary"
          disabled={opening}
          onClick={async () => {
            setOpening(true);
            setError(null);
            try {
              await loadMediaIntoStudio(id);
              // Adopt the minted media as the room (no side effects — the load
              // already switched studio + set the doc), so the URL becomes
              // /media/image/<id> and a refresh re-opens it. This turns an id-less
              // "new image" room into a real media room — the media analogue of a
              // draft adopting its node on first Save.
              consoleNav.adoptRoom({ kind: "media", id: Number(id) });
            } catch (e) {
              setError(e instanceof Error ? e.message : "Couldn’t open the image.");
            } finally {
              setOpening(false);
            }
          }}
        >
          {opening ? "Opening…" : isSuggestion ? "View in Media studio" : "Open in Media studio"}
        </button>
      </div>
      {error && <p className="ain-mediaresult__error" role="alert">{error}</p>}
    </div>
  );
}

/**
 * Registers the generated-image card for the `media_result` tool. Mount once
 * inside the AssistantRuntimeProvider; it renders nothing itself. `args` is the
 * payload the dispatcher passed through as the tool call's `arguments`.
 */
export const MediaResultToolUI = makeSafeAssistantToolUI<MediaResultPayload, unknown>({
  toolName: "media_result",
  render: ({ args, toolCallId }) => <MediaResultCard payload={args ?? {}} toolCallId={toolCallId} />,
});
