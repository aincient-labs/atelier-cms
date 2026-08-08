// @vitest-environment jsdom
/**
 * `DesignTokenAdmissionToolUI`, rendered — the design-file admission card
 * (DECISIONS 0350/0369). Proposal-only: Preview seeds the unsaved studio draft;
 * there is NO publish button on the card (the site's one global write is the
 * studio's Publish button), and a historical (replayed) card offers no actions.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";

const setBrandOverride = vi.fn();
const setPendingFonts = vi.fn();
const ensureStudio = vi.fn();
const adoptRoom = vi.fn();

vi.mock("./brand-state", () => ({
  setBrandOverride: (...a: unknown[]) => setBrandOverride(...a),
  setPendingFonts: (...a: unknown[]) => setPendingFonts(...a),
}));
vi.mock("./flow", () => ({ ensureStudio: (...a: unknown[]) => ensureStudio(...a) }));
vi.mock("./console-nav", () => ({ consoleNav: { adoptRoom: (...a: unknown[]) => adoptRoom(...a) } }));

// Test the inner card component directly (the house pattern — see
// markdown-image.test.tsx). The ToolUI wrapper is a thin
// makeSafeAssistantToolUI({ toolName, render }) around it.
import { DesignTokenAdmission, type DesignTokenAdmissionPayload } from "./design-token-admission";

/** Render the admission card with a payload. */
function renderCard(payload: DesignTokenAdmissionPayload) {
  return render(<DesignTokenAdmission {...payload} />);
}

const PAYLOAD: DesignTokenAdmissionPayload = {
  tokens: { "brand-primary": "oklch(0.7 0.2 30)", "radius-md": "0.5rem" },
  fonts: ["Poppins"],
  count: 3,
  source_filename: "design.md",
};

beforeEach(() => {
  vi.clearAllMocks();
});
afterEach(cleanup);

describe("DesignTokenAdmission card", () => {
  it("shows the source file and token count", () => {
    renderCard(PAYLOAD);
    expect(screen.getByText("design.md")).toBeTruthy();
    expect(screen.getByText(/3 design tokens found/)).toBeTruthy();
  });

  it("Preview seeds the draft store (css_var tokens + fonts), no fetch", () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);
    renderCard(PAYLOAD);
    fireEvent.click(screen.getByText("Preview"));
    expect(setBrandOverride).toHaveBeenCalledWith("brand-primary", "oklch(0.7 0.2 30)");
    expect(setBrandOverride).toHaveBeenCalledWith("radius-md", "0.5rem");
    expect(setPendingFonts).toHaveBeenCalledWith(["Poppins"]);
    expect(ensureStudio).toHaveBeenCalledWith("design_system");
    // The card never writes — no publish path lives here (DECISIONS 0369).
    expect(fetchSpy).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });

  it("has NO publish/apply button — the studio Publish is the one write", () => {
    renderCard(PAYLOAD);
    expect(screen.getByText("Preview")).toBeTruthy();
    expect(screen.getByText("Just this turn")).toBeTruthy();
    expect(screen.queryByText(/apply to brand/i)).toBeNull();
    expect(screen.queryByText(/^publish$/i)).toBeNull();
  });

  it("after Preview it points the user to the studio Publish button", () => {
    renderCard(PAYLOAD);
    fireEvent.click(screen.getByText("Preview"));
    expect(screen.getByText(/click/i).textContent).toMatch(/Publish/);
  });

  it("a historical card offers no action buttons", () => {
    renderCard({ ...PAYLOAD, __historical: true });
    expect(screen.queryByText("Preview")).toBeNull();
    expect(screen.queryByText(/apply to brand/i)).toBeNull();
    expect(screen.queryByText("Just this turn")).toBeNull();
  });
});
