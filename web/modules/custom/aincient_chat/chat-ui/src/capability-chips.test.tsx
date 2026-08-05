// @vitest-environment jsdom
/**
 * The capability row, rendered — the scoping rules, which are the only thing it
 * decides.
 *
 * `capabilities.test.ts` covers reading the injected payload. What is worth a
 * render environment is the three-input answer this component composes: the
 * STUDIO decides which verbs appear at all, the room's AGENT decides whether an
 * appearing chip is live, and the INSTALL decides the rest. The defect that put
 * this here: every room showed all three chips, so General advertised
 * "Draw — needs an image provider" for a picture it has no tool to make, sending
 * the user to fix something that would have changed nothing.
 *
 * The two dim states must stay distinguishable, in copy and in class: an unused
 * verb is a closed door (no link), a missing provider is a "not yet" (a link, for
 * an admin).
 */

import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { CapabilityChips } from "./capability-chips";

const chip = (id: string, label: string, available: boolean) => ({
  id,
  label,
  means: `${label} means this`,
  available,
  hint: available ? "" : `needs a ${id} provider`,
  unusedHint: `this chat doesn’t ${id}`,
  setupUrl: available ? "" : "/atelier?onboarding=1",
});

/** A fully connected install: all three verbs available. */
function inject(chips: unknown = [
  chip("write", "Write", true),
  chip("describe", "Describe", true),
  chip("draw", "Draw", true),
]): void {
  (window as unknown as { aincientChat: unknown }).aincientChat = {
    capabilities: chips,
  };
}

afterEach(() => {
  cleanup();
  delete (window as unknown as { aincientChat?: unknown }).aincientChat;
});

const labels = () =>
  Array.from(document.querySelectorAll(".ain-cap__label")).map((n) => n.textContent);

const chipFor = (label: string) =>
  screen.getByText(label).closest(".ain-cap") as HTMLElement;

describe("CapabilityChips", () => {
  it("shows only the verbs the STUDIO raises", () => {
    // THE REGRESSION: General has no image tool anywhere in it, so it never
    // mentions pictures — not even to say they need setting up.
    inject();
    render(<CapabilityChips studioVerbs={["write"]} agentVerbs={["write"]} />);
    expect(labels()).toEqual(["Write"]);
  });

  it("shows the whole row when nothing scoped it (an un-rebuilt shell)", () => {
    inject();
    render(<CapabilityChips />);
    expect(labels()).toEqual(["Write", "Describe", "Draw"]);
  });

  it("renders nothing when the studio raises no verbs at all", () => {
    inject();
    const { container } = render(<CapabilityChips studioVerbs={[]} />);
    expect(container.querySelector(".ain-caps")).toBeNull();
  });

  it("strikes out a verb this room's AGENT has no tool for — and links nowhere", () => {
    // The install can draw; the agent you are talking to cannot. Nothing to fix,
    // so the chip is a statement, not an invitation.
    inject();
    render(<CapabilityChips studioVerbs={["write", "draw"]} agentVerbs={["write"]} />);
    const draw = chipFor("Draw");
    expect(draw.className).toContain("ain-cap--unused");
    expect(draw.tagName).toBe("SPAN");
    expect(draw.textContent).toContain("this chat doesn’t draw");
    expect(draw.textContent).not.toContain("needs a draw provider");
  });

  it("dims a verb the INSTALL cannot do — with the remedy, as a link", () => {
    inject([
      chip("write", "Write", true),
      chip("draw", "Draw", false),
    ]);
    render(<CapabilityChips studioVerbs={["write", "draw"]} agentVerbs={["write", "draw"]} />);
    const draw = chipFor("Draw");
    expect(draw.className).toContain("ain-cap--setup");
    expect(draw.tagName).toBe("A");
    expect(draw.getAttribute("href")).toBe("/atelier?onboarding=1");
    expect(draw.textContent).toContain("needs a draw provider");
  });

  it("says the room's reason first when BOTH are true", () => {
    // Unconnected provider AND no tool: telling someone to connect a provider
    // for a tool this room does not have is a fix that changes nothing.
    inject([chip("write", "Write", true), chip("draw", "Draw", false)]);
    render(<CapabilityChips studioVerbs={["write", "draw"]} agentVerbs={["write"]} />);
    const draw = chipFor("Draw");
    expect(draw.className).toContain("ain-cap--unused");
    expect(draw.tagName).toBe("SPAN");
    expect(draw.textContent).toContain("this chat doesn’t draw");
  });

  it("leaves a live chip lit and silent", () => {
    inject();
    render(<CapabilityChips studioVerbs={["write"]} agentVerbs={["write"]} />);
    const write = chipFor("Write");
    expect(write.className).toContain("ain-cap--on");
    expect(write.querySelector(".ain-cap__hint")).toBeNull();
  });
});
