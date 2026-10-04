/**
 * Move one item of a list to another index — the splice every reorder in the
 * content studio shares (section grip-drag + move up/down, repeatable rows).
 *
 * Pure: returns a NEW array and never mutates `list`. An out-of-range `from`
 * or `to`, or `from === to`, is a no-op that returns `list` itself, so a caller
 * can skip committing a draft when nothing moved (`next === list`).
 */
export function moveItem<T>(list: readonly T[], from: number, to: number): T[] {
  const n = list.length;
  if (
    from === to ||
    !Number.isInteger(from) ||
    !Number.isInteger(to) ||
    from < 0 ||
    from >= n ||
    to < 0 ||
    to >= n
  ) {
    return list as T[];
  }
  const next = [...list];
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return next;
}
