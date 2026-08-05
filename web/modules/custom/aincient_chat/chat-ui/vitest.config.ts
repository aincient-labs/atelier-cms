import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

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
 * their JSX transformed the same way the bundle's does.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "node",
    include: ["src/**/*.test.{ts,tsx}"],
  },
});
