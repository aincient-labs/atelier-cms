import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";
import { aliases, dedupe, fsAllow, STUDIO_TIER } from "./vite.aliases";
import { chunkFor, chunkVersionPlugin } from "./vite.chunks";

// `import.meta.dirname` rather than `__dirname`: Vite 8 warns that its future
// default native config loader cannot provide the CJS globals.
const here = import.meta.dirname;
const entry = resolve(here, "src/main.tsx");

// Builds an ESM bundle — the entry + the console chunk + one lazy chunk per
// studio module (vite.chunks.ts) — and one CSS file into the module's
// js/dist/, which Drupal serves as a library (`type="module"`). No build step
// at install time; the output is committed.
export default defineConfig({
  plugins: [react(), chunkVersionPlugin()],
  // Studio UI compiles from outside this package (DECISIONS 0430) — the alias
  // table explains why, and is shared with vitest.config.ts.
  resolve: { alias: aliases, dedupe },
  // `npm run dev` must be allowed to serve a studio's UI from outside chat-ui.
  server: { fs: { allow: fsAllow } },
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  // Asset URLs relative to the importing file, never to the site root: the
  // bundle is served from a module path, not from `/`.
  base: "./",
  build: {
    outDir: resolve(here, "../js/dist"),
    emptyOutDir: true,
    // One stylesheet for every chunk: the console and the studios share the
    // same tokens and cascade, and Drupal attaches one CSS file.
    cssCodeSplit: false,
    // No preload helper / polyfill: it resolves chunk URLs from the site root
    // (wrong under /modules/…/js/dist/); plain import() resolves against the
    // importing module, which is right. See vite.chunks.ts.
    modulePreload: false,
    // public/ (the dev-harness favicon) is for `npm run dev` only — keep it out
    // of the shipped library bundle. Production serves the module's own
    // images/favicon.svg, wired by ConsoleController.
    copyPublicDir: false,
    rollupOptions: {
      input: entry,
      output: {
        format: "es",
        entryFileNames: "aincient-chat.js",
        chunkFileNames: "[name].js",
        assetFileNames: "aincient-chat.[ext]",
        manualChunks: (id) => chunkFor(id, { studioTier: STUDIO_TIER, entry }),
      },
    },
  },
});
