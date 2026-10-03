// @vitest-environment jsdom
/**
 * The kit FilterSelect: a long list behind a trigger, narrowed by typing,
 * driven from the filter box (combobox + aria-activedescendant), and nested in
 * a Dialog without the Dialog closing on its Escape.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { Field } from "./field";
import { FilterSelect } from "./filter-select";

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

const ZONES = [
  { value: "UTC", label: "UTC", group: null },
  { value: "Europe/Berlin", label: "Berlin", group: "Europe" },
  { value: "Europe/Paris", label: "Paris", group: "Europe" },
  { value: "Asia/Tokyo", label: "Tokyo", group: "Asia" },
];

function open(onChange = vi.fn()) {
  render(
    <Field label="Timezone">
      <FilterSelect label="Timezone" value="Europe/Berlin" onChange={onChange} options={ZONES} />
    </Field>,
    { container: root },
  );
  const trigger = screen.getByLabelText("Timezone", { selector: "button" });
  expect(trigger.textContent).toContain("Berlin");
  fireEvent.click(trigger);
  return { trigger, onChange, input: screen.getByRole("combobox") };
}

describe("FilterSelect", () => {
  it("opens into the console root with focus in the filter, the current option active and selected", () => {
    const { trigger, input } = open();
    expect(trigger.hasAttribute("data-open")).toBe(true);
    expect(root.contains(screen.getByRole("listbox", { name: "Timezone" }))).toBe(true);
    expect(document.activeElement).toBe(input);
    const active = document.getElementById(input.getAttribute("aria-activedescendant") ?? "");
    expect(active?.textContent).toBe("Berlin");
    expect(active?.getAttribute("aria-selected")).toBe("true");
  });

  it("narrows by typing (label, value or group) and chooses with the keys", () => {
    const { input, onChange } = open();
    fireEvent.change(input, { target: { value: "asia" } });
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Tokyo"]);
    fireEvent.change(input, { target: { value: "europe" } });
    fireEvent.keyDown(input, { key: "ArrowDown" });
    fireEvent.keyDown(input, { key: "Enter" });
    expect(onChange).toHaveBeenCalledWith("Europe/Paris");
    expect(screen.queryByRole("listbox")).toBeNull();
  });

  it("says so when nothing matches, and picks by click", () => {
    const { input, onChange } = open();
    fireEvent.change(input, { target: { value: "zzz" } });
    expect(screen.getByText("No match.")).toBeTruthy();
    fireEvent.change(input, { target: { value: "" } });
    fireEvent.click(screen.getByRole("option", { name: "Tokyo" }));
    expect(onChange).toHaveBeenCalledWith("Asia/Tokyo");
  });

  it("closes on Escape and gives focus back to its trigger", async () => {
    const { trigger, input } = open();
    fireEvent.keyDown(input, { key: "Escape" });
    expect(screen.queryByRole("listbox")).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(trigger));
  });
});
