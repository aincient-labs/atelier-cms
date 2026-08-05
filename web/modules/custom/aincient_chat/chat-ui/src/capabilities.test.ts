import { afterEach, describe, expect, it } from "vitest";
import { capabilityAvailable, capabilityChips } from "./capabilities";

/**
 * The bundle's job with capabilities is to READ them, never to derive them: the
 * three verbs are computed server-side because the same booleans also generate
 * the agent's system-prompt block. These tests pin the two things that can still
 * go wrong on this side — what happens with no injected payload, and that
 * `capabilityAvailable` never invents an affordance.
 */

function inject(capabilities: unknown): void {
  (globalThis as { window?: unknown }).window = { aincientChat: { capabilities } };
}

afterEach(() => {
  delete (globalThis as { window?: unknown }).window;
});

const chip = (id: string, available: boolean) => ({
  id,
  label: id,
  means: "",
  available,
  hint: available ? "" : "needs setup",
  setupUrl: available ? "" : "/admin/config/aincient/models",
});

describe("capabilityChips", () => {
  it("returns the server's row, in the server's order", () => {
    inject([chip("write", true), chip("describe", false), chip("draw", false)]);
    expect(capabilityChips().map((c) => c.id)).toEqual(["write", "describe", "draw"]);
  });

  it("renders NOTHING when the shell injected no capabilities", () => {
    // An un-rebuilt shell or a dev harness. Three dimmed chips here would be an
    // alarm raised from ignorance, not information.
    inject(undefined);
    expect(capabilityChips()).toEqual([]);
  });

  it("ignores a payload that is not a list", () => {
    inject({ write: true });
    expect(capabilityChips()).toEqual([]);
  });
});

describe("capabilityAvailable", () => {
  it("answers per verb", () => {
    inject([chip("write", true), chip("draw", false)]);
    expect(capabilityAvailable("write")).toBe(true);
    expect(capabilityAvailable("draw")).toBe(false);
  });

  it("says no to a verb it was told nothing about", () => {
    // Unknown must read as unavailable: an invitation we cannot vouch for is the
    // failure shape the chips exist to remove.
    inject([chip("write", true)]);
    expect(capabilityAvailable("describe")).toBe(false);
    inject(undefined);
    expect(capabilityAvailable("write")).toBe(false);
  });
});
