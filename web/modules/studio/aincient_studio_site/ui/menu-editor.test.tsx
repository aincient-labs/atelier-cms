// @vitest-environment jsdom
/**
 * The menu editor's keyboard path for nesting (DnD's accessible twin):
 * "Nest under previous link" makes the row the previous row's last child and
 * "Move out of submenu" puts it back right after its former parent — each a
 * single `onChange` with the whole new tree. The first row can't nest and a
 * top-level row can't move out. After a level-changing move the editor stays
 * on the current level (no auto-drill).
 */

import { useState } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ChromeMenuLink } from "@console/sdk";
import { MenuEditor } from "./menu-editor";

const link = (id: number, children?: ChromeMenuLink[]): ChromeMenuLink => ({
  id,
  title: `Link ${id}`,
  url: `/l${id}`,
  enabled: true,
  ...(children ? { children } : {}),
});

const labels = () => screen.getAllByRole("textbox", { name: "Link label" }).map((i) => (i as HTMLInputElement).value);

afterEach(cleanup);

describe("MenuEditor nest / outdent buttons", () => {
  it("nests a row under the previous one, and the first row cannot nest", () => {
    const onChange = vi.fn();
    render(<MenuEditor links={[link(1), link(2), link(3)]} onChange={onChange} />);

    const nest = screen.getAllByRole("button", { name: "Nest under previous link" });
    expect((nest[0] as HTMLButtonElement).disabled).toBe(true);
    for (const b of screen.getAllByRole("button", { name: "Move out of submenu" })) expect((b as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(nest[2]);
    expect(onChange).toHaveBeenCalledTimes(1);
    expect(onChange.mock.calls[0][0]).toEqual([link(1), link(2, [link(3)])]);
  });

  it("moves a submenu row out, right after its former parent, and stays on the level", () => {
    function Harness() {
      const [links, setLinks] = useState([link(1, [link(11), link(12)]), link(2)]);
      return <MenuEditor links={links} onChange={setLinks} rootLabel="Header menu" />;
    }
    render(<Harness />);

    fireEvent.click(screen.getAllByRole("button", { name: /submenu \(2\)/ })[0]);
    expect(labels()).toEqual(["Link 11", "Link 12"]);

    fireEvent.click(screen.getAllByRole("button", { name: "Move out of submenu" })[0]);
    // Still inside Link 1's submenu, which now holds only Link 12.
    expect(labels()).toEqual(["Link 12"]);

    fireEvent.click(screen.getByRole("button", { name: "Header menu" }));
    expect(labels()).toEqual(["Link 1", "Link 11", "Link 2"]);
  });
});
