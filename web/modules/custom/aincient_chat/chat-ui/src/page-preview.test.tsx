// @vitest-environment jsdom
/**
 * The preview's lens seam (DECISIONS 0453, S3), rendered: with no lenses the
 * bar has no switch (Content's preview is unchanged); with lenses it offers
 * "Page" first, badges a lens's count, and an active lens paints its render
 * with the draft and the baseline.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { PagePreview, type PreviewLens } from "./page-preview";
import { setPageDraft, type PageSchema } from "./page-state";

const draft = {
  type: "landing",
  title: "Home",
  sections: [{ id: "hero", component: "hero", props: { title: "Hi" } }],
} as PageSchema;

beforeEach(() => {
  vi.stubGlobal(
    "fetch",
    vi.fn(() => Promise.resolve(new Response("<p>page</p>", { status: 200 }))),
  );
  setPageDraft(draft);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const lens = (render: PreviewLens["render"]): PreviewLens => ({ id: "search", label: "Search result", count: 2, render });

describe("PagePreview lenses", () => {
  it("offers no lens switch without lenses", async () => {
    render(<PagePreview />);
    await waitFor(() => expect(screen.getByTitle("Page preview")).toBeTruthy());
    expect(screen.queryByRole("group", { name: "Preview lens" })).toBeNull();
  });

  it("offers Page first, badges the count, and paints the chosen lens", async () => {
    const seen = vi.fn((d: PageSchema | null) => <p>lens of {d?.title}</p>);
    render(<PagePreview lenses={[lens(seen)]} />);
    await waitFor(() => expect(screen.getByTitle("Page preview")).toBeTruthy());
    const buttons = screen.getByRole("group", { name: "Preview lens" }).querySelectorAll("button");
    expect([...buttons].map((b) => b.textContent)).toEqual(["Page", "Search result2"]);
    expect(screen.queryByText("lens of Home")).toBeNull();

    fireEvent.click(buttons[1]);
    expect(screen.getByText("lens of Home")).toBeTruthy();
    // The page frame stays mounted under the lens (its scroll survives).
    expect(screen.getByTitle("Page preview")).toBeTruthy();

    fireEvent.click(buttons[0]);
    expect(screen.queryByText("lens of Home")).toBeNull();
  });

  it("follows a controlled lens", async () => {
    const onLensChange = vi.fn();
    render(<PagePreview lenses={[lens(() => <p>search lens</p>)]} lens="search" onLensChange={onLensChange} />);
    await waitFor(() => expect(screen.getByText("search lens")).toBeTruthy());
    fireEvent.click(screen.getByRole("button", { name: "Page" }));
    expect(onLensChange).toHaveBeenCalledWith("page");
    // Controlled: the studio decides, so the lens stays until it does.
    expect(screen.getByText("search lens")).toBeTruthy();
  });
});
