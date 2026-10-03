import { resolve } from "node:path";
import { searchForWorkspaceRoot } from "vite";

/**
 * The console build's module-resolution table — ONE file, read by both
 * `vite.config.ts` and `vitest.config.ts`, so the bundle and the test runner can
 * never disagree about what an import means.
 *
 * WHY IT EXISTS: studio UI source lives OUTSIDE this package (DECISIONS 0430 —
 * `web/modules/studio/<module>/ui/`, compiled by this one build). A file there has
 * no `node_modules` above it, so a bare `import … from "react"` resolves to
 * nothing — or, worse, to a second React somewhere up the tree, and two Reacts
 * are a hooks crash at runtime, not a build error. So:
 *
 *   - `react` / `react-dom` (and their subpaths, incl. the JSX runtime the React
 *     plugin injects) point at THIS package's copies, and `dedupe` makes that
 *     hold for anything they pull in;
 *   - the three roots a studio may import (`@console/kit`, `@console/sdk`,
 *     `@console/aui`) and the tier itself (`@studio`, used only by the generated
 *     registry) are named here. `src/kit` and `src/sdk` are created by a later
 *     step; an alias to a directory that does not exist yet costs nothing until
 *     something imports through it.
 *
 * What a studio may import is a separate question, answered by the import fence
 * (`src/studio-fence.ts`), not by what happens to resolve. `tsconfig.json`
 * mirrors these paths for the type checker — keep the two in step.
 */

const here = import.meta.dirname;

/** The studio tier, relative to this package: `web/modules/studio`. */
export const STUDIO_TIER = resolve(here, "../../../studio");

export const aliases: Record<string, string> = {
  react: resolve(here, "node_modules/react"),
  "react-dom": resolve(here, "node_modules/react-dom"),
  "@console/kit": resolve(here, "src/kit"),
  "@console/sdk": resolve(here, "src/sdk"),
  "@console/aui": resolve(here, "src/aui"),
  "@studio": STUDIO_TIER,
};

export const dedupe = ["react", "react-dom"];

/**
 * What the dev server (and Vitest, which serves test files through it) may read.
 * Vite's default is the workspace root it detects — for this package, the
 * nearest `package.json`, i.e. chat-ui itself — so a file under the studio tier
 * is refused (Vitest reports it as "Cannot find module '/@fs/…'"). Name the tier
 * explicitly beside the default.
 */
export const fsAllow = [searchForWorkspaceRoot(here), STUDIO_TIER];

/**
 * The test runner's additions: a studio's `ui/*.test.tsx` imports `vitest` and
 * `@testing-library/*` (the fence allows both, in test files only), and those
 * have no `node_modules` above the studio tier either. Resolved from this
 * package by `dedupe` — which resolves a bare import from the project root
 * wherever the importer lives — rather than aliased: `vitest` is the runner
 * itself, and pointing it at a path instead of letting Vitest resolve its own
 * entry is asking for two copies of the runner's state. The bundle never
 * imports either, so only `vitest.config.ts` reads this.
 */
export const testDedupe = [...dedupe, "vitest", "@testing-library/react", "@testing-library/dom"];
