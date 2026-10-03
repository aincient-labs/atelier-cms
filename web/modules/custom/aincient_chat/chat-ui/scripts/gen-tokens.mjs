#!/usr/bin/env node
/**
 * Builds the console's design tokens from their one source,
 * `web/modules/custom/aincient_core/tokens/*.json` (W3C DTCG format), into
 * the CSS custom properties every Atelier chrome surface reads, plus a TS
 * type of the token names (plans/studio-modules.md § Console tokens,
 * DECISIONS 0438).
 *
 * THREE TIERS, one grammar (after Nathan Curtis; Material 3 ref/sys/comp):
 *
 *   --ain-ref-color-cinnabar-55            reference — what a colour IS
 *   --ain-color-accent[-hover]             system    — what it is FOR
 *   --ain-topbar-height                    component — the exceptions
 *
 * Light / dark is a MODE: the same system name resolves to a different
 * reference in `mode.light.json` and `mode.dark.json` — never a `-dark`
 * suffix. That is what makes a theme a small file: override the reference
 * tier (and a handful of system roles) and every pixel follows, because
 * `kit/` and studio CSS may reference the system and component tiers only
 * (`studio-css.test.ts` refuses a `--ain-ref-*` there).
 *
 * THREE SURFACES, three files, because each switches mode differently and
 * none of that changes here (the mode trigger is each surface's own):
 *
 *   console   #aincient-chat-root            dark base, light on [data-ain-theme="light"]
 *   admin     :root                          light base, dark on prefers-color-scheme
 *   flowdrop  :root                          light base, dark on [data-theme="dark"]
 *
 * Each file holds the whole reference tier, the mode-less system +
 * component tokens, the base mode's colour roles, and the DEPRECATED
 * aliases (the pre-0438 names, `--ain-bg` → `var(--ain-color-bg)`), then a
 * second block with the other mode's colour roles only. The aliases are
 * for CSS this tree does not own (a client pack's); everything in-tree was
 * renamed. They go in the release after 0.16.0 — delete `DEPRECATED`
 * below and the aliases vanish from all three files.
 *
 * Style Dictionary does the parsing and the alias resolution; the CSS is
 * composed here so each surface keeps the selector shape it has today.
 * `outputReferences` keeps `var(--ain-ref-…)` in the output rather than
 * flattening to a literal — the whole point.
 *
 * LOUD BY DESIGN: a token name outside the grammar, a dangling alias, a
 * deprecated name whose target does not exist, or a system colour that is
 * not an alias of a reference all exit 1. Run by `prebuild`, `pretest` and
 * `pretypecheck`; `npm run gen:tokens` by hand.
 */

import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import StyleDictionary from "style-dictionary";
import { formattedVariables } from "style-dictionary/utils";

const here = import.meta.dirname;
const CHAT_UI = join(here, "..");
const MODULES = join(CHAT_UI, "../../..");
export const TOKENS_DIR = join(MODULES, "custom/aincient_core/tokens");

/** The three surfaces: where the file goes and how it scopes each mode. */
export const SURFACES = {
  console: {
    out: join(CHAT_UI, "src/tokens.generated.css"),
    base: { mode: "dark", selector: "#aincient-chat-root" },
    alt: { mode: "light", selector: '#aincient-chat-root[data-ain-theme="light"]' },
  },
  admin: {
    out: join(MODULES, "../themes/custom/aincient_studio_backend/css/tokens.generated.css"),
    base: { mode: "light", selector: ":root" },
    alt: { mode: "dark", selector: ":root", media: "(prefers-color-scheme: dark)" },
  },
  flowdrop: {
    out: join(MODULES, "custom/aincient_core/css/flowdrop-tokens.generated.css"),
    base: { mode: "light", selector: ":root" },
    alt: { mode: "dark", selector: '[data-theme="dark"]' },
  },
};
export const TS_OUT = join(CHAT_UI, "src/tokens.generated.ts");

/**
 * Pre-0438 names → their grammar names. Emitted as `--old: var(--new)` in
 * every surface for one release, and refused by the studio CSS rule.
 */
export const DEPRECATED = {
  "--ain-bg": "--ain-color-bg",
  "--ain-surface": "--ain-color-surface",
  "--ain-surface-2": "--ain-color-surface-raised",
  "--ain-border": "--ain-color-border",
  "--ain-border-strong": "--ain-color-border-strong",
  "--ain-text": "--ain-color-text",
  "--ain-text-dim": "--ain-color-text-muted",
  "--ain-text-faint": "--ain-color-text-faint",
  "--ain-accent": "--ain-color-accent",
  "--ain-accent-2": "--ain-color-warning",
  "--ain-accent-hover": "--ain-color-accent-hover",
  "--ain-accent-muted": "--ain-color-accent-tint",
  "--ain-on-accent": "--ain-color-on-accent",
  "--ain-jade": "--ain-color-success",
  "--ain-danger": "--ain-color-danger",
  "--ain-shadow": "--ain-color-shadow",
  "--ain-band-1": "--ain-mark-band-1",
  "--ain-band-2": "--ain-mark-band-2",
  "--ain-band-3": "--ain-mark-band-3",
  "--ain-band-4": "--ain-mark-band-4",
  "--ain-radius": "--ain-radius-md",
  "--ain-max": "--ain-size-column-max",
  "--ain-sidebar-w": "--ain-sidebar-width",
  "--ain-topbar-h": "--ain-topbar-height",
  "--ain-bar-h": "--ain-panel-bar-height",
  "--ain-font": "--ain-font-body",
  "--ain-mono": "--ain-font-mono",
};

/* ------------------------------------------------------------ the grammar */

const CATEGORIES = "color|font|size|space|radius|shadow|motion|z";
/** `--ain-ref-<category>-<name>-<step>` */
const REF = new RegExp(`^--ain-ref-(${CATEGORIES})-[a-z]+(-[a-z]+)*-[0-9]+$`);
/** `--ain-<category>-<role>[-<variant>][-<state>]` */
const SYS = new RegExp(`^--ain-(${CATEGORIES})-[a-z]+(-[a-z]+)*$`);
/** `--ain-<component>-<property>[-<n>]` — the component tier, named here so it stays an exception. */
const COMPONENTS = ["mark", "sidebar", "topbar", "panel-bar"];
const COMP = new RegExp(`^--ain-(${COMPONENTS.join("|")})-[a-z]+(-[a-z]+)*(-[0-9]+)?$`);

export function tierOf(name) {
  // Light/dark is a MODE — the same name, two values — never a suffix.
  if (/-(dark|light)$/.test(name)) return null;
  if (REF.test(name)) return "ref";
  if (SYS.test(name)) return "sys";
  if (COMP.test(name)) return "comp";
  return null;
}

/* ------------------------------------------------------------ the build */

const MODE_FILE = (mode) => `mode.${mode}.json`;

/** Source order: file by file, key by key — so the output reads like the source. */
function sourceOrder(files) {
  const order = new Map();
  const walk = (node, path) => {
    for (const [k, v] of Object.entries(node)) {
      if (k.startsWith("$") || typeof v !== "object" || v === null) continue;
      if ("$value" in v) order.set([...path, k].join("."), order.size);
      else walk(v, [...path, k]);
    }
  };
  for (const f of files) walk(JSON.parse(readFileSync(f, "utf8")), []);
  return order;
}

async function tokensFor(mode) {
  const files = ["ref.json", "base.json", MODE_FILE(mode)].map((f) => join(TOKENS_DIR, f));
  const sd = new StyleDictionary({
    source: files,
    log: { verbosity: "silent" },
    platforms: {
      css: { prefix: "ain", transforms: ["name/kebab"] },
    },
  });
  const dictionary = await sd.getPlatformTokens("css");
  const order = sourceOrder(files);
  const at = (t) => order.get(t.path.join(".")) ?? Infinity;
  dictionary.allTokens = [...dictionary.allTokens].sort((a, b) => at(a) - at(b));
  return dictionary;
}

function fail(msg) {
  console.error(`gen-tokens: ${msg}`);
  process.exit(1);
}

function check({ allTokens: all }) {
  const names = new Set(all.map((t) => `--${t.name}`));
  for (const t of all) {
    const name = `--${t.name}`;
    const tier = tierOf(name);
    if (!tier) fail(`"${name}" is outside the grammar (${t.filePath})`);
    const raw = t.original.$value;
    if (tier === "sys" && t.$type === "color" && !/\{[^}]+\}/.test(String(raw))) {
      fail(`system colour "${name}" must alias a reference, not hold a literal (${t.filePath})`);
    }
    if (tier === "ref" && /\{/.test(String(raw))) fail(`reference "${name}" may not alias (${t.filePath})`);
  }
  for (const [from, to] of Object.entries(DEPRECATED)) {
    if (!names.has(to)) fail(`deprecated "${from}" points at "${to}", which no token defines`);
    if (names.has(from)) fail(`deprecated "${from}" is also a live token`);
  }
  return names;
}

function block(dictionary, only, { selector, media }, extra = "") {
  const vars = formattedVariables({
    format: "css",
    dictionary: { ...dictionary, allTokens: dictionary.allTokens.filter(only) },
    outputReferences: true,
    usesDtcg: true,
    formatting: { indentation: "  ", commentPosition: "above" },
  });
  const body = `${selector} {\n${vars}${extra}\n}`;
  return media ? `@media ${media} {\n${body.replace(/^(?=.)/gm, "  ")}\n}` : body;
}

const HEADER = `/* GENERATED by aincient_chat/chat-ui/scripts/gen-tokens.mjs from
   web/modules/custom/aincient_core/tokens/*.json — DO NOT EDIT. Change the
   source and run \`npm run gen:tokens\` (prebuild / pretest run it too).
   Tiers and grammar: docs/console-tokens.md (DECISIONS 0438). */\n\n`;

/** One surface's stylesheet, from both modes' resolved token lists. */
export function renderSurface(surface, byMode) {
  const isMode = (t) => /\/mode\.[a-z]+\.json$/.test(t.filePath);
  const base = byMode[surface.base.mode];
  const alt = byMode[surface.alt.mode];
  const aliases = Object.entries(DEPRECATED)
    .map(([from, to]) => `  ${from}: var(${to});`)
    .join("\n");
  const extra = `\n\n  /* Deprecated aliases (pre-0438 names) — removed in the release after 0.16.0. */\n${aliases}`;
  return HEADER + block(base, () => true, surface.base, extra) + "\n\n" + block(alt, isMode, surface.alt) + "\n";
}

export function renderTs(names) {
  const sorted = [...names].sort();
  const byTier = { ref: [], sys: [], comp: [] };
  for (const n of sorted) byTier[tierOf(n)].push(n);
  const list = (arr) => arr.map((n) => `  ${JSON.stringify(n)},`).join("\n");
  const deprecated = Object.entries(DEPRECATED)
    .map(([from, to]) => `  ${JSON.stringify(from)}: ${JSON.stringify(to)},`)
    .join("\n");
  return `// GENERATED by scripts/gen-tokens.mjs from aincient_core/tokens/*.json — DO NOT EDIT.
// The console's design-token names, by tier (docs/console-tokens.md, DECISIONS 0438).

/** Reference tier — what a colour IS. Only the system tier may reference these. */
export const REF_TOKENS = [
${list(byTier.ref)}
] as const;

/** System tier — what a value is FOR. Light/dark is a mode: same name, two values. */
export const SYSTEM_TOKENS = [
${list(byTier.sys)}
] as const;

/** Component tier — the named exceptions. */
export const COMPONENT_TOKENS = [
${list(byTier.comp)}
] as const;

export type RefToken = (typeof REF_TOKENS)[number];
export type SystemToken = (typeof SYSTEM_TOKENS)[number];
export type ComponentToken = (typeof COMPONENT_TOKENS)[number];
/** Every token a stylesheet outside the system tier may use. */
export type ConsoleToken = SystemToken | ComponentToken;

/** Pre-0438 names, aliased for one release (removed after 0.16.0) and refused by the studio CSS rule. */
export const DEPRECATED_TOKENS: Readonly<Record<string, ConsoleToken>> = {
${deprecated}
};
`;
}

export async function render() {
  const byMode = { dark: await tokensFor("dark"), light: await tokensFor("light") };
  const names = check(byMode.dark);
  check(byMode.light);
  const files = {};
  for (const surface of Object.values(SURFACES)) files[surface.out] = renderSurface(surface, byMode);
  files[TS_OUT] = renderTs(names);
  return files;
}

async function main() {
  const files = await render();
  let changed = 0;
  for (const [path, body] of Object.entries(files)) {
    let current = "";
    try {
      current = readFileSync(path, "utf8");
    } catch {}
    if (current !== body) {
      writeFileSync(path, body);
      changed++;
    }
  }
  console.log(`gen-tokens: ${Object.keys(files).length} files, ${changed} written`);
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  await main();
}
