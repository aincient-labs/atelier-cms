import { describe, expect, it } from "vitest";
import { starterAsks, type WorkflowRef } from "./flow";

/**
 * Starter chips come from configuration and nowhere else
 * (`aincient_chat.settings:workflow_metadata.<workflow>.sample_asks`).
 *
 * The regression these lock down: a hardcoded fallback list in the console
 * competed with the shipped config, so which asks a room offered depended on
 * whether workflow metadata happened to resolve — and a room could advertise
 * asks it cannot fulfil.
 */
describe("starterAsks", () => {
  const configured: WorkflowRef = {
    id: "aincient_operator_agent_loop",
    label: "General",
    sampleAsks: ["Show me around my new site", "Where do I change the colours and fonts?"],
  };

  it("renders the room's configured asks, in order", () => {
    expect(starterAsks(configured)).toEqual([
      "Show me around my new site",
      "Where do I change the colours and fonts?",
    ]);
  });

  it("renders nothing for a room with no configured asks — never another room's", () => {
    const bare: WorkflowRef = { id: "brand_studio_rice", label: "Brand" };
    expect(starterAsks(bare)).toEqual([]);
    expect(starterAsks({ ...bare, sampleAsks: [] })).toEqual([]);
  });

  it("renders nothing for a freeform-only room, even if asks are configured", () => {
    expect(starterAsks({ ...configured, freeformOnly: true })).toEqual([]);
  });

  it("renders nothing when the flow has not resolved yet", () => {
    expect(starterAsks(undefined)).toEqual([]);
  });
});
