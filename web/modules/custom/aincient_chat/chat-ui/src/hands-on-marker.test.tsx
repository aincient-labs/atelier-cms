// @vitest-environment jsdom
/**
 * The hands-on marker, rendered — the one thing it decides: WHETHER to mark.
 *
 * It is data-driven off the injected chat-reachable set, so the badge cannot
 * drift from what the agent does. The two states that matter: a human-only field
 * (key absent → marked) and a field the backend reports reachable (key present →
 * nothing rendered, the default carried by absence). Plus the accessibility
 * contract: a real aria-label (not a bare title) and a focusable badge.
 */

import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { HandsOnMarker } from "./hands-on-marker";

type Win = { aincientChat?: { chatReach?: string[] } };

beforeEach(() => {
  (window as unknown as Win).aincientChat = {
    chatReach: ["identity.name", "chrome.header.logo_size"],
  };
});

afterEach(() => {
  cleanup();
  delete (window as unknown as Win).aincientChat;
});

describe("HandsOnMarker", () => {
  it("marks a field whose key is NOT in the reachable set", () => {
    render(<HandsOnMarker reachKey="identity.logo" />);
    const badge = screen.getByLabelText("Set directly — not editable through chat");
    expect(badge).toBeTruthy();
    // Focusable for keyboard users, and role=img so it is announced.
    expect(badge.getAttribute("tabindex")).toBe("0");
    expect(badge.getAttribute("role")).toBe("img");
  });

  it("renders nothing when the backend reports the field reachable", () => {
    const { container } = render(<HandsOnMarker reachKey="identity.name" />);
    expect(container.firstChild).toBeNull();
  });

  it("always renders for an inherently hands-on field (no reachKey)", () => {
    render(<HandsOnMarker label="Fixed foundation — set directly, not editable through chat" text="Set directly" />);
    expect(screen.getByText("Set directly")).toBeTruthy();
    expect(
      screen.getByLabelText("Fixed foundation — set directly, not editable through chat"),
    ).toBeTruthy();
  });

  it("with no injected settings, a keyed human-only field is still marked", () => {
    delete (window as unknown as Win).aincientChat;
    render(<HandsOnMarker reachKey="site.mail" />);
    expect(screen.getByLabelText("Set directly — not editable through chat")).toBeTruthy();
  });
});
