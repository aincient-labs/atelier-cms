import { useSyncExternalStore } from "react";
import { EmptyState, PanelBar } from "@console/kit";
import { consoleNav, getMediaDetail, roomVersion, subscribeMedia, subscribeRoom } from "@console/sdk";
import { LibraryBrowse } from "./library-browse";

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
        <div
          className="ain-preview ain-media-preview"
          aria-label="Library"
          data-testid="studio-preview"
          data-studio="media"
          data-view="shelf"
        >
          <PanelBar title="Library" />
          <LibraryBrowse />
        </div>
      );
    }
    // The id-less "new image" room has nothing to load yet — invite a prompt
    // instead of the edit-an-existing-item hint.
    const isNew = room.kind === "media" && room.id == null;
    return (
      <div
        className="ain-preview ain-media-preview"
        aria-label="Media preview"
        data-testid="studio-preview"
        data-studio="media"
        data-view="empty"
      >
        <PanelBar title="Image" />
        <EmptyState variant="stage">
          {isNew
            ? "Describe the image you want in the chat — it’ll appear here once generated."
            : "Open an image from the Library to edit it."}
        </EmptyState>
      </div>
    );
  }

  return (
    <div
      className="ain-preview ain-media-preview"
      aria-label="Media preview"
      data-testid="studio-preview"
      data-studio="media"
      data-view="item"
    >
      <PanelBar title="Image" />
      <div className="ain-preview__stage ain-media-preview__stage">
        <img className="ain-media-preview__img" src={detail.preview} alt={detail.alt} />
      </div>
      <dl className="ain-media-preview__facts">
        {detail.name && (
          <div className="ain-media-preview__fact">
            <dt>Name</dt>
            <dd>{detail.name}</dd>
          </div>
        )}
        {detail.width > 0 && detail.height > 0 && (
          <div className="ain-media-preview__fact">
            <dt>Dimensions</dt>
            <dd>{detail.width}×{detail.height}</dd>
          </div>
        )}
        {detail.mime && (
          <div className="ain-media-preview__fact">
            <dt>Type</dt>
            <dd>{detail.mime}</dd>
          </div>
        )}
        <div className="ain-media-preview__fact">
          <dt>Token</dt>
          <dd><code>{detail.token}</code></dd>
        </div>
      </dl>
    </div>
  );
}
