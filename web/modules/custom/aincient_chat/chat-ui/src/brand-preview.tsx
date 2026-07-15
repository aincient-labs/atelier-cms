import { useCallback, useEffect, useRef, useState } from "react";
import {
  subscribeBrandOverrides,
  subscribePreviewReload,
  getBrandOverrides,
  getPendingFonts,
  type BrandOverrides,
} from "./brand-state";
import { interceptPreviewLinks, injectPreviewScrollbar, neutralizePreviewTabbing } from "./preview-nav";
import { PanelBar } from "./panel-bar";

/**
 * Google Fonts stylesheet URL for the staged families. Mirrors
 * BrandRepository::fontLinkHref so the preview loads exactly the typefaces a
 * preset will apply on Publish — the names are also validated server-side, and
 * we build the URL ourselves, so this can't carry an injected URL.
 */
function fontHref(fonts: string[] | null): string {
  if (!fonts || fonts.length === 0) return "";
  const parts = fonts
    .map((f) => f.trim())
    .filter(Boolean)
    .map((f) => `family=${f.replace(/ /g, "+")}:wght@400;500;600;700;800`);
  return parts.length ? `https://fonts.googleapis.com/css2?${parts.join("&")}&display=swap` : "";
}

// Divider travel is clamped a little inside the edges so the handle always has a
// sliver of each side to grab and neither label is fully covered.
const MIN_POS = 2;
const MAX_POS = 98;
const clampPos = (p: number) => Math.min(MAX_POS, Math.max(MIN_POS, p));

/**
 * The live brand preview: an iframe of the brand demo page, reskinned in real
 * time. Overrides are written as inline custom properties on the iframe's <html>
 * — inline styles beat the page's own :root brand block regardless of source
 * order, so the repaint is instant and the saved brand is never touched.
 *
 * Same-origin (both served from this Drupal site), so we reach contentDocument
 * directly; no postMessage handshake needed.
 *
 * Compare mode ("before/after") stacks a SECOND iframe of the same demo page
 * underneath, loaded clean so it renders the brand exactly as the server has it
 * saved. The draft frame on top is wiped away from the left with a clip so the
 * draggable divider reveals Saved on the left and Draft on the right — a direct
 * visual diff of unsaved edits against what's live. The two frames are
 * scroll-synced so the seam always lines up.
 */
export function BrandPreview() {
  const frameRef = useRef<HTMLIFrameElement>(null); // draft (front, carries the override overlay)
  const savedRef = useRef<HTMLIFrameElement>(null); // saved (back, clean server brand — compare only)
  // CSS-var keys we've written, so a cleared override removes its inline prop.
  const applied = useRef<Set<string>>(new Set());

  // Before/after compare state. `compare` is the toggle; `pos` is the divider
  // position as a percentage from the left; `hasDiff` gates the toggle (nothing
  // to compare when the draft matches the saved brand).
  const [compare, setCompare] = useState(false);
  const [pos, setPos] = useState(50);
  const [hasDiff, setHasDiff] = useState(false);
  const [dragging, setDragging] = useState(false);
  const stageRef = useRef<HTMLDivElement>(null);
  // The last scroll-Y we *programmatically* wrote into each frame. A frame's own
  // scroll handler ignores an event that lands on exactly that value — it's the
  // echo of our write, not a user scroll — which is what stops the two panes
  // fighting each other and drifting out of sync. Unlike a timed lock it can't
  // release mid-momentum (the old drift) and can't get stuck if no event fires.
  const echoY = useRef<{ draft: number; saved: number }>({ draft: -1, saved: -1 });

  const apply = useCallback((overrides: BrandOverrides) => {
    const root = frameRef.current?.contentDocument?.documentElement;
    if (!root) return;
    // Remove props that are no longer present.
    for (const key of applied.current) {
      if (!(key in overrides)) root.style.removeProperty(`--${key}`);
    }
    const next = new Set<string>();
    for (const [key, value] of Object.entries(overrides)) {
      root.style.setProperty(`--${key}`, value);
      next.add(key);
    }
    applied.current = next;
  }, []);

  // A preset stages web fonts that can't be expressed as a CSS var. Load the
  // matching Google Fonts stylesheet into the iframe so the font-family override
  // actually renders in the chosen typeface (not a fallback) before Publish.
  // Keyed by a data attribute so a cleared/discarded draft removes the link.
  const syncFonts = useCallback(() => {
    // contentDocument can exist while the iframe's document is still loading,
    // so head may be null mid-navigation — bail until it's parsed. onLoad
    // re-runs this once the document settles, so nothing is lost by bailing.
    const doc = frameRef.current?.contentDocument;
    if (!doc?.head) return;
    const href = fontHref(getPendingFonts());
    let link = doc.head.querySelector<HTMLLinkElement>("link[data-ain-fonts]");
    if (!href) {
      link?.remove();
      return;
    }
    // Re-set the font-family overrides once the web fonts finish loading. The
    // font-family token repaints synchronously, but the @font-face files arrive
    // async; without this the preview can paint the fallback and never swap —
    // the intermittent "font didn't apply" race. Re-applying after the stylesheet
    // loads (so the faces are registered) and again on fonts.ready forces the
    // chosen typeface to render.
    const reapplyWhenReady = () => {
      doc.fonts?.ready.then(() => apply(getBrandOverrides())).catch(() => {});
    };
    if (!link) {
      link = doc.createElement("link");
      link.rel = "stylesheet";
      link.setAttribute("data-ain-fonts", "");
      link.addEventListener("load", reapplyWhenReady);
      doc.head.appendChild(link);
    }
    if (link.href !== href) link.href = href;
    reapplyWhenReady();
  }, [apply]);

  // setBrandOverride and setPendingFonts both emit, so one subscription keeps
  // the token overrides and the font link in sync with the draft — and tracks
  // whether there's any diff at all (the Compare toggle's enabled state).
  useEffect(() => {
    const recompute = (ov: BrandOverrides) =>
      setHasDiff(Object.keys(ov).length > 0 || !!getPendingFonts());
    recompute(getBrandOverrides());
    return subscribeBrandOverrides((ov) => {
      apply(ov);
      syncFonts();
      recompute(ov);
    });
  }, [apply, syncFonts]);

  // A Publish (or a discard) clears the diff; there's then nothing to compare,
  // so drop back to the plain single-frame preview.
  useEffect(() => {
    if (!hasDiff && compare) setCompare(false);
  }, [hasDiff, compare]);

  // After a Publish the draft is cleared and this fires: reload the frame(s) so
  // they re-pull the freshly-saved brand from the server (the inline overlay is
  // gone, so the preview reflects the persisted brand exactly). onLoad re-applies
  // the now-empty draft + fonts.
  useEffect(
    () =>
      subscribePreviewReload(() => {
        frameRef.current?.contentWindow?.location.reload();
        savedRef.current?.contentWindow?.location.reload();
      }),
    [],
  );

  // Mirror one frame's scroll onto the other so the diff seam lines up. clip-path
  // passes pointer events through to whatever is painted underneath, so the user
  // can scroll on either side of the divider; either pane can be the source.
  const mirrorScroll = useCallback((from: "draft" | "saved") => {
    const to = from === "draft" ? "saved" : "draft";
    const fromWin = (from === "draft" ? frameRef : savedRef).current?.contentWindow;
    const toWin = (to === "draft" ? frameRef : savedRef).current?.contentWindow;
    if (!fromWin || !toWin) return;
    const y = fromWin.scrollY;
    if (echoY.current[from] === y) return; // this event is the echo of our own write — ignore
    toWin.scrollTo(fromWin.scrollX, y);
    // Record where `to` actually landed (scrollTo clamps to its own height, which
    // can differ when a density/size token changes the layout) so its upcoming
    // scroll event is recognised as the echo and not bounced back.
    echoY.current[to] = toWin.scrollY;
  }, []);

  // The draft frame reloads with the saved brand; re-apply the working diff +
  // fonts on top so the preview survives navigation/reload within the demo page.
  const onLoad = useCallback(() => {
    applied.current = new Set();
    apply(getBrandOverrides());
    syncFonts();
    // Each (re)load is a fresh document; following a link would navigate the
    // frame off /demo/brand and lose the live override overlay. Block it.
    interceptPreviewLinks(frameRef.current?.contentDocument);
    injectPreviewScrollbar(frameRef.current?.contentDocument);
    neutralizePreviewTabbing(frameRef.current?.contentDocument);
    const win = frameRef.current?.contentWindow;
    if (win) win.addEventListener("scroll", () => mirrorScroll("draft"), { passive: true });
  }, [apply, syncFonts, mirrorScroll]);

  // The saved (back) frame: a clean render of the server brand — no overrides.
  // Snap it to the draft's current scroll on load so the two start aligned.
  const onSavedLoad = useCallback(() => {
    const doc = savedRef.current?.contentDocument;
    interceptPreviewLinks(doc);
    injectPreviewScrollbar(doc);
    neutralizePreviewTabbing(doc);
    const win = savedRef.current?.contentWindow;
    const draft = frameRef.current?.contentWindow;
    if (win && draft) {
      win.scrollTo(draft.scrollX, draft.scrollY);
      echoY.current.saved = win.scrollY; // suppress the echo from this initial align
    }
    if (win) win.addEventListener("scroll", () => mirrorScroll("saved"), { passive: true });
  }, [mirrorScroll]);

  const startDrag = useCallback(() => setDragging(true), []);
  const endDrag = useCallback(() => setDragging(false), []);
  const onOverlayMove = useCallback((e: React.PointerEvent) => {
    const rect = stageRef.current?.getBoundingClientRect();
    if (!rect || rect.width === 0) return;
    setPos(clampPos(((e.clientX - rect.left) / rect.width) * 100));
  }, []);

  // Keyboard nudge for the divider so the diff is operable without a pointer.
  const onHandleKey = useCallback((e: React.KeyboardEvent) => {
    const step = e.shiftKey ? 10 : 2;
    if (e.key === "ArrowLeft") setPos((p) => clampPos(p - step));
    else if (e.key === "ArrowRight") setPos((p) => clampPos(p + step));
    else if (e.key === "Home") setPos(MIN_POS);
    else if (e.key === "End") setPos(MAX_POS);
    else return;
    e.preventDefault();
  }, []);

  const toggleCompare = useCallback(() => {
    setCompare((c) => {
      if (!c) setPos(50); // re-centre each time compare is entered
      return !c;
    });
  }, []);

  return (
    <div className="ain-preview">
      <PanelBar
        title="Live preview"
        actions={
          <>
            <button
              type="button"
              className={`ain-btn ain-topbtn ain-topbtn--sm ain-preview__compare${compare ? " ain-topbtn--on" : ""}`}
              onClick={toggleCompare}
              disabled={!hasDiff}
              aria-pressed={compare}
              title={
                hasDiff
                  ? "Compare your unsaved changes against the saved brand"
                  : "No unsaved changes to compare"
              }
            >
              Compare
            </button>
            <a className="ain-preview__open" href="/demo/brand" target="_blank" rel="noreferrer">
              Open ↗
            </a>
          </>
        }
      />
      {/* The stage is the iframe's positioning context: `.ain-preview__frame`
          is `position: absolute; inset: 0` (a contract shared with the
          double-buffered page preview), so without this `position: relative`
          wrapper the iframe escapes to fill the whole app and swallows every
          click — the studio looks frozen. In compare mode a second (saved)
          frame stacks underneath and the draft frame on top is clipped. */}
      <div className="ain-preview__stage" ref={stageRef}>
        {compare && (
          <iframe
            ref={savedRef}
            className="ain-preview__frame is-shown"
            src="/demo/brand"
            title="Saved brand"
            onLoad={onSavedLoad}
          />
        )}
        <iframe
          ref={frameRef}
          className="ain-preview__frame is-shown"
          src="/demo/brand"
          title="Brand preview"
          onLoad={onLoad}
          style={compare ? { clipPath: `inset(0 0 0 ${pos}%)` } : undefined}
        />
        {compare && (
          <>
            <span className="ain-preview__tag ain-preview__tag--saved">Saved</span>
            <span className="ain-preview__tag ain-preview__tag--draft">Draft</span>
            <div className="ain-preview__divider" style={{ left: `${pos}%` }}>
              <button
                type="button"
                className="ain-preview__handle"
                onPointerDown={startDrag}
                onKeyDown={onHandleKey}
                role="slider"
                aria-label="Compare divider: drag to wipe between saved and draft"
                aria-orientation="horizontal"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(pos)}
              >
                <span aria-hidden="true">⇆</span>
              </button>
            </div>
            {/* While dragging, an overlay above both frames captures pointer
                moves — without it the iframes swallow the events and the drag
                stalls the moment the cursor crosses a frame. */}
            {dragging && (
              <div
                className="ain-preview__overlay"
                onPointerMove={onOverlayMove}
                onPointerUp={endDrag}
                onPointerLeave={endDrag}
              />
            )}
          </>
        )}
      </div>
    </div>
  );
}
