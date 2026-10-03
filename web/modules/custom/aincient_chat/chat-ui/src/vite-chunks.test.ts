import { describe, expect, it } from "vitest";
import { buildId, chunkFor, versionImports } from "../vite.chunks";

/**
 * The code-splitting rules (vite.chunks.ts): where a module lands, and how the
 * chunks name each other. The build itself is checked by `npm run build` +
 * the dist shape; these pin the two pure decisions it is made of.
 */

const roots = {
  studioTier: "/w/web/modules/studio",
  entry: "/w/web/modules/custom/aincient_chat/chat-ui/src/main.tsx",
};

describe("chunkFor", () => {
  it("puts a studio module's files — any depth, both entries — in that module's chunk", () => {
    expect(chunkFor("/w/web/modules/studio/aincient_audit/ui/index.tsx", roots)).toBe("studio-aincient_audit");
    expect(chunkFor("/w/web/modules/studio/aincient_audit/ui/parts/row.tsx", roots)).toBe("studio-aincient_audit");
    expect(chunkFor("/w/web/modules/studio/aincient_studio_site/ui/settings.tsx", roots)).toBe("studio-aincient_studio_site");
  });

  it("puts the console, its deps and the bundler's virtual modules in the console chunk", () => {
    expect(chunkFor("/w/web/modules/custom/aincient_chat/chat-ui/src/App.tsx", roots)).toBe("console");
    expect(chunkFor("/w/web/modules/custom/aincient_chat/chat-ui/node_modules/react/index.js", roots)).toBe("console");
    expect(chunkFor("\0vite/preload-helper.js", roots)).toBe("console");
    expect(chunkFor("\0commonjsHelpers.js", roots)).toBe("console");
  });

  it("leaves the entry alone (it must stay a facade of its own) and stylesheets to the CSS pass", () => {
    expect(chunkFor(roots.entry, roots)).toBeUndefined();
    expect(chunkFor("/w/web/modules/studio/aincient_audit/ui/styles.css", roots)).toBeUndefined();
    expect(chunkFor("/w/web/modules/custom/aincient_chat/chat-ui/src/styles.css?inline", roots)).toBeUndefined();
  });

  it("does not mistake a sibling of the tier for the tier", () => {
    expect(chunkFor("/w/web/modules/studio_other/x/ui/index.tsx", roots)).toBe("console");
  });
});

describe("versionImports", () => {
  it("stamps every relative .js import — static, dynamic, re-export, minified", () => {
    // Backticks included: Rolldown's minifier writes dynamic imports that way.
    const code =
      'import{a}from"./console.js";import(`./studio-aincient_audit.js`).then(x=>x);' +
      "export{b}from'../other.js';const s=\"./not-an-import.txt\";";
    expect(versionImports(code, "abc123")).toBe(
      'import{a}from"./console.js?v=abc123";import(`./studio-aincient_audit.js?v=abc123`).then(x=>x);' +
        "export{b}from'../other.js?v=abc123';const s=\"./not-an-import.txt\";",
    );
  });

  it("leaves bare and absolute specifiers, and already-versioned ones, alone", () => {
    const code = 'import r from"react";import x from"/abs/x.js";import y from"./y.js?v=old";';
    expect(versionImports(code, "new")).toBe(code);
  });
});

describe("buildId", () => {
  it("is stable for the same code and changes with any chunk, independent of insertion order", () => {
    const a = buildId({ "console.js": "A", "studio-x.js": "B" });
    expect(a).toMatch(/^[0-9a-f]{12}$/);
    expect(buildId({ "studio-x.js": "B", "console.js": "A" })).toBe(a);
    expect(buildId({ "console.js": "A", "studio-x.js": "B!" })).not.toBe(a);
    expect(buildId({ "console.js": "A" })).not.toBe(a);
  });
});
