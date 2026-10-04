import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { parse } from "yaml";
import { RETIRED_TOKENS } from "./tokens.generated";

/**
 * The studio CSS guards (plans/studio-modules.md "`kit/`", DECISIONS 0430),
 * enforced on every stylesheet under `web/modules/studio/<module>/ui/`:
 *
 *   1. every class a studio DEFINES is prefixed with one of its studio ids
 *      (`.ain-<id>-…` / `.ain-<id>__…`; an id with underscores may also be
 *      written in kebab form — `design_system` → `.ain-design-system-…` — since
 *      a class name is not a machine name), so two studios can never style the
 *      same name and a studio's rules are greppable by its id;
 *   2. no raw colour — a hex, rgb/hsl/oklch, or a named colour is a value the
 *      console's theme cannot reach; colours come from `var(--ain-…)` only;
 *   3. no `opacity` dimming (0376, `memory/never-mute-text-with-opacity.md`):
 *      muted text uses a muted token, which stays WCAG-AA in both modes;
 *   4. system and component tokens only (DECISIONS 0438, docs/console-tokens.md):
 *      a `--ain-ref-*` reference names what a colour IS, and a stylesheet that
 *      reaches past the role to the pigment is one a theme cannot restyle —
 *      that single rule is what makes a white-label override reach every
 *      pixel; and no pre-0438 name (`--ain-bg`, …) — retired in 0.17.0, they
 *      resolve to nothing, so the rule names the replacement.
 *
 * Rule 4 also runs over the console's OWN stylesheets (`src/**\/*.css`, which
 * is `styles.css` and the kit's) — the same theme has to reach them.
 *
 * A studio may USE the console's shared classes (`.ain-field`, `.ain-studio__*`)
 * in its markup — the rule is about what it defines. Reads source text like the
 * import fence does, so it catches the rule in a file nothing renders yet. The
 * rule half runs against synthetic bodies so the test is not vacuous when the
 * tree happens to be clean.
 */

export type CssOffender = { selector: string; why: string };

const CLASS = /\.([A-Za-z_-][\w-]*)/g;
const RAW_COLOUR =
  /#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|oklch|oklab|color)\s*\(|:\s*(?:white|black|red|blue|green|gr[ae]y|transparent)\b/;
const TOKEN_REF = /var\(\s*(--ain-[\w-]+)/g;

/** Strip comments; a rule's text is `selector { declarations }`. */
function rules(css: string): { selector: string; body: string }[] {
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, "");
  const out: { selector: string; body: string }[] = [];
  const RULE = /([^{}]+)\{([^{}]*)\}/g;
  for (const m of clean.matchAll(RULE)) out.push({ selector: m[1].trim(), body: m[2] });
  return out;
}

/** Every guard violation in one studio stylesheet, given the ids it may prefix with. */
export function cssOffenders(css: string, studioIds: string[]): CssOffender[] {
  const out: CssOffender[] = [];
  const prefixes = studioIds.flatMap((id) => [`ain-${id}`, `ain-${id.replace(/_/g, "-")}`]);
  for (const { selector, body } of rules(css)) {
    for (const m of selector.matchAll(CLASS)) {
      const cls = m[1];
      const ok = prefixes.some((p) => cls === p || cls.startsWith(p + "-") || cls.startsWith(p + "__"));
      if (!ok) {
        out.push({
          selector,
          why: `defines ".${cls}" — a studio's classes are prefixed with its id (${prefixes.map((p) => `.${p}-*`).join(", ")}); shared looks belong in kit/.`,
        });
        break;
      }
    }
    if (RAW_COLOUR.test(body)) {
      out.push({ selector, why: "uses a raw colour — studio CSS takes colours from var(--ain-…) tokens only." });
    }
    if (/(^|[\s;])opacity\s*:/.test(body)) {
      out.push({ selector, why: "dims with opacity — use a muted text token instead (DECISIONS 0376)." });
    }
    out.push(...tokenOffenders(selector, body));
  }
  return out;
}

/** Rule 4 alone — the token tiers a stylesheet may reach (also run over the console's own CSS). */
export function tokenOffenders(selector: string, body: string): CssOffender[] {
  const out: CssOffender[] = [];
  for (const m of body.matchAll(TOKEN_REF)) {
    const name = m[1];
    if (name.startsWith("--ain-ref-")) {
      out.push({ selector, why: `reads the reference tier (${name}) — use the system role it stands for (docs/console-tokens.md).` });
    } else if (name in RETIRED_TOKENS) {
      out.push({ selector, why: `uses the retired pre-0438 name ${name} — it is ${RETIRED_TOKENS[name]} now.` });
    }
  }
  return out;
}

/** Every rule-4 offender in a stylesheet. */
export function cssTokenOffenders(css: string): CssOffender[] {
  return rules(css).flatMap(({ selector, body }) => tokenOffenders(selector, body));
}

/* ------------------------------------------------------------------ the rule */

describe("the studio CSS rule", () => {
  const ids = ["forms"];

  it("accepts prefixed classes with token colours", () => {
    expect(cssOffenders(".ain-forms__row { color: var(--ain-color-text-faint); } .ain-forms-tier { gap: 4px; }", ids)).toEqual([]);
  });

  it("refuses an unprefixed class, even beside a prefixed one", () => {
    const found = cssOffenders(".ain-forms__row .ain-field { gap: 4px; }", ids);
    expect(found).toHaveLength(1);
    expect(found[0].why).toContain('".ain-field"');
  });

  it("refuses a lookalike prefix", () => {
    expect(cssOffenders(".ain-formsy { gap: 4px; }", ids)).toHaveLength(1);
  });

  it("refuses raw colours in every spelling", () => {
    for (const v of ["#fff", "#A1B2C3", "rgb(1 2 3)", "hsla(1,2%,3%,.4)", "oklch(50% 0.1 20)", "white"]) {
      expect(cssOffenders(`.ain-forms__x { color: ${v}; }`, ids), v).toHaveLength(1);
    }
    expect(cssOffenders(".ain-forms__x { color: var(--ain-color-text-muted); background: var(--ain-color-surface); }", ids)).toEqual([]);
  });

  it("refuses opacity dimming", () => {
    expect(cssOffenders(".ain-forms__x { opacity: .6; }", ids)).toHaveLength(1);
    expect(cssOffenders(".ain-forms__x { --ain-forms-opacity-x: 1; }", ids)).toEqual([]);
  });

  it("accepts the kebab form of an id with underscores", () => {
    expect(cssOffenders(".ain-design-system__x { gap: 0; } .ain-design_system-y { gap: 0; }", ["design_system"])).toEqual([]);
    expect(cssOffenders(".ain-design__x { gap: 0; }", ["design_system"])).toHaveLength(1);
  });

  it("ignores comments and takes any of a module's studio ids", () => {
    expect(cssOffenders("/* .ain-field { color: #fff } */ .ain-b__x { gap: 0; }", ["a", "b"])).toEqual([]);
  });

  it("refuses the reference tier and the pre-0438 names, accepts system and component tokens", () => {
    expect(cssOffenders(".ain-forms__x { color: var(--ain-ref-color-cinnabar-55); }", ids)).toHaveLength(1);
    expect(cssOffenders(".ain-forms__x { color: var( --ain-ref-color-cinnabar-55 ); }", ids)).toHaveLength(1);
    const old = cssOffenders(".ain-forms__x { color: var(--ain-text-dim); }", ids);
    expect(old).toHaveLength(1);
    expect(old[0].why).toContain("--ain-color-text-muted");
    expect(cssOffenders(".ain-forms__x { color: var(--ain-color-accent); height: var(--ain-topbar-height); }", ids)).toEqual([]);
    // A studio's own component-local property is not a console token and is not judged.
    expect(cssOffenders(".ain-forms__x { --ain-forms-c: var(--ain-color-success); color: var(--ain-forms-c); }", ids)).toEqual([]);
  });
});

/* ------------------------------------------------------------------ the walk */

const STUDIO_TIER = join(import.meta.dirname, "../../../../studio");

function cssFiles(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return entry === "node_modules" ? [] : cssFiles(full);
    return entry.endsWith(".css") ? [full] : [];
  });
}

/** Module → its studio ids, from `<module>.studios.yml`. */
function studioIds(moduleDir: string): string[] {
  return readdirSync(moduleDir)
    .filter((f) => f.endsWith(".studios.yml"))
    .flatMap((f) => Object.keys(parse(readFileSync(join(moduleDir, f), "utf8")) ?? {}));
}

describe("the studio CSS rule on the real tree", () => {
  const modules = existsSync(STUDIO_TIER)
    ? readdirSync(STUDIO_TIER).filter((m) => statSync(join(STUDIO_TIER, m)).isDirectory())
    : [];

  it("is met by every studio stylesheet", () => {
    const found: string[] = [];
    for (const module of modules) {
      const ids = studioIds(join(STUDIO_TIER, module));
      for (const file of cssFiles(join(STUDIO_TIER, module, "ui"))) {
        for (const o of cssOffenders(readFileSync(file, "utf8"), ids)) {
          found.push(`${module}/ui/${file.slice(join(STUDIO_TIER, module, "ui").length + 1)}: "${o.selector}" ${o.why}`);
        }
      }
    }
    expect(found, found.join("\n")).toEqual([]);
  });

  it("still sees a studio stylesheet to check", () => {
    // A broken walk would make the check above pass silently forever.
    expect(modules.flatMap((m) => cssFiles(join(STUDIO_TIER, m, "ui"))).length).toBeGreaterThan(0);
  });
});

describe("the token-tier rule on the console's own stylesheets", () => {
  const SRC = join(import.meta.dirname);
  const files = cssFiles(SRC).filter((f) => !f.endsWith(".generated.css"));

  it("is met by styles.css and every kit stylesheet", () => {
    const found: string[] = [];
    for (const file of files) {
      for (const o of cssTokenOffenders(readFileSync(file, "utf8"))) {
        found.push(`${file.slice(SRC.length + 1)}: "${o.selector}" ${o.why}`);
      }
    }
    expect(found, found.join("\n")).toEqual([]);
  });

  it("still sees styles.css", () => {
    expect(files.some((f) => f.endsWith("/styles.css"))).toBe(true);
  });
});
