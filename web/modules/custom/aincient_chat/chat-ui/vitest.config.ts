import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";
import { aliases, fsAllow, testDedupe } from "./vite.aliases";

/**
 * Two environments, chosen PER FILE — node by default, jsdom on request.
 *
 * Most of this suite tests the pure logic layer (the console statechart, the room
 * primitives, and the deliberately DOM-free seams like `provider-failure.ts` and
 * `tour-model.ts`). Those need no DOM and are fast as they are, so
 * `environment: "node"` stays the default and no existing test changes behaviour.
 *
 * A file that RENDERS a component opts in with a `// @vitest-environment jsdom`
 * docblock on its first line. Per-file rather than a global flip or an
 * `environmentMatchGlobs` pattern: the opt-in is then visible at the top of the
 * file that needs it, and nothing about a test's environment is decided in a
 * config file the reader isn't looking at.
 *
 * The React plugin is here (not only in `vite.config.ts`) so `.test.tsx` files get
 * their JSX transformed the same way the bundle's does. The alias table is the
 * bundle's too (`vite.aliases.ts`, imported rather than copied) so a studio's
 * `ui/*.test.tsx` — picked up from `web/modules/studio/` by the second include
 * glob (DECISIONS 0430) — resolves `react` and `@console/*` exactly as it will
 * in the build.
 */
export default defineConfig({
  plugins: [react()],
  resolve: { alias: aliases, dedupe: testDedupe },
  server: { fs: { allow: fsAllow } },
  test: {
    environment: "node",
    include: ["src/**/*.test.{ts,tsx}", "../../../studio/*/ui/**/*.test.{ts,tsx}"],
  },
});
