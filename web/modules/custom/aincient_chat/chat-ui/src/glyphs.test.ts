import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * Icons are inline SVG, never text glyphs (house rule; audit N4). A glyph renders
 * in whatever fallback font the OS has, so it changes size and weight by
 * platform, sits off the baseline next to SVG icons, and a screen reader reads
 * it aloud ("north east arrow"). Use the kit icons instead: `ArrowLeftIcon`,
 * `ArrowRightIcon`, `ExternalIcon` / `NewTabMark` for links that open a new tab.
 *
 * This scans console and studio source (comments excluded) for the arrow,
 * geometric, dingbat and emoji ranges, the same set the console audit's probe
 * counts on screen, plus the arrow HTML entities.
 */

const GLYPH = /[←-⇿⌀-⏿■-◿☀-➿⬀-⯿\u{1F300}-\u{1FAFF}]|&(?:[lrud]arr|[nesw][ew]arr|harr);/u;

/** Source lines with a glyph outside comments: `<line>: <text>`. */
export function glyphLines(src: string): string[] {
  const blanked = src.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "));
  return blanked.split("\n").flatMap((line, i) => {
    const code = line.replace(/(^|[^:"'`])\/\/.*$/, "$1");
    return GLYPH.test(code) ? [`${i + 1}: ${line.trim()}`] : [];
  });
}

describe("the glyph detector", () => {
  it("finds glyphs and arrow entities in code, not in comments", () => {
    expect(glyphLines('<a>Open ↗</a>\n// a → b\n/* ← */\nconst u = "https://x"; // ok →')).toEqual(["1: <a>Open ↗</a>"]);
    expect(glyphLines("<span>Next &rarr;</span>")).toHaveLength(1);
    expect(glyphLines("Publishing… · ×")).toEqual([]);
  });
});

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");

function sources(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return entry === "node_modules" || entry === "dist" ? [] : sources(full);
    return /\.tsx?$/.test(entry) && !/\.test\.tsx?$/.test(entry) && !entry.includes(".generated.") ? [full] : [];
  });
}

describe("glyphs in the real tree", () => {
  it("no console or studio source draws an icon with a text glyph", () => {
    const files = [...sources(SRC), ...(existsSync(STUDIO_TIER) ? readdirSync(STUDIO_TIER).flatMap((m) => sources(join(STUDIO_TIER, m, "ui"))) : [])];
    const found = files.flatMap((f) => glyphLines(readFileSync(f, "utf8")).map((l) => `${relative(join(SRC, "../../../.."), f)}:${l}`));
    expect(found, `Use a kit SVG icon (ArrowLeftIcon, ArrowRightIcon, NewTabMark…):\n${found.join("\n")}`).toEqual([]);
  });
});
