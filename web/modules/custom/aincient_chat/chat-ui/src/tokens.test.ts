import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { COMPONENT_TOKENS, REF_TOKENS, RETIRED_TOKENS, SYSTEM_TOKENS } from "./tokens.generated";
// The generator is plain ESM; its pure half renders without writing.
// @ts-expect-error — an .mjs script with no declaration file.
import { RETIRED, SURFACES, TS_OUT, render, tierOf } from "../scripts/gen-tokens.mjs";

/**
 * The console tokens (DECISIONS 0438, docs/console-tokens.md): one DTCG source
 * in `aincient_core/tokens/`, three generated stylesheets (one per chrome
 * surface) and this package's generated TS names. What is pinned here:
 *
 *   - the GRAMMAR — every name is in exactly one of the three tiers;
 *   - the pre-0438 names are RETIRED (0.17.0) — no surface defines one;
 *   - the generated files are FRESH — what `render()` produces now is what is
 *     committed (prebuild / pretest regenerate; this catches a hand edit);
 *   - every `--ain-*` a stylesheet READS is a token the source defines or a
 *     property the same file defines itself — a typo
 *     silently resolves to nothing, which is the bug `--ain-text-muted` was.
 */

const CHAT_UI = join(import.meta.dirname, "..");
const MODULES = join(CHAT_UI, "../../..");

/** `--name: value;` pairs of one CSS block, in order. */
function declarations(css: string): [string, string][] {
  return [...css.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]);
}

/** Resolve `var(--x)` chains in a block's declarations against themselves. */
function resolveAll(decls: [string, string][]): Map<string, string> {
  const map = new Map(decls);
  const resolve = (v: string, depth = 0): string =>
    depth > 10 ? v : v.replace(/var\((--[\w-]+)\)/g, (_, n) => resolve(map.get(n) ?? `var(${n})`, depth + 1));
  return new Map([...map].map(([k, v]) => [k, resolve(v)]));
}

/** A surface's stylesheet as its two mode blocks' resolved maps. */
function modesOf(css: string): Map<string, string>[] {
  const blocks = [...css.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/\{([^{}]*)\}/g)].map((m) => m[1]);
  const base = declarations(blocks[0]);
  const alt = declarations(blocks[blocks.length - 1]);
  // The alt block inherits everything it does not redefine.
  return [resolveAll(base), resolveAll([...base, ...alt])];
}

describe("the token grammar", () => {
  it("places every generated name in one tier", () => {
    for (const n of REF_TOKENS) expect(tierOf(n), n).toBe("ref");
    for (const n of SYSTEM_TOKENS) expect(tierOf(n), n).toBe("sys");
    for (const n of COMPONENT_TOKENS) expect(tierOf(n), n).toBe("comp");
  });

  it("refuses the leaks the plan named", () => {
    for (const n of ["--ain-jade", "--ain-hitl-c", "--ain-accent-2", "--ain-band-1", "--ain-color-bg-dark", "--primary"]) {
      expect(tierOf(n), n).toBeNull();
    }
  });

  it("keeps the retired names out of the live set", () => {
    const live = new Set<string>([...REF_TOKENS, ...SYSTEM_TOKENS, ...COMPONENT_TOKENS]);
    for (const [from, to] of Object.entries(RETIRED_TOKENS)) {
      expect(live.has(from), from).toBe(false);
      expect(live.has(to), to).toBe(true);
    }
    expect(RETIRED_TOKENS).toEqual(RETIRED);
  });
});

describe("the generated files", async () => {
  const files: Record<string, string> = await render();

  it("are what the source renders now", () => {
    for (const [path, body] of Object.entries(files)) {
      expect(readFileSync(path, "utf8"), path).toBe(body);
    }
    expect(Object.keys(files)).toHaveLength(Object.keys(SURFACES).length + 1);
    expect(files[TS_OUT]).toBeDefined();
  });

  it("define no retired name and resolve every replacement, on every surface and in both modes", () => {
    for (const [id, surface] of Object.entries(SURFACES) as [string, { out: string }][]) {
      for (const [i, mode] of modesOf(files[surface.out]).entries()) {
        for (const [from, to] of Object.entries(RETIRED_TOKENS)) {
          expect(mode.has(from), `${id} mode ${i} ${from}`).toBe(false);
          expect(mode.get(to), `${id} mode ${i} ${to}`).not.toMatch(/var\(/);
        }
      }
    }
  });

  it("give the two modes different inks and the same type", () => {
    const [dark, light] = modesOf(files[SURFACES.console.out]);
    expect(dark.get("--ain-color-text")).not.toBe(light.get("--ain-color-text"));
    expect(dark.get("--ain-font-body")).toBe(light.get("--ain-font-body"));
    // Studio night is the console's base; gesso paper the admin's.
    expect(modesOf(files[SURFACES.admin.out])[0].get("--ain-color-bg")).toBe(light.get("--ain-color-bg"));
  });
});

describe("every token a stylesheet reads", () => {
  const known = new Set<string>([...REF_TOKENS, ...SYSTEM_TOKENS, ...COMPONENT_TOKENS]);
  const sheets = [
    "custom/aincient_chat/chat-ui/src/styles.css",
    "custom/aincient_core/css/flowdrop-brand.css",
    "custom/aincient_core/css/pricing.css",
    "custom/aincient_core/css/usage.css",
    "../themes/custom/aincient_studio_backend/css/studio-backend.css",
    "../themes/custom/aincient_studio_backend/css/admin-kit.css",
    "studio/aincient_audit/ui/styles.css",
    "studio/aincient_brand/ui/styles.css",
    "studio/aincient_studio_components/ui/styles.css",
    "studio/aincient_studio_content/ui/styles.css",
    "studio/aincient_studio_media/ui/styles.css",
    "studio/aincient_studio_site/ui/styles.css",
  ];

  it("is defined", () => {
    const missing: string[] = [];
    for (const rel of sheets) {
      const css = readFileSync(join(MODULES, rel), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
      const local = new Set([...css.matchAll(/(--ain-[\w-]+)\s*:/g)].map((m) => m[1]));
      for (const m of css.matchAll(/var\(\s*(--ain-[\w-]+)/g)) {
        if (!known.has(m[1]) && !local.has(m[1])) missing.push(`${rel}: ${m[1]}`);
      }
    }
    expect(missing, missing.join("\n")).toEqual([]);
  });
});
