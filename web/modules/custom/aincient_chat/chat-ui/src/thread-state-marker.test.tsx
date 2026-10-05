// @vitest-environment jsdom
/**
 * The sidebar row's editorial marker (content-workflow.md Phase 4): the backend
 * state id → the word the row shows, the chip tint it wears, and the listing
 * store that feeds it (live state — a newer listing wins, null clears).
 */

import { afterEach, describe, expect, it } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { ThreadRowStateMarker, ThreadStateMarker } from "./thread-state-marker";
import { editorialMarker, rememberThreadEditorialState, threadEditorialState } from "./thread-editorial-state";

afterEach(() => cleanup());

describe("editorialMarker", () => {
  it("maps the three agreed states to their row words", () => {
    expect(editorialMarker({ state: "draft", label: "Draft", live: false })).toEqual({
      state: "draft", text: "Draft", detail: "Draft",
    });
    expect(editorialMarker({ state: "needs_review", label: "Needs review", live: false })?.text).toBe("In review");
    expect(editorialMarker({ state: "published", label: "Published", live: true })?.text).toBe("Live");
    expect(editorialMarker({ state: "published", label: "Published", live: true })?.detail).toBe("Live");
  });

  it("keeps the server label as the detail of a forward draft over a live page", () => {
    const m = editorialMarker({ state: "draft", label: "Draft · published copy live", live: true });
    expect(m?.text).toBe("Draft");
    expect(m?.detail).toBe("Draft · published copy live");
  });

  it("shows nothing for no state, archived, or an unknown state", () => {
    expect(editorialMarker(null)).toBeNull();
    expect(editorialMarker(undefined)).toBeNull();
    expect(editorialMarker({ state: "archived", label: "Archived", live: false })).toBeNull();
    expect(editorialMarker({ state: "custom", label: "Custom", live: false })).toBeNull();
  });
});

describe("ThreadStateMarker", () => {
  it("renders the whisper chip with the state key and the detail as its title", () => {
    render(<ThreadStateMarker state={{ state: "needs_review", label: "Needs review", live: false }} />);
    const chip = screen.getByTestId("thread-state-marker");
    expect(chip.textContent).toBe("In review");
    expect(chip.className).toContain("ain-studio__statebadge");
    expect(chip.getAttribute("data-state")).toBe("needs_review");
    expect(chip.getAttribute("title")).toBe("Needs review");
  });

  it("renders nothing without a marker", () => {
    const { container } = render(<ThreadStateMarker state={null} />);
    expect(container.innerHTML).toBe("");
  });
});

describe("ThreadRowStateMarker + listing store", () => {
  it("follows the latest listing: set, change, clear", () => {
    act(() => rememberThreadEditorialState("thr_a", { state: "draft", label: "Draft", live: false }));
    render(<ThreadRowStateMarker remoteId="thr_a" />);
    expect(screen.getByTestId("thread-state-marker").textContent).toBe("Draft");

    act(() => rememberThreadEditorialState("thr_a", { state: "published", label: "Published", live: true }));
    expect(screen.getByTestId("thread-state-marker").textContent).toBe("Live");

    act(() => rememberThreadEditorialState("thr_a", null));
    expect(screen.queryByTestId("thread-state-marker")).toBeNull();
    expect(threadEditorialState("thr_a")).toBeUndefined();
  });

  it("is per thread", () => {
    act(() => rememberThreadEditorialState("thr_en", { state: "published", label: "Published", live: true }));
    act(() => rememberThreadEditorialState("thr_de", { state: "draft", label: "Draft", live: false }));
    render(<><ThreadRowStateMarker remoteId="thr_en" /><ThreadRowStateMarker remoteId="thr_de" /></>);
    expect(screen.getAllByTestId("thread-state-marker").map((n) => n.textContent)).toEqual(["Live", "Draft"]);
  });
});
