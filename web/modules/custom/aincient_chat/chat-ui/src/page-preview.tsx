import { useEffect, useRef, useState } from "react";
import {
  subscribePageDraft,
  subscribePreviewReload,
  subscribePageLoad,
  subscribePageNode,
  getPageDraft,
  getPageUrl,
  getPageKind,
  getPageNode,
  getAuthoringNew,
  startNewPage,
  setSelectedSection,
  getSelectedSection,
  subscribeSelectedSection,
  type PageSchema,
} from "./page-state";
import {
  interceptPreviewLinks,
  interceptPreviewClicks,
  injectPreviewScrollbar,
  injectSelectionStyles,
  neutralizePreviewTabbing,
  paintSelection,
} from "./preview-nav";
import { PanelBar } from "./panel-bar";
import { ContentBrowser } from "./content-browser";
import { PresencePreview } from "./presence-preview";
import { useFacet } from "./page-facet";
import { activeStudioKey } from "./flow";
import { consoleNav, deriveRoomFromStores } from "./console-nav";
import { openPageInPlace } from "./url-sync";
import { apiUrl } from "./console-config";

/**
 * The live page preview: an iframe whose `srcdoc` is the chrome-less HTML the
 * server renders from the current draft schema. Unlike the brand preview (which
 * reskins a fixed demo page live with inline CSS vars), a page is a full
 * re-render — every draft change re-POSTs the schema to the stateless
 * /atelier/page/preview endpoint and renders the returned HTML.
 *
 * Re-rendering is DOUBLE-BUFFERED: instead of swapping `srcdoc` on one iframe
 * (which tears the document down — a white blink, and the scroll jumps to top),
 * we mount the new HTML in a second iframe stacked on top at opacity 0, wait for
 * its `load`, copy the outgoing frame's scroll position onto it, then crossfade
 * it in and drop the old one. The result: no blink, and the viewer stays put
 * where they were reading. Two iframes never collide — each is its own document,
 * so duplicate ids/styles are isolated, and the preview runs no stateful logic
 * of its own, so a brief overlap of two frames is harmless.
 *
 * `srcdoc` (not `src`) means the preview inherits the parent's origin, so
 * reading/writing the frame's `scrollY` is same-origin-allowed. Links, though,
 * are NOT inert — srcdoc resolves hrefs against the parent's URL, so a click
 * would navigate the frame and lose the unsaved draft; `interceptPreviewLinks`
 * (wired in each layer's onLoad) cancels anchor navigation and surfaces a modal.
 * The render is debounced so dragging a value or the agent emitting several ops
 * doesn't fire a request per keystroke.
 */
const PREVIEW_URL = apiUrl("/page/preview");
const DEBOUNCE_MS = 220;
/** Crossfade duration — keep in sync with `.ain-preview__frame` transition. */
const FADE_MS = 260;

/** One stacked preview document. `shown` flips on once it has loaded + had the
 *  scroll position carried over, which fades it in over the layer below. */
type Layer = { key: number; html: string; shown: boolean };

/** Whether a draft has anything worth rendering (a fresh/empty draft shows the
 *  placeholder instead of an empty chrome shell). */
function hasContent(schema: PageSchema | null): boolean {
  if (!schema) return false;
  if (schema.type === "blog") return true;
  return Array.isArray(schema.sections) && schema.sections.length > 0;
}

export function PagePreview() {
  // The Presence facet (Content studio, pages only) shows the SEO/social canvas
  // instead of the rendered-page iframe. Gated to Content so switching to Checks
  // (which reuses this preview) always shows the body render.
  const facet = useFacet();

  // The stacked documents. At rest there's one (the visible front); during an
  // update a second rides on top until it's ready, then the front is dropped.
  const [layers, setLayers] = useState<Layer[]>([]);
  const [error, setError] = useState<string | null>(null);
  // True from the moment a draft change is detected until the new preview has
  // painted. Drives the "updating" affordance (the progress line in the bar) so
  // an agent op or a studio edit reads as a smooth update, not a flicker.
  const [updating, setUpdating] = useState(false);
  // The live URL of the saved page, for the "Open ↗" link in the bar. null until
  // the draft has a saved page behind it (a brand-new draft / a block has none).
  // Brand's Open points at a fixed demo page; a page's URL only exists once saved.
  const [openUrl, setOpenUrl] = useState<string | null>(null);
  // Bumped on doc-identity / New changes so the idle empty-state re-evaluates
  // whether to show the content browser (nothing open) vs the build placeholder
  // (a deliberate New). The values it reads (getPageNode/getAuthoringNew) are
  // module state, not props, so this tick is what forces the re-read.
  const [, setIdleTick] = useState(0);

  // Bumps on each render request so a slow response can't overwrite a newer one.
  const reqSeq = useRef(0);
  // Monotonic key per mounted layer (React list identity + scroll bookkeeping).
  const keySeq = useRef(0);
  // If a re-render returns byte-identical markup there's nothing to swap.
  const lastHtml = useRef<string | null>(null);
  // Current layers, readable inside the deferred `onLoad`/prune callbacks.
  const layersRef = useRef<Layer[]>([]);
  layersRef.current = layers;
  // The live iframe nodes by layer key — for cross-frame scroll carry-over.
  const frameEls = useRef<Map<number, HTMLIFrameElement>>(new Map());

  // A loaded layer surfaces: carry the outgoing frame's scroll onto it, fade it
  // in, then once the crossfade is done drop every layer beneath it.
  const reveal = useRef<(key: number) => void>(() => {});
  reveal.current = (key) => {
    const el = frameEls.current.get(key);
    const prev = layersRef.current.find((l) => l.shown && l.key !== key);
    const prevEl = prev ? frameEls.current.get(prev.key) : undefined;
    try {
      const win = prevEl?.contentWindow;
      if (win && el?.contentWindow) el.contentWindow.scrollTo(win.scrollX, win.scrollY);
    } catch {
      // srcdoc is same-origin so this is allowed; ignore if a browser ever blocks it.
    }
    setUpdating(false);
    setLayers((prevLayers) => prevLayers.map((l) => (l.key === key ? { ...l, shown: true } : l)));
    window.setTimeout(() => {
      setLayers((prevLayers) =>
        prevLayers.some((l) => l.key === key) ? prevLayers.filter((l) => l.key === key) : prevLayers,
      );
    }, FADE_MS);
  };

  // Render the current draft: POST it and stack the returned HTML as a new layer.
  // A null/empty draft clears to the placeholder without a request.
  const render = useRef<() => void>(() => {});
  render.current = () => {
    const schema = getPageDraft();
    if (!hasContent(schema)) {
      setLayers([]);
      frameEls.current.clear();
      setError(null);
      setUpdating(false);
      lastHtml.current = null;
      return;
    }
    const seq = ++reqSeq.current;
    fetch(PREVIEW_URL, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ schema }),
    })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((text) => {
        // Ignore a response a newer request has already superseded — that newer
        // one is still in flight and owns clearing `updating`.
        if (seq !== reqSeq.current) return;
        setError(null);
        // Identical markup → nothing visually changes → no new layer.
        if (text === lastHtml.current) {
          setUpdating(false);
          return;
        }
        lastHtml.current = text;
        const key = ++keySeq.current;
        setLayers((prev) => [...prev, { key, html: text, shown: false }]);
      })
      .catch((e) => {
        if (seq !== reqSeq.current) return;
        setError(`Couldn’t render the preview: ${e instanceof Error ? e.message : e}`);
        setUpdating(false);
      });
  };

  // Debounced re-render on every draft change (studio edit or agent op).
  useEffect(() => {
    let timer: number | undefined;
    const schedule = () => {
      // Flag immediately (before the debounce) so the affordance tracks intent,
      // then coalesce the actual render.
      setUpdating(true);
      window.clearTimeout(timer);
      timer = window.setTimeout(() => render.current(), DEBOUNCE_MS);
    };
    // Render whatever's already in the store on mount, then track changes.
    render.current();
    const unsub = subscribePageDraft(schedule);
    return () => {
      window.clearTimeout(timer);
      unsub();
    };
  }, []);

  // After a Publish the draft equals the saved page; re-render so the preview
  // stays authoritative (mirrors brand's reload contract).
  useEffect(() => subscribePreviewReload(() => render.current()), []);

  // Repaint the selection outline live when it changes (click-to-focus). Paints
  // every mounted frame — harmless on the outgoing one mid-crossfade, and the
  // incoming one also paints from onLoad, so whichever wins shows the outline.
  useEffect(
    () =>
      subscribeSelectedSection((id) => {
        for (const el of frameEls.current.values()) paintSelection(el.contentDocument, id);
      }),
    [],
  );

  // Re-evaluate the idle empty-state (browser vs build placeholder) when the open
  // document or the authoring-new intent changes.
  useEffect(() => {
    const bump = () => setIdleTick((t) => t + 1);
    const unsubNode = subscribePageNode(bump);
    const unsubLoad = subscribePageLoad(bump);
    return () => {
      unsubNode();
      unsubLoad();
    };
  }, []);

  // Track the saved page's URL for the "Open ↗" link. It changes when a page is
  // loaded/switched (page-load) and when a first Publish mints one (reload). A
  // block has no standalone page, so only a `page` draft ever shows the link.
  useEffect(() => {
    const refresh = () => setOpenUrl(getPageKind() === "page" ? getPageUrl() : null);
    refresh();
    const unsubLoad = subscribePageLoad(refresh);
    const unsubReload = subscribePreviewReload(refresh);
    return () => {
      unsubLoad();
      unsubReload();
    };
  }, []);

  // Presence facet: swap the rendered-page iframe for the SEO/social canvas.
  // Only in Content and only with a page open (the switch is offered nowhere
  // else); otherwise fall through to the body render below.
  if (
    facet === "presence" &&
    activeStudioKey() === "content" &&
    getPageKind() === "page" &&
    (getPageNode() !== null || hasContent(getPageDraft()))
  ) {
    return <PresencePreview />;
  }

  return (
    <div className="ain-preview">
      <PanelBar
        title={updating ? "Updating preview…" : "Live preview · unsaved draft"}
        actions={
          openUrl ? (
            <a className="ain-preview__open" href={openUrl} target="_blank" rel="noreferrer">
              Open ↗
            </a>
          ) : undefined
        }
      />
      <div
        className={`ain-preview__progress${updating ? " is-active" : ""}`}
        aria-hidden="true"
      />
      {error ? (
        <p className="ain-studio__error">{error}</p>
      ) : layers.length === 0 ? (
        // Idle (nothing open, no deliberate New) → browse + pick a page from the
        // canvas. Mid-build / a deliberate New / an opened-but-empty page → the
        // "build it" placeholder, so the directory doesn't bury the intent.
        // In Checks the same canvas picks a page to audit: the pick sets the
        // audit node (the ChecksStudio rail loads the draft for THIS preview +
        // runs the audit) — and there's no "New" (you audit existing pages).
        getPageNode() === null && !getAuthoringNew() ? (
          activeStudioKey() === "checks" ? (
            // Pick a page to audit → enter its audit room (the machine sets the
            // audit node; the ChecksStudio rail loads the draft + runs the audit).
            <ContentBrowser verb="Check" currentId={getPageNode()} onPick={(id) => consoleNav.enterRoom({ kind: "audit", nid: Number(id) })} />
          ) : (
            <ContentBrowser
              verb="Open"
              currentId={getPageNode()}
              // Open a listed page → enter its Content node room (the machine loads
              // the draft + switches to the room's thread).
              onPick={(id) => openPageInPlace(undefined, id)}
              onNew={() => {
                // Seed a fresh node-less draft, then adopt the room the stores now
                // imply (the draft room, thread-addressed) so the machine + URL
                // move to /content/draft[/<thread>], off the listing's URL.
                startNewPage();
                consoleNav.adoptRoom(deriveRoomFromStores());
              }}
            />
          )
        ) : (
          <div className="ain-pagepreview__empty">
            <p>Your page preview appears here.</p>
            <p className="ain-pagepreview__hint">
              Ask the agent to build a page, or add a section in the studio.
            </p>
          </div>
        )
      ) : (
        <div className="ain-preview__stage">
          {layers.map((layer) => (
            <iframe
              key={layer.key}
              ref={(el) => {
                if (el) frameEls.current.set(layer.key, el);
                else frameEls.current.delete(layer.key);
              }}
              className={`ain-preview__frame${layer.shown ? " is-shown" : ""}`}
              title="Page preview"
              srcDoc={layer.html}
              onLoad={(e) => {
                const doc = e.currentTarget.contentDocument;
                interceptPreviewLinks(doc);
                // Click-to-focus: a click in the canvas selects its section (the
                // studio opens + scrolls that card); the styles + current paint
                // ride the fresh document so the outline survives re-renders.
                interceptPreviewClicks(doc, (id) => setSelectedSection(id));
                injectSelectionStyles(doc);
                paintSelection(doc, getSelectedSection());
                injectPreviewScrollbar(doc);
                neutralizePreviewTabbing(doc);
                reveal.current(layer.key);
              }}
            />
          ))}
        </div>
      )}
    </div>
  );
}
