import { useEffect, useRef, useState } from "react";
import {
  subscribeChromeDraft,
  subscribePreviewReload,
  getChromeDraft,
  type ChromeDraft,
} from "./globals-state";
import { interceptPreviewLinks, injectPreviewScrollbar } from "./preview-nav";
import { PanelBar } from "./panel-bar";

/**
 * The live Globals preview: an iframe whose `srcdoc` is the HTML the server
 * renders for the site header + footer (around a placeholder body) from the
 * current chrome draft. Chrome layout is markup/class changes, not pure CSS
 * vars, so — like the page preview, unlike the brand preview — every draft change
 * re-POSTs the whole draft to the stateless /aincient/chrome/preview endpoint and
 * renders the returned document.
 *
 * Re-rendering is DOUBLE-BUFFERED (the page-preview pattern): the new HTML mounts
 * in a second iframe stacked on top at opacity 0, and once it loads we carry the
 * outgoing frame's scroll onto it, crossfade it in, and drop the old one — no
 * white blink, scroll stays put. `srcdoc` keeps the frame same-origin (so scroll
 * carry-over is allowed); links would still navigate, so interceptPreviewLinks
 * cancels them. The render is debounced so a slider/keystroke run coalesces.
 */
const PREVIEW_URL = "/aincient/chrome/preview";
const DEBOUNCE_MS = 220;
/** Crossfade duration — keep in sync with `.ain-preview__frame` transition. */
const FADE_MS = 260;

/** One stacked preview document. `shown` flips on once it has loaded + had the
 *  scroll position carried over, which fades it in over the layer below. */
type Layer = { key: number; html: string; shown: boolean };

export function GlobalsPreview() {
  const [layers, setLayers] = useState<Layer[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [updating, setUpdating] = useState(false);

  // Bumps on each render request so a slow response can't overwrite a newer one.
  const reqSeq = useRef(0);
  // Monotonic key per mounted layer (React list identity + scroll bookkeeping).
  const keySeq = useRef(0);
  // If a re-render returns byte-identical markup there's nothing to swap.
  const lastHtml = useRef<string | null>(null);
  // Current layers, readable inside the deferred onLoad/prune callbacks.
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
  const render = useRef<() => void>(() => {});
  render.current = () => {
    const draft: ChromeDraft | null = getChromeDraft();
    if (!draft) {
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
      body: JSON.stringify(draft),
    })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((text) => {
        if (seq !== reqSeq.current) return;
        setError(null);
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

  // Debounced re-render on every draft change.
  useEffect(() => {
    let timer: number | undefined;
    const schedule = () => {
      setUpdating(true);
      window.clearTimeout(timer);
      timer = window.setTimeout(() => render.current(), DEBOUNCE_MS);
    };
    render.current();
    const unsub = subscribeChromeDraft(schedule);
    return () => {
      window.clearTimeout(timer);
      unsub();
    };
  }, []);

  // After a Publish the draft equals the saved chrome; re-render so the preview
  // stays authoritative (mirrors the brand/page reload contract).
  useEffect(() => subscribePreviewReload(() => render.current()), []);

  return (
    <div className="ain-preview">
      <PanelBar title={updating ? "Updating preview…" : "Live preview · unsaved draft"} />
      <div className={`ain-preview__progress${updating ? " is-active" : ""}`} aria-hidden="true" />
      {error ? (
        <p className="ain-studio__error">{error}</p>
      ) : layers.length === 0 ? (
        <div className="ain-pagepreview__empty">
          <p>Your header &amp; footer preview appears here.</p>
        </div>
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
              title="Globals preview"
              srcDoc={layer.html}
              onLoad={(e) => {
                interceptPreviewLinks(e.currentTarget.contentDocument);
                injectPreviewScrollbar(e.currentTarget.contentDocument);
                reveal.current(layer.key);
              }}
            />
          ))}
        </div>
      )}
    </div>
  );
}
