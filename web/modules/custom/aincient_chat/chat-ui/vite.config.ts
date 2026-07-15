import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";

// Builds a single self-mounting IIFE bundle + one CSS file into the module's
// js/dist/, which Drupal serves as a library. No build step at install time.
export default defineConfig({
  plugins: [react()],
  define: { "process.env.NODE_ENV": JSON.stringify("production") },
  build: {
    outDir: resolve(__dirname, "../js/dist"),
    emptyOutDir: true,
    cssCodeSplit: false,
    // public/ (the dev-harness favicon) is for `npm run dev` only — keep it out
    // of the shipped library bundle. Production serves the module's own
    // images/favicon.svg, wired by ConsoleController.
    copyPublicDir: false,
    lib: {
      entry: resolve(__dirname, "src/main.tsx"),
      formats: ["iife"],
      name: "AIncientChat",
      fileName: () => "aincient-chat.js",
    },
    rollupOptions: {
      output: { assetFileNames: "aincient-chat.[ext]" },
    },
  },
});
