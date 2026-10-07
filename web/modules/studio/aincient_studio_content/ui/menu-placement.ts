import type { ChromeDraft, ChromeMenuLink } from "@console/sdk";

/**
 * Pure helpers behind the Content rail's "Add to menu" (atelier-cms#35): find a
 * page in a chrome menu tree, list the links a new one can nest under, and stage
 * a link into a copy of the chrome draft.
 *
 * Paths are INDEX PATHS over the nested `ChromeMenuLink[]` (`[]` = the top level,
 * `[2, 0]` = the first child of the third link) — the same addressing the
 * Navigation & Pages studio's `menu-tree.ts` uses. That module's
 * `levelAt`/`setLevelAt` can't be imported from here (the studio import fence
 * forbids studio-on-studio imports — `src/studio-fence.ts`), so the two tiny
 * walkers below are a local copy of the same shape.
 */

/** The two chrome menus a page can be added to. */
export type MenuName = "main" | "footer";

/** Operator wording for a menu ("main menu" / "footer menu"). */
export const MENU_LABEL: Record<MenuName, string> = { main: "main menu", footer: "footer menu" };

/** The reference token a menu link to a page stores (`entity:node:<nid>`) — the
 *  value the menu editor's Page picker writes; MenuRepository maps it to core's
 *  `entity:node/<nid>` uri on Publish. */
export function pageToken(nid: string | number): string {
  return `entity:node:${nid}`;
}

/**
 * Whether a link's `url` points at page `nid`: its reference token, or the raw
 * `/node/<nid>` path a hand-typed link may hold. A link typed as the page's
 * ALIAS (`/about`) can't be recognised without a server lookup, so it is not.
 */
export function linksToPage(url: string, nid: string | number): boolean {
  const u = url.trim();
  return u === pageToken(nid) || u === `/node/${nid}`;
}

/** Whether any link in the tree (at any depth) points at page `nid`. */
export function menuHasPage(tree: ChromeMenuLink[], nid: string | number): boolean {
  return tree.some((l) => linksToPage(l.url ?? "", nid) || menuHasPage(l.children ?? [], nid));
}

/** One link a new link can be placed under. */
export type ParentOption = { path: number[]; title: string; depth: number };

/** Every link of the tree, depth-first in display order, with its depth (0 = top level). */
export function parentOptions(tree: ChromeMenuLink[], prefix: number[] = []): ParentOption[] {
  return tree.flatMap((link, i) => {
    const path = [...prefix, i];
    return [
      { path, title: link.title.trim() || "(untitled link)", depth: prefix.length },
      ...parentOptions(link.children ?? [], path),
    ];
  });
}

/** The children array under the link at `path` (`[]` = the top level), or null if
 *  the path doesn't resolve. */
function levelAt(tree: ChromeMenuLink[], path: number[]): ChromeMenuLink[] | null {
  let level = tree;
  for (const i of path) {
    const link = level[i];
    if (!link) return null;
    level = link.children ?? [];
  }
  return level;
}

/** Immutably replace the children array under `path`, returning a new root tree. */
function setLevelAt(tree: ChromeMenuLink[], path: number[], next: ChromeMenuLink[]): ChromeMenuLink[] {
  if (path.length === 0) return next;
  const [head, ...rest] = path;
  return tree.map((link, idx) =>
    idx === head ? { ...link, children: setLevelAt(link.children ?? [], rest, next) } : link,
  );
}

/**
 * Append `link` as the LAST child of the link at `parentPath` (`[]` = the end of
 * the top level). Returns the input tree itself when the path doesn't resolve.
 */
export function appendUnder(tree: ChromeMenuLink[], parentPath: number[], link: ChromeMenuLink): ChromeMenuLink[] {
  const level = levelAt(tree, parentPath);
  if (!level) return tree;
  return setLevelAt(tree, parentPath, [...level, link]);
}

/**
 * A NEW draft with a link to page `nid` (titled `title`) appended under
 * `parentPath` in `menu`. The input draft is never mutated. The link has no `id`,
 * so MenuRepository::sync creates it — but only when the operator Publishes in
 * Navigation & Pages; staging it here writes nothing to the server.
 */
export function stageMenuLink(
  draft: ChromeDraft,
  menu: MenuName,
  parentPath: number[],
  nid: string | number,
  title: string,
): ChromeDraft {
  const link: ChromeMenuLink = { title, url: pageToken(nid), enabled: true };
  return { ...draft, menus: { ...draft.menus, [menu]: appendUnder(draft.menus[menu], parentPath, link) } };
}
