import { describe, expect, it } from "vitest";
import { moveItem } from "./move-item";

describe("moveItem", () => {
  it("moves an item forward", () => {
    expect(moveItem(["a", "b", "c", "d"], 0, 2)).toEqual(["b", "c", "a", "d"]);
    expect(moveItem(["a", "b", "c"], 1, 2)).toEqual(["a", "c", "b"]);
  });

  it("moves an item backward", () => {
    expect(moveItem(["a", "b", "c", "d"], 3, 1)).toEqual(["a", "d", "b", "c"]);
    expect(moveItem(["a", "b", "c"], 1, 0)).toEqual(["b", "a", "c"]);
  });

  it("is a no-op (same reference) for the same index", () => {
    const list = ["a", "b", "c"];
    expect(moveItem(list, 1, 1)).toBe(list);
  });

  it("is a no-op (same reference) when either index is out of range", () => {
    const list = ["a", "b", "c"];
    expect(moveItem(list, -1, 0)).toBe(list);
    expect(moveItem(list, 3, 0)).toBe(list);
    expect(moveItem(list, 0, -1)).toBe(list);
    expect(moveItem(list, 0, 3)).toBe(list);
    expect(moveItem(list, 0.5, 1)).toBe(list);
    expect(moveItem([], 0, 0)).toEqual([]);
  });

  it("never mutates the input and keeps item identity", () => {
    const rows = [{ t: "one" }, { t: "two" }, { t: "three" }];
    const snapshot = [...rows];
    const next = moveItem(rows, 2, 0);
    expect(next).not.toBe(rows);
    expect(rows).toEqual(snapshot);
    expect(rows[0]).toBe(snapshot[0]);
    expect(next).toEqual([{ t: "three" }, { t: "one" }, { t: "two" }]);
    expect(next[0]).toBe(rows[2]);
  });
});
