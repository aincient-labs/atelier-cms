// @vitest-environment jsdom
/**
 * The Managers section (Manager Instances M4), rendered against a mocked
 * backend.
 *
 * The contract: nothing renders when the engine is absent; the list shows
 * every pairing with names as TEXT (never markup), the primary badged and
 * without Revoke; no code and no pairing control anywhere; Revoke posts
 * exactly `{id}` with the CSRF header after the operator confirms; a declined
 * confirm posts nothing; a malformed list shows the server's honest error.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ManagersSection, type Pairing } from "./managers-section";

const pairing = (id: string, name: string, primary = false): Pairing => ({
  id,
  name,
  primary,
  created: 1790000000,
  last_used: 1790003600,
  via: primary ? "install code" : "approved by 0000000000aa",
});

type Call = { path: string; init?: RequestInit };
let calls: Call[] = [];
let routes: Record<string, (body: unknown) => { status?: number; json?: unknown; text?: string }>;

function response(status: number, json: unknown, text = ""): Response {
  return { ok: status >= 200 && status < 300, status, json: async () => json, text: async () => text } as unknown as Response;
}

beforeEach(() => {
  calls = [];
  routes = {};
  (window as unknown as { aincientChat: unknown }).aincientChat = { csrfTokenUrl: "/session/token" };
  routes["/session/token"] = () => ({ text: "tok-123" });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      const path = url.startsWith("/session/token") ? "/session/token" : url.replace(/^.*?\/managers/, "/managers");
      calls.push({ path, init });
      const responder = routes[path];
      if (!responder) return response(404, { ok: false, error: `no route for ${path}` });
      const body = init?.body ? JSON.parse(String(init.body)) : undefined;
      const { status = 200, json, text } = responder(body);
      return response(status, json, text);
    }),
  );
  vi.stubGlobal("confirm", vi.fn(() => true));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  delete (window as unknown as { aincientChat?: unknown }).aincientChat;
});

describe("ManagersSection", () => {
  it("renders nothing when the engine is not enabled", async () => {
    routes["/managers"] = () => ({ json: { state: "absent", pairings: [] } });
    render(<ManagersSection />);
    await waitFor(() => expect(calls).toHaveLength(1));
    expect(screen.queryByTestId("managers-section")).toBeNull();
  });

  it("lists pairings as text, badges the primary, offers Revoke only on the others, and shows no code", async () => {
    routes["/managers"] = () => ({
      json: {
        state: "ok",
        pairings: [pairing("0000000000aa", "Owner’s laptop", true), pairing("0000000000bb", '<img src=x onerror="alert(1)">')],
      },
    });
    const { container } = render(<ManagersSection />);
    await screen.findByTestId("managers-section");
    expect(screen.getAllByTestId("managers-row")).toHaveLength(2);
    expect(screen.getByText("Primary")).toBeTruthy();
    // The hostile name is shown literally, never parsed into an element.
    expect(screen.getByText('<img src=x onerror="alert(1)">')).toBeTruthy();
    expect(container.querySelector("img")).toBeNull();
    const revokes = screen.getAllByRole("button", { name: /^Revoke/ });
    expect(revokes).toHaveLength(1);
    expect(revokes[0].getAttribute("aria-label")).toBe('Revoke <img src=x onerror="alert(1)">');
    // Nothing that pairs: no inputs, no approve, no code-shaped string.
    expect(container.querySelector("input")).toBeNull();
    expect(screen.queryByRole("button", { name: /approve|pair/i })).toBeNull();
    expect(container.textContent).not.toMatch(/[0-9A-Z]{4}-[0-9A-Z]{4}/);
    expect(container.textContent).toContain("Ask to join");
    // The approver is named, not shown as an id.
    expect(container.textContent).toContain("approved on Owner’s laptop");
    expect(container.textContent).toContain("atelier engine admin install-code");
  });

  it("posts exactly {id} with the CSRF header after confirming", async () => {
    const list = [pairing("0000000000aa", "Owner", true), pairing("0000000000bb", "Old phone")];
    routes["/managers"] = () => ({ json: { state: "ok", pairings: list } });
    routes["/managers/revoke"] = () => ({ json: { ok: true, revoked: { id: "0000000000bb", name: "Old phone" }, state: "ok", pairings: list } });
    render(<ManagersSection />);
    fireEvent.click(await screen.findByRole("button", { name: "Revoke Old phone" }));
    await screen.findByText(/Revoke sent/);
    const post = calls.find((c) => c.path === "/managers/revoke");
    expect(post?.init?.method).toBe("POST");
    expect(JSON.parse(String(post?.init?.body))).toEqual({ id: "0000000000bb" });
    expect((post?.init?.headers as Record<string, string>)["X-CSRF-Token"]).toBe("tok-123");
  });

  it("posts nothing when the confirm is declined", async () => {
    vi.stubGlobal("confirm", vi.fn(() => false));
    routes["/managers"] = () => ({ json: { state: "ok", pairings: [pairing("0000000000bb", "Old phone")] } });
    render(<ManagersSection />);
    fireEvent.click(await screen.findByRole("button", { name: "Revoke Old phone" }));
    expect(calls.some((c) => c.path === "/managers/revoke")).toBe(false);
  });

  it("shows the server's refusal", async () => {
    routes["/managers"] = () => ({ json: { state: "ok", pairings: [pairing("0000000000bb", "Old phone")] } });
    routes["/managers/revoke"] = () => ({ status: 409, json: { ok: false, error: "Another revoke is still waiting for the engine.", state: "ok", pairings: [pairing("0000000000bb", "Old phone")] } });
    render(<ManagersSection />);
    fireEvent.click(await screen.findByRole("button", { name: "Revoke Old phone" }));
    await screen.findByText("Another revoke is still waiting for the engine.");
  });

  it("shows an honest error for an unreadable list", async () => {
    routes["/managers"] = () => ({ json: { state: "error", pairings: [], error: "The engine’s pairings list is not valid JSON." } });
    render(<ManagersSection />);
    await screen.findByText("The engine’s pairings list is not valid JSON.");
    expect(screen.queryByTestId("managers-row")).toBeNull();
  });
});
