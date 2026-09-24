/**
 * The seam, enforced.
 *
 * `./index.ts` is the console's only door to `@assistant-ui`. That is worth
 * nothing as a convention — a facade without a gate erodes in a month, one
 * convenient import at a time, and by the time anyone notices, the vendor's
 * release notes are our breaking-change policy again (DECISIONS 0424).
 *
 * So this walks the real source tree and fails on any import of `@assistant-ui/*`
 * outside the facade and the ONE recorded exemption.
 *
 * THE EXEMPTION: `App.tsx` may import the layout primitives and the markdown
 * primitive directly — they are compound-component namespaces used as markup, and
 * App.tsx is ours and never plugin-facing. It is listed by name here, so widening
 * the exemption is an edit to this list and shows up in review, which is the
 * difference between a decision and a drift.
 *
 * Note this greps SOURCE, not the module graph: it catches the import before it
 * type-checks, and it catches one in a file nothing imports yet.
 */

import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

/** Files allowed to import the vendor directly, relative to `src/`. */
const EXEMPT = new Set(["aui/index.ts", "App.tsx"]);

const SRC = join(import.meta.dirname, "..");

function sources(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return sources(full);
    return /\.tsx?$/.test(entry) ? [full] : [];
  });
}

describe("the assistant-ui seam", () => {
  it("is crossed only by the facade and the recorded App.tsx exemption", () => {
    const offenders = sources(SRC)
      .map((file) => [file.slice(SRC.length + 1), readFileSync(file, "utf8")] as const)
      .filter(([rel, body]) => !EXEMPT.has(rel) && /["']@assistant-ui\//.test(body))
      .map(([rel]) => rel);

    // The message is the point: whoever trips this needs to know what to do.
    expect(
      offenders,
      `import from "./aui" instead — or, if the facade is missing what you need, ` +
        `add it there. See src/aui/index.ts.`,
    ).toEqual([]);
  });

  it("still sees the files it is supposed to be walking", () => {
    // A broken walk would make the check above pass silently forever.
    expect(sources(SRC).length).toBeGreaterThan(100);
  });
});
