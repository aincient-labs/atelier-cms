import { describe, expect, it, vi } from "vitest";
import type { ComponentType } from "react";
import { createStudioLoader } from "./studio-loader";
import type { StudioDef, StudioUiModule } from "./studio-module";

/**
 * The studio loader (Phase C of plans/studio-modules.md): one fetch per
 * studio, outcome remembered, failures held not thrown, subscribers told.
 */

const Icon = (() => null) as unknown as StudioDef["Icon"];
const Rail = (() => null) as unknown as StudioUiModule["Studio"];
const Card = (() => null) as ComponentType;

/** A `load` the test can resolve or reject by hand, counting its calls. */
function deferred() {
  let resolve!: (m: StudioUiModule) => void;
  let reject!: (e: unknown) => void;
  const load = vi.fn(
    () =>
      new Promise<StudioUiModule>((res, rej) => {
        resolve = res;
        reject = rej;
      }),
  );
  return { load, resolve: (m: StudioUiModule) => resolve(m), reject: (e: unknown) => reject(e) };
}

function rows(extra: Record<string, StudioDef> = {}): Record<string, StudioDef> {
  return { general: { name: "General", Icon }, ...extra };
}

describe("studio loader", () => {
  it("resolves a chat-only or unknown studio to nothing, without a fetch", async () => {
    const loader = createStudioLoader(() => rows());
    expect(await loader.load("general")).toBeUndefined();
    expect(await loader.load("nope")).toBeUndefined();
    expect(await loader.load(undefined)).toBeUndefined();
    expect(loader.status("general")).toBe("none");
  });

  it("fetches a studio once and remembers the module", async () => {
    const d = deferred();
    const loader = createStudioLoader(() => rows({ forms: { name: "Forms", Icon, load: d.load } }));
    const first = loader.load("forms");
    const second = loader.load("forms"); // while in flight
    expect(loader.status("forms")).toBe("loading");
    expect(loader.loaded("forms")).toBeUndefined();
    d.resolve({ Studio: Rail, ToolUIs: [Card] });
    const module = await first;
    expect(module?.Studio).toBe(Rail);
    expect((await second)?.Studio).toBe(Rail);
    expect((await loader.load("forms"))?.Studio).toBe(Rail); // after
    expect(d.load).toHaveBeenCalledTimes(1);
    expect(loader.status("forms")).toBe("ready");
  });

  it("holds a failure instead of throwing, and retries on demand", async () => {
    const d = deferred();
    const loader = createStudioLoader(() => rows({ forms: { name: "Forms", Icon, load: d.load } }));
    const p = loader.load("forms");
    d.reject(new Error("offline"));
    expect(await p).toBeUndefined();
    expect(loader.status("forms")).toBe("failed");
    expect((loader.error("forms") as Error).message).toBe("offline");
    // A plain load does NOT loop on a failure.
    expect(await loader.load("forms")).toBeUndefined();
    expect(d.load).toHaveBeenCalledTimes(1);
    // A retry does.
    const r = loader.retry("forms");
    d.resolve({ Studio: Rail });
    expect((await r)?.Studio).toBe(Rail);
    expect(d.load).toHaveBeenCalledTimes(2);
  });

  it("tells subscribers on every change and bumps the snapshot version", async () => {
    const d = deferred();
    const loader = createStudioLoader(() => rows({ forms: { name: "Forms", Icon, load: d.load } }));
    const seen: number[] = [];
    const unsubscribe = loader.subscribe(() => seen.push(loader.getVersion()));
    const p = loader.load("forms"); // → loading
    d.resolve({ Studio: Rail });
    await p; // → ready
    expect(seen).toEqual([1, 2]);
    unsubscribe();
    loader.reset();
    expect(seen).toEqual([1, 2]);
  });

  it("flattens the loaded studios' ToolUIs with stable keys, and only the loaded ones", async () => {
    const a = deferred();
    const b = deferred();
    const loader = createStudioLoader(() =>
      rows({
        media: { name: "Library", Icon, load: a.load },
        design_system: { name: "Identity", Icon, load: b.load },
      }),
    );
    expect(loader.toolUIs()).toEqual([]);
    const p = loader.load("media");
    a.resolve({ Studio: Rail, ToolUIs: [Card, Card] });
    await p;
    expect(loader.toolUIs().map((t) => t.key)).toEqual(["media:0", "media:1"]);
    expect(b.load).not.toHaveBeenCalled();
  });

  it("preloads every not-yet-requested studio, one after another, at idle", async () => {
    vi.useFakeTimers();
    try {
      const a = deferred();
      const b = deferred();
      const loader = createStudioLoader(() =>
        rows({
          media: { name: "Library", Icon, load: a.load },
          checks: { name: "Checks", Icon, load: b.load },
        }),
      );
      expect(loader.pending()).toEqual(["media", "checks"]);
      loader.preloadAll();
      await vi.runAllTimersAsync(); // the idle slot (setTimeout fallback in node)
      expect(a.load).toHaveBeenCalledTimes(1);
      expect(b.load).not.toHaveBeenCalled(); // sequential: waits for media
      a.resolve({ Studio: Rail });
      await vi.runAllTimersAsync();
      expect(b.load).toHaveBeenCalledTimes(1);
      expect(loader.pending()).toEqual([]);
      // Already-requested studios are left alone.
      loader.preloadAll();
      await vi.runAllTimersAsync();
      expect(a.load).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it("never preloads a studio marked preload: false, but still loads it on demand", async () => {
    vi.useFakeTimers();
    try {
      const pack = deferred();
      const loader = createStudioLoader(() =>
        rows({ acme_leads: { name: "Leads", Icon, load: pack.load, preload: false } }),
      );
      expect(loader.pending()).toEqual([]);
      loader.preloadAll();
      await vi.runAllTimersAsync();
      expect(pack.load).not.toHaveBeenCalled();
      const loading = loader.load("acme_leads");
      pack.resolve({ Studio: Rail });
      expect(await loading).toEqual({ Studio: Rail });
    } finally {
      vi.useRealTimers();
    }
  });
});
