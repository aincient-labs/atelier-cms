import { Fragment, useState } from "react";
import type { ChromeMenuLink } from "@console/sdk";
import {
  LinkField,
  ChevronUpIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  ChevronLeftIcon,
  TrashIcon,
  PlusIcon,
  IconButton,
} from "@console/kit";

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
    <div className="ain-globals-menu">
      {safePath.length > 0 && (
        <div className="ain-globals-menu__crumbs">
          <IconButton
            label="Back one level"
            className="ain-globals-menu__back"
            type="button"
            onClick={() => goTo(safePath.length - 1)}
          >
            <ChevronLeftIcon />
          </IconButton>
          <nav className="ain-globals-menu__trail" aria-label="Menu location">
            <button type="button" className="ain-btn ain-globals-menu__crumb" onClick={() => goTo(0)}>
              {rootLabel}
            </button>
            {crumbs.map((c, d) => (
              <Fragment key={d}>
                <span className="ain-globals-menu__crumbsep" aria-hidden="true">
                  ›
                </span>
                {d === crumbs.length - 1 ? (
                  <span className="ain-globals-menu__crumb" data-current>{c}</span>
                ) : (
                  <button
                    type="button"
                    className="ain-btn ain-globals-menu__crumb"
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
        <p className="ain-globals-menu__empty">No links yet.</p>
      ) : (
        <ul className="ain-globals-menu__list">
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
      <button type="button" className="ain-btn ain-globals-menu__add" onClick={add}>
        <PlusIcon /> {addLabel}
      </button>
    </div>
  );
}

/**
 * One link row: reorder handles, the label, the URL｜Page target editor
 * ({@link LinkField}, shared with the page studio's CTA props), and the
 * shown/submenu/remove actions.
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
  const childCount = link.children?.length ?? 0;

  return (
    <li className="ain-globals-menu__row" data-disabled={link.enabled ? undefined : true}>
      <div className="ain-globals-menu__reorder">
        <IconButton
          label="Move up"
          className="ain-globals-menu__move"
          type="button"
          onClick={() => onMove(-1)}
          disabled={isFirst}
        >
          <ChevronUpIcon />
        </IconButton>
        <IconButton
          label="Move down"
          className="ain-globals-menu__move"
          type="button"
          onClick={() => onMove(1)}
          disabled={isLast}
        >
          <ChevronDownIcon />
        </IconButton>
      </div>
      <div className="ain-globals-menu__fields">
        <input
          className="ain-field__input ain-globals-menu__title"
          type="text"
          value={link.title}
          placeholder="Link label"
          aria-label="Link label"
          onChange={(e) => onPatch({ title: e.target.value })}
        />
        <LinkField value={link.url} onChange={(url) => onPatch({ url })} compact />
      </div>
      <div className="ain-globals-menu__rowactions">
        <label className="ain-globals-menu__shown" title="Show this link in the menu">
          <input
            type="checkbox"
            checked={link.enabled}
            onChange={(e) => onPatch({ enabled: e.target.checked })}
          />
          <span>Shown</span>
        </label>
        <div className="ain-globals-menu__rowbtns">
          <IconButton
            label={childCount ? `Edit submenu (${childCount})` : "Add submenu"}
            className="ain-globals-menu__drill"
            type="button"
            onClick={onDrill}
          >
            {childCount > 0 && <span className="ain-globals-menu__count">{childCount}</span>}
            <ChevronRightIcon />
          </IconButton>
          <IconButton
            label="Remove link"
            className="ain-globals-menu__remove"
            type="button"
            onClick={onRemove}
          >
            <TrashIcon />
          </IconButton>
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
