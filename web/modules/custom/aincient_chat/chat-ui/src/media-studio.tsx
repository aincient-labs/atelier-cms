import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import type { ChangeEvent } from "react";
import { PanelBar } from "./panel-bar";
import { consoleNav, roomVersion, subscribeRoom } from "./console-nav";
import {
  clearPendingAlt,
  clearPendingName,
  deleteMedia,
  getEditSource,
  getMediaDetail,
  getPendingAlt,
  getPendingName,
  mediaVersion,
  replaceMediaFile,
  replaceOriginalWithCurrent,
  saveMediaMetadata,
  subscribeMedia,
} from "./media-state";
import { agentsForStudio } from "./studios";
import { CheckIcon, RotateCcwIcon, SpinnerIcon, TrashIcon, UploadIcon } from "./icons";

/**
 * The Media studio's editor rail — the NON-AI way to edit one image-media item.
 *
 * The right-hand rail of the split-pane (the image preview is the centre canvas):
 * a plain form to rename, retitle its alt text, and replace the underlying file.
 * No agent required — this is the always-available human path, exactly as
 * PageStudio is for pages (DECISIONS 0144). The OPTIONAL Nano Banana chat rail
 * (Phase 2) attaches as the studio's left rail once an image provider is
 * configured; this rail is unchanged by it.
 *
 * Reads/writes the open item through media-state; name and alt save independently
 * (matching the backend, which only writes the keys it's sent). Replacing the file
 * keeps the same `media:<id>` token, so every page embedding it updates in place.
 */
export function MediaStudio({ onClose: _onClose }: { onClose: () => void }) {
  const detail = useSyncExternalStore(subscribeMedia, getMediaDetail);
  // Also track a monotonic store version so an out-of-band store change that leaves
  // `detail`'s reference intact — e.g. an AI alt suggestion staged for the open item
  // — still re-renders this rail (getMediaDetail alone would bail on the same ref).
  const version = useSyncExternalStore(subscribeMedia, mediaVersion);
  // Room-reactive: shelf ↔ item navigation keeps the same studio (no remount),
  // so the shelf/new-image hint branches must re-read the room when it changes.
  useSyncExternalStore(subscribeRoom, roomVersion);
  const [name, setName] = useState(detail?.name ?? "");
  const [alt, setAlt] = useState(detail?.alt ?? "");
  const [saving, setSaving] = useState(false);
  const [replacing, setReplacing] = useState(false);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState<null | "delete" | "replace-original">(null);
  const [error, setError] = useState<string | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  // Reset the form baseline when a DIFFERENT item loads (not on same-item saves).
  const loadedId = useRef(detail?.id);

  useEffect(() => {
    if (detail && detail.id !== loadedId.current) {
      loadedId.current = detail.id;
      setName(detail.name);
      setAlt(detail.alt);
      setSaved(false);
      setError(null);
    }
  }, [detail]);

  // Apply an AI alt-text suggestion staged for the OPEN item: drop it into the alt
  // field (which makes the form dirty → Save lights up), then consume it. The human
  // reviews and Saves — the AI never persists it. Keyed on `version` so it fires when
  // the suggestion arrives, not only when a new item loads.
  useEffect(() => {
    const pending = getPendingAlt();
    if (pending && detail && pending.id === String(detail.id)) {
      setAlt(pending.alt);
      setSaved(false);
      clearPendingAlt();
    }
  }, [version, detail]);

  // The naming twin: an AI name suggestion staged for the OPEN item drops into the
  // Name field (dirty, unsaved) for the human to review + Save — never persisted.
  useEffect(() => {
    const pending = getPendingName();
    if (pending && detail && pending.id === String(detail.id)) {
      setName(pending.name);
      setSaved(false);
      clearPendingName();
    }
  }, [version, detail]);

  // "Replace original" is offered only when the open item is a generated EDIT result
  // (it was made by editing another image) — that source is what gets overwritten.
  const editSource = getEditSource();
  const canReplaceOriginal = !!detail && !!editSource && editSource.id === detail.id;

  const dirty = !!detail && (name !== detail.name || alt !== detail.alt);
  // The SHELF (browse) room and the id-less "new image" room have nothing loaded
  // yet — their hints invite a pick / a prompt rather than reading as a stuck
  // "Loading…".
  const room = consoleNav.room();
  const isShelfRoom = room.kind === "shelf";
  const isNewImageRoom = room.kind === "media" && room.id == null;
  // The shelf hint only offers the chat when the image agent actually exists
  // (the server drops it while the image role is unbound).
  const canGenerate = agentsForStudio("media").length > 0;

  const save = useCallback(async () => {
    if (!detail || !dirty) return;
    setSaving(true);
    setError(null);
    try {
      await saveMediaMetadata({ name: name.trim(), alt: alt.trim() });
      setSaved(true);
      window.setTimeout(() => setSaved(false), 1400);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  }, [detail, dirty, name, alt]);

  const onReplace = useCallback(async (file: File) => {
    setReplacing(true);
    setError(null);
    try {
      await replaceMediaFile(file);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setReplacing(false);
    }
  }, []);

  const onFile = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) void onReplace(file);
    e.target.value = "";
  };

  const onDelete = useCallback(async () => {
    if (!detail) return;
    if (!window.confirm(`Delete “${detail.name}”? This can’t be undone.`)) return;
    setBusy("delete");
    setError(null);
    try {
      await deleteMedia();
      // The item is gone — back to the shelf (the family's browse room).
      consoleNav.enterRoom({ kind: "shelf" });
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      setBusy(null);
    }
  }, [detail]);

  const onReplaceOriginal = useCallback(async () => {
    if (!canReplaceOriginal) return;
    if (!window.confirm("Replace the original image with this edit? Every page using the original updates.")) return;
    setBusy("replace-original");
    setError(null);
    try {
      await replaceOriginalWithCurrent();
      setSaved(true);
      window.setTimeout(() => setSaved(false), 1400);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  }, [canReplaceOriginal]);

  return (
    <section className="ain-studio__rail ain-mediastudio" aria-label="Edit media">
      <PanelBar
        title="Edit image"
        actions={
          <>
            {/* On the shelf the Library IS the centre canvas — no way "back". */}
            {!isShelfRoom && (
              <button
                type="button"
                className="ain-btn ain-topbtn"
                onClick={() => consoleNav.enterRoom({ kind: "shelf" })}
                title="Back to the Library"
              >
                ← Library
              </button>
            )}
            <button
              type="button"
              className="ain-btn ain-topbtn ain-topbtn--primary"
              onClick={() => void save()}
              disabled={!dirty || saving}
              title={dirty ? "Save name & alt text" : "No unsaved changes"}
            >
              {saving ? <SpinnerIcon className="ain-spin" /> : saved ? <CheckIcon /> : null}
              <span>{saved ? "Saved" : "Save"}</span>
            </button>
          </>
        }
      />

      {!detail ? (
        <p className="ain-studio__hint">
          {isShelfRoom
            ? `Pick something from the shelf — it opens here to rename, describe, or replace.${
                canGenerate ? " Or ask the chat for a new image." : ""
              }`
            : isNewImageRoom
              ? "Generate an image in the chat, then rename it, edit its alt text, or replace the file here."
              : "Loading…"}
        </p>
      ) : (
        <div className="ain-mediastudio__body">
          {error && <p className="ain-studio__error">{error}</p>}

          <label className="ain-field">
            <span className="ain-field__label">Name</span>
            <input
              className="ain-field__input"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="A short name for this image"
            />
          </label>

          <label className="ain-field">
            <span className="ain-field__label">Alt text</span>
            <textarea
              className="ain-field__input ain-field__textarea"
              value={alt}
              onChange={(e) => setAlt(e.target.value)}
              rows={3}
              placeholder="Describe the image for screen readers and SEO"
            />
          </label>

          <div className="ain-field">
            <span className="ain-field__label">File</span>
            <button
              type="button"
              className="ain-btn ain-topbtn ain-mediastudio__replace"
              onClick={() => fileInput.current?.click()}
              disabled={replacing}
              title="Replace the image file (keeps the same token — every page using it updates)"
            >
              {replacing ? <SpinnerIcon className="ain-spin" /> : <UploadIcon />}
              <span>{replacing ? "Replacing…" : "Replace file"}</span>
            </button>
            <span className="ain-field__hint">
              Swaps the picture while keeping the same reference, so pages using it update automatically.
            </span>
          </div>

          {canReplaceOriginal && (
            <div className="ain-field">
              <span className="ain-field__label">Original image</span>
              <button
                type="button"
                className="ain-btn ain-topbtn ain-mediastudio__replace"
                onClick={() => void onReplaceOriginal()}
                disabled={busy !== null}
                title="Overwrite the image this was edited from with this version (keeps its token)"
              >
                {busy === "replace-original" ? <SpinnerIcon className="ain-spin" /> : <RotateCcwIcon />}
                <span>{busy === "replace-original" ? "Replacing…" : "Replace original with this"}</span>
              </button>
              <span className="ain-field__hint">
                Applies this edit onto the image you started from, so pages already using it update in place.
              </span>
            </div>
          )}

          <div className="ain-field ain-mediastudio__danger">
            <span className="ain-field__label">Delete</span>
            <button
              type="button"
              className="ain-btn ain-topbtn ain-mediastudio__delete"
              onClick={() => void onDelete()}
              disabled={busy !== null}
              title="Delete this image"
            >
              {busy === "delete" ? <SpinnerIcon className="ain-spin" /> : <TrashIcon />}
              <span>{busy === "delete" ? "Deleting…" : "Delete image"}</span>
            </button>
            <span className="ain-field__hint">
              Removes this image for good. Pages that still reference it will show nothing in its place.
            </span>
          </div>
        </div>
      )}

      <input ref={fileInput} type="file" accept="image/*" hidden onChange={onFile} />
    </section>
  );
}
