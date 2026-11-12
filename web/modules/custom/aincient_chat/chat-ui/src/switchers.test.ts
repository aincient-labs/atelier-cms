import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * One switcher grammar (audit F8): a filter or a mode is the kit
 * `SegmentedControl`, sections of one form are the kit `Tabs`. Before this,
 * studios hand-built four treatments (an ink pair, underline tabs, tab-role
 * filters), each with its own height and keyboard behaviour. Outside `kit/`,
 * markup may not write the segmented classes or a tab list by hand.
 */

const HAND_BUILT = /className="[^"]*\bain-seg(__btn)?\b|role="tab(list)?"/;

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");

function sources(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return ["node_modules", "dist", "kit"].includes(entry) ? [] : sources(full);
    return entry.endsWith(".tsx") && !entry.endsWith(".test.tsx") ? [full] : [];
  });
}

describe("switchers", () => {
  it("are kit SegmentedControl or Tabs, never hand-built", () => {
    const files = [...sources(SRC), ...(existsSync(STUDIO_TIER) ? readdirSync(STUDIO_TIER).flatMap((m) => sources(join(STUDIO_TIER, m, "ui"))) : [])];
    const found = files.flatMap((f) =>
      readFileSync(f, "utf8")
        .split("\n")
        .flatMap((line, i) => (HAND_BUILT.test(line) ? [`${relative(join(SRC, "../../../.."), f)}:${i + 1}: ${line.trim()}`] : [])),
    );
    expect(found, `Use the kit SegmentedControl (a filter or mode) or Tabs (sections):\n${found.join("\n")}`).toEqual([]);
  });
});
