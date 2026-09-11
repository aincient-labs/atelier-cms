// @vitest-environment jsdom
/**
 * The Snapshots section (Freeze & Live, DECISIONS 0416), rendered against a
 * mocked backend.
 *
 * What is worth a render environment here is the CONTRACT with the operator:
 * the Live row is always first and reads its state from `serving`; "Serve this"
 * posts exactly `{id}` to /snapshots/use and the list re-reads from the reply;
 * and a freeze that came back `ok:false` shows the export's problems and offers
 * the "Serve it anyway" override only while the snapshot is NOT served.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { SnapshotsSection, type Snapshot } from "./snapshots-section";

const snap = (id: string, over: Partial<Snapshot> = {}): Snapshot => ({
  id,
  label: null,
  frozen_at: "2026-09-10T09:02:00Z",
  frozen_by: "shibin",
  atelier_version: "1.4.0",
  pages: 12,
  assets: 40,
  keep: false,
  ...over,
});

type Call = { path: string; init?: RequestInit };
let calls: Call[] = [];
/** Per-endpoint responders, keyed by the API path suffix (the base is injected, never hardcoded). */
let routes: Record<string, (body: unknown) => { status?: number; json: unknown }>;

function jsonResponse(status: number, json: unknown): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => json,
  } as unknown as Response;
}

beforeEach(() => {
  calls = [];
  routes = {};
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      const path = url.replace(/^.*?\/snapshots/, "/snapshots");
      calls.push({ path, init });
      const responder = routes[path];
      if (!responder) return jsonResponse(404, { ok: false, error: `no route for ${path}` });
      const body = init?.body ? JSON.parse(String(init.body)) : undefined;
      const { status = 200, json } = responder(body);
      return jsonResponse(status, json);
    }),
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const rowFor = (name: string) =>
  screen.getByText(name, { selector: ".ain-snapshots__name" }).closest(".ain-snapshots__row") as HTMLElement;

describe("SnapshotsSection", () => {
  it("renders the Live row first, then one row per snapshot, with serving + kept chips", async () => {
    routes["/snapshots"] = () => ({
      json: { serving: "s2", snapshots: [snap("s2", { label: "Autumn", keep: true }), snap("s1")] },
    });
    render(<SnapshotsSection />);

    await screen.findByText("Autumn", { selector: ".ain-snapshots__name" });
    const rows = document.querySelectorAll(".ain-snapshots__row");
    expect(rows).toHaveLength(3);
    expect(rows[0].textContent).toContain("Live");
    expect(rows[0].textContent).toContain("Drupal renders every visit");
    // Live is NOT serving → it offers "Serve live" and has no chip.
    expect(rows[0].querySelector(".ain-snapshots__chip--serving")).toBeNull();
    expect(rows[0].textContent).toContain("Serve live");

    const autumn = rowFor("Autumn");
    expect(autumn.querySelector(".ain-snapshots__chip--serving")?.textContent).toBe("serving");
    expect(autumn.querySelector(".ain-snapshots__chip--kept")?.textContent).toBe("kept");
    expect(autumn.textContent).toContain("by shibin");
    expect(autumn.textContent).toContain("Atelier 1.4.0");
    expect(autumn.textContent).toContain("12 pages");
    expect(autumn.textContent).toContain("40 assets");
    // The serving row hides Serve/Delete; it can only be viewed or kept.
    expect(autumn.textContent).not.toContain("Serve this");
    expect(autumn.querySelector('[aria-label="Delete Autumn"]')).toBeNull();
    expect(autumn.textContent).toContain("Unkeep");

    // An unlabelled snapshot is plainly "Snapshot", offers Serve + Delete, and links its preview.
    const plain = rowFor("Snapshot");
    expect(plain.textContent).toContain("Serve this");
    expect(plain.querySelector('[aria-label="Delete Snapshot"]')).not.toBeNull();
    const view = plain.querySelector("a") as HTMLAnchorElement;
    expect(view.getAttribute("href")).toMatch(/\/snapshots\/s1\/$/);
    expect(view.getAttribute("target")).toBe("_blank");
    expect(view.getAttribute("rel")).toBe("noopener");

    // Status line names the served snapshot by label.
    expect(screen.getByText(/Visitors see snapshot/).textContent).toContain("Autumn");
  });

  it('"Serve this" posts {id} to /snapshots/use and re-reads serving from the reply', async () => {
    routes["/snapshots"] = () => ({ json: { serving: "live", snapshots: [snap("s1", { label: "One" })] } });
    routes["/snapshots/use"] = (body) => ({
      json: { ok: true, serving: (body as { id: string }).id, snapshots: [snap("s1", { label: "One" })] },
    });
    render(<SnapshotsSection />);
    await screen.findByText("One", { selector: ".ain-snapshots__name" });
    expect(screen.getByText("Visitors see the live site.")).toBeTruthy();

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Serve this/ }));
    });

    const use = calls.find((c) => c.path === "/snapshots/use");
    expect(use).toBeTruthy();
    expect(use!.init?.method).toBe("POST");
    expect(use!.init?.credentials).toBe("same-origin");
    expect(JSON.parse(String(use!.init?.body))).toEqual({ id: "s1" });

    await waitFor(() => expect(rowFor("One").querySelector(".ain-snapshots__chip--serving")).not.toBeNull());
    expect(screen.getByText(/Visitors see snapshot/).textContent).toContain("One");
    // Live is no longer serving → it now offers "Serve live".
    expect(screen.getByRole("button", { name: "Serve live" })).toBeTruthy();
  });

  it("a freeze with ok:false shows the problems and offers to serve it anyway", async () => {
    const broken = snap("s9", { label: "Broken" });
    routes["/snapshots"] = () => ({ json: { serving: "live", snapshots: [] } });
    routes["/snapshots/freeze"] = () => ({
      json: {
        ok: false,
        snapshot: broken,
        served: false,
        problems: ["/about → 404 /team", "/blog/x → missing image hero.png"],
        serving: "live",
        snapshots: [broken],
      },
    });
    routes["/snapshots/use"] = (body) => ({
      json: { ok: true, serving: (body as { id: string }).id, snapshots: [broken] },
    });
    render(<SnapshotsSection />);
    await screen.findByText("Live", { selector: ".ain-snapshots__name" });

    fireEvent.change(screen.getByLabelText(/Label for the new snapshot/), { target: { value: "Broken" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: /Freeze now/ }));
    });

    const freeze = calls.find((c) => c.path === "/snapshots/freeze");
    expect(JSON.parse(String(freeze!.init?.body))).toEqual({ label: "Broken" });

    // The snapshot exists (listed) but the export's problems are on show.
    await screen.findByText("Broken", { selector: ".ain-snapshots__name" });
    const problems = document.querySelector(".ain-snapshots__problems") as HTMLElement;
    expect(problems.textContent).toContain("2 problems");
    expect(problems.textContent).toContain("not being served");
    expect(problems.querySelectorAll("li")).toHaveLength(2);
    expect(problems.textContent).toContain("/about → 404 /team");
    expect(screen.getByText("Visitors see the live site.")).toBeTruthy();

    // The override posts the NEW id and, once served, the button goes away.
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Serve it anyway" }));
    });
    const use = calls.find((c) => c.path === "/snapshots/use");
    expect(JSON.parse(String(use!.init?.body))).toEqual({ id: "s9" });
    await waitFor(() => expect(screen.queryByRole("button", { name: "Serve it anyway" })).toBeNull());
    expect(screen.getByText(/Visitors see snapshot/).textContent).toContain("Broken");
  });

  it("surfaces a backend error inline, not as an alert()", async () => {
    routes["/snapshots"] = () => ({ json: { serving: "live", snapshots: [snap("s1", { label: "One" })] } });
    routes["/snapshots/delete"] = () => ({ status: 400, json: { ok: false, error: "Snapshot is being served." } });
    vi.spyOn(window, "confirm").mockReturnValue(true);
    render(<SnapshotsSection />);
    await screen.findByText("One", { selector: ".ain-snapshots__name" });

    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Delete One" }));
    });
    expect(window.confirm).toHaveBeenCalled();
    expect((await screen.findByRole("alert")).textContent).toContain("Snapshot is being served.");
  });
});
