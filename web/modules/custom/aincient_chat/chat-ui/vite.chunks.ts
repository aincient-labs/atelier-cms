import { createHash } from "node:crypto";
import { sep } from "node:path";
import type { Plugin } from "vite";

/**
 * The console's code-splitting rules (Phase C of plans/studio-modules.md).
 *
 * The bundle is ESM in three kinds of file, all with DETERMINISTIC names so
 * the committed `js/dist/` diffs by content, not by hash, and the Drupal
 * library definition can name the entry forever:
 *
 *   aincient-chat.js            the entry Drupal loads (`type="module"`): the
 *                               mount + crash card, importing the console
 *   console.js                  everything that is not a studio — React, the
 *                               chat runtime, the kit, the sdk, the registry
 *   studio-<module>.js          one chunk per studio MODULE, reached only by
 *                               the registry's `import()` (studio-loader.ts)
 *   kit-gallery.js              the dev-only kit gallery (/atelier/dev/kit),
 *                               reached by the entry's one `import()`
 *   rolldown-runtime.js         the bundler's own interop helpers (<1 KB).
 *                               Rolldown emits them as a chunk of their own
 *                               whenever more than one chunk needs them and
 *                               does not route them through `manualChunks`;
 *                               harmless, stable-named, versioned like the rest.
 *
 * WHY THE ENTRY IS A FACADE. Drupal serves the entry as `aincient-chat.js?v=…`
 * — its own cache-busting query, which the inter-chunk imports Rollup writes
 * (`import"./x.js"`) know nothing about. If a studio chunk imported the ENTRY
 * for its shared code, the browser would fetch `aincient-chat.js` a second
 * time under a different URL and evaluate it as a second module — two Reacts,
 * two registries, a hooks crash. So nothing shared lives in the entry: the
 * studios import `console.js`, which only our own imports ever name.
 *
 * The three fixes plans/console-extension-point.md told us to take from
 * FlowDrop's build, applied here:
 *
 *   - `chunkVersionPlugin` stamps every relative `.js` import with `?v=<build
 *     id>`, the id being a hash of every chunk's code. A browser that cached
 *     `console.js?v=a1b2` keeps it until a build changes it; a chunk removed
 *     by a deploy is never served stale from cache against a new entry.
 *   - `modulePreload: false` (in vite.config.ts): Vite's preload helper would
 *     resolve chunk URLs from the SITE root, which 404s under
 *     `/modules/custom/aincient_chat/js/dist/`. Plain `import()` resolves
 *     against the importing module's own URL, which is right.
 *   - stable `chunkFileNames` / `entryFileNames` (above).
 *
 * The ENTRY's own cache-busting is Drupal's job: `aincient_chat_library_info_alter()`
 * versions the console library by the entry file's content hash.
 */

/** The chunk a module belongs to, by its id — `manualChunks`. */
export function chunkFor(id: string, roots: { studioTier: string; entry: string }): string | undefined {
  // Rolldown/Vite virtual modules (`\0vite/preload-helper`, commonjs helpers,
  // the React refresh runtime) are console plumbing.
  if (id.startsWith("\0")) return "console";
  // CSS is collected into the one stylesheet (`cssCodeSplit: false`), so its
  // (empty) JS side needs no home of its own.
  const path = id.split("?")[0];
  if (/\.(css|scss|sass|less|styl)$/.test(path)) return undefined;
  if (path === roots.entry) return undefined;
  // The dev-only kit gallery: reached by one `import()` from the entry, never
  // by the console, so a production console never downloads it.
  if (path.includes(`${sep}src${sep}kit-gallery${sep}`)) return "kit-gallery";
  const tier = roots.studioTier.endsWith(sep) ? roots.studioTier : roots.studioTier + sep;
  if (path.startsWith(tier)) {
    const module = path.slice(tier.length).split(sep)[0];
    return `studio-${module}`;
  }
  return "console";
}

/**
 * A relative `./x.js` or `../x.js` string literal — the only kind the bundler
 * writes for its own chunks. Any quote: Rolldown's minifier emits a dynamic
 * `import(\`./x.js\`)` in backticks, the static ones in double quotes.
 */
const RELATIVE_JS = /(["'`])(\.\.?\/[^"'`\s?]+\.js)\1/g;

/** Appends `?v=<version>` to every relative `.js` import in a chunk. */
export function versionImports(code: string, version: string): string {
  return code.replace(RELATIVE_JS, (_, quote: string, path: string) => `${quote}${path}?v=${version}${quote}`);
}

/** A short, stable id for a set of chunks: the same code → the same id. */
export function buildId(codeByFile: Record<string, string>): string {
  const hash = createHash("sha256");
  for (const file of Object.keys(codeByFile).sort()) {
    hash.update(file).update("\0").update(codeByFile[file]).update("\0");
  }
  return hash.digest("hex").slice(0, 12);
}

/**
 * The Vite plugin: after the bundle is generated, rewrite every chunk's
 * relative `.js` imports to carry the build id. `generateBundle` sees every
 * chunk's final code at once, which `renderChunk` (one chunk at a time) does
 * not — and the id has to be the same in all of them.
 */
export function chunkVersionPlugin(): Plugin {
  return {
    name: "aincient:chunk-version",
    generateBundle(_options, bundle) {
      const chunks = Object.values(bundle).filter((item) => item.type === "chunk");
      const id = buildId(Object.fromEntries(chunks.map((chunk) => [chunk.fileName, chunk.code])));
      for (const chunk of chunks) chunk.code = versionImports(chunk.code, id);
    },
  };
}
