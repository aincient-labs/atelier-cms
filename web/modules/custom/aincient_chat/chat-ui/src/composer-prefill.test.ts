import { afterEach, describe, expect, it } from "vitest";
import {
  forgetComposerPrefill,
  stageComposerPrefill,
  takeComposerPrefill,
} from "./composer-prefill";

/**
 * The one-hop ask carry. The bug it fixes is an empty composer after a handoff;
 * the WORSE bug it must not introduce is the user's old sentence reappearing
 * later, so every test here is really about the forgetting.
 */
describe("composer prefill", () => {
  afterEach(() => forgetComposerPrefill());

  it("hands the sentence to the room it was staged for", () => {
    stageComposerPrefill("content", "Build a landing page for a coffee shop");
    expect(takeComposerPrefill("content")).toBe("Build a landing page for a coffee shop");
  });

  it("is consumed once — a second read is empty", () => {
    stageComposerPrefill("content", "Build a landing page");
    expect(takeComposerPrefill("content")).toBe("Build a landing page");
    expect(takeComposerPrefill("content")).toBeNull();
  });

  it("forgets on a hop to a DIFFERENT room, so nothing surfaces later", () => {
    stageComposerPrefill("content", "Build a landing page");
    // The user changed their mind and went to Library instead.
    expect(takeComposerPrefill("media")).toBeNull();
    // …and the sentence is gone, not lying in wait for Pages.
    expect(takeComposerPrefill("content")).toBeNull();
  });

  it("stages nothing for empty or whitespace text", () => {
    stageComposerPrefill("content", "   \n ");
    expect(takeComposerPrefill("content")).toBeNull();
  });

  it("trims, and a fresh stage replaces the previous one", () => {
    stageComposerPrefill("content", "  first  ");
    stageComposerPrefill("design_system", "second");
    expect(takeComposerPrefill("content")).toBeNull();
    stageComposerPrefill("design_system", "second");
    expect(takeComposerPrefill("design_system")).toBe("second");
  });

  it("caps a runaway paste rather than carrying it whole", () => {
    stageComposerPrefill("content", "x".repeat(5000));
    expect(takeComposerPrefill("content")?.length).toBe(2000);
  });

  it("forgetComposerPrefill drops an unconsumed stage", () => {
    stageComposerPrefill("content", "Build a landing page");
    forgetComposerPrefill();
    expect(takeComposerPrefill("content")).toBeNull();
  });

  it("needs no web storage at all — a hostile storage layer changes nothing", () => {
    // The carry is deliberately in-memory: it cannot survive a reload (so it
    // needs no "was this consumed?" flag), and private mode / disabled storage
    // cannot make it throw. Prove it by making every storage access explode.
    const throwing = {
      getItem: () => {
        throw new Error("storage disabled");
      },
      setItem: () => {
        throw new Error("storage disabled");
      },
      removeItem: () => {
        throw new Error("storage disabled");
      },
    };
    const priors = new Map<string, PropertyDescriptor | undefined>();
    for (const name of ["sessionStorage", "localStorage"]) {
      priors.set(name, Object.getOwnPropertyDescriptor(globalThis, name));
      Object.defineProperty(globalThis, name, {
        value: throwing,
        configurable: true,
        writable: true,
      });
    }
    try {
      stageComposerPrefill("content", "Build a landing page");
      expect(takeComposerPrefill("content")).toBe("Build a landing page");
      expect(takeComposerPrefill("content")).toBeNull();
    } finally {
      for (const [name, descriptor] of priors) {
        if (descriptor) Object.defineProperty(globalThis, name, descriptor);
        else delete (globalThis as unknown as Record<string, unknown>)[name];
      }
    }
  });
});
