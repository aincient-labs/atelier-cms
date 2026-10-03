// @vitest-environment jsdom
/**
 * The pack-studio mount boundary, host side (DECISIONS 0448).
 *
 * What a client pack depends on, rendered: the shell's `packStudios` become
 * registry rows; opening one imports the script and calls `mount(el, ctx)`
 * inside our rail; leaving calls `unmount` and aborts `ctx.signal`; and every
 * way a pack can be wrong — no `mount`, another `apiVersion`, a `mount` that
 * throws — is a named placeholder in the rail, never a crashed console.
 *
 * The chat handle is mocked at our `../aui` seam (as in thread-seal-hooks):
 * `chat.send` only appends to the open thread, so a one-method fake is the
 * whole surface it touches.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import type { StudioMountContext } from "./contract";

const appended = vi.hoisted(() => [] as string[]);

vi.mock("../aui", () => ({
  useConsoleThread: () => ({}),
  sendTurn: (_thread: unknown, text: string) => {
    appended.push(text);
  },
}));

import { kitIcon, loadPackStudio, mountProblem, packStudioRows, resetPackCsrfToken } from "./pack-studios";
import { ChatBubbleIcon, ShieldCheckIcon } from "../kit/icons";

type Settings = Record<string, unknown>;

function setShell(settings: Settings): void {
  (window as unknown as { aincientChat: Settings }).aincientChat = settings;
}

const SETTING = { name: "Leads", icon: "shield-check", script: "/modules/packs/acme/studio/dist/studio.js" };

/** A pack module whose mount records what it was given. */
function fakeModule(overrides: Record<string, unknown> = {}) {
  const calls = { ctx: null as StudioMountContext | null, el: null as HTMLElement | null, unmounted: 0 };
  const mod = {
    apiVersion: 1,
    mount(el: HTMLElement, ctx: StudioMountContext) {
      calls.el = el;
      calls.ctx = ctx;
      el.textContent = "pack content";
      return {
        unmount() {
          calls.unmounted += 1;
        },
      };
    },
    ...overrides,
  };
  return { mod, calls };
}

async function renderStudio(mod: unknown, onClose = () => {}) {
  const ui = await loadPackStudio("acme_leads", SETTING, async () => mod);
  return render(<ui.Studio onClose={onClose} />);
}

beforeEach(() => {
  appended.length = 0;
  resetPackCsrfToken();
  setShell({ basePath: "/atelier", apiBase: "/atelier", studios: { acme_leads: { default: "x", agents: [] } } });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("registry rows", () => {
  it("turns the shell's packStudios into rows that are never preloaded", () => {
    const rows = packStudioRows({ acme_leads: SETTING, broken: { name: "No script", script: "" } }, async () => ({}));
    expect(Object.keys(rows)).toEqual(["acme_leads"]);
    expect(rows.acme_leads.name).toBe("Leads");
    expect(rows.acme_leads.Icon).toBe(ShieldCheckIcon);
    expect(rows.acme_leads.preload).toBe(false);
    expect(typeof rows.acme_leads.load).toBe("function");
  });

  it("falls back to the chat glyph for an unknown or missing icon", () => {
    expect(kitIcon("no-such-thing")).toBe(ChatBubbleIcon);
    expect(kitIcon(null)).toBe(ChatBubbleIcon);
  });

  it("names what is wrong with a module it cannot mount", () => {
    expect(mountProblem({ apiVersion: 1, mount() {} })).toBeNull();
    expect(mountProblem({ apiVersion: 1 })).toMatch(/mount\(\)/);
    expect(mountProblem(null)).toMatch(/mount\(\)/);
    expect(mountProblem({ apiVersion: 2, mount() {} })).toMatch(/version 2.*version 1/);
    expect(mountProblem({ mount() {} })).toMatch(/\(none\)/);
  });
});

describe("mount and unmount", () => {
  it("mounts into our rail, then unmounts, aborts and empties it on leave", async () => {
    const { mod, calls } = fakeModule();
    const view = await renderStudio(mod);
    expect(screen.getByText("pack content")).toBeTruthy();
    expect(screen.getByTestId("studio-rail").dataset.studio).toBe("acme_leads");
    expect(calls.ctx?.apiVersion).toBe(1);
    expect(calls.ctx?.studio).toEqual({ id: "acme_leads", name: "Leads" });
    const el = calls.el!;
    const signal = calls.ctx!.signal;
    view.unmount();
    expect(calls.unmounted).toBe(1);
    expect(signal.aborted).toBe(true);
    expect(el.childNodes.length).toBe(0);
  });

  it("hands mount a frozen context", async () => {
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    expect(Object.isFrozen(calls.ctx)).toBe(true);
    expect(Object.isFrozen(calls.ctx!.api)).toBe(true);
  });

  it("ctx.close leaves through the host's onClose", async () => {
    const { mod, calls } = fakeModule();
    const onClose = vi.fn();
    await renderStudio(mod, onClose);
    calls.ctx!.close();
    expect(onClose).toHaveBeenCalledOnce();
  });
});

describe("degrade, never crash", () => {
  it("a module without mount is a named placeholder", async () => {
    await renderStudio({ apiVersion: 1 });
    expect(screen.getByRole("alert").textContent).toMatch(/The Leads studio can’t open here: .*mount\(\)/);
  });

  it("a module built for another apiVersion is a named placeholder and is never mounted", async () => {
    const { mod, calls } = fakeModule({ apiVersion: 2 });
    await renderStudio(mod);
    expect(screen.getByRole("alert").textContent).toMatch(/version 2/);
    expect(calls.ctx).toBeNull();
  });

  it("a mount that throws is a named placeholder, logged", async () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {});
    await renderStudio({
      apiVersion: 1,
      mount() {
        throw new Error("boom");
      },
    });
    expect(screen.getByRole("alert").textContent).toMatch(/stopped with an error/);
    expect(error).toHaveBeenCalled();
    error.mockRestore();
  });

  it("a failed import rejects, for the loader's own retry state", async () => {
    await expect(
      loadPackStudio("acme_leads", SETTING, async () => {
        throw new TypeError("Failed to fetch dynamically imported module");
      }),
    ).rejects.toThrow(/Failed to fetch/);
  });
});

describe("ctx.chat", () => {
  it("sends a turn when the studio has an agent", async () => {
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    expect(calls.ctx!.chat.send("Summarise this week's leads")).toBe(true);
    expect(appended).toEqual(["Summarise this week's leads"]);
  });

  it("refuses, sending nothing, when the studio has no agent", async () => {
    setShell({ basePath: "/atelier", studios: {} });
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    expect(calls.ctx!.chat.send("hello")).toBe(false);
    expect(appended).toEqual([]);
  });
});

describe("ctx.api", () => {
  it("resolves relative paths on the console API and adds the CSRF token to writes", async () => {
    setShell({ basePath: "/atelier", apiBase: "/atelier", csrfTokenUrl: "/session/token", studios: {} });
    const fetchMock = vi.fn(async (url: string) =>
      url.endsWith("/session/token") ? new Response("tok123") : new Response("{}"),
    );
    vi.stubGlobal("fetch", fetchMock);
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    const api = calls.ctx!.api;
    expect(api.url("pages")).toBe("/atelier/pages");

    await api.fetch("pages");
    const [getUrl, getInit] = fetchMock.mock.calls[fetchMock.mock.calls.length - 1] as unknown as [string, RequestInit];
    expect(getUrl).toBe(`${window.location.origin}/atelier/pages`);
    expect(getInit.method).toBe("GET");
    expect(new Headers(getInit.headers).has("X-CSRF-Token")).toBe(false);

    await api.fetch("/acme/leads", { json: { name: "Ada" } });
    const [postUrl, postInit] = fetchMock.mock.calls[fetchMock.mock.calls.length - 1] as unknown as [string, RequestInit];
    expect(postUrl).toBe(`${window.location.origin}/acme/leads`);
    expect(postInit.method).toBe("POST");
    expect(postInit.body).toBe('{"name":"Ada"}');
    const headers = new Headers(postInit.headers);
    expect(headers.get("X-CSRF-Token")).toBe("tok123");
    expect(headers.get("Content-Type")).toBe("application/json");
    expect(postInit.credentials).toBe("same-origin");
  });

  it("refuses cross-origin URLs", async () => {
    vi.stubGlobal("fetch", vi.fn());
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    await expect(calls.ctx!.api.fetch("https://evil.example/steal")).rejects.toThrow(/same-origin/);
    expect(fetch).not.toHaveBeenCalled();
  });
});

describe("ctx.nav", () => {
  it("builds console hrefs and opens them in place through popstate", async () => {
    const { mod, calls } = fakeModule();
    await renderStudio(mod);
    expect(calls.ctx!.nav.href("page=12")).toBe("/atelier?page=12");
    const popped = vi.fn();
    window.addEventListener("popstate", popped);
    act(() => calls.ctx!.nav.open("/atelier/content?page=12"));
    window.removeEventListener("popstate", popped);
    expect(window.location.pathname).toBe("/atelier/content");
    expect(window.location.search).toBe("?page=12");
    expect(popped).toHaveBeenCalledOnce();
  });
});
