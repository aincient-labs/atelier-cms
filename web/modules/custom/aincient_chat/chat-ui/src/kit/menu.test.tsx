// @vitest-environment jsdom
/**
 * The kit Menu, rendered — in a file of its own ON PURPOSE: in jsdom, once a
 * Radix Dialog has opened and closed in the same file, a DropdownMenu rendered
 * afterwards no longer toggles from its trigger. A real browser does not do
 * this (verified on /atelier/dev/kit: dialog → Escape → menu by click and by
 * Enter), so it is an environment artefact, not a kit bug; vitest isolates
 * files, so the Menu runs here, clear of the Dialog tests in kit.test.tsx.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { IconButton } from "./button";
import { Menu, MenuItem } from "./menu";

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

describe("Menu", () => {
  it("opens from its trigger inside the console root, fires the chosen item, and takes a link as an item", () => {
    const onRename = vi.fn();
    render(
      <Menu label="Page actions" trigger={<IconButton label="More"><svg /></IconButton>}>
        <MenuItem onSelect={onRename}>Rename</MenuItem>
        <MenuItem danger onSelect={() => {}}>Delete</MenuItem>
        <MenuItem asChild>
          <a href="/user/logout">Log out</a>
        </MenuItem>
      </Menu>,
      { container: root },
    );
    const trigger = screen.getByRole("button", { name: "More" });
    // jsdom has no PointerEvent, so open it the keyboard way (Enter on the trigger).
    fireEvent.keyDown(trigger, { key: "Enter" });
    const menu = screen.getByRole("menu", { name: "Page actions" });
    expect(root.contains(menu)).toBe(true);
    expect(screen.getByRole("menuitem", { name: "Delete" }).classList.contains("ain-menu__item--danger")).toBe(true);
    // asChild lends the item role to a link, keeping its href (one menu per
    // file — a second DropdownMenu here would stop toggling in jsdom).
    const link = screen.getByRole("menuitem", { name: "Log out" });
    expect(link.tagName).toBe("A");
    expect(link.getAttribute("href")).toBe("/user/logout");
    expect(link.classList.contains("ain-menu__item")).toBe(true);
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    expect(onRename).toHaveBeenCalledOnce();
    expect(screen.queryByRole("menu")).toBeNull();
  });
});
