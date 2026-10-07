import { Fragment, useRef, useState } from "react";
import type { DragEvent, SVGProps } from "react";
import type { ChromeMenuLink } from "@console/sdk";
import {
  LinkField,
  ChevronUpIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  ChevronLeftIcon,
  GripIcon,
  TrashIcon,
  PlusIcon,
  IconButton,
} from "@console/kit";
import {
  clampPath,
  levelAt,
  moveLink,
  moveToAncestor,
  nestLink,
  outdentLink,
  reorderLink,
  setLevelAt,
} from "./menu-tree";

/**
 * The shared inline menu editor used by the Header and Footer tabs of the Globals
 * studio (and, later, a dedicated "Navigation" global).
 *
 * Edits a chrome menu as a NESTED tree, but only ONE level at a time to fit the
 * narrow rail: you see and edit the siblings at the current level (add / rename /
 * re-point / reorder / nest / remove + a per-link shown toggle), and "go in" on a
 * link to edit its children — a breadcrumb walks back up. The component owns no
 * draft state: the parent holds the whole tree (in the chrome draft) and
 * re-renders on every `onChange`; only the ephemeral drill `path` (and the
 * in-flight drag) is local. This is the "modern console, not stock admin
 * widgets" north star — editing the `main`/`footer` menus in place instead of
 * linking out to /admin/structure/menu.
 *
 * Moving links (the tree moves are pure, in `menu-tree.ts`). Each row's grip
 * drags (native HTML5 DnD, the page studio's section-drag idiom: the source
 * index lives in a ref, only the drop indicator is state):
 *   - drop on a row → REORDER: the upper/lower half of the row picks the gap
 *     before/after it, shown as an accent line between rows;
 *   - drop on a sibling's submenu button ("Edit submenu" / "Add submenu") →
 *     NEST it as that sibling's last child;
 *   - drop on a breadcrumb crumb (an ancestor level) → move it UP to that level,
 *     placed right after the branch it came from.
 * The keyboard equivalents are per-row buttons: Move up / Move down (reorder),
 * "Nest under previous link" (last child of the row above; disabled on the
 * first row) and "Move out of submenu" (to the parent level, right after its
 * former parent; disabled at the top level). A move that changes level keeps
 * you on the current level — the moved row just leaves it; nothing auto-drills.
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
  // Commit a whole-tree move, skipping a refused one (same tree back).
  const commitTree = (next: ChromeMenuLink[]) => {
    if (next !== links) onChange(next);
  };

  const patch = (i: number, change: Partial<ChromeMenuLink>) =>
    commit(level.map((l, idx) => (idx === i ? { ...l, ...change } : l)));

  const remove = (i: number) => commit(level.filter((_, idx) => idx !== i));

  const move = (i: number, delta: number) => commitTree(reorderLink(links, safePath, i, i + delta));
  const nestUnderPrevious = (i: number) => commitTree(nestLink(links, safePath, i, i - 1));
  const outdent = (i: number) => commitTree(outdentLink(links, [...safePath, i]));

  const add = () => commit([...level, { title: "", url: "/", enabled: true }]);

  const drillInto = (i: number) => setPath([...safePath, i]);
  // Crumb at `depth` (0 = root) shows that ancestor's level.
  const goTo = (depth: number) => setPath(safePath.slice(0, depth));

  // --- Drag (see the docblock). The source index at the CURRENT level lives in
  // a ref — no re-render on drag start; `drop` is the indicator to paint.
  const dragFrom = useRef<number | null>(null);
  const [drop, setDrop] = useState<DropTarget>(null);
  const showDrop = (next: DropTarget) => {
    if (!sameDrop(drop, next)) setDrop(next);
  };
  const endDrag = () => {
    dragFrom.current = null;
    setDrop(null);
  };
  /** A drop zone's dragover: accept only our own in-flight row drag. */
  const accept = (e: DragEvent, target: Exclude<DropTarget, null>) => {
    if (dragFrom.current === null) return;
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = "move";
    showDrop(target);
  };
  /** The gap a pointer over row `i` means: before it (upper half) or after it. */
  const gapAt = (e: DragEvent<HTMLLIElement>, i: number) => {
    const box = e.currentTarget.getBoundingClientRect();
    return e.clientY < box.top + box.height / 2 ? i : i + 1;
  };
  const rowDragOver = (e: DragEvent<HTMLLIElement>, i: number) => {
    const from = dragFrom.current;
    if (from === null) return;
    // Stay a valid drop zone even over a "stay put" gap (the two hugging the
    // dragged row), but paint no indicator there.
    e.preventDefault();
    e.dataTransfer.dropEffect = "move";
    const gap = gapAt(e, i);
    showDrop(gap === from || gap === from + 1 ? null : { kind: "gap", index: gap });
  };
  const dropOn = (e: DragEvent, run: (from: number) => ChromeMenuLink[]) => {
    e.preventDefault();
    e.stopPropagation();
    const from = dragFrom.current;
    endDrag();
    if (from !== null) commitTree(run(from));
  };

  // Breadcrumb labels along the descended path (the last crumb is the current
  // parent, whose children are listed below).
  const crumbs: string[] = [];
  let cursor = links;
  for (const i of safePath) {
    crumbs.push(cursor[i]?.title?.trim() || "Untitled");
    cursor = cursor[i]?.children ?? [];
  }

  /** Ancestor crumb (`depth` < current depth) as a drop target: move up there. */
  const crumbDrop = (depth: number) => ({
    "data-drop": drop?.kind === "crumb" && drop.depth === depth ? "crumb" : undefined,
    onDragOver: (e: DragEvent) => accept(e, { kind: "crumb", depth }),
    onDrop: (e: DragEvent) => dropOn(e, (from) => moveToAncestor(links, [...safePath, from], depth)),
  });

  return (
    <div
      className="ain-globals-menu"
      onDragLeave={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) showDrop(null);
      }}
    >
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
            <button
              type="button"
              className="ain-btn ain-globals-menu__crumb"
              onClick={() => goTo(0)}
              {...crumbDrop(0)}
            >
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
                    {...crumbDrop(d + 1)}
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
              isNested={safePath.length > 0}
              drop={
                drop?.kind === "nest" && drop.index === i
                  ? "nest"
                  : drop?.kind === "gap" && drop.index === i
                    ? "before"
                    : drop?.kind === "gap" && drop.index === i + 1 && i === level.length - 1
                      ? "after"
                      : undefined
              }
              onPatch={(change) => patch(i, change)}
              onMove={(delta) => move(i, delta)}
              onNest={() => nestUnderPrevious(i)}
              onOutdent={() => outdent(i)}
              onRemove={() => remove(i)}
              onDrill={() => drillInto(i)}
              onDragStart={(e) => {
                e.dataTransfer.effectAllowed = "move";
                e.dataTransfer.setData("text/plain", String(i));
                dragFrom.current = i;
              }}
              onDragEnd={endDrag}
              onRowDragOver={(e) => rowDragOver(e, i)}
              onRowDrop={(e) =>
                dropOn(e, (from) => moveLink(links, [...safePath, from], safePath, gapAt(e, i)))
              }
              onNestDragOver={(e) => {
                if (dragFrom.current !== null && dragFrom.current !== i) accept(e, { kind: "nest", index: i });
              }}
              onNestDrop={(e) => dropOn(e, (from) => nestLink(links, safePath, from, i))}
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

/** Where an in-flight drag would land: a gap at the current level, into a row's submenu, or up to a crumb's level. */
type DropTarget =
  | { kind: "gap"; index: number }
  | { kind: "nest"; index: number }
  | { kind: "crumb"; depth: number }
  | null;

const sameDrop = (a: DropTarget, b: DropTarget) => JSON.stringify(a) === JSON.stringify(b);

/**
 * One link row: the drag grip + reorder/nest handles, the label, the URL｜Page
 * target editor ({@link LinkField}, shared with the page studio's CTA props),
 * and the shown/submenu/remove actions. The row is a reorder drop zone; its
 * submenu button is the nest drop zone.
 */
function MenuRow({
  link,
  isFirst,
  isLast,
  isNested,
  drop,
  onPatch,
  onMove,
  onNest,
  onOutdent,
  onRemove,
  onDrill,
  onDragStart,
  onDragEnd,
  onRowDragOver,
  onRowDrop,
  onNestDragOver,
  onNestDrop,
}: {
  link: ChromeMenuLink;
  isFirst: boolean;
  isLast: boolean;
  isNested: boolean;
  drop?: "before" | "after" | "nest";
  onPatch: (change: Partial<ChromeMenuLink>) => void;
  onMove: (delta: number) => void;
  onNest: () => void;
  onOutdent: () => void;
  onRemove: () => void;
  onDrill: () => void;
  onDragStart: (e: DragEvent<HTMLSpanElement>) => void;
  onDragEnd: () => void;
  onRowDragOver: (e: DragEvent<HTMLLIElement>) => void;
  onRowDrop: (e: DragEvent<HTMLLIElement>) => void;
  onNestDragOver: (e: DragEvent<HTMLButtonElement>) => void;
  onNestDrop: (e: DragEvent<HTMLButtonElement>) => void;
}) {
  const childCount = link.children?.length ?? 0;

  return (
    <li
      className="ain-globals-menu__row"
      data-disabled={link.enabled ? undefined : true}
      data-drop={drop}
      onDragOver={onRowDragOver}
      onDrop={onRowDrop}
    >
      <span
        className="ain-globals-menu__grip"
        draggable
        aria-hidden
        title="Drag to move (drop on a submenu button to nest)"
        onDragStart={onDragStart}
        onDragEnd={onDragEnd}
      >
        <GripIcon />
      </span>
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
          label="Nest under previous link"
          className="ain-globals-menu__move"
          type="button"
          onClick={onNest}
          disabled={isFirst}
        >
          <IndentIcon />
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
        <IconButton
          label="Move out of submenu"
          className="ain-globals-menu__move"
          type="button"
          onClick={onOutdent}
          disabled={!isNested}
        >
          <OutdentIcon />
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
            data-drop={drop === "nest" ? "nest" : undefined}
            onDragOver={onNestDragOver}
            onDrop={onNestDrop}
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

// Nest / un-nest glyphs, in the kit's icon grammar (24-unit box, 2px
// currentColor stroke, 1em) — the kit has no indent pair, and these are local
// to this editor.
function Glyph(props: SVGProps<SVGSVGElement>) {
  return (
    <svg
      width="1em"
      height="1em"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      {...props}
    />
  );
}

const IndentIcon = () => (
  <Glyph>
    <polyline points="3 8 7 12 3 16" />
    <line x1="21" y1="12" x2="11" y2="12" />
    <line x1="21" y1="6" x2="11" y2="6" />
    <line x1="21" y1="18" x2="11" y2="18" />
  </Glyph>
);

const OutdentIcon = () => (
  <Glyph>
    <polyline points="7 8 3 12 7 16" />
    <line x1="21" y1="12" x2="11" y2="12" />
    <line x1="21" y1="6" x2="11" y2="6" />
    <line x1="21" y1="18" x2="11" y2="18" />
  </Glyph>
);
