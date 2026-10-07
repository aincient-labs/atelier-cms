import { describe, expect, it } from "vitest";
import type { ChromeDraft, ChromeMenuLink } from "@console/sdk";
import { appendUnder, linksToPage, menuHasPage, pageToken, parentOptions, stageMenuLink } from "./menu-placement";

const link = (title: string, url = "/x", children?: ChromeMenuLink[]): ChromeMenuLink => ({
  id: title.length,
  title,
  url,
  enabled: true,
  ...(children ? { children } : {}),
});

const tree: ChromeMenuLink[] = [
  link("Home", "/"),
  link("About", "/about", [link("Team", "entity:node:7"), link("Jobs", "/jobs", [link("Open roles", "/node/9")])]),
  link("Contact", "/contact"),
];

describe("pageToken / linksToPage", () => {
  it("speaks the menu editor's page-reference token", () => {
    expect(pageToken(12)).toBe("entity:node:12");
    expect(pageToken("12")).toBe("entity:node:12");
  });

  it("matches the token and the raw node path, not a prefix or an alias", () => {
    expect(linksToPage("entity:node:7", 7)).toBe(true);
    expect(linksToPage(" entity:node:7 ", "7")).toBe(true);
    expect(linksToPage("/node/7", 7)).toBe(true);
    expect(linksToPage("entity:node:70", 7)).toBe(false);
    expect(linksToPage("/node/70", 7)).toBe(false);
    expect(linksToPage("/about", 7)).toBe(false);
  });
});

describe("menuHasPage", () => {
  it("finds a page at any depth", () => {
    expect(menuHasPage(tree, 7)).toBe(true);
    expect(menuHasPage(tree, 9)).toBe(true);
  });

  it("is false for a page that isn't linked, and for an empty menu", () => {
    expect(menuHasPage(tree, 8)).toBe(false);
    expect(menuHasPage([], 7)).toBe(false);
  });
});

describe("parentOptions", () => {
  it("lists every link depth-first with its path and depth", () => {
    expect(parentOptions(tree)).toEqual([
      { path: [0], title: "Home", depth: 0 },
      { path: [1], title: "About", depth: 0 },
      { path: [1, 0], title: "Team", depth: 1 },
      { path: [1, 1], title: "Jobs", depth: 1 },
      { path: [1, 1, 0], title: "Open roles", depth: 2 },
      { path: [2], title: "Contact", depth: 0 },
    ]);
  });

  it("names an untitled link rather than offering a blank option", () => {
    expect(parentOptions([link("  ")])[0].title).toBe("(untitled link)");
  });
});

describe("appendUnder", () => {
  const added = link("New", "entity:node:42");

  it("appends at the end of the top level", () => {
    const next = appendUnder(tree, [], added);
    expect(next.map((l) => l.title)).toEqual(["Home", "About", "Contact", "New"]);
  });

  it("appends as the last child of a nested link, creating children when absent", () => {
    const next = appendUnder(tree, [1, 1], added);
    expect(next[1].children?.[1].children?.map((l) => l.title)).toEqual(["Open roles", "New"]);
    const leaf = appendUnder(tree, [2], added);
    expect(leaf[2].children).toEqual([added]);
  });

  it("never mutates the input, and returns it untouched for a path that doesn't resolve", () => {
    const before = JSON.stringify(tree);
    appendUnder(tree, [1, 0], added);
    expect(JSON.stringify(tree)).toBe(before);
    expect(appendUnder(tree, [5], added)).toBe(tree);
    expect(appendUnder(tree, [1, 4], added)).toBe(tree);
  });
});

describe("stageMenuLink", () => {
  const draft = {
    chrome: { header: {}, footer: {} },
    identity: {},
    privacy: { font_delivery: "selfhost" },
    menus: { main: tree, footer: [link("Imprint", "/imprint")] },
  } as unknown as ChromeDraft;

  it("adds a new (id-less, enabled) page link to the chosen menu only", () => {
    const next = stageMenuLink(draft, "footer", [], 42, "Pricing");
    expect(next.menus.footer).toEqual([link("Imprint", "/imprint"), { title: "Pricing", url: "entity:node:42", enabled: true }]);
    expect(next.menus.main).toBe(draft.menus.main);
    expect(next.chrome).toBe(draft.chrome);
  });

  it("nests under a parent path and leaves the input draft untouched", () => {
    const next = stageMenuLink(draft, "main", [1], 42, "Pricing");
    expect(next.menus.main[1].children?.[2]).toEqual({ title: "Pricing", url: "entity:node:42", enabled: true });
    expect(draft.menus.main[1].children).toHaveLength(2);
  });
});
