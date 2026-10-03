#!/usr/bin/env node
/**
 * Writes src/studio-registry.generated.ts from every studio module's manifest.
 *
 * Studio UI lives in its module (`web/modules/studio/<module>/ui/`) but is
 * compiled by this ONE console build — the source-only split of DECISIONS 0430.
 * There is no runtime registration, so the console has to learn the set of
 * studios at build time, and this is where it does: scan
 * `web/modules/studio/<module>/*.studios.yml` and emit one registry row per
 * declared studio, keyed by the studio id.
 *
 * A row is the manifest's `ui:` map, resolved:
 *
 *   - `name` — the console's crumb name; defaults to the manifest `label`.
 *   - `Icon` — a kit icon, named in kebab-case (`shield-check` →
 *     `ShieldCheckIcon` from `src/kit/icons.tsx`); defaults to the chat glyph.
 *   - `load` — `() => import("@studio/<module>/<ui.entry>")`, present only for
 *     a studio with an entry. A studio without one is chat-only: it still gets
 *     a name and an icon in the nav, and nothing else from the front end.
 *
 * LAZY ON PURPOSE (Phase C of plans/studio-modules.md). Name and icon are the
 * two things the console needs about a studio BEFORE it opens — the nav lists
 * every studio on first paint — so they come from the manifest and land in the
 * console chunk. Everything else a studio brings (`Studio`, `Preview`,
 * `ToolUIs`) lives behind `load`, which the build turns into one chunk per
 * studio module (`vite.chunks.ts`). "Does this studio have a rail?" is answered
 * by `ui.entry` being present — the manifest-side answer the Phase A learnings
 * asked for — so `studioHasEditor()` stays synchronous.
 *
 * LOUD BY DESIGN. Every check below exits 1 rather than skipping the studio: a
 * skipped studio is a console that silently lacks a surface the server offers,
 * and a dangling import is a build error with a worse message. Checked: the
 * manifest parses to a map; `ui` is a map of entry / name / icon; `entry` is a
 * relative path with no `..` that names an existing file; `icon` names an
 * export of the kit's icon set; a studio id is a valid identifier-ish machine
 * name; no id is declared twice across modules. The server validates the same
 * shape in `StudioManifest::validate()`; the two must agree on what a manifest
 * may say, so change them together.
 *
 * Run by `prebuild`, `pretest` and `pretypecheck`, so the committed file can never
 * be stale at the moment it matters. `npm run gen:studios` runs it by hand.
 */

import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { isAbsolute, join, normalize, relative, sep } from "node:path";
import { parse } from "yaml";

const here = import.meta.dirname;
const CHAT_UI = join(here, "..");
const STUDIO_TIER = join(CHAT_UI, "../../../studio");
const OUT = join(CHAT_UI, "src/studio-registry.generated.ts");
const ICONS = join(CHAT_UI, "src/kit/icons.tsx");

/** Same shape as a studio key elsewhere (0425): lowercase machine name. */
const ID = /^[a-z][a-z0-9_]*$/;
/** A kit icon name as the manifest spells it: `shield-check`. */
const ICON = /^[a-z][a-z0-9-]*$/;
/**
 * The keys a STUDIO-TIER manifest's `ui:` map may use. StudioManifest::UI_KEYS
 * also allows `script` / `style`, but those are the pack-studio mount boundary
 * (DECISIONS 0448) and a studio module ships an `entry` instead — the server
 * refuses `script` in this tier too, so here it is simply an unknown key.
 */
const UI_KEYS = ["entry", "name", "icon"];
/** The icon of a studio whose manifest names none: the chat glyph, General's. */
const DEFAULT_ICON = "ChatBubbleIcon";

function fail(message) {
  console.error(`[gen-studios] ${message}`);
  process.exit(1);
}

/** `shield-check` → `ShieldCheckIcon`. */
function iconExport(name) {
  return name.split("-").map((part) => part[0].toUpperCase() + part.slice(1)).join("") + "Icon";
}

/** Every icon component the kit exports, so a manifest typo fails here. */
function kitIcons() {
  const source = readFileSync(ICONS, "utf8");
  return new Set([...source.matchAll(/^export const (\w+Icon)\b/gm)].map((m) => m[1]));
}

/** Every `<tier>/<module>/*.studios.yml`, sorted for a stable walk. */
function manifests() {
  if (!existsSync(STUDIO_TIER)) return [];
  return readdirSync(STUDIO_TIER)
    .filter((module) => statSync(join(STUDIO_TIER, module)).isDirectory())
    .sort()
    .flatMap((module) =>
      readdirSync(join(STUDIO_TIER, module))
        .filter((file) => file.endsWith(".studios.yml"))
        .sort()
        .map((file) => ({ module, file: join(STUDIO_TIER, module, file) })),
    );
}

const icons = kitIcons();
if (!icons.has(DEFAULT_ICON)) fail(`${relative(CHAT_UI, ICONS)} no longer exports ${DEFAULT_ICON}.`);

/** studio id → { name, icon, module, entry? } for every declared studio. */
const studios = new Map();

for (const { module, file } of manifests()) {
  const where = relative(join(CHAT_UI, "../../.."), file);
  let doc;
  try {
    doc = parse(readFileSync(file, "utf8"));
  } catch (e) {
    fail(`${where}: not valid YAML — ${e.message}`);
  }
  if (doc == null) continue; // an empty manifest declares nothing
  if (typeof doc !== "object" || Array.isArray(doc)) {
    fail(`${where}: must be a map of studio id → definition.`);
  }

  for (const [id, def] of Object.entries(doc)) {
    if (!ID.test(id)) fail(`${where}: studio id "${id}" must match ${ID} (a lowercase machine name).`);
    if (studios.has(id)) {
      fail(
        `${where}: studio id "${id}" is already declared by module "${studios.get(id).module}". ` +
          `A studio id is one flat namespace — rename one of them.`,
      );
    }
    const label = def && typeof def === "object" ? def.label : undefined;
    if (typeof label !== "string" || label.trim() === "") {
      fail(`${where}: studio "${id}" needs a non-empty label.`);
    }
    const row = { module, name: label, icon: DEFAULT_ICON };

    const ui = def.ui;
    if (ui !== undefined && ui !== null) {
      if (typeof ui !== "object" || Array.isArray(ui)) {
        fail(`${where}: studio "${id}" ui must be a map of ${UI_KEYS.join(" / ")}, e.g. { entry: ui/index.tsx, icon: sliders }.`);
      }
      for (const key of Object.keys(ui)) {
        if (!UI_KEYS.includes(key)) fail(`${where}: studio "${id}" ui has an unknown key "${key}" (allowed: ${UI_KEYS.join(", ")}).`);
      }
      if (ui.name !== undefined) {
        if (typeof ui.name !== "string" || ui.name.trim() === "") fail(`${where}: studio "${id}" ui.name must be a non-empty string.`);
        row.name = ui.name;
      }
      if (ui.icon !== undefined) {
        if (typeof ui.icon !== "string" || !ICON.test(ui.icon)) {
          fail(`${where}: studio "${id}" ui.icon must be a kit icon name like "shield-check", got ${JSON.stringify(ui.icon)}.`);
        }
        row.icon = iconExport(ui.icon);
        if (!icons.has(row.icon)) {
          fail(`${where}: studio "${id}" ui.icon "${ui.icon}" names ${row.icon}, which ${relative(CHAT_UI, ICONS)} does not export.`);
        }
      }
      const entry = ui.entry;
      if (entry !== undefined && entry !== null) {
        if (typeof entry !== "string" || entry === "" || isAbsolute(entry) || entry.startsWith("/")) {
          fail(`${where}: studio "${id}" ui.entry must be a path relative to the module, e.g. "ui/index.tsx".`);
        }
        const norm = normalize(entry);
        if (norm.split(/[\\/]/).includes("..")) {
          fail(`${where}: studio "${id}" ui.entry "${entry}" may not leave its module (no "..").`);
        }
        const target = join(STUDIO_TIER, module, norm);
        if (!existsSync(target) || !statSync(target).isFile()) {
          fail(`${where}: studio "${id}" ui.entry "${entry}" does not exist (looked for ${relative(CHAT_UI, target)}).`);
        }
        row.entry = norm.split(sep).join("/");
      }
    }
    studios.set(id, row);
  }
}

const ids = [...studios.keys()].sort();
const used = [...new Set(ids.map((id) => studios.get(id).icon))].sort();
const rows = ids.map((id) => {
  const { module, name, icon, entry } = studios.get(id);
  const load = entry ? `, load: () => import("@studio/${module}/${entry}")` : "";
  return `  ${id}: { name: ${JSON.stringify(name)}, Icon: ${icon}${load} },`;
});

// Line comments, not a block: the glob's "*/" would close a /** … */ header.
const body = `// GENERATED by scripts/gen-studio-registry.mjs from web/modules/studio/*/*.studios.yml
// — do not edit; \`npm run gen:studios\` regenerates (prebuild/pretest/pretypecheck
// run it too, so this file cannot be stale at build or test time).
//
// One row per declared studio, keyed by studio id and merged over the console's
// own General in \`studio-registry.tsx\` (DECISIONS 0430): the manifest's
// \`ui.name\` (default: its label) and \`ui.icon\` (a kit icon; default: the chat
// glyph) land here, in the console chunk, because the nav shows them before a
// studio opens. A studio with a \`ui.entry\` also gets \`load\` — a dynamic
// \`import()\` the build turns into that module's own chunk (\`vite.chunks.ts\`),
// fetched the first time the studio is opened or at idle for its chat cards
// (\`studio-loader.ts\`). A row without \`load\` is a chat-only studio.
//
// This is the ONE console file allowed to import from \`@studio/\`
// (\`studio-fence.test.ts\`).

import type { StudioDef } from "./studio-module";
${used.length ? `import { ${used.join(", ")} } from "./kit/icons";\n` : ""}
export const GENERATED_STUDIOS: Record<string, StudioDef> = {${rows.length ? "\n" + rows.join("\n") + "\n" : ""}};
`;

const previous = existsSync(OUT) ? readFileSync(OUT, "utf8") : null;
if (previous !== body) writeFileSync(OUT, body);
const lazy = ids.filter((id) => studios.get(id).entry);
console.log(
  `[gen-studios] ${ids.length} studio${ids.length === 1 ? "" : "s"}` +
    (ids.length ? ` (${ids.join(", ")})` : "") +
    `, ${lazy.length} with a UI entry` +
    `${previous === body ? ", unchanged" : ` → ${relative(CHAT_UI, OUT)}`}`,
);
