/**
 * The studio import fence, enforced (DECISIONS 0430; the rule is `studio-fence.ts`).
 *
 * Two halves, on purpose. The WALK reads the real studio tier and the real
 * console source and must find zero offenders — that is the gate. But today the
 * studio tier holds no UI at all, so the walk alone would pass whatever the rule
 * said; a fence that has never seen a trespasser is not known to stop one. The
 * RULE cases below therefore feed `offenders()` synthetic files, one allowed and
 * one refused for every clause, and are what keep this test non-vacuous until the
 * first studio module lands.
 *
 * Fixture bodies are built by `imp()` at runtime rather than written out as
 * import statements, so this file's own source — which the walk also reads —
 * never contains a literal trespass.
 */

import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { GENERATED_REGISTRY, KIT_BACKREFS_ALLOWED, offenders, specifiers, type FenceRoots } from "./studio-fence";

/* ------------------------------------------------------------------ the rule */

const ROOTS: FenceRoots = {
  studioTier: "/w/web/modules/studio",
  consoleSrc: "/w/web/modules/custom/aincient_chat/chat-ui/src",
};
const FORMS_UI = `${ROOTS.studioTier}/aincient_studio_forms/ui`;

/** A one-import source body for `spec`. */
const imp = (spec: string) => `import { x } from "${spec}";\n`;

/** The specifiers `offenders()` refuses when `spec` is imported from `path`. */
function refused(path: string, spec: string): string[] {
  return offenders([{ path, body: imp(spec) }], ROOTS).map((o) => o.specifier);
}

describe("the studio fence rule", () => {
  const ui = `${FORMS_UI}/index.tsx`;
  const test = `${FORMS_UI}/index.test.tsx`;

  it("lets a studio import its own ui/ files", () => {
    expect(refused(ui, "./field-list")).toEqual([]);
    expect(refused(`${FORMS_UI}/parts/row.tsx`, "../field-list")).toEqual([]);
  });

  it("refuses a relative climb out of the studio's own ui/", () => {
    // Up into its own module's PHP side, into a sibling studio, into the console.
    expect(refused(ui, "../src/thing")).toEqual(["../src/thing"]);
    expect(refused(ui, "../../aincient_studio_other/ui/index.tsx")).toHaveLength(1);
    expect(refused(ui, "../../../custom/aincient_chat/chat-ui/src/App")).toHaveLength(1);
  });

  it("lets a studio import the three console roots, bare or by subpath", () => {
    for (const spec of ["@console/kit", "@console/sdk/use-studio", "@console/aui"]) {
      expect(refused(ui, spec)).toEqual([]);
    }
  });

  it("refuses anything that merely looks like a console root", () => {
    expect(refused(ui, "@console/kitchen")).toHaveLength(1);
    expect(refused(ui, "@console/internal")).toHaveLength(1);
  });

  it("lets a studio import react and react-dom, with subpaths", () => {
    for (const spec of ["react", "react/jsx-runtime", "react-dom", "react-dom/client"]) {
      expect(refused(ui, spec)).toEqual([]);
    }
  });

  it("refuses another studio by alias", () => {
    expect(refused(ui, "@studio/aincient_studio_other/ui/index.tsx")).toHaveLength(1);
  });

  it("refuses any other npm package", () => {
    expect(refused(ui, "react-markdown")).toHaveLength(1);
    // (Not spelled with the vendor scope: the aui seam test would flag this file.)
    expect(refused(ui, "xstate")).toHaveLength(1);
    expect(refused(ui, "@scope/pkg")).toHaveLength(1);
  });

  it("allows vitest and @testing-library only in a test file", () => {
    expect(refused(test, "vitest")).toEqual([]);
    expect(refused(test, "@testing-library/react")).toEqual([]);
    expect(refused(ui, "vitest")).toHaveLength(1);
    expect(refused(ui, "@testing-library/react")).toHaveLength(1);
  });

  it("holds only ui/ to the studio rule — a module's other files are not front end", () => {
    expect(refused(`${ROOTS.studioTier}/aincient_studio_forms/scripts/x.ts`, "lodash")).toEqual([]);
  });

  it("holds src/kit/ to kit files, react and the frozen back-reference list", () => {
    const kit = `${ROOTS.consoleSrc}/kit/data-table.tsx`;
    expect(refused(kit, "./icons")).toEqual([]);
    expect(refused(kit, "react")).toEqual([]);
    expect(refused(kit, "react-dom")).toEqual([]);
    expect(refused(kit, "../aui")).toEqual([]);
    expect(refused(kit, "../App")).toEqual(["../App"]);
    expect(refused(kit, "../adapter")).toEqual(["../adapter"]);
    expect(refused(kit, "xstate")).toEqual(["xstate"]);
    // Only kit files are held to it; the rest of the console may import freely.
    expect(refused(`${ROOTS.consoleSrc}/App.tsx`, "./adapter")).toEqual([]);
    expect(KIT_BACKREFS_ALLOWED.has("App")).toBe(false);
  });

  it("keeps radix-ui behind the kit: the kit may import it, nothing else may", () => {
    expect(refused(`${ROOTS.consoleSrc}/kit/dialog.tsx`, "radix-ui")).toEqual([]);
    expect(refused(`${ROOTS.consoleSrc}/kit/dialog.test.tsx`, "@testing-library/react")).toEqual([]);
    expect(refused(`${ROOTS.consoleSrc}/App.tsx`, "radix-ui")).toEqual(["radix-ui"]);
    expect(refused(`${ROOTS.consoleSrc}/aui/thread.tsx`, "radix-ui")).toEqual(["radix-ui"]);
    expect(refused(`${FORMS_UI}/index.tsx`, "radix-ui")).toEqual(["radix-ui"]);
  });

  it("lets only the generated registry import the studio tier from the console", () => {
    const spec = "@studio/aincient_studio_forms/ui/index.tsx";
    expect(refused(`${ROOTS.consoleSrc}/${GENERATED_REGISTRY}`, spec)).toEqual([]);
    expect(refused(`${ROOTS.consoleSrc}/App.tsx`, spec)).toEqual([spec]);
    expect(refused(`${ROOTS.consoleSrc}/App.tsx`, "../../../../studio/aincient_studio_forms/ui/index.tsx")).toHaveLength(1);
    expect(refused(`${ROOTS.consoleSrc}/App.tsx`, "react-markdown")).toEqual([]);
  });

  it("reads every import form the fence promises to", () => {
    const body = [
      'import a from "a";',
      'import type { B } from "b";',
      "import {\n  c,\n  d,\n} from 'c';",
      'import "d";',
      'export { e } from "e";',
      'export * from "f";',
      'const g = import("g");',
      'const h = require("h");',
    ].join("\n");
    expect(specifiers(body)).toEqual(["a", "b", "c", "d", "e", "f", "g", "h"]);
  });
});

/* ------------------------------------------------------------------ the walk */

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");

function sources(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return entry === "node_modules" ? [] : sources(full);
    return /\.tsx?$/.test(entry) ? [full] : [];
  });
}

/** Every studio module's `ui/` source file. */
function studioSources(): string[] {
  if (!existsSync(STUDIO_TIER)) return [];
  return readdirSync(STUDIO_TIER).flatMap((module) => sources(join(STUDIO_TIER, module, "ui")));
}

describe("the studio fence on the real tree", () => {
  const files = [...studioSources(), ...sources(SRC)].map((path) => ({
    path,
    body: readFileSync(path, "utf8"),
  }));

  it("is crossed by no studio UI and no console file", () => {
    const found = offenders(files, { studioTier: STUDIO_TIER, consoleSrc: SRC }).map(
      (o) => `${o.path.slice(join(SRC, "../../../..").length + 1)}: "${o.specifier}" ${o.why}`,
    );
    // The message is the point: whoever trips this needs to know what to do.
    expect(found, found.join("\n")).toEqual([]);
  });

  it("still sees the files it is supposed to be walking", () => {
    // A broken walk would make the check above pass silently forever.
    expect(sources(SRC).length).toBeGreaterThan(100);
    expect(files.some((f) => f.path.endsWith(GENERATED_REGISTRY))).toBe(true);
  });
});
