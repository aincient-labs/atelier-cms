import { useSyncExternalStore } from "react";
import { getMediaDetail, subscribeMedia } from "./media-state";
import { consoleNav, roomVersion, subscribeRoom } from "./console-nav";
import { LibraryBrowse } from "./library-studio";
import { PanelBar } from "./panel-bar";

/**
 * The Media studio's centre canvas — the image at real size, with its read-only
 * facts (dimensions · type · token). The visual counterpart to the editor rail
 * ({@see MediaStudio}); the split-pane's "preview" slot, mirroring how Globals /
 * Content render a live view beside their editor. Reads the open item from
 * media-state. With nothing open, the SHELF room renders the Library browser as
 * this canvas (DECISIONS 0168) — exactly as PagePreview renders ContentBrowser
 * in the Content list room.
 */
export function MediaPreview() {
  const detail = useSyncExternalStore(subscribeMedia, getMediaDetail);
  // Room-reactive: shelf ↔ item navigation keeps the same studio (no remount),
  // so the empty-state branch below must re-read the room when it changes.
  useSyncExternalStore(subscribeRoom, roomVersion);

  if (!detail) {
    const room = consoleNav.room();
    // The shelf — the family's browse room: the Library ledger IS the canvas.
    // The PanelBar keeps the pane-eyebrow row aligned across the workspace
    // (chat · centre · rail), exactly as PagePreview's does in the list room.
    if (room.kind === "shelf") {
      return (
        <div className="ain-preview ain-mediapreview" aria-label="Library">
          <PanelBar title="Library" />
          <LibraryBrowse />
        </div>
      );
    }
    // The id-less "new image" room has nothing to load yet — invite a prompt
    // instead of the edit-an-existing-item hint.
    const isNew = room.kind === "media" && room.id == null;
    return (
      <div className="ain-preview ain-mediapreview" aria-label="Media preview">
        <PanelBar title="Image" />
        <div className="ain-pagepreview__empty">
          {isNew
            ? "Describe the image you want in the chat — it’ll appear here once generated."
            : "Open an image from the Library to edit it."}
        </div>
      </div>
    );
  }

  return (
    <div className="ain-preview ain-mediapreview" aria-label="Media preview">
      <PanelBar title="Image" />
      <div className="ain-preview__stage ain-mediapreview__stage">
        <img className="ain-mediapreview__img" src={detail.preview} alt={detail.alt} />
      </div>
      <dl className="ain-mediapreview__facts">
        {detail.name && (
          <div className="ain-mediapreview__fact">
            <dt>Name</dt>
            <dd>{detail.name}</dd>
          </div>
        )}
        {detail.width > 0 && detail.height > 0 && (
          <div className="ain-mediapreview__fact">
            <dt>Dimensions</dt>
            <dd>{detail.width}×{detail.height}</dd>
          </div>
        )}
        {detail.mime && (
          <div className="ain-mediapreview__fact">
            <dt>Type</dt>
            <dd>{detail.mime}</dd>
          </div>
        )}
        <div className="ain-mediapreview__fact">
          <dt>Token</dt>
          <dd><code>{detail.token}</code></dd>
        </div>
      </dl>
    </div>
  );
}
