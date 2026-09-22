// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The URL ↔ Room codec, on the axis M3 added: the audit room's LANGCODE.
 *
 * Opening Checks from a German page used to navigate to /atelier/checks/node/N
 * and audit the English page — the room, and therefore the URL, carried no
 * language. The audit path now takes the same trailing segment the Content page
 * path always did, so EN and DE are distinct, bookmarkable rooms.
 *
 * `roomToPath`/`parseUrl` read `window.location` through `consoleBase()`, which
 * reads the server-injected console config — stubbed here to a fixed base.
 */
vi.mock("./console-config", () => ({
  consoleBase: () => "/atelier",
  apiBase: () => "/atelier/api",
}));

vi.mock("./studios", async () => {
  const actual = await vi.importActual<typeof import("./studios")>("./studios");
  return {
    ...actual,
    // Every studio is reachable in these tests; the default is the Content list.
    isStudioAccessible: () => true,
    serverDefaultStudio: () => "content",
  };
});

import { parseUrl, roomToPath } from "./console-url";
import { pageDeepLink } from "./studios";
import { roomId, type Room } from "./rooms-core";

function at(path: string, search = ""): void {
  Object.defineProperty(window, "location", {
    value: { pathname: path, search },
    writable: true,
    configurable: true,
  });
}

beforeEach(() => at("/atelier"));

describe("audit room ↔ path", () => {
  it("writes the langcode as the trailing segment", () => {
    expect(roomToPath({ kind: "audit", nid: 5, langcode: "de" })).toBe("/atelier/checks/node/5/de");
  });

  it("omits it for the source language", () => {
    expect(roomToPath({ kind: "audit", nid: 5 })).toBe("/atelier/checks/node/5");
    expect(roomToPath({ kind: "audit", nid: 5, langcode: null })).toBe("/atelier/checks/node/5");
  });

  it("round-trips through parseUrl", () => {
    at("/atelier/checks/node/5/de");
    const { room } = parseUrl();
    expect(room).toEqual({ kind: "audit", nid: 5, langcode: "de" });
    expect(roomToPath(room)).toBe("/atelier/checks/node/5/de");
  });

  it("parses the bare audit path as the source language", () => {
    at("/atelier/checks/node/5");
    expect(parseUrl().room).toEqual({ kind: "audit", nid: 5, langcode: null });
  });

  it("makes EN and DE two distinct rooms", () => {
    const en: Room = { kind: "audit", nid: 5 };
    const de: Room = { kind: "audit", nid: 5, langcode: "de" };
    expect(roomId(en)).not.toBe(roomId(de));
    // …and the source-language key is unchanged from before the langcode axis.
    expect(roomId(en)).toBe("checks:audit:5");
  });
});

describe("pageDeepLink", () => {
  it("carries the langcode into both studios", () => {
    expect(pageDeepLink("checks", "5", "/atelier", "de")).toBe("/atelier/checks/node/5/de");
    expect(pageDeepLink("content", "5", "/atelier", "de")).toBe("/atelier/content/node/5/de");
  });

  it("omits it when there is none", () => {
    expect(pageDeepLink("checks", "5", "/atelier")).toBe("/atelier/checks/node/5");
    expect(pageDeepLink("content", "5", "/atelier", null)).toBe("/atelier/content/node/5");
  });
});
