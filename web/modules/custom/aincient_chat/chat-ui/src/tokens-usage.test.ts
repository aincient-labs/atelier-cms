import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/**
 * The faint-text guard (accessibility audit N2): `--ain-color-text-faint` is a
 * placeholder grey that fails WCAG AA on real content. It is for placeholders and
 * disabled controls ONLY; every other muted text uses `--ain-color-text-muted`.
 *
 * Scans the console's own stylesheets (`src/**\/*.css`, minus *generated*) and every
 * studio stylesheet (`web/modules/studio/<module>/ui/**\/*.css`) and fails, listing
 * `file: selector`, for any rule that uses `text-faint` unless EVERY comma-separated
 * part of its selector targets a placeholder or disabled state. A mixed selector
 * list must be split so the faint half stays in its own rule.
 */

const FAINT = /var\(\s*--ain-color-text-faint\b/;
const ALLOWED_STATE =
  /::placeholder|:placeholder-shown|:disabled|\[disabled|\[aria-disabled|\[data-disabled|--disabled\b|\bis-disabled\b/;

/** Strip comments; innermost `selector { declarations }` blocks (so @media nesting is flattened). */
function rules(css: string): { selector: string; body: string }[] {
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, "");
  const out: { selector: string; body: string }[] = [];
  for (const m of clean.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    out.push({ selector: m[1].trim().replace(/\s+/g, " "), body: m[2] });
  }
  return out;
}

/** Selectors of rules that use text-faint on something other than a placeholder / disabled state. */
export function faintOffenders(css: string): string[] {
  return rules(css)
    .filter(({ selector, body }) => FAINT.test(body) && !selector.split(",").every((p) => ALLOWED_STATE.test(p)))
    .map(({ selector }) => selector);
}

describe("the faint-text rule", () => {
  it("allows placeholder and disabled rules", () => {
    expect(
      faintOffenders(
        ".a::placeholder { color: var(--ain-color-text-faint); } .b:disabled, .b[data-disabled] { color: var(--ain-color-text-faint); }",
      ),
    ).toEqual([]);
  });

  it("refuses faint on real content, including a mixed selector list and @media nesting", () => {
    expect(faintOffenders(".hint { color: var(--ain-color-text-faint); }")).toEqual([".hint"]);
    expect(faintOffenders(".a::placeholder, .hint { color: var(--ain-color-text-faint); }")).toHaveLength(1);
    expect(faintOffenders("@media (min-width: 1px) { .x { color: var(--ain-color-text-faint); } }")).toEqual([".x"]);
    expect(faintOffenders("/* .c { color: var(--ain-color-text-faint) } */ .d { color: var(--ain-color-text-muted); }")).toEqual([]);
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

const FILES = [
  ...cssFiles(SRC),
  ...(existsSync(STUDIO_TIER)
    ? readdirSync(STUDIO_TIER).flatMap((m) => cssFiles(join(STUDIO_TIER, m, "ui")))
    : []),
];

describe("the faint-text rule on the real tree", () => {
  it("is met by every stylesheet", () => {
    const found = FILES.flatMap((f) => faintOffenders(readFileSync(f, "utf8")).map((s) => `${f}: ${s}`));
    expect(found, found.join("\n")).toEqual([]);
  });

  it("still sees the console and studio stylesheets", () => {
    expect(FILES.some((f) => f.endsWith("/styles.css") && f.startsWith(SRC))).toBe(true);
    expect(FILES.some((f) => f.startsWith(STUDIO_TIER))).toBe(true);
  });
});

/**
 * The type-scale guard (0517, audit M2/N7): a console or studio font size is a
 * `--ain-font-size-*` or `--ain-size-icon-*` token, never a px literal. The only
 * exceptions draw something that is not ours — previews of how a page appears on
 * another platform (search result, social card, teaser) and the font specimen —
 * plus the weather widget's display numerals. They are listed by selector here.
 */
const PX_FONT = /(?<![-\w])font-size:\s*[0-9.]+px/;
const SCALE_EXEMPT = /\.ain-serp|\.ain-social|\.ain-teasercard|\.ain-design-system-fontopt__sample|\.ain-weather__(icon|temp)\b/;

export function pxFontOffenders(css: string): string[] {
  return rules(css)
    .filter(({ selector, body }) => PX_FONT.test(body) && !selector.split(",").every((p) => SCALE_EXEMPT.test(p)))
    .map(({ selector }) => selector);
}

describe("the type-scale rule", () => {
  it("refuses a px font size and allows tokens and the named exceptions", () => {
    expect(pxFontOffenders(".a { font-size: 12.5px; }")).toEqual([".a"]);
    expect(pxFontOffenders(".a { font-size: var(--ain-font-size-sm); } .b svg { font-size: var(--ain-size-icon-md); }")).toEqual([]);
    expect(pxFontOffenders(".ain-serp__title { font-size: 18px; }")).toEqual([]);
    expect(pxFontOffenders(".ain-serp__title, .x { font-size: 18px; }")).toHaveLength(1);
  });

  it("is met by every stylesheet", () => {
    const found = FILES.flatMap((f) => pxFontOffenders(readFileSync(f, "utf8")).map((s) => `${f}: ${s}`));
    expect(found, found.join("\n")).toEqual([]);
  });
});
