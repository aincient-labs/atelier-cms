import type { ChromeMenuLink } from "@console/sdk";

/**
 * Pure, immutable moves over a chrome menu tree (`ChromeMenuLink[]`, nested via
 * `children`), addressed by INDEX PATHS: `[2, 0]` is the first child of the
 * third top-level link; a "parent path" names a sibling array (`[]` = the top
 * level, `[2]` = the third link's children).
 *
 * Everything is expressed through one {@link moveLink}: take the link at a path
 * out of the tree and insert it into a sibling array at a gap index. The moved
 * object keeps its `id` and its own `children` (a branch moves whole), so
 * MenuRepository::sync on Publish re-parents it by id instead of recreating it.
 * Every refused or out-of-range move returns the input tree ITSELF, so a caller
 * can skip committing a draft when nothing moved (`next === tree`).
 */

/** The sibling array at `path` (each step descends into that index's children). */
export function levelAt(tree: ChromeMenuLink[], path: number[]): ChromeMenuLink[] {
  let level = tree;
  for (const i of path) {
    level = level[i]?.children ?? [];
  }
  return level;
}

/** Immutably replace the sibling array at `path`, returning a new root tree. */
export function setLevelAt(
  tree: ChromeMenuLink[],
  path: number[],
  next: ChromeMenuLink[],
): ChromeMenuLink[] {
  if (path.length === 0) return next;
  const [head, ...rest] = path;
  return tree.map((link, idx) =>
    idx === head ? { ...link, children: setLevelAt(link.children ?? [], rest, next) } : link,
  );
}

/** Truncate a drill path to the longest prefix that still resolves in `tree`. */
export function clampPath(tree: ChromeMenuLink[], path: number[]): number[] {
  const out: number[] = [];
  let level = tree;
  for (const i of path) {
    if (!level[i]) break;
    out.push(i);
    level = level[i].children ?? [];
  }
  return out;
}

/** True when `path` resolves to a link (and, for a parent path, to a sibling array). */
function resolves(tree: ChromeMenuLink[], path: number[]): boolean {
  return clampPath(tree, path).length === path.length;
}

const isPrefix = (prefix: number[], path: number[]) =>
  prefix.length <= path.length && prefix.every((v, i) => path[i] === v);

/**
 * Move the link at `from` into the sibling array at `toParent`, inserting it at
 * gap `toIndex` — counted in that array AS IT IS BEFORE the move (0 = before the
 * first row, `length` = after the last), i.e. exactly the "drop between rows"
 * position a pointer sees. Out-of-range gaps clamp to the ends.
 *
 * Refused (returns `tree` unchanged): a `from` that doesn't resolve, a
 * `toParent` that doesn't resolve, a move into the link itself or any of its
 * descendants (a cycle), and a move that lands where the link already is.
 */
export function moveLink(
  tree: ChromeMenuLink[],
  from: number[],
  toParent: number[],
  toIndex: number,
): ChromeMenuLink[] {
  if (from.length === 0 || !resolves(tree, from) || !resolves(tree, toParent)) return tree;
  // A link cannot live inside itself or its own subtree.
  if (isPrefix(from, toParent)) return tree;

  const fromParent = from.slice(0, -1);
  const fromIndex = from[from.length - 1];
  const destLen = levelAt(tree, toParent).length;
  let gap = Math.max(0, Math.min(Number.isFinite(toIndex) ? Math.trunc(toIndex) : destLen, destLen));

  // Same sibling array: gaps fromIndex and fromIndex+1 both mean "stay put".
  const sameLevel = fromParent.length === toParent.length && isPrefix(fromParent, toParent);
  if (sameLevel && (gap === fromIndex || gap === fromIndex + 1)) return tree;

  // 1. Take the link out.
  const sourceLevel = levelAt(tree, fromParent);
  const moved = sourceLevel[fromIndex];
  const without = setLevelAt(
    tree,
    fromParent,
    sourceLevel.filter((_, i) => i !== fromIndex),
  );

  // 2. Re-address the destination in the pruned tree: removing index
  //    `fromIndex` from `fromParent` shifts every later sibling (and every path
  //    running through one) down by one.
  const depth = fromParent.length;
  const dest = toParent.slice();
  if (sameLevel) {
    if (gap > fromIndex) gap -= 1;
  } else if (isPrefix(fromParent, dest) && dest.length > depth && dest[depth] > fromIndex) {
    dest[depth] -= 1;
  }

  // 3. Insert it.
  const destLevel = levelAt(without, dest).slice();
  destLevel.splice(gap, 0, moved);
  return setLevelAt(without, dest, destLevel);
}

/**
 * Reorder within one sibling array: the link at index `from` ends up at index
 * `to` (final position, `moveItem` semantics — Move up/down is `to = from ∓ 1`).
 */
export function reorderLink(
  tree: ChromeMenuLink[],
  parent: number[],
  from: number,
  to: number,
): ChromeMenuLink[] {
  const len = levelAt(tree, parent).length;
  if (to < 0 || to >= len) return tree;
  return moveLink(tree, [...parent, from], parent, to > from ? to + 1 : to);
}

/** Nest: move the link at `index` INTO its sibling at `target`, as that sibling's LAST child. */
export function nestLink(
  tree: ChromeMenuLink[],
  parent: number[],
  index: number,
  target: number,
): ChromeMenuLink[] {
  if (index === target) return tree;
  const host = levelAt(tree, parent)[target];
  if (!host) return tree;
  return moveLink(tree, [...parent, index], [...parent, target], host.children?.length ?? 0);
}

/**
 * Move the link at `path` up to the ancestor level `depth` (0 = the top level;
 * must be shallower than its current level), placed right AFTER the branch it
 * came from — the ancestor-level link it was nested under. `depth = level − 1`
 * is a plain outdent.
 */
export function moveToAncestor(
  tree: ChromeMenuLink[],
  path: number[],
  depth: number,
): ChromeMenuLink[] {
  if (depth < 0 || depth >= path.length - 1) return tree;
  return moveLink(tree, path, path.slice(0, depth), path[depth] + 1);
}

/** Outdent: move the link at `path` out of its submenu, right after its former parent. */
export function outdentLink(tree: ChromeMenuLink[], path: number[]): ChromeMenuLink[] {
  return moveToAncestor(tree, path, path.length - 2);
}
