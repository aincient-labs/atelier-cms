/**
 * Link interception for the live-preview iframes.
 *
 * The brand and page previews are same-origin iframes (the brand demo page;
 * the page draft via `srcdoc`) and fully interactive — hover, accordions,
 * buttons all work. The one interaction we DON'T want is link navigation:
 * following an `<a href>` would navigate the iframe away from the preview,
 * dropping the brand override overlay (brand) or the unsaved draft (page).
 *
 * So each preview attaches `interceptPreviewLinks` to its iframe document: a
 * capture-phase click handler that cancels anchor navigation ONLY (everything
 * else passes through) and fires `notifyBlockedLink`. The shell subscribes and
 * shows a modal explaining links are disabled in the preview.
 */

const listeners = new Set<(href: string) => void>();

/** Tell the shell a preview link was clicked (and blocked). */
export function notifyBlockedLink(href: string): void {
  for (const l of listeners) l(href);
}

/** Subscribe to blocked-link events; returns an unsubscribe fn. */
export function subscribeBlockedLink(cb: (href: string) => void): () => void {
  listeners.add(cb);
  return () => {
    listeners.delete(cb);
  };
}

/**
 * Cancel link navigation inside a preview iframe document and report it.
 * Capture phase so it wins before the page's own handlers; returns a cleanup
 * fn. In-page fragment jumps (`#…`) and `javascript:` links are left alone —
 * they don't navigate the frame away, so blocking them would just break the
 * preview's own behaviour.
 */
export function interceptPreviewLinks(doc: Document | null | undefined): () => void {
  if (!doc) return () => {};
  const onClick = (e: MouseEvent) => {
    const anchor = (e.target as Element | null)?.closest?.("a[href]") as HTMLAnchorElement | null;
    if (!anchor) return;
    const raw = anchor.getAttribute("href") ?? "";
    if (!raw || raw.startsWith("#") || raw.startsWith("javascript:")) return;
    e.preventDefault();
    e.stopPropagation();
    notifyBlockedLink(anchor.href || raw);
  };
  doc.addEventListener("click", onClick, true);
  return () => doc.removeEventListener("click", onClick, true);
}

/** The attribute the backend stamps on each placed section's wrapper (preview
 *  only) — the click-to-focus carrier for the section's stable slot id. */
const SECTION_ATTR = "data-ain-sec";

/**
 * Report which section a click in the preview landed in (click-to-focus).
 *
 * Walks up from the click target to the nearest `[data-ain-sec]` wrapper (the
 * backend stamps one per placed section in the preview render) and reports its
 * slot id, so the studio can open + scroll that section's editing card. Runs in
 * the capture phase alongside {@link interceptPreviewLinks} — a click on a link
 * or button inside a section is still reported (you selected that section) while
 * the link handler independently cancels the navigation. Clicks outside any
 * stamped band (chrome header/footer, gaps, a spliced global block) report
 * nothing. Returns a cleanup fn.
 */
export function interceptPreviewClicks(
  doc: Document | null | undefined,
  onSelect: (id: string) => void,
): () => void {
  if (!doc) return () => {};
  const onClick = (e: MouseEvent) => {
    const wrap = (e.target as Element | null)?.closest?.(`[${SECTION_ATTR}]`) as HTMLElement | null;
    const id = wrap?.getAttribute(SECTION_ATTR);
    if (id) onSelect(id);
  };
  doc.addEventListener("click", onClick, true);
  return () => doc.removeEventListener("click", onClick, true);
}

/**
 * Inject the click-to-focus stylesheet into a preview iframe document.
 *
 * Two rules, scoped to the editor-only wrappers so they never touch a published
 * page (which carries no `.ain-sec-wrap`):
 *   - `display: contents` makes each section wrapper layout-transparent — it
 *     emits no box, so wrapping every band can't shift spacing, sibling gaps or
 *     `:first/last-child` rules; it stays only as a DOM query + click target.
 *   - the selected band gets a brand-violet inset outline (offset inward so a
 *     full-bleed section still shows it), drawn on the wrapper's own child since
 *     the `display:contents` wrapper itself paints nothing.
 * Idempotent — re-baked on each (re)load like {@link injectPreviewScrollbar}.
 */
export function injectSelectionStyles(doc: Document | null | undefined): void {
  if (!doc?.head) return;
  let style = doc.head.querySelector<HTMLStyleElement>("style[data-ain-select]");
  if (!style) {
    style = doc.createElement("style");
    style.setAttribute("data-ain-select", "");
    doc.head.appendChild(style);
  }
  const root = document.getElementById("aincient-chat-root") ?? document.documentElement;
  const accent = getComputedStyle(root).getPropertyValue("--ain-accent").trim() || "#7c3aed";
  style.textContent = `
    .ain-sec-wrap { display: contents; }
    .ain-sec-wrap[data-ain-selected] > * {
      outline: 2px solid ${accent};
      outline-offset: -2px;
    }
  `;
}

/**
 * Paint the selection outline in a preview document: clear any prior selected
 * band and mark the one whose slot id matches (or none, for null). Called on
 * each frame load and whenever the selection changes, so the highlight survives
 * the preview's double-buffered re-renders.
 */
export function paintSelection(doc: Document | null | undefined, id: string | null): void {
  if (!doc) return;
  for (const el of doc.querySelectorAll(`[${SECTION_ATTR}][data-ain-selected]`)) {
    el.removeAttribute("data-ain-selected");
  }
  if (id) {
    const match = doc.querySelector(`[${SECTION_ATTR}="${CSS.escape(id)}"]`);
    match?.setAttribute("data-ain-selected", "");
  }
}

/**
 * Theme the preview iframe's scrollbar to match the console chrome.
 *
 * The preview is its OWN document (the brand demo / the page srcdoc), so the
 * app's `#aincient-chat-root` scrollbar rules don't reach inside it — it paints
 * the bare UA scrollbar, which reads as foreign against the flat pixel chrome.
 *
 * The scrollbar is console furniture, not part of the brand, so it should look
 * like every other console scrollbar (chat viewport, editor rail) regardless of
 * the brand being previewed — keying it off the brand's own tokens made it go
 * near-white on a dark brand. The iframe can't read the parent's `--ain-*`
 * vars, so we resolve the console's scrollbar colours here and bake the literal
 * values in. Mirrors the global `::-webkit-scrollbar` block in styles.css (8px,
 * transparent track, flat square `--ain-border-strong` thumb, `--ain-text-dim`
 * on hover); `scrollbar-width` is Firefox-only (it would disable the webkit
 * rules in Chrome), hence the `@supports` guard. Re-baked on each (re)load, so
 * a console theme switch is picked up the next time the preview reloads.
 */
export function injectPreviewScrollbar(doc: Document | null | undefined): void {
  if (!doc?.head) return;
  let style = doc.head.querySelector<HTMLStyleElement>("style[data-ain-scrollbar]");
  if (!style) {
    style = doc.createElement("style");
    style.setAttribute("data-ain-scrollbar", "");
    doc.head.appendChild(style);
  }
  const root = document.getElementById("aincient-chat-root") ?? document.documentElement;
  const cs = getComputedStyle(root);
  const thumb = cs.getPropertyValue("--ain-border-strong").trim() || "#888";
  const thumbHover = cs.getPropertyValue("--ain-text-dim").trim() || "#555";
  style.textContent = `
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: ${thumb}; border-radius: 0; }
    ::-webkit-scrollbar-thumb:hover { background: ${thumbHover}; }
    @supports not selector(::-webkit-scrollbar) {
      html { scrollbar-width: thin; scrollbar-color: ${thumb} transparent; }
    }
  `;
}

/** Natively-focusable elements that land in the sequential tab order. */
const PREVIEW_FOCUSABLE =
  'a[href],button,input,select,textarea,[contenteditable="true"],[tabindex]:not([tabindex="-1"]),audio[controls],video[controls],area[href],summary,iframe';

/**
 * Pull every focusable element inside a preview iframe out of keyboard tab order.
 *
 * The live previews are same-origin, MOUSE-interactive display surfaces — you
 * click a section to select it (see {@link interceptPreviewClicks}), hover
 * states and accordions work — but they are NOT meant to be driven by the
 * keyboard. Left alone, a `Tab` from the studio walks *into* the iframe and
 * through every link and control of the embedded page; and because the preview
 * is DOUBLE-BUFFERED (a second iframe kept mounted at `opacity: 0` during the
 * crossfade), through an invisible copy of them too. Focus visually "vanishes"
 * into the frame and takes many tabs to escape — the reported a11y bug.
 *
 * So each preview neutralises its document's tab stops: `tabindex="-1"` on every
 * natively-focusable element leaves them clickable (mouse selection is
 * untouched) while removing them from sequential focus navigation. Idempotent +
 * re-baked on each (re)load like {@link injectPreviewScrollbar}, so it also
 * covers the hidden double-buffer layer (its `onLoad` fires too). Applied to the
 * same-origin previews only — the studio-tour YouTube embed is a real,
 * intentionally keyboard-operable, cross-origin frame we can't (and shouldn't)
 * reach into.
 */
export function neutralizePreviewTabbing(doc: Document | null | undefined): void {
  if (!doc) return;
  for (const el of doc.querySelectorAll<HTMLElement>(PREVIEW_FOCUSABLE)) {
    el.setAttribute("tabindex", "-1");
  }
}
