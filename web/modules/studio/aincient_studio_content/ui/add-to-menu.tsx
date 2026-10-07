import { useEffect, useId, useState } from "react";
import { Button, MenuIcon } from "@console/kit";
import {
  apiUrl,
  cloneChromeDraft,
  consoleNav,
  getChromeDraft,
  seedChromeDraft,
  setChromeDraft,
  subscribeChromeDraft,
  type ChromeDraft,
  type ChromeManifest,
  type ChromeMenuLink,
} from "@console/sdk";
import { MENU_LABEL, menuHasPage, parentOptions, stageMenuLink, type MenuName } from "./menu-placement";

/**
 * The Content rail's "Add to menu" row (atelier-cms#35).
 *
 * It STAGES a link to this page into the shared chrome DRAFT (globals-state, the
 * store the Navigation & Pages studio edits) and never publishes: publishing a
 * menu stays in Navigation & Pages, like every other chrome edit. That studio
 * adopts an existing draft on mount instead of re-seeding it, so the new link
 * shows there as an unsaved change against the saved chrome, ready to review,
 * move, rename and Publish (or Discard).
 *
 * The baseline is seeded exactly as Navigation & Pages seeds it
 * (`seedChromeDraft` over GET /atelier/chrome/manifest) when no draft exists yet,
 * so its dirty count sees only the staged link.
 *
 * LIMITATION (shared by every chrome draft today): the draft lives in memory, so
 * a full browser reload — or opening Navigation in a new tab — before Publish
 * drops the staged link. That's why "Review in Navigation" is an in-place room
 * change, not a link a modifier-click could open in another tab.
 */

const MANIFEST_URL = apiUrl("/chrome/manifest");

/** The arrow-out glyph ("↗") for a hop to another studio — inline SVG, never a glyph. */
function ArrowUpRightIcon() {
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
    >
      <line x1="7" y1="17" x2="17" y2="7" />
      <polyline points="8 7 17 7 17 16" />
    </svg>
  );
}

/** The chrome draft, live (another surface may change it while this rail is up). */
function useChromeDraft(): ChromeDraft | null {
  const [draft, setDraft] = useState<ChromeDraft | null>(() => getChromeDraft());
  useEffect(() => subscribeChromeDraft(setDraft), []);
  return draft;
}

export function AddToMenuRow({ nodeId, title }: { nodeId: string | null; title: string }) {
  const draft = useChromeDraft();
  const [open, setOpen] = useState(false);
  const [menu, setMenu] = useState<MenuName>("main");
  // The parent's index path, comma-joined ("" = top level) — a select's value.
  const [parent, setParent] = useState("");
  const [manifest, setManifest] = useState<ChromeManifest | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [added, setAdded] = useState<MenuName | null>(null);
  const menuId = useId();
  const parentId = useId();

  // The saved menus are only needed when there's no draft to read (and seed) yet.
  const needManifest = open && !draft && !manifest;
  useEffect(() => {
    if (!needManifest) return;
    let live = true;
    setLoadError(null);
    fetch(MANIFEST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ChromeManifest) => live && setManifest(data))
      .catch((e) => live && setLoadError(`Couldn’t load the menus: ${e instanceof Error ? e.message : e}`));
    return () => {
      live = false;
    };
  }, [needManifest]);

  // The page itself is a new, unsaved draft: there's no node to link to yet.
  if (nodeId === null) {
    return (
      <div className="ain-content-menu" data-testid="menu-row">
        <span className="ain-content-checks__head">
          <MenuIcon /> Menu
        </span>
        <span className="ain-content-checks__muted">Save the page first to add it to a menu.</span>
      </div>
    );
  }

  const menus = (draft ?? manifest)?.menus ?? null;
  const tree: ChromeMenuLink[] = menus?.[menu] ?? [];
  const already = menus !== null && menuHasPage(tree, nodeId);
  const options = parentOptions(tree);

  const commit = () => {
    const base = getChromeDraft() ?? (manifest ? seedChromeDraft(manifest) : null);
    if (!base) return;
    const path = parent === "" ? [] : parent.split(",").map(Number);
    setChromeDraft(stageMenuLink(cloneChromeDraft(base), menu, path, nodeId, title));
    setAdded(menu);
    setOpen(false);
  };

  return (
    <div className="ain-content-menu" data-testid="menu-row">
      <div className="ain-content-menu__line">
        <span className="ain-content-checks__head">
          <MenuIcon /> Menu
        </span>
        {added && !open && (
          <span className="ain-content-menu__added" role="status">
            Added to the {MENU_LABEL[added]} draft ·{" "}
            <Button
              size="sm"
              variant="quiet"
              className="ain-content-menu__review"
              onClick={() => consoleNav.enterRoom({ kind: "studio", studio: "globals" })}
              title="Open Navigation & Pages to review and publish the menu"
            >
              Review in Navigation <ArrowUpRightIcon />
            </Button>
          </span>
        )}
        {!open && (
          <Button
            size="sm"
            className="ain-content-menu__open"
            onClick={() => {
              setOpen(true);
              setParent("");
            }}
            title="Stage a link to this page in the main or footer menu"
          >
            Add to menu
          </Button>
        )}
      </div>

      {open && (
        <div className="ain-content-menu__form">
          <div className="ain-field">
            <label className="ain-field__label" htmlFor={menuId}>
              Menu
            </label>
            <select
              id={menuId}
              className="ain-field__input"
              value={menu}
              onChange={(e) => {
                setMenu(e.target.value as MenuName);
                setParent("");
              }}
            >
              <option value="main">Main</option>
              <option value="footer">Footer</option>
            </select>
          </div>
          <div className="ain-field">
            <label className="ain-field__label" htmlFor={parentId}>
              Place under
            </label>
            <select
              id={parentId}
              className="ain-field__input"
              value={parent}
              onChange={(e) => setParent(e.target.value)}
              disabled={!menus}
            >
              <option value="">Top level</option>
              {options.map((o) => (
                <option key={o.path.join(",")} value={o.path.join(",")}>
                  {"— ".repeat(o.depth)}
                  {o.title}
                </option>
              ))}
            </select>
          </div>
          {loadError && <span className="ain-content-menu__error">{loadError}</span>}
          {already && (
            <span className="ain-content-checks__muted" role="status">
              Already in the {MENU_LABEL[menu]}
            </span>
          )}
          <div className="ain-content-menu__actions">
            <Button size="sm" variant="quiet" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button size="sm" onClick={commit} disabled={!menus || already}>
              Add to {MENU_LABEL[menu]}
            </Button>
          </div>
          <p className="ain-content-checks__muted ain-content-menu__note">
            Staged in Navigation &amp; Pages as a draft — nothing goes live until you publish it there.
          </p>
        </div>
      )}
    </div>
  );
}
