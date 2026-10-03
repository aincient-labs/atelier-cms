import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * The loading-state guard (Law 09, docs/console-kit.md "LoadingState"): a pane,
 * list or rail that waits shows the kit's `LoadingState` — the shape it will
 * have, with placeholder bars — and never a hand-written "Loading…" line or the
 * old spinner-over-text in the empty-state box. Runs over the console's own
 * source and every studio's `ui/`, so a studio author (or an agent) who reaches
 * for the old pattern fails the build instead of shipping it.
 *
 * A BUTTON that waits is different: it keeps its own label and spinner
 * ("Saving…", "Replacing…"). The one button that says "Loading…" is listed in
 * ALLOWED with its reason — a new entry needs the same kind of reason.
 */

export type LoadingOffender = { file: string; line: number; text: string; why: string };

// A string or JSX text that is a loading line: starts with "Loading", ends in an ellipsis.
const LOADING_TEXT = /(?:>|["'`])\s*(Loading\b[^<>"'`{}\n]*?(?:…|\.\.\.))\s*(?=<|["'`])/g;
// The pre-kit spinner line: the empty-state box written by hand.
const HAND_EMPTY_BOX = /className=["'`][^"'`]*\bain-browser__empty\b/g;

/** Every guard violation in one source file (path is for the report only). */
export function loadingOffenders(raw: string, file: string): LoadingOffender[] {
  // Blank out comments (keeping newlines, so line numbers hold): a comment
  // that QUOTES the old pattern is documentation, not a loading state.
  const source = raw.replace(/\/\*[\s\S]*?\*\/|(?<![:"'`])\/\/[^\n]*/g, (c) => c.replace(/[^\n]/g, " "));
  const out: LoadingOffender[] = [];
  const lineOf = (index: number) => source.slice(0, index).split("\n").length;
  for (const m of source.matchAll(LOADING_TEXT)) {
    out.push({ file, line: lineOf(m.index ?? 0), text: m[1], why: 'a "Loading…" line — use <LoadingState> (kit)' });
  }
  for (const m of source.matchAll(HAND_EMPTY_BOX)) {
    out.push({ file, line: lineOf(m.index ?? 0), text: m[0], why: "the empty-state box by hand — use <EmptyState> or <LoadingState>" });
  }
  return out;
}

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");

/** Files that DEFINE the pattern (the kit itself) or only exercise it. */
const EXEMPT = /(^|\/)kit\/(empty-state|loading-state)\.tsx$|\.test\.tsx?$|(^|\/)kit-gallery\//;

/** Known, reasoned exceptions: `file:text` → why it is not a pane. */
const ALLOWED: Record<string, string> = {
  "App.tsx:Loading…":
    "the Load-earlier-messages BUTTON names its own wait; a button keeps its label, only panes take LoadingState",
};

function tsx(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    if (entry === "node_modules" || entry === "dist") return [];
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) return tsx(path);
    return /\.tsx?$/.test(entry) ? [path] : [];
  });
}

function allSources(): { path: string; rel: string }[] {
  const studios = existsSync(STUDIO_TIER)
    ? readdirSync(STUDIO_TIER).flatMap((m) => tsx(join(STUDIO_TIER, m, "ui")))
    : [];
  return [...tsx(SRC), ...studios].map((path) => ({
    path,
    rel: path.startsWith(SRC) ? relative(SRC, path) : relative(STUDIO_TIER, path),
  }));
}

describe("the loading-state guard (rule)", () => {
  it("flags a hand-written Loading… line in JSX text and in a string", () => {
    const jsx = loadingOffenders('<p className="ain-studio__hint">Loading tokens…</p>', "x.tsx");
    expect(jsx.map((o) => o.text)).toEqual(["Loading tokens…"]);
    const str = loadingOffenders('const t = ready ? "Done" : "Loading...";', "x.tsx");
    expect(str.map((o) => o.text)).toEqual(["Loading..."]);
  });

  it("flags the old spinner-over-text box written by hand", () => {
    const old = loadingOffenders('<p className="ain-browser__empty"><SpinnerIcon /> Wait</p>', "x.tsx");
    expect(old).toHaveLength(1);
    expect(old[0].why).toMatch(/EmptyState|LoadingState/);
  });

  it("leaves labels, state and words that merely contain loading alone", () => {
    const fine = [
      '<LoadingState label="Loading pages" />',
      "const [loading, setLoading] = useState(false);",
      '<span>{saving ? "Saving…" : "Save"}</span>',
      "// Loading replaces the draft, so while it's dirty",
      '// rather than reading as a stuck "Loading…".',
      '{/* the old "Loading…" line */}',
    ].join("\n");
    expect(loadingOffenders(fine, "x.tsx")).toEqual([]);
  });
});

describe("the loading-state guard (tree)", () => {
  it("no console or studio source hand-writes a loading state", () => {
    const offenders = allSources()
      .filter(({ rel }) => !EXEMPT.test(rel))
      .flatMap(({ path, rel }) => loadingOffenders(readFileSync(path, "utf8"), rel))
      .filter((o) => !ALLOWED[`${o.file}:${o.text}`]);
    expect(offenders).toEqual([]);
  });

  it("every allowance still matches something, so a stale one cannot linger", () => {
    const found = new Set(
      allSources().flatMap(({ path, rel }) => loadingOffenders(readFileSync(path, "utf8"), rel).map((o) => `${o.file}:${o.text}`)),
    );
    for (const key of Object.keys(ALLOWED)) expect(found.has(key), key).toBe(true);
  });

  it("reads the studio tier, so the tree test is not vacuous", () => {
    expect(allSources().some(({ path }) => path.startsWith(STUDIO_TIER))).toBe(true);
  });
});
