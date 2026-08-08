// @vitest-environment jsdom
/**
 * `LogoHandoffToolUI`, rendered — the logo-handoff card (DECISIONS 0372,
 * Phase 3). It routes "use this as my logo" to Identity → Logo: opening the
 * Identity studio in place and focusing the field by its stable anchor. It
 * PLACES nothing — no field write, no upload — so the button only navigates.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";

const ensureStudio = vi.fn();
const adoptRoom = vi.fn();
const focusStudioField = vi.fn((..._a: unknown[]) => true);

vi.mock("./flow", () => ({ ensureStudio: (...a: unknown[]) => ensureStudio(...a) }));
vi.mock("./console-nav", () => ({ consoleNav: { adoptRoom: (...a: unknown[]) => adoptRoom(...a) } }));
vi.mock("./studio-field-anchor", () => ({
  focusStudioField: (...a: unknown[]) => focusStudioField(...a),
}));

// Test the inner card directly (the house pattern); the ToolUI wrapper is a thin
// makeSafeAssistantToolUI({ toolName, render }) around it.
import { LogoHandoff, type LogoHandoffPayload } from "./logo-handoff";

function renderCard(payload: LogoHandoffPayload) {
  return render(<LogoHandoff {...payload} />);
}

beforeEach(() => {
  vi.clearAllMocks();
});
afterEach(cleanup);

describe("LogoHandoff card", () => {
  it("explains images can't be placed from chat", () => {
    renderCard({ asset: "logo" });
    expect(screen.getByText(/can.t be placed from chat/i)).toBeTruthy();
  });

  it("deep-links to Identity → Logo: opens design_system + focuses identity.logo, no fetch", () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);
    renderCard({ asset: "logo" });
    fireEvent.click(screen.getByRole("button"));
    expect(ensureStudio).toHaveBeenCalledWith("design_system");
    expect(adoptRoom).toHaveBeenCalledWith({ kind: "studio", studio: "design_system" });
    // The deep-link TARGET is the logo field's stable anchor key.
    expect(focusStudioField).toHaveBeenCalledWith("identity.logo");
    // It routes only — it never writes to the site.
    expect(fetchSpy).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });

  it("routes a favicon ask to the favicon field", () => {
    renderCard({ asset: "favicon" });
    fireEvent.click(screen.getByRole("button"));
    expect(focusStudioField).toHaveBeenCalledWith("identity.favicon");
  });

  it("defaults a missing asset to the logo", () => {
    renderCard({});
    fireEvent.click(screen.getByRole("button"));
    expect(focusStudioField).toHaveBeenCalledWith("identity.logo");
  });
});
