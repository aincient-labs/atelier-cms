// @vitest-environment jsdom
/**
 * The Example content section ("Clear examples", DECISIONS 0441), rendered
 * against a mocked backend.
 *
 * The contract: nothing renders while no example content is present; one row
 * per studio with its count; "Remove" posts exactly `{studio}` after the
 * operator confirms, and the list re-reads from the reply; "Remove all" exists
 * only with two or more studios and posts `{}`; a declined confirm posts
 * nothing.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ExamplesSection, type ExampleStudio } from "./examples-section";

const studio = (id: string, label: string, count: number): ExampleStudio => ({ id, label, count, ships: true });

type Call = { path: string; init?: RequestInit };
let calls: Call[] = [];
let routes: Record<string, (body: unknown) => { status?: number; json: unknown }>;

function jsonResponse(status: number, json: unknown): Response {
  return { ok: status >= 200 && status < 300, status, json: async () => json } as unknown as Response;
}

beforeEach(() => {
  calls = [];
  routes = {};
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      const path = url.replace(/^.*?\/examples/, "/examples");
      calls.push({ path, init });
      const responder = routes[path];
      if (!responder) return jsonResponse(404, { ok: false, error: `no route for ${path}` });
      const body = init?.body ? JSON.parse(String(init.body)) : undefined;
      const { status = 200, json } = responder(body);
      return jsonResponse(status, json);
    }),
  );
  vi.stubGlobal("confirm", vi.fn(() => true));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("ExamplesSection", () => {
  it("renders nothing when no example content is present", async () => {
    routes["/examples"] = () => ({ json: { studios: [], total: 0 } });
    render(<ExamplesSection />);
    await waitFor(() => expect(calls).toHaveLength(1));
    expect(screen.queryByTestId("examples-section")).toBeNull();
  });

  it("lists one row per studio and offers Remove all only for two or more", async () => {
    routes["/examples"] = () => ({ json: { studios: [studio("content", "Content", 3), studio("media", "Media", 2)], total: 5 } });
    render(<ExamplesSection />);
    await screen.findByText("Content");
    const rows = document.querySelectorAll(".ain-settings-examples__row");
    expect(rows).toHaveLength(2);
    expect(rows[0].textContent).toContain("3 examples");
    expect(rows[1].textContent).toContain("2 examples");
    expect(screen.getByText(/Remove all examples/)).toBeTruthy();
  });

  it('"Remove" confirms, posts {studio} and re-reads the list from the reply', async () => {
    routes["/examples"] = () => ({ json: { studios: [studio("content", "Content", 1)], total: 1 } });
    routes["/examples/clear"] = (body) => {
      expect(body).toEqual({ studio: "content" });
      return { json: { ok: true, deleted: 1, studios: [], total: 0 } };
    };
    render(<ExamplesSection />);
    await screen.findByText("Content");
    expect(screen.queryByText(/Remove all examples/)).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "Remove the Content examples" }));
    await screen.findByText(/Removed 1 example\./);
    expect(window.confirm).toHaveBeenCalledTimes(1);
    expect(calls.filter((c) => c.path === "/examples/clear")).toHaveLength(1);
    expect(document.querySelectorAll(".ain-settings-examples__row")).toHaveLength(0);
  });

  it("a declined confirm posts nothing", async () => {
    vi.stubGlobal("confirm", vi.fn(() => false));
    routes["/examples"] = () => ({ json: { studios: [studio("content", "Content", 1)], total: 1 } });
    render(<ExamplesSection />);
    await screen.findByText("Content");
    fireEvent.click(screen.getByRole("button", { name: "Remove the Content examples" }));
    expect(calls.filter((c) => c.path === "/examples/clear")).toHaveLength(0);
    expect(screen.getByText("Content")).toBeTruthy();
  });

  it('"Remove all" posts {} and shows the server error when it fails', async () => {
    routes["/examples"] = () => ({ json: { studios: [studio("a", "A", 1), studio("b", "B", 1)], total: 2 } });
    routes["/examples/clear"] = (body) => {
      expect(body).toEqual({});
      return { status: 500, json: { ok: false, error: "Clearing demo content failed" } };
    };
    render(<ExamplesSection />);
    await screen.findByText("A");
    fireEvent.click(screen.getByText(/Remove all examples/));
    await screen.findByRole("alert");
    expect(screen.getByRole("alert").textContent).toContain("Clearing demo content failed");
    expect(document.querySelectorAll(".ain-settings-examples__row")).toHaveLength(2);
  });
});
