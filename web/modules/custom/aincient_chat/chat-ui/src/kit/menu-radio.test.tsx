// @vitest-environment jsdom
/**
 * The kit Menu's single-choice group — a file of its own for the same reason
 * menu.test.tsx is one (below): in jsdom a second DropdownMenu in a file stops
 * toggling once the first has opened and closed.
 *
 * (menu.test.tsx's note:) The kit Menu, rendered — in a file of its own ON PURPOSE: in jsdom, once a
 * Radix Dialog has opened and closed in the same file, a DropdownMenu rendered
 * afterwards no longer toggles from its trigger. A real browser does not do
 * this (verified on /atelier/dev/kit: dialog → Escape → menu by click and by
 * Enter), so it is an environment artefact, not a kit bug; vitest isolates
 * files, so the Menu runs here, clear of the Dialog tests in kit.test.tsx.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { Menu, MenuRadioGroup, MenuRadioItem } from "./menu";

beforeAll(() => {
  // Radix's popper measures its anchor; jsdom has no ResizeObserver.
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
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

describe("MenuRadioGroup", () => {
  it("marks its trigger open, and a radio group checks the current value", () => {
    const onChange = vi.fn();
    render(
      <Menu label="Section" trigger={<button>Section</button>}>
        <MenuRadioGroup value="pages" onValueChange={onChange}>
          <MenuRadioItem value="pages">Pages</MenuRadioItem>
          <MenuRadioItem value="media">Media</MenuRadioItem>
        </MenuRadioGroup>
      </Menu>,
      { container: root },
    );
    const trigger = screen.getByRole("button", { name: "Section" });
    expect(trigger.hasAttribute("data-open")).toBe(false);
    fireEvent.keyDown(trigger, { key: "Enter" });
    expect(trigger.hasAttribute("data-open")).toBe(true);
    expect(screen.getByRole("menuitemradio", { name: "Pages" }).getAttribute("aria-checked")).toBe("true");
    expect(screen.getByRole("menuitemradio", { name: "Media" }).getAttribute("aria-checked")).toBe("false");
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Media" }));
    expect(onChange).toHaveBeenCalledWith("media");
    expect(screen.queryByRole("menu")).toBeNull();
  });
});
