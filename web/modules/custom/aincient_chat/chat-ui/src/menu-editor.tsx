import { Fragment, useState } from "react";
import type { ChromeMenuLink } from "./globals-state";
import { ReferenceField } from "./reference-field";
import {
  ChevronUpIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  ChevronLeftIcon,
  TrashIcon,
  PlusIcon,
} from "./icons";

/** A page-reference link stores a console token (`entity:node:<id>`) in `url`,
 *  so a link's "mode" is derived from whether its url is such a token. */
function isPageToken(url: string): boolean {
  return /^entity:[a-z][a-z0-9_]*:\d+/.test(url.trim());
}

/**
 * The shared inline menu editor used by the Header and Footer tabs of the Globals
 * studio (and, later, a dedicated "Navigation" global).
 *
 * Edits a chrome menu as a NESTED tree, but only ONE level at a time to fit the
 * narrow rail: you see and edit the siblings at the current level (add / rename /
 * re-point / reorder / remove + a per-link shown toggle), and "go in" on a link to
 * edit its children — a breadcrumb walks back up. The component owns no draft
 * state: the parent holds the whole tree (in the chrome draft) and re-renders on
 * every `onChange`; only the ephemeral drill `path` is local. This is the "modern
 * console, not stock admin widgets" north star — editing the `main`/`footer` menus
 * in place instead of linking out to /admin/structure/menu.
 *
 * On Publish the tree is reconciled to the live menu by MenuRepository::sync
 * (create new, update by id, re-parent + reorder by position, delete removed); a
 * link with no `id` is created, sibling order IS the saved weight, and `children`
 * become nested menu links. A new link starts pointing at the front page.
 *
 * Each link targets EITHER a raw url (a friendly path `/about` or a full
 * `https://…` — the server maps it to/from a storable menu-link uri) OR a
 * reference to an existing page (a `entity:node:<id>` token picked with the
 * shared ReferenceField, which the server stores as core's `entity:node/<id>`
 * uri so the live nav tracks the page's canonical/published URL). Both modes
 * live in the one `url` field; a link in "Page" mode is the one holding a token.
 */
export function MenuEditor({
  links,
  onChange,
  addLabel = "Add link",
  rootLabel = "Menu",
}: {
  links: ChromeMenuLink[];
  onChange: (links: ChromeMenuLink[]) => void;
  addLabel?: string;
  rootLabel?: string;
}) {
  // The drill path: indices into the tree at each descended level. Ephemeral —
  // not part of the saved draft.
  const [path, setPath] = useState<number[]>([]);
  // Clamp to a still-valid path in case the tree changed under us (agent edit /
  // discard re-seed). We don't setState during render — just render the clamped one.
  const safePath = clampPath(links, path);

  const level = levelAt(links, safePath);

  // Replace the current level's siblings, returning a fresh whole-tree draft.
  const commit = (nextLevel: ChromeMenuLink[]) =>
    onChange(setLevelAt(links, safePath, nextLevel));

  const patch = (i: number, change: Partial<ChromeMenuLink>) =>
    commit(level.map((l, idx) => (idx === i ? { ...l, ...change } : l)));

  const remove = (i: number) => commit(level.filter((_, idx) => idx !== i));

  const move = (i: number, delta: number) => {
    const j = i + delta;
    if (j < 0 || j >= level.length) return;
    const next = level.slice();
    [next[i], next[j]] = [next[j], next[i]];
    commit(next);
  };

  const add = () => commit([...level, { title: "", url: "/", enabled: true }]);

  const drillInto = (i: number) => setPath([...safePath, i]);
  // Crumb at `depth` (0 = root) shows that ancestor's level.
  const goTo = (depth: number) => setPath(safePath.slice(0, depth));

  // Breadcrumb labels along the descended path (the last crumb is the current
  // parent, whose children are listed below).
  const crumbs: string[] = [];
  let cursor = links;
  for (const i of safePath) {
    crumbs.push(cursor[i]?.title?.trim() || "Untitled");
    cursor = cursor[i]?.children ?? [];
  }

  return (
    <div className="ain-menued">
      {safePath.length > 0 && (
        <div className="ain-menued__crumbs">
          <button
            type="button"
            className="ain-btn ain-iconbtn ain-menued__back"
            onClick={() => goTo(safePath.length - 1)}
            aria-label="Back one level"
            title="Back one level"
          >
            <ChevronLeftIcon />
          </button>
          <nav className="ain-menued__trail" aria-label="Menu location">
            <button type="button" className="ain-btn ain-menued__crumb" onClick={() => goTo(0)}>
              {rootLabel}
            </button>
            {crumbs.map((c, d) => (
              <Fragment key={d}>
                <span className="ain-menued__crumbsep" aria-hidden="true">
                  ›
                </span>
                {d === crumbs.length - 1 ? (
                  <span className="ain-menued__crumb is-current">{c}</span>
                ) : (
                  <button
                    type="button"
                    className="ain-btn ain-menued__crumb"
                    onClick={() => goTo(d + 1)}
                  >
                    {c}
                  </button>
                )}
              </Fragment>
            ))}
          </nav>
        </div>
      )}

      {level.length === 0 ? (
        <p className="ain-menued__empty">No links yet.</p>
      ) : (
        <ul className="ain-menued__list">
          {level.map((link, i) => (
            <MenuRow
              key={link.id ?? `new-${i}`}
              link={link}
              isFirst={i === 0}
              isLast={i === level.length - 1}
              onPatch={(change) => patch(i, change)}
              onMove={(delta) => move(i, delta)}
              onRemove={() => remove(i)}
              onDrill={() => drillInto(i)}
            />
          ))}
        </ul>
      )}
      <button type="button" className="ain-btn ain-menued__add" onClick={add}>
        <PlusIcon /> {addLabel}
      </button>
    </div>
  );
}

/**
 * One link row: reorder handles, the label, the URL｜Page target editor, and the
 * shown/submenu/remove actions.
 *
 * The target's mode (raw URL vs a page reference) is local state so switching to
 * "Page" and not-yet-picking a page keeps the picker up (an empty value isn't a
 * token, so a pure value-derived mode would snap back to URL). It seeds from the
 * link's url on mount, which covers reload / agent edits.
 */
function MenuRow({
  link,
  isFirst,
  isLast,
  onPatch,
  onMove,
  onRemove,
  onDrill,
}: {
  link: ChromeMenuLink;
  isFirst: boolean;
  isLast: boolean;
  onPatch: (change: Partial<ChromeMenuLink>) => void;
  onMove: (delta: number) => void;
  onRemove: () => void;
  onDrill: () => void;
}) {
  const [mode, setMode] = useState<"url" | "page">(isPageToken(link.url) ? "page" : "url");
  const childCount = link.children?.length ?? 0;

  // Switching mode clears a value that doesn't belong to the target mode, so the
  // control starts clean (a leftover token in a url box, or vice versa, is junk).
  const toMode = (next: "url" | "page") => {
    if (next === mode) return;
    if (next === "page" && !isPageToken(link.url)) onPatch({ url: "" });
    if (next === "url" && isPageToken(link.url)) onPatch({ url: "" });
    setMode(next);
  };

  return (
    <li className={`ain-menued__row${link.enabled ? "" : " is-disabled"}`}>
      <div className="ain-menued__reorder">
        <button
          type="button"
          className="ain-btn ain-iconbtn ain-menued__move"
          onClick={() => onMove(-1)}
          disabled={isFirst}
          aria-label="Move up"
          title="Move up"
        >
          <ChevronUpIcon />
        </button>
        <button
          type="button"
          className="ain-btn ain-iconbtn ain-menued__move"
          onClick={() => onMove(1)}
          disabled={isLast}
          aria-label="Move down"
          title="Move down"
        >
          <ChevronDownIcon />
        </button>
      </div>
      <div className="ain-menued__fields">
        <input
          className="ain-field__input ain-menued__title"
          type="text"
          value={link.title}
          placeholder="Link label"
          aria-label="Link label"
          onChange={(e) => onPatch({ title: e.target.value })}
        />
        <div className="ain-menued__target">
          <div className="ain-facet ain-menued__mode" role="group" aria-label="Link target type">
            <button
              type="button"
              className="ain-facet__btn"
              aria-pressed={mode === "url"}
              onClick={() => toMode("url")}
            >
              URL
            </button>
            <button
              type="button"
              className="ain-facet__btn"
              aria-pressed={mode === "page"}
              onClick={() => toMode("page")}
            >
              Page
            </button>
          </div>
          {mode === "url" ? (
            <input
              className="ain-field__input ain-menued__url"
              type="text"
              value={link.url}
              placeholder="/about or https://…"
              aria-label="Link URL"
              onChange={(e) => onPatch({ url: e.target.value })}
            />
          ) : (
            <ReferenceField
              label="Links to page"
              meaning="Pick a page — the link follows its published URL."
              value={link.url}
              onChange={(v) => onPatch({ url: typeof v === "string" ? v : "" })}
              types={["node"]}
              compact
            />
          )}
        </div>
      </div>
      <div className="ain-menued__rowactions">
        <label className="ain-menued__shown" title="Show this link in the menu">
          <input
            type="checkbox"
            checked={link.enabled}
            onChange={(e) => onPatch({ enabled: e.target.checked })}
          />
          <span>Shown</span>
        </label>
        <div className="ain-menued__rowbtns">
          <button
            type="button"
            className="ain-btn ain-iconbtn ain-menued__drill"
            onClick={onDrill}
            aria-label={childCount ? `Edit submenu (${childCount})` : "Add submenu"}
            title={childCount ? `Edit submenu (${childCount})` : "Add submenu"}
          >
            {childCount > 0 && <span className="ain-menued__count">{childCount}</span>}
            <ChevronRightIcon />
          </button>
          <button
            type="button"
            className="ain-btn ain-iconbtn ain-menued__remove"
            onClick={onRemove}
            aria-label="Remove link"
            title="Remove link"
          >
            <TrashIcon />
          </button>
        </div>
      </div>
    </li>
  );
}

/** The sibling array at `path` (each step descends into that index's children). */
function levelAt(tree: ChromeMenuLink[], path: number[]): ChromeMenuLink[] {
  let level = tree;
  for (const i of path) {
    level = level[i]?.children ?? [];
  }
  return level;
}

/** Immutably replace the sibling array at `path`, returning a new root tree. */
function setLevelAt(
  tree: ChromeMenuLink[],
  path: number[],
  next: ChromeMenuLink[],
): ChromeMenuLink[] {
  if (path.length === 0) return next;
  const [head, ...rest] = path;
  return tree.map((link, idx) =>
    idx === head
      ? { ...link, children: setLevelAt(link.children ?? [], rest, next) }
      : link,
  );
}

/** Truncate a drill path to the longest prefix that still resolves in `tree`. */
function clampPath(tree: ChromeMenuLink[], path: number[]): number[] {
  const out: number[] = [];
  let level = tree;
  for (const i of path) {
    if (!level[i]) break;
    out.push(i);
    level = level[i].children ?? [];
  }
  return out;
}
