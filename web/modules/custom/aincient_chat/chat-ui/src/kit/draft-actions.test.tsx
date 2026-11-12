// @vitest-environment jsdom
/**
 * DraftActions (audit N3): Discard sits beside Publish on a wide top bar and
 * folds into Publish's caret menu on a phone-width one. One menu opens in this
 * file, on purpose (kit/menu.test.tsx explains the jsdom limit).
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { DraftActions } from "./draft-actions";

let width = 1440;
beforeAll(() => {
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
  window.matchMedia = ((query: string) => {
    const m = /max-width:\s*(\d+)px/.exec(query);
    return {
      matches: m ? width <= Number(m[1]) : false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    };
  }) as unknown as typeof window.matchMedia;
});

let root: HTMLElement;
beforeEach(() => {
  root = document.createElement("div");
  root.id = "aincient-chat-root";
  document.body.appendChild(root);
});
afterEach(() => {
  cleanup();
  root.remove();
});

const props = () => ({ dirty: 2, publishing: false, onDiscard: vi.fn(), onPublish: vi.fn() });

describe("DraftActions", () => {
  it("shows the count, Discard and Publish on a wide bar", () => {
    width = 1440;
    const p = props();
    render(<DraftActions {...p} />, { container: root });
    expect(screen.getByText("2").getAttribute("title")).toBe("2 unsaved changes");
    fireEvent.click(screen.getByRole("button", { name: "Discard" }));
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect(p.onDiscard).toHaveBeenCalledOnce();
    expect(p.onPublish).toHaveBeenCalledOnce();
    expect(screen.queryByRole("button", { name: "More draft actions" })).toBeNull();
  });

  it("disables both with nothing to save, and Publish alone when it is blocked", () => {
    width = 1440;
    const { rerender } = render(<DraftActions {...props()} dirty={0} />, { container: root });
    expect((screen.getByRole("button", { name: "Discard" }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole("button", { name: "Publish" }) as HTMLButtonElement).disabled).toBe(true);
    rerender(<DraftActions {...props()} canPublish={false} />);
    expect((screen.getByRole("button", { name: "Discard" }) as HTMLButtonElement).disabled).toBe(false);
    expect((screen.getByRole("button", { name: "Publish" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("folds Discard into Publish's menu on a phone-width bar", () => {
    width = 390;
    const p = props();
    render(<DraftActions {...p} />, { container: root });
    expect(screen.queryByRole("button", { name: "Discard" })).toBeNull();
    fireEvent.keyDown(screen.getByRole("button", { name: "More draft actions" }), { key: "Enter" });
    fireEvent.click(screen.getByRole("menuitem", { name: "Discard changes" }));
    expect(p.onDiscard).toHaveBeenCalledOnce();
  });
});
