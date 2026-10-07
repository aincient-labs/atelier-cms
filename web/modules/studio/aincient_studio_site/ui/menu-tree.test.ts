/**
 * The menu editor's pure tree moves (`menu-tree.ts`): reorder within a level,
 * nest into a sibling (last child), outdent (right after the former parent),
 * move up to an ancestor level, and the refusals (cycle, stay-put, bad paths)
 * that hand the SAME tree back. A moved link keeps its id and its own subtree.
 */

import { describe, expect, it } from "vitest";
import type { ChromeMenuLink } from "@console/sdk";
import {
  clampPath,
  levelAt,
  moveLink,
  moveToAncestor,
  nestLink,
  outdentLink,
  reorderLink,
} from "./menu-tree";

const link = (id: number, children?: ChromeMenuLink[]): ChromeMenuLink => ({
  id,
  title: `L${id}`,
  url: `/l${id}`,
  enabled: true,
  ...(children ? { children } : {}),
});

/** A compact shape: ids, nested where there are children. */
type Shape = (number | [number, Shape])[];
const shape = (tree: ChromeMenuLink[]): Shape =>
  tree.map((l) => (l.children?.length ? [l.id as number, shape(l.children)] : (l.id as number)));

// 1, 2 [21, 22 [221]], 3
const tree = (): ChromeMenuLink[] => [
  link(1),
  link(2, [link(21), link(22, [link(221)])]),
  link(3),
];

describe("reorderLink", () => {
  it("moves a link down and up within the top level", () => {
    expect(shape(reorderLink(tree(), [], 0, 2))).toEqual([[2, [21, [22, [221]]]], 3, 1]);
    expect(shape(reorderLink(tree(), [], 2, 0))).toEqual([3, 1, [2, [21, [22, [221]]]]]);
    expect(shape(reorderLink(tree(), [], 0, 1))).toEqual([[2, [21, [22, [221]]]], 1, 3]);
  });

  it("reorders inside a submenu without touching other levels", () => {
    const t = tree();
    const next = reorderLink(t, [1], 1, 0);
    expect(shape(next)).toEqual([1, [2, [[22, [221]], 21]], 3]);
    expect(next[0]).toBe(t[0]);
    expect(next[2]).toBe(t[2]);
  });

  it("refuses an out-of-range or no-op reorder with the same tree", () => {
    const t = tree();
    expect(reorderLink(t, [], 0, -1)).toBe(t);
    expect(reorderLink(t, [], 2, 3)).toBe(t);
    expect(reorderLink(t, [], 1, 1)).toBe(t);
  });
});

describe("moveLink gaps", () => {
  it("counts the gap in the level as it was before the move", () => {
    // Gap 3 = after the last top-level row.
    expect(shape(moveLink(tree(), [0], [], 3))).toEqual([[2, [21, [22, [221]]]], 3, 1]);
    // Gaps hugging the link itself are stay-put.
    const t = tree();
    expect(moveLink(t, [1], [], 1)).toBe(t);
    expect(moveLink(t, [1], [], 2)).toBe(t);
  });

  it("re-addresses a destination that runs through a later sibling", () => {
    // Move 1 into 2's submenu at the front: 2 shifts from index 1 to 0 once 1 leaves.
    expect(shape(moveLink(tree(), [0], [1], 0))).toEqual([[2, [1, 21, [22, [221]]]], 3]);
  });
});

describe("nestLink", () => {
  it("nests a link as its sibling's LAST child", () => {
    expect(shape(nestLink(tree(), [], 2, 1))).toEqual([1, [2, [21, [22, [221]], 3]]]);
  });

  it("nests under a sibling with no submenu yet", () => {
    expect(shape(nestLink(tree(), [], 1, 0))).toEqual([[1, [[2, [21, [22, [221]]]]]], 3]);
  });

  it("nests inside a submenu", () => {
    expect(shape(nestLink(tree(), [1], 0, 1))).toEqual([1, [2, [[22, [221, 21]]]], 3]);
  });

  it("refuses nesting a link into itself", () => {
    const t = tree();
    expect(nestLink(t, [], 1, 1)).toBe(t);
    expect(nestLink(t, [], 1, 9)).toBe(t);
  });
});

describe("outdentLink / moveToAncestor", () => {
  it("outdents right after the former parent", () => {
    expect(shape(outdentLink(tree(), [1, 0]))).toEqual([1, [2, [[22, [221]]]], 21, 3]);
  });

  it("outdents a grandchild one level", () => {
    expect(shape(outdentLink(tree(), [1, 1, 0]))).toEqual([1, [2, [21, 22, 221]], 3]);
  });

  it("moves up to the top level, right after the branch it came from", () => {
    expect(shape(moveToAncestor(tree(), [1, 1, 0], 0))).toEqual([1, [2, [21, 22]], 221, 3]);
  });

  it("refuses at the top level", () => {
    const t = tree();
    expect(outdentLink(t, [0])).toBe(t);
    expect(moveToAncestor(t, [1, 0], 1)).toBe(t);
  });
});

describe("cycles and bad paths", () => {
  it("refuses moving a link into itself or its own descendant", () => {
    const t = tree();
    expect(moveLink(t, [1], [1], 0)).toBe(t);
    expect(moveLink(t, [1], [1, 1], 0)).toBe(t);
    expect(moveLink(t, [1], [1, 1, 0], 0)).toBe(t);
  });

  it("refuses a source or destination that does not resolve", () => {
    const t = tree();
    expect(moveLink(t, [7], [], 0)).toBe(t);
    expect(moveLink(t, [], [], 0)).toBe(t);
    expect(moveLink(t, [0], [5, 0], 0)).toBe(t);
  });
});

describe("identity", () => {
  it("keeps the moved link's object, id, fields and subtree; never mutates the input", () => {
    const t = tree();
    const before = JSON.stringify(t);
    const branch = t[1];
    const next = nestLink(t, [], 1, 0);
    expect(JSON.stringify(t)).toBe(before);
    const moved = next[0].children?.[0];
    expect(moved).toBe(branch);
    expect(moved?.id).toBe(2);
    expect(moved?.title).toBe("L2");
    expect(moved?.children?.[1].children?.[0].id).toBe(221);
  });

  it("levelAt / clampPath read the moved tree", () => {
    const next = outdentLink(tree(), [1, 1]);
    expect(levelAt(next, []).map((l) => l.id)).toEqual([1, 2, 22, 3]);
    expect(clampPath(next, [1, 1])).toEqual([1]);
  });
});
