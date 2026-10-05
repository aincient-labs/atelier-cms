import { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { ComposingState, EmptyState, Notice, PanelBar, SegmentedControl, Select } from "@console/kit";
import {
  apiUrl,
  injectPreviewScrollbar,
  interceptPreviewLinks,
  neutralizePreviewTabbing,
} from "@console/sdk";
import {
  compareFrames,
  contactSheetItems,
  humanizeName,
  isRecipe,
  kindContactSheetItems,
  overridePreview,
  selectionItem,
  SITE_SCOPE,
  type CompareMode,
  type ComponentEntry,
  type PreviewView,
  type RenderItem,
} from "./constraint-model";
import { scopeDirty, selectComponent, setView, useComponentsState } from "./components-store";

/**
 * The Components studio's canvas (the `Preview` export): components rendered
 * from their declared `examples:` in the site shell (POST /components/render,
 * DECISIONS 0455).
 *
 * Nothing selected → the CONTACT SHEET: every component the draft still offers,
 * first example each, in the rail's group order — unticking one in the rail
 * drops it from the sheet. Selected → that one component, with the view strip's
 * example, variant, tone and width. The strip is VIEW state only: it never
 * changes availability, and a component that is off in the draft still
 * previews (view-only). Clicking a section on the sheet selects it.
 *
 * In a kind scope (P1b) the sheet is that kind's palette — its saved
 * `effective.placeable` adjusted by the staged ticks; a recipe kind has
 * nothing to place. The header says "unsaved draft" only while the current
 * scope has staged changes.
 *
 * A selected built-in that a pack overrides (P3) gets a view switch — Pack |
 * Original | Compare — where Original renders through `original: true` and
 * Compare puts the two side by side, each labelled. View-only, like the rest of
 * the strip. Once the draft switches the override off (P4b) only the original
 * shows. Under the hood each frame is its own render request.
 *
 * Each frame sits in a browser window (`BrowserWindow`): the page renders at the
 * TRUE chosen width and is scaled down to fit the canvas — never cropped, never
 * scrolled sideways — so 1280 reads as a desktop page, not an overflow. The
 * window's bar says the width and the scale.
 *
 * Re-renders are debounced and a stale response never overwrites a newer one.
 * `srcdoc` keeps the frame same-origin, so links are intercepted and inner tab
 * stops neutralized on load (the a11y rule for preview iframes).
 */

const RENDER_URL = apiUrl("/components/render");
const DEBOUNCE_MS = 200;
const WIDTHS: PreviewView["width"][] = [375, 768, 1280];
const COMPARE_OPTIONS: { value: CompareMode; label: string }[] = [
  { value: "pack", label: "Pack" },
  { value: "original", label: "Original" },
  { value: "compare", label: "Compare" },
];

/** `stale`: the html on hand belongs to a different subject than the one asked for. */
type Rendered = { html: string | null; error: string | null; updating: boolean; stale: boolean };

/**
 * One render request: `body` is the request key (null = nothing to render),
 * `subject` what it shows (a component, or a scope's sheet). Debounced; a
 * stale response never overwrites a newer one. While a NEW subject renders the
 * old html is reported `stale`, so the canvas composes instead of showing the
 * previous subject under the new caption; a variant, tone or width change on
 * the same subject keeps the old frame until the new one lands.
 */
function useRender(body: string | null, subject: string): Rendered {
  const [html, setHtml] = useState<string | null>(null);
  const [htmlSubject, setHtmlSubject] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [updating, setUpdating] = useState(false);
  const seq = useRef(0);

  useEffect(() => {
    if (body === null) {
      seq.current++;
      setHtml(null);
      setHtmlSubject(null);
      setUpdating(false);
      setError(null);
      return;
    }
    setUpdating(true);
    const mine = ++seq.current;
    const timer = window.setTimeout(() => {
      fetch(RENDER_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body,
      })
        .then((r) => (r.ok ? r.text() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((text) => {
          if (mine !== seq.current) return;
          setError(null);
          setHtml(text);
          setHtmlSubject(subject);
          setUpdating(false);
        })
        .catch((e) => {
          if (mine !== seq.current) return;
          setError(`Couldn’t render the preview: ${e instanceof Error ? e.message : e}`);
          setUpdating(false);
        });
    }, DEBOUNCE_MS);
    return () => window.clearTimeout(timer);
    // `subject` travels with `body` (it is derived from the same selection).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [body]);

  return { html, error, updating, stale: html !== null && htmlSubject !== subject };
}

/** The view as the strip SHOWS it: an unset variant/tone reads as the first one, and is sent as such. */
function shownView(entry: ComponentEntry, view: PreviewView): PreviewView {
  return {
    ...view,
    variant: entry.variants.includes(view.variant) ? view.variant : entry.variants[0] ?? "",
    tone: entry.tones.includes(view.tone) ? view.tone : entry.tones[0] ?? "",
  };
}

/** The rendered page's own height: the bottom of its last section (the shell may stretch body to the frame). */
function contentHeight(doc: Document): number {
  const secs = Array.from(doc.querySelectorAll<HTMLElement>("[data-ain-sec]"));
  if (secs.length === 0) return 0;
  const scrollY = doc.defaultView?.scrollY ?? 0;
  return Math.ceil(Math.max(...secs.map((el) => el.getBoundingClientRect().bottom)) + scrollY);
}

/**
 * A browser window around one preview frame. The iframe is laid out at the true
 * `width` and scaled (≤ 1) to the window's width. The window is as tall as the
 * scaled page — so a single short component leaves no empty paper below it —
 * capped at the space the canvas has, where the page scrolls inside the frame.
 */
function BrowserWindow({
  width,
  caption,
  title,
  html,
  shape = "page",
  onLoad,
}: {
  width: number;
  /** What the window shows, e.g. `hero · example "Studio opener" · split · default`. */
  caption: string;
  title: string;
  /** null = the render is on its way: the window shows the composing wireframe. */
  html: string | null;
  /** The wireframe's shape while waiting: one section, or a page of them. */
  shape?: "page" | "section";
  onLoad?: (frame: HTMLIFrameElement) => void;
}) {
  const win = useRef<HTMLDivElement>(null);
  // w = the window's inner width; h = the most the viewport may be (canvas minus chrome).
  const [box, setBox] = useState({ w: 0, h: 0 });
  const [content, setContent] = useState(0);
  const contentObserver = useRef<ResizeObserver | null>(null);

  useLayoutEffect(() => {
    const el = win.current;
    const canvas = el?.parentElement;
    if (!el || !canvas || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const cs = getComputedStyle(canvas);
      const chrome = (el.firstElementChild as HTMLElement | null)?.offsetHeight ?? 0;
      const borders = el.offsetHeight - el.clientHeight;
      setBox({
        w: el.clientWidth,
        h: canvas.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom) - chrome - borders,
      });
    };
    const observer = new ResizeObserver(measure);
    observer.observe(el);
    observer.observe(canvas);
    measure();
    return () => observer.disconnect();
  }, []);
  useEffect(() => () => contentObserver.current?.disconnect(), []);

  const scale = box.w > 0 ? Math.min(1, box.w / width) : 1;
  const viewportH = box.h > 0 ? (content > 0 ? Math.min(box.h, Math.ceil(content * scale)) : box.h) : undefined;
  const frameH = viewportH !== undefined ? viewportH / scale : undefined;

  const load = (frame: HTMLIFrameElement) => {
    onLoad?.(frame);
    const doc = frame.contentDocument;
    if (!doc) return;
    const update = () => setContent(contentHeight(doc));
    update();
    // Fonts, images and opened disclosures change the height after load.
    contentObserver.current?.disconnect();
    if (typeof ResizeObserver !== "undefined") {
      const ro = new ResizeObserver(update);
      doc.querySelectorAll("[data-ain-sec]").forEach((el) => ro.observe(el));
      contentObserver.current = ro;
    }
  };

  return (
    <div className="ain-components-preview__window" ref={win} style={{ maxInlineSize: width + 2 }}>
      <div className="ain-components-preview__chrome">
        <span className="ain-components-preview__dots" aria-hidden="true"><i /><i /><i /></span>
        <span className="ain-components-preview__caption-bar" title={caption}>{caption}</span>
        <span className="ain-components-preview__zoom">
          {width} px{scale < 1 ? ` · ${Math.round(scale * 100)}%` : ""}
        </span>
      </div>
      {html === null ? (
        <ComposingState bare label={`Rendering ${caption}`} shape={shape} />
      ) : (
        <div className="ain-components-preview__viewport" style={{ blockSize: viewportH }}>
          <iframe
            className="ain-components-preview__frame"
            style={{ width, height: frameH, transform: scale < 1 ? `scale(${scale})` : undefined }}
            title={title}
            srcDoc={html}
            onLoad={(e) => load(e.currentTarget)}
          />
        </div>
      )}
    </div>
  );
}

export function ComponentPreview() {
  const componentsState = useComponentsState();
  const { manifest, draft, selected, view, scope, kinds, scopes } = componentsState;
  const kind = scope === SITE_SCOPE ? null : kinds[scope] ?? null;
  const recipe = scope !== SITE_SCOPE && isRecipe(scopes.find((s) => s.id === scope) ?? kind?.state.kind);
  const dirty = scopeDirty(componentsState);

  const entry = useMemo(
    () => (selected ? manifest?.components.find((c) => c.name === selected) ?? null : null),
    [manifest, selected],
  );

  // What to render. A selection with no example (the virtual `block`) renders nothing.
  const items: RenderItem[] | null = useMemo(() => {
    if (!manifest || !draft) return null;
    if (entry) return entry.examples.length > 0 ? [selectionItem(entry, shownView(entry, view))] : [];
    if (scope === SITE_SCOPE) return contactSheetItems(manifest.components, draft);
    if (recipe) return [];
    if (!kind) return null;
    const { baseline, draft: kindDraft, state } = kind;
    return kindContactSheetItems(manifest.components, baseline, kindDraft, state.site_off.components, state.effective.placeable);
  }, [manifest, draft, entry, view, scope, kind, recipe]);

  // The selection's override mode decides which frames render (P3, P4b).
  const mode = entry && draft ? overridePreview(entry, draft) : "none";
  const frames = compareFrames(mode, view.compare);

  // Stable request keys — the draft changes often; the bodies only sometimes.
  const hasItems = items !== null && items.length > 0;
  const subject = entry ? `component:${entry.name}` : `sheet:${scope}`;
  const pack = useRender(hasItems && frames.includes("pack") ? JSON.stringify({ items }) : null, subject);
  const original = useRender(hasItems && frames.includes("original") ? JSON.stringify({ items, original: true }) : null, subject);
  const shown = frames.map((f) => ({ frame: f, ...(f === "pack" ? pack : original) }));
  const updating = shown.some((r) => r.updating);
  const error = shown.find((r) => r.error)?.error ?? null;
  const ready = shown.every((r) => r.html !== null && !r.stale);

  const onLoad = (frame: HTMLIFrameElement) => {
    const doc = frame.contentDocument;
    if (!doc) return;
    interceptPreviewLinks(doc);
    injectPreviewScrollbar(doc);
    neutralizePreviewTabbing(doc);
    // A click on a section selects its component (the rail scrolls to it).
    doc.addEventListener("click", (e) => {
      const target = e.target as Element | null;
      const sec = target?.closest?.("[data-ain-sec]");
      const name = sec?.getAttribute("data-ain-sec");
      if (name) selectComponent(name);
    });
    if (!entry) {
      const style = doc.createElement("style");
      style.textContent = "[data-ain-sec]{cursor:pointer}";
      doc.head?.appendChild(style);
    }
  };

  const label = entry ? humanizeName(entry.name) : null;

  // The window bar's caption: what is in the frame, in the strip's own terms.
  const windowCaption: string[] = entry
    ? [
        entry.name,
        entry.examples.length > 0 ? `example “${entry.examples[Math.min(view.example, entry.examples.length - 1)]}”` : "",
        shownView(entry, view).variant,
        shownView(entry, view).tone,
      ].filter(Boolean)
    : [
        kind ? kind.state.kind.label.toLowerCase() : "whole site",
        items ? `${items.length} component${items.length === 1 ? "" : "s"}` : "",
      ].filter(Boolean);

  return (
    <div className="ain-preview" data-testid="studio-preview" data-studio="components">
      <PanelBar title={updating ? "Updating preview…" : dirty > 0 ? "Live preview · unsaved draft" : "Live preview"} />
      <div className={`ain-preview__progress${updating ? " is-active" : ""}`} aria-hidden="true" />

      <div className="ain-components-preview__strip">
        {entry ? (
          <>
            <span className="ain-components-preview__caption">{label}</span>
            {entry.examples.length > 1 && (
              <Select
                className="ain-components-preview__select"
                aria-label="Example"
                value={String(Math.min(view.example, entry.examples.length - 1))}
                onChange={(e) => setView({ example: Number(e.target.value) })}
              >
                {entry.examples.map((name, i) => (
                  <option key={i} value={i}>{name}</option>
                ))}
              </Select>
            )}
            {entry.variants.length > 0 && (
              <SegmentedControl
                label="Variant"
                value={shownView(entry, view).variant}
                onChange={(v) => setView({ variant: v })}
                options={entry.variants.map((v) => ({ value: v, label: humanizeName(v) }))}
              />
            )}
            {entry.tones.length > 0 && (
              <SegmentedControl
                label="Tone"
                value={shownView(entry, view).tone}
                onChange={(t) => setView({ tone: t })}
                options={entry.tones.map((t) => ({ value: t, label: humanizeName(t) }))}
              />
            )}
            {mode === "switch" && (
              <SegmentedControl
                label="Version"
                value={view.compare}
                onChange={(c) => setView({ compare: c })}
                options={COMPARE_OPTIONS}
              />
            )}
            {mode === "original" && (
              <span className="ain-components-preview__note">
                Using the original — the pack’s version is switched off
              </span>
            )}
            {mode === "pending" && (
              <span className="ain-components-preview__note">
                Showing the original — the pack’s version previews once you publish
              </span>
            )}
          </>
        ) : (
          <span className="ain-components-preview__caption">
            {kind ? `Every component offered on ${kind.state.kind.label}` : "Every component this site offers"}
          </span>
        )}
        <SegmentedControl
          className="ain-components-preview__width"
          label="Width"
          value={String(view.width) as `${PreviewView["width"]}`}
          onChange={(w) => setView({ width: Number(w) as PreviewView["width"] })}
          options={WIDTHS.map((w) => ({ value: String(w) as `${PreviewView["width"]}`, label: `${w}` }))}
        />
      </div>

      {error ? (
        <Notice tone="error" panel>{error}</Notice>
      ) : entry && entry.examples.length === 0 ? (
        <EmptyState variant="stage">{label} has no example to preview.</EmptyState>
      ) : recipe && !entry ? (
        <EmptyState variant="stage">This page type has a fixed layout — there is nothing to place.</EmptyState>
      ) : items && items.length === 0 ? (
        <EmptyState variant="stage">No component is on — tick one in the rail.</EmptyState>
      ) : !ready ? (
        // On its way: the window, captioned, with the composing wireframe —
        // a component, not a list, is what is coming.
        <div className="ain-components-preview__canvas">
          <BrowserWindow
            width={view.width}
            caption={windowCaption.join(" · ")}
            title="Components preview"
            html={null}
            shape={entry ? "section" : "page"}
          />
        </div>
      ) : (
        <div className="ain-components-preview__canvas" data-testid={shown.length > 1 ? "components-compare" : undefined}>
          {shown.map((r) => {
            const side = shown.length > 1
              ? r.frame === "pack" ? `Pack · ${entry?.replaced_by?.provider ?? ""}` : "Original"
              : null;
            const caption = [...windowCaption, side?.toLowerCase()].filter(Boolean).join(" · ");
            return (
              <BrowserWindow
                key={r.frame}
                width={view.width}
                caption={caption}
                title={`${label ?? "Components"} preview${side ? ` — ${side}` : ""}`}
                html={r.html ?? ""}
                onLoad={onLoad}
              />
            );
          })}
        </div>
      )}
    </div>
  );
}
