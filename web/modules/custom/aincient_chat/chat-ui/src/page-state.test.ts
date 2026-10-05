import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The review-loop half of page-state (DECISIONS 0453): the shared baseline and
 * the per-field origin map. The store's network and lock edges are stubbed —
 * these cases are about what the store remembers, not the transport.
 */
vi.mock("./page-lock", () => ({
  acquireLock: vi.fn(async () => undefined),
  releaseLock: vi.fn(async () => undefined),
  lockToken: () => null,
  markLockLost: vi.fn(),
}));
vi.mock("./flow", () => ({ ensureStudio: vi.fn(), activeStudioKey: () => "checks" }));
vi.mock("./console-config", () => ({ apiUrl: (path: string) => `/atelier${path}` }));

const loaded = {
  type: "landing",
  title: "Home",
  meta: { description: "Old" },
  sections: [{ id: "cta22", component: "cta", props: { cta_url: "/about" } }],
};

type Store = typeof import("./page-state");
let store: Store;
let bodies: Record<string, unknown>[];

beforeEach(async () => {
  vi.resetModules();
  bodies = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      if (init?.body) bodies.push(JSON.parse(String(init.body)));
      const payload = String(url).includes("/schema")
        ? { node_id: 5, schema: structuredClone(loaded), base_vid: 10 }
        : { node_id: 5, base_vid: 11 };
      return new Response(JSON.stringify(payload), { status: 200 });
    }),
  );
  store = await import("./page-state");
  await store.loadPageIntoStudio("5", null, "checks");
});

afterEach(() => vi.unstubAllGlobals());

const draft = () => store.getPageDraft()!;

describe("baseline", () => {
  it("is the loaded schema", () => {
    expect(store.getPageBaseline()).toEqual(loaded);
  });

  it("moves to the saved schema after a save, and notifies", async () => {
    const seen = vi.fn();
    store.subscribePageBaseline(seen);
    const next = { ...draft(), title: "Home, saved" };
    store.setPageDraft(next);
    await store.saveDraft(next, "page", "5", null);
    expect(store.getPageBaseline()).toEqual(next);
    expect(seen).toHaveBeenCalled();
  });
});

describe("origins", () => {
  it("records who changed each field", () => {
    store.setPageDraft({ ...draft(), meta: { description: "By the agent" } }, { source: "agent" });
    store.setPageDraft({ ...draft(), title: "By you" });
    expect(Object.fromEntries(store.getPageOrigins())).toEqual({ "meta.description": "agent", title: "user" });
  });

  it("drops a field's origin when it returns to the baseline value", () => {
    store.setPageDraft({ ...draft(), meta: { description: "By the agent" } }, { source: "agent" });
    store.setPageDraft({ ...draft(), meta: { description: "Old" } });
    expect(store.getPageOrigins().size).toBe(0);
  });

  it("the last writer of a field wins", () => {
    store.setPageDraft({ ...draft(), title: "Agent" }, { source: "agent" });
    store.setPageDraft({ ...draft(), title: "Mine" });
    expect(store.getPageOrigins().get("title")).toBe("user");
  });

  it("rides the save as `origin` and clears after it", async () => {
    store.setPageDraft({ ...draft(), meta: { description: "By the agent" } }, { source: "agent" });
    store.setPageDraft({ ...draft(), title: "By you" });
    await store.saveDraft(draft(), "page", "5", null);
    expect(bodies[bodies.length - 1]?.origin).toEqual({ agent: ["meta.description"], user: ["title"] });
    expect(store.getPageOrigins().size).toBe(0);
  });

  it("keeps the origin of a field changed again while the save was in flight", async () => {
    store.setPageDraft({ ...draft(), title: "First" }, { source: "agent" });
    const sent = draft();
    const save = store.saveDraft(sent, "page", "5", null);
    store.setPageDraft({ ...draft(), title: "Second" }, { source: "agent" });
    await save;
    expect(store.getPageOrigins().get("title")).toBe("agent");
  });

  it("clears on a reload", async () => {
    store.setPageDraft({ ...draft(), title: "Agent" }, { source: "agent" });
    await store.loadPageIntoStudio("5", null, "checks");
    expect(store.getPageOrigins().size).toBe(0);
  });

  it("sends no origin when nothing changed", async () => {
    await store.saveDraft(draft(), "page", "5", null);
    expect(bodies[bodies.length - 1]).not.toHaveProperty("origin");
  });
});
