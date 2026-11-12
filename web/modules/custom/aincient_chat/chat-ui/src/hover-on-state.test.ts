import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * Hover must never repaint a selected control's text. A selected chip, segment
 * or toggle is filled with ink (`--ain-color-accent`) and writes in paper. A
 * plain `.x:hover { color: … }` out-ranks `.x--on` (0,2,0 against 0,1,0), so
 * hovering the selected one painted ink text on the ink fill (the Identity
 * radius chips, 2026-11-12).
 *
 * For every ink-filled selected state, any hover rule on the same class that
 * sets `color` must either lose on specificity, exclude the state (`:not(…)`),
 * or be answered by an `<on-state>:hover` rule that sets colour again.
 */

type Rule = { file: string; selector: string; body: string; order: number };

function rules(file: string, css: string, start: number): Rule[] {
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, "");
  const out: Rule[] = [];
  let order = start;
  for (const m of clean.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    for (const sel of m[1].split(",")) out.push({ file, selector: sel.trim().replace(/\s+/g, " "), body: m[2], order: order++ });
  }
  return out;
}

/** Classes + attributes + pseudo-classes; good enough for these flat selectors. */
function specificity(sel: string): number {
  const s = sel.replace(/::[\w-]+/g, "");
  return (s.match(/\.[\w-]+|\[[^\]]+\]|:(?!:)[\w-]+(\([^)]*\))?/g) ?? []).length;
}

const ON = /--on\b|\[aria-(pressed|selected|checked)="true"\]|\[data-(on|selected|active)\]|\.is-[\w-]+|:checked/;
const INK_FILL = /background(-color)?:\s*var\(--ain-color-accent\)/;
const SETS_COLOR = /(?<![-\w])color:/;

export function hoverClashes(all: Rule[]): string[] {
  const out: string[] = [];
  for (const on of all) {
    if (!ON.test(on.selector) || !INK_FILL.test(on.body) || /::/.test(on.selector) || on.selector.includes(":hover")) continue;
    const cls = (on.selector.split(ON)[0].match(/\.[\w-]+/g) ?? []).pop();
    if (!cls) continue;
    const answered = all.some((r) => r.selector.includes(":hover") && r.selector.replace(":hover", "") === on.selector && SETS_COLOR.test(r.body));
    if (answered) continue;
    for (const h of all) {
      if (!h.selector.includes(":hover") || !h.selector.includes(cls) || !SETS_COLOR.test(h.body)) continue;
      if (h.selector.includes(":not(") || ON.test(h.selector) || /::/.test(h.selector)) continue;
      const hs = specificity(h.selector), os = specificity(on.selector);
      if (hs > os || (hs === os && h.order > on.order)) out.push(`${on.selector}  ←  ${h.selector}`);
    }
  }
  return out;
}

describe("the hover-on-state detector", () => {
  const r = (css: string) => rules("x.css", css, 0);
  it("flags a hover that out-ranks an ink-filled on state", () => {
    expect(hoverClashes(r(".c:hover { color: red } .c--on { background: var(--ain-color-accent); color: white }"))).toHaveLength(1);
  });
  it("passes when the state wins, is excluded, or answers its own hover", () => {
    expect(hoverClashes(r(".s:hover { color: red } .s[aria-pressed=\"true\"] { background: var(--ain-color-accent) }"))).toEqual([]);
    expect(hoverClashes(r(".c:not(.c--on):hover { color: red } .c--on { background: var(--ain-color-accent) }"))).toEqual([]);
    expect(hoverClashes(r(".c:hover { color: red } .c--on { background: var(--ain-color-accent) } .c--on:hover { color: white }"))).toEqual([]);
  });
});

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");
function cssFiles(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return entry === "node_modules" || entry === "dist" ? [] : cssFiles(full);
    return entry.endsWith(".css") && !entry.includes("generated") ? [full] : [];
  });
}

describe("hover on selected states in the real tree", () => {
  it("never repaints a selected control's text", () => {
    // kit.css is loaded before styles.css, so kit rules come first in cascade order.
    const files = [
      ...cssFiles(join(SRC, "kit")),
      ...cssFiles(SRC).filter((f) => !f.includes("/kit/")),
      ...(existsSync(STUDIO_TIER) ? readdirSync(STUDIO_TIER).flatMap((m) => cssFiles(join(STUDIO_TIER, m, "ui"))) : []),
    ];
    let n = 0;
    const all = files.flatMap((f) => {
      const rs = rules(f, readFileSync(f, "utf8"), n);
      n += rs.length;
      return rs;
    });
    const found = hoverClashes(all);
    expect(found, found.join("\n")).toEqual([]);
  });
});
