import { useState } from "react";
import type { ReactNode } from "react";
import { ReferenceField } from "./reference-field";

/**
 * The studio's ONE link-target control — a URL｜Page switch over a single value.
 *
 * Authoring a link runs into the same wall everywhere: nobody knows a page's URL
 * before the page exists, and copying one means going and visiting it. So a link
 * target is EITHER typed (a site path `/pricing`, or a full `https://…` for an
 * external site) OR PICKED — a page chosen with the shared {@link ReferenceField},
 * stored as a `entity:node:<id>` token and resolved to that page's live URL at
 * render (so it also survives an alias change).
 *
 * Both modes live in the ONE value; a link in "Page" mode is the one holding a
 * token. Extracted from the menu editor (where the pattern started) so component
 * CTA props — `cta_url` / `secondary_url`, top-level and inside repeatable rows —
 * offer exactly the same affordance. The two hosts differ only in `compact`:
 * the menu rail drops the label header and the raw-token chip.
 *
 * Resolution differs per host and is NOT this control's business: a menu link
 * becomes core's `entity:node/<id>` uri (MenuRepository), a page-schema prop is
 * resolved by EntityEmbedResolver::resolveLinks() — which drops the whole button
 * if the target is gone or unviewable.
 */

/** A link target stored as a console reference token (`entity:node:<id>`). */
export function isPageToken(url: unknown): boolean {
  return typeof url === "string" && /^entity:[a-z][a-z0-9_]*:\d+/.test(url.trim());
}

export function LinkField({
  label,
  meaning,
  value,
  onChange,
  disabled = false,
  dirty = false,
  revert,
  compact = false,
  placeholder = "/about or https://…",
}: {
  /** Field label. Omitted in a compact host, where the row labels the control. */
  label?: string;
  meaning?: string;
  value: unknown;
  onChange: (value: string) => void;
  disabled?: boolean;
  /** This field differs from the saved baseline (page-studio per-field dirty). */
  dirty?: boolean;
  /** The pre-built per-field revert marker, shown beside the label when dirty. */
  revert?: ReactNode;
  /** Narrow host (the menu-editor rail): no label header, no raw-token chip. */
  compact?: boolean;
  placeholder?: string;
}) {
  const current = typeof value === "string" ? value : "";
  // The mode is LOCAL, not derived: switching to "Page" and not yet picking one
  // leaves an empty value, which isn't a token — a purely value-derived mode
  // would snap straight back to URL.
  const [mode, setMode] = useState<"url" | "page">(isPageToken(current) ? "page" : "url");
  // …but it must FOLLOW a value that changed from outside (the agent repointing
  // a CTA, a draft reload, a revert). Seeding on mount alone left the control in
  // URL mode showing a raw `entity:node:10` in the text box after the agent
  // switched a link from a path to a page. Track the last value we rendered and
  // re-derive only when it changes underneath us, so a user's own mode switch
  // (which never changes the value's kind) is still honoured.
  const [seen, setSeen] = useState(current);
  if (current !== seen) {
    setSeen(current);
    const wantsPage = isPageToken(current);
    if (wantsPage !== (mode === "page")) setMode(wantsPage ? "page" : "url");
  }

  // Switching clears a value that doesn't belong to the target mode, so the
  // control starts clean (a leftover token in a url box, or vice versa, is junk).
  const toMode = (next: "url" | "page") => {
    if (next === mode || disabled) return;
    if (isPageToken(current) !== (next === "page")) {
      onChange("");
      // Record the value WE are about to cause, so the follow-the-value check
      // above doesn't read our own clear as an outside edit and snap the mode
      // straight back (an empty value is not a token).
      setSeen("");
    }
    setMode(next);
  };

  return (
    <div className="ain-field ain-linkfield" data-dirty={dirty || undefined} title={meaning}>
      <span className="ain-field__label">
        {label && <span className="ain-field__labeltext">{label}</span>}
        <span className="ain-facet ain-linkfield__mode" role="group" aria-label={`${label ?? "Link"} target type`}>
          <button
            type="button"
            className="ain-facet__btn"
            aria-pressed={mode === "url"}
            onClick={() => toMode("url")}
            disabled={disabled}
            title="Type a path or an external address"
          >
            URL
          </button>
          <button
            type="button"
            className="ain-facet__btn"
            aria-pressed={mode === "page"}
            onClick={() => toMode("page")}
            disabled={disabled}
            title="Pick a page on this site"
          >
            Page
          </button>
        </span>
        {revert}
      </span>
      <div className="ain-linkfield__target">
        {mode === "url" ? (
          <input
            className="ain-field__input"
            type="text"
            value={current}
            placeholder={placeholder}
            aria-label={label ? `${label} (URL)` : "Link URL"}
            spellCheck={false}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
          />
        ) : (
          <ReferenceField
            label={label ?? "Links to page"}
            meaning="Pick a page — the link follows its published URL."
            hideLabel
            value={current}
            onChange={(v) => onChange(typeof v === "string" ? v : "")}
            disabled={disabled}
            types={["node"]}
            compact={compact}
          />
        )}
      </div>
    </div>
  );
}
