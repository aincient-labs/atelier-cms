/**
 * Per-field deep-link anchors for studio surfaces (DECISIONS 0372, Phase 2).
 *
 * The studio split gave a few fields new homes (the WHOLE logo now lives in
 * Identity). Phase 3's chat handoff — "use this as my logo" → route the operator
 * to Identity → Logo — and the existing Checks/list_pages links both need a
 * durable way to ADDRESS a single field, in the same spirit as the reflected
 * open-doc URL axes (`?page` / `?block` / `?audit`; see `pageDeepLink`).
 *
 * The contract is intentionally tiny and leaf (no app imports, so anything can
 * depend on it):
 *
 *  - {@link fieldAnchorId} maps a namespaced field key ("identity.logo") to a
 *    STABLE DOM id the rendering studio stamps on that field's wrapper. The id
 *    is derived, never hand-maintained, so a new anchorable field is one call.
 *  - {@link FIELD_ANCHOR_PARAM} is the URL query axis Phase 3 sets to request a
 *    field — `?field=identity.logo`. {@link requestedFieldAnchor} reads it.
 *  - {@link focusStudioField} scrolls the field into view and moves focus to its
 *    first control, so the handoff lands the operator ON the thing to change.
 *
 * Phase 3 wiring: navigate to the Identity studio with `?field=identity.logo`
 * (or call {@link focusStudioField} directly once the rail is mounted). The
 * Identity rail already consumes the param on mount, so setting it is enough.
 */

/** The URL query axis that names a studio field to reveal (`?field=<key>`). */
export const FIELD_ANCHOR_PARAM = "field";

/**
 * The stable DOM id for a field key. Namespaced keys ("identity.logo",
 * "site.mail") map to `ain-fa--identity-logo` — dots/underscores to hyphens so
 * the id is a valid selector. The inverse isn't needed: callers hold the key.
 */
export function fieldAnchorId(key: string): string {
  return "ain-fa--" + key.replace(/[._]+/g, "-");
}

/**
 * Fired on `window` (detail = the field key) when {@link focusStudioField}
 * cannot find a field: a collapsed card that holds it listens and opens, and
 * the caller's retry then lands. A card that folds fields away must listen, or
 * a deep link into it fails silently.
 */
export const REVEAL_FIELD_EVENT = "ain:reveal-field";

/** The field key requested via the URL (`?field=`), or null when none is set. */
export function requestedFieldAnchor(search: string = window.location.search): string | null {
  const value = new URLSearchParams(search).get(FIELD_ANCHOR_PARAM);
  return value && value.trim() !== "" ? value.trim() : null;
}

/**
 * Reveal a field by key: scroll its anchored wrapper into view and focus its
 * first focusable control. Returns whether the field was found (so a caller can
 * retry after a late mount). Safe to call before the DOM settles — a miss
 * fires {@link REVEAL_FIELD_EVENT} so a collapsed card can open, and the studio
 * re-calls it once its rail renders.
 */
export function focusStudioField(key: string, root: ParentNode = document): boolean {
  // The id is selector-safe by construction (lowercase + hyphens); CSS.escape is
  // belt-and-braces, and absent in some non-browser test DOMs.
  const id = fieldAnchorId(key);
  const el = root.querySelector<HTMLElement>("#" + (typeof CSS !== "undefined" ? CSS.escape(id) : id));
  if (!el) {
    if (typeof window !== "undefined") window.dispatchEvent(new CustomEvent(REVEAL_FIELD_EVENT, { detail: key }));
    return false;
  }
  el.scrollIntoView?.({ block: "center", behavior: "smooth" });
  const focusable = el.matches("input,select,textarea,button,a[href],[tabindex]")
    ? el
    : el.querySelector<HTMLElement>("input,select,textarea,button,a[href],[tabindex]");
  // Focus after the scroll begins; a bare focus() would itself jump the scroll.
  window.setTimeout(() => focusable?.focus?.({ preventScroll: true }), 120);
  return true;
}

/**
 * The `?field=` the console was OPENED with, captured when this module first
 * loads — url-sync canonicalises the address bar to the room's own URL on the
 * next tick, so a lazily loaded studio that mounts later would otherwise never
 * see it (the Checks rail, deep-linked from a chat card's field link opened in
 * a new tab, DECISIONS 0453).
 */
let bootField: string | null = typeof window !== "undefined" ? requestedFieldAnchor() : null;

/**
 * The requested field, ONCE: the live `?field=` if the URL still carries it,
 * else the one the console booted with. Either way the boot capture is spent,
 * so a later mount doesn't re-land on an old link.
 */
export function takeRequestedFieldAnchor(): string | null {
  const live = typeof window !== "undefined" ? requestedFieldAnchor() : null;
  const field = live ?? bootField;
  bootField = null;
  return field;
}
