import { dirname, join, relative, resolve, sep } from "node:path";

/**
 * The studio import fence — the pure rule; `studio-fence.test.ts` walks the tree.
 *
 * Studio UI is compiled by the console build (DECISIONS 0430), which means it CAN
 * import anything the console can. That is exactly the problem: one convenient
 * reach into `App.tsx` or another studio's folder, and studios stop being units —
 * the module you switch off still has a sibling depending on it, and every
 * console-internal rename breaks a studio that was never supposed to know the
 * name. So a studio's `ui/` may import only:
 *
 *   - its OWN files (a relative specifier that stays inside its `ui/`);
 *   - the three console roots: `@console/kit` (primitives), `@console/sdk`
 *     (console hooks), `@console/aui` (the chat-runtime facade);
 *   - `react` / `react-dom` and their subpaths;
 *   - and, in a `*.test.ts(x)` file only, `vitest` and `@testing-library/*`.
 *
 * Everything else is an offender — another studio (`@studio/…`, or a relative
 * path that climbs into one), console internals, any other npm package. A
 * package a studio genuinely needs is a reason to put it behind `kit/` or `sdk/`,
 * where the console owns its version for every studio at once. This one rule is
 * also the front end's "no studio-on-studio dependency" guard.
 *
 * The kit has its own, narrower rule, for the opposite direction: a primitive that
 * imports console internals drags them into every studio that uses it, so files
 * under `src/kit/` may import only other kit files, `react` / `react-dom`, and the
 * back-references frozen in {@link KIT_BACKREFS_ALLOWED}. That set records what
 * the skeleton already did when it was cut (it was extracted, not designed) and
 * exists so the smell can shrink and never grow.
 *
 * The converse holds too: the console never reaches into a studio by hand. Only
 * the generated registry imports from the studio tier.
 *
 * Like `aui/seam.test.ts`, this reads SOURCE text, not the module graph: it
 * catches the import before it type-checks, and in a file nothing imports yet.
 * It is pure (paths + bodies in, offenders out) so the rule can be tested against
 * synthetic fixtures while the studio tier is still empty.
 */

export type SourceFile = { path: string; body: string };

export type FenceRoots = {
  /** Absolute path of `web/modules/studio`. */
  studioTier: string;
  /** Absolute path of the console's `chat-ui/src`. */
  consoleSrc: string;
};

export type Offender = { path: string; specifier: string; why: string };

/** The one console file allowed to import from the studio tier, relative to `src/`. */
export const GENERATED_REGISTRY = "studio-registry.generated.ts";

/**
 * Console files `src/kit/` may still import, relative to `src/`, extensionless.
 * Frozen at the skeleton extraction (plans/studio-modules.md: the kit is pulled
 * out on demand, and these are the primitives that still reach back). Remove an
 * entry when its primitive is cut loose; never add one — move the thing the kit
 * needs behind the kit (or `sdk/`) instead.
 */
export const KIT_BACKREFS_ALLOWED: ReadonlySet<string> = new Set([
  "aui", // data-table + error-boundary: the chat-runtime facade for the tool-UI factory
  "flow", // data-table: flow/step types for the table's row actions
  "studios", // data-table: studio ids for row deep links
  "console-url", // data-table: deep-link building
  "surface-nav", // data-table: opening a surface from a row
  "url-sync", // data-table: in-place page navigation
  "page-state", // data-table: loading a document from a row
  "console-config", // reference-field: injected API base
]);

/**
 * Every module specifier in a source body: `import … from "x"`, `import "x"`,
 * `export … from "x"`, `import("x")` and `require("x")`. Deliberately a regex,
 * as in the seam test — a false positive (say, `from "x"` in a comment) fails
 * loudly and is trivial to reword; a parser would be one more dependency for the
 * same answer.
 */
const SPECIFIER =
  /\b(?:import|export)\s[^'"`;]*?\bfrom\s*["']([^"']+)["']|\bimport\s*["']([^"']+)["']|\b(?:import|require)\s*\(\s*["']([^"']+)["']\s*\)/g;

export function specifiers(body: string): string[] {
  return [...body.matchAll(SPECIFIER)].map((m) => m[1] ?? m[2] ?? m[3]);
}

/** `spec` is `root` or a subpath of it (`react` and `react/jsx-runtime`, never `react-dom` for `react`). */
function under(spec: string, root: string): boolean {
  return spec === root || spec.startsWith(root + "/");
}

/** `child` is `parent` or inside it, as filesystem paths. */
function inside(child: string, parent: string): boolean {
  return child === parent || child.startsWith(parent + sep);
}

const CONSOLE_ROOTS = ["@console/kit", "@console/sdk", "@console/aui"];
const RUNTIME = ["react", "react-dom"];
const isTest = (path: string) => /\.test\.tsx?$/.test(path);

const STUDIO_ADVICE =
  `a studio's ui/ may import only its own files, @console/kit, @console/sdk, ` +
  `@console/aui and react — move what you need behind kit/ or sdk/ (DECISIONS 0430).`;

/** Why a specifier in a studio UI file is out of bounds, or null when it is allowed. */
function studioVerdict(file: string, spec: string, uiDir: string, tier: string): string | null {
  if (spec.startsWith(".")) {
    const target = resolve(dirname(file), spec);
    if (inside(target, uiDir)) return null;
    return inside(target, tier)
      ? `reaches outside its own ui/ — ${STUDIO_ADVICE}`
      : `reaches into the console's internals — ${STUDIO_ADVICE}`;
  }
  if (CONSOLE_ROOTS.some((r) => under(spec, r))) return null;
  if (RUNTIME.some((r) => under(spec, r))) return null;
  if (isTest(file) && (spec === "vitest" || spec.startsWith("@testing-library/"))) return null;
  if (under(spec, "@studio")) return `imports another studio — studios never depend on each other; ${STUDIO_ADVICE}`;
  return `is not an allowed import — ${STUDIO_ADVICE}`;
}

/** Why a specifier in a console file is out of bounds, or null when it is allowed. */
function consoleVerdict(file: string, spec: string, roots: FenceRoots): string | null {
  if (KIT_DEPENDENCIES.some((r) => under(spec, r)) && !inside(file, join(roots.consoleSrc, "kit"))) {
    return `is a kit-only dependency — use the kit primitive built on it (KIT_DEPENDENCIES, studio-fence.ts).`;
  }
  const reaches = spec.startsWith(".")
    ? inside(resolve(dirname(file), spec), roots.studioTier)
    : under(spec, "@studio");
  if (!reaches) return null;
  if (relative(roots.consoleSrc, file) === GENERATED_REGISTRY) return null;
  return (
    `the console reaches into a studio by hand — only ${GENERATED_REGISTRY} imports ` +
    `from the studio tier; declare the studio's ui: in its manifest and run npm run gen:studios.`
  );
}

/**
 * Third-party packages only the kit may import. Radix supplies the behaviour
 * (focus trap, Escape, roving focus, ARIA) under Popover, Menu, Dialog and Tabs;
 * it stays behind the kit the way assistant-ui stays behind `aui/`, so a studio
 * or a console screen gets those through the kit or not at all.
 */
export const KIT_DEPENDENCIES: readonly string[] = ["radix-ui"];

/** Why a specifier in a kit file is out of bounds, or null when it is allowed. */
function kitVerdict(file: string, spec: string, roots: FenceRoots): string | null {
  if (RUNTIME.some((r) => under(spec, r))) return null;
  if (KIT_DEPENDENCIES.some((r) => under(spec, r))) return null;
  if (isTest(file) && (spec === "vitest" || spec.startsWith("@testing-library/"))) return null;
  const advice = `src/kit/ may import only kit files, react, KIT_DEPENDENCIES and KIT_BACKREFS_ALLOWED (studio-fence.ts).`;
  if (!spec.startsWith(".")) return `is not an allowed import — ${advice}`;
  const target = resolve(dirname(file), spec);
  if (inside(target, join(roots.consoleSrc, "kit"))) return null;
  const rel = relative(roots.consoleSrc, target);
  if (KIT_BACKREFS_ALLOWED.has(rel)) return null;
  return `reaches into console internals (${rel}) — ${advice}`;
}

/**
 * Every out-of-bounds import across `files`. A file under `<studioTier>/<module>/ui/`
 * is held to the studio rule, a file under `consoleSrc` to the console rule (and,
 * under `consoleSrc/kit`, to the kit rule as well), and anything else is not this
 * fence's business.
 */
export function offenders(files: SourceFile[], roots: FenceRoots): Offender[] {
  const out: Offender[] = [];
  for (const { path, body } of files) {
    let verdict: (spec: string) => string | null;
    if (inside(path, roots.studioTier)) {
      const [module, ui] = relative(roots.studioTier, path).split(sep);
      if (ui !== "ui") continue;
      const uiDir = resolve(roots.studioTier, module, "ui");
      verdict = (spec) => studioVerdict(path, spec, uiDir, roots.studioTier);
    } else if (inside(path, roots.consoleSrc)) {
      const kitDir = join(roots.consoleSrc, "kit");
      verdict = inside(path, kitDir)
        ? (spec) => kitVerdict(path, spec, roots) ?? consoleVerdict(path, spec, roots)
        : (spec) => consoleVerdict(path, spec, roots);
    } else {
      continue;
    }
    for (const specifier of specifiers(body)) {
      const why = verdict(specifier);
      if (why) out.push({ path, specifier, why });
    }
  }
  return out;
}
