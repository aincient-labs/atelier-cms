// @vitest-environment jsdom
/**
 * The kit's primitives, rendered — what each one PROMISES beyond its classes:
 * a Field wires its control, a button never submits by accident, and the
 * Radix-backed layers portal INTO the console root (the tokens live on
 * `#aincient-chat-root`; a layer under `document.body` would render unstyled),
 * dismiss on Escape unless told not to, and hand focus and selection back.
 *
 * The look is the gallery's job (/atelier/dev/kit); this is the behaviour.
 * The Menu has a file of its own (menu.test.tsx) — see there for why.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useRef, useState } from "react";
import { Button, IconButton } from "./button";
import { Field, TextInput } from "./field";
import { Dialog, DialogClose, DialogTitle } from "./dialog";
import { Popover } from "./popover";
import { Tabs } from "./tabs";
import { SegmentedControl } from "./segmented";
import { Notice } from "./notice";
import { EmptyState } from "./empty-state";
import { LoadingState } from "./loading-state";
import { StudioGroup } from "./studio-group";
import { StudioStatus } from "./studio-status";

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

const inRoot = (ui: React.ReactElement) => render(ui, { container: root.appendChild(document.createElement("div")) });

describe("Button", () => {
  it("is type=button with the variant's classes, and a toggle states aria-pressed", () => {
    inRoot(
      <>
        <Button variant="primary" size="sm">Publish</Button>
        <Button pressed>Compare</Button>
      </>,
    );
    const publish = screen.getByRole("button", { name: "Publish" });
    expect(publish.getAttribute("type")).toBe("button");
    expect(publish.className).toBe("ain-btn ain-topbtn ain-topbtn--primary ain-topbtn--sm");
    const compare = screen.getByRole("button", { name: "Compare" });
    expect(compare.getAttribute("aria-pressed")).toBe("true");
    expect(compare.classList.contains("ain-topbtn--on")).toBe(true);
  });

  it("names an icon button by its label", () => {
    inRoot(<IconButton label="Delete"><svg /></IconButton>);
    const b = screen.getByRole("button", { name: "Delete" });
    expect(b.getAttribute("title")).toBe("Delete");
  });
});

describe("Field", () => {
  it("wires the label, the hint and the error to the control inside it", () => {
    inRoot(
      <Field label="Slug" hint="Lowercase, dashes" error="Already used">
        <TextInput defaultValue="x" />
      </Field>,
    );
    const input = screen.getByLabelText("Slug");
    expect(input.tagName).toBe("INPUT");
    expect(input.getAttribute("aria-invalid")).toBe("true");
    const described = (input.getAttribute("aria-describedby") ?? "").split(" ").map((id) => document.getElementById(id)?.textContent);
    expect(described).toEqual(["Already used", "Lowercase, dashes"]);
  });

  it("leaves a control outside a Field alone", () => {
    inRoot(<TextInput aria-label="Search" />);
    const input = screen.getByRole("textbox", { name: "Search" });
    expect(input.hasAttribute("aria-invalid")).toBe(false);
    expect(input.hasAttribute("id")).toBe(false);
  });
});

function DialogHarness({ dismissible = true }: { dismissible?: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button onClick={() => setOpen(true)}>Open</Button>
      <Dialog
        open={open}
        onOpenChange={setOpen}
        title="Delete page?"
        description="This cannot be undone."
        dismissible={dismissible}
        actions={<DialogClose><Button>Cancel</Button></DialogClose>}
      />
    </>
  );
}

describe("Dialog", () => {
  it("portals into the console root, is named by its title, and closes on Escape", async () => {
    inRoot(<DialogHarness />);
    screen.getByRole("button", { name: "Open" }).focus();
    fireEvent.click(screen.getByRole("button", { name: "Open" }));
    const dialog = screen.getByRole("dialog", { name: "Delete page?" });
    expect(root.contains(dialog)).toBe(true);
    expect(dialog.getAttribute("aria-describedby")).toBeTruthy();
    fireEvent.keyDown(dialog, { key: "Escape" });
    expect(screen.queryByRole("dialog")).toBeNull();
    // Back to what opened it — a plain button, not a Radix Trigger. Radix
    // hands focus back on the tick after unmount.
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole("button", { name: "Open" })));
  });

  it("stays open on Escape when it must be answered, and closes from its own Cancel", () => {
    inRoot(<DialogHarness dismissible={false} />);
    fireEvent.click(screen.getByRole("button", { name: "Open" }));
    fireEvent.keyDown(screen.getByRole("dialog"), { key: "Escape" });
    expect(screen.getByRole("dialog")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("a form dialog is the form: Enter submits, focus starts where it is told, the ✕ closes", () => {
    const onSubmit = vi.fn((e: React.FormEvent) => e.preventDefault());
    const onOpenChange = vi.fn();
    function FormHarness() {
      const titleRef = useRef<HTMLInputElement>(null);
      return (
        <Dialog open onOpenChange={onOpenChange} title="New page" closeButton onSubmit={onSubmit} initialFocus={titleRef}
          actions={<Button type="submit" variant="primary">Create</Button>}>
          <Field label="Title"><TextInput ref={titleRef} /></Field>
        </Dialog>
      );
    }
    inRoot(<FormHarness />);
    const dialog = screen.getByRole("dialog", { name: "New page" });
    expect(dialog.tagName).toBe("FORM");
    expect(document.activeElement).toBe(screen.getByLabelText("Title"));
    fireEvent.submit(dialog);
    expect(onSubmit).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByRole("button", { name: "Close" }));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it("returns focus to returnFocus when its opener has unmounted (a menu item)", async () => {
    function GoneOpenerHarness() {
      const fallback = useRef<HTMLButtonElement>(null);
      const [opener, setOpener] = useState(true);
      const [open, setOpen] = useState(false);
      return (
        <>
          <button ref={fallback} type="button">Account</button>
          {opener && (
            <button type="button" onClick={() => { setOpen(true); setOpener(false); }}>
              Manage account
            </button>
          )}
          <Dialog open={open} onOpenChange={setOpen} title="My account" returnFocus={fallback} />
        </>
      );
    }
    inRoot(<GoneOpenerHarness />);
    const opener = screen.getByRole("button", { name: "Manage account" });
    opener.focus();
    fireEvent.click(opener);
    fireEvent.keyDown(screen.getByRole("dialog", { name: "My account" }), { key: "Escape" });
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole("button", { name: "Account" })));
  });

  it("asChild hands the card to its child, named by a DialogTitle inside it", () => {
    inRoot(
      <Dialog open onOpenChange={() => {}} dismissible={false} asChild>
        <section className="ain-endstate">
          <DialogTitle>Document unavailable</DialogTitle>
        </section>
      </Dialog>,
    );
    const dialog = screen.getByRole("dialog", { name: "Document unavailable" });
    expect(dialog.tagName).toBe("SECTION");
    expect(dialog.classList.contains("ain-confirm")).toBe(false);
  });
});

describe("Popover", () => {
  it("toggles from its trigger and renders inside the console root", () => {
    inRoot(
      <Popover title="Link target" trigger={<Button>Link</Button>}>
        <p>body</p>
      </Popover>,
    );
    const trigger = screen.getByRole("button", { name: "Link" });
    expect(trigger.getAttribute("aria-expanded")).toBe("false");
    fireEvent.click(trigger);
    expect(trigger.getAttribute("aria-expanded")).toBe("true");
    expect(root.contains(screen.getByText("body"))).toBe(true);
  });
});

describe("Tabs and SegmentedControl", () => {
  it("tabs show one panel, labelled by its tab, and wear the segmented look", () => {
    inRoot(
      <Tabs
        label="Facets"
        items={[
          { value: "body", label: "Body", content: <p>sections</p> },
          { value: "presence", label: "Presence", content: <p>search</p> },
        ]}
      />,
    );
    expect(screen.getByRole("tablist", { name: "Facets" }).classList.contains("ain-seg")).toBe(true);
    const body = screen.getByRole("tab", { name: "Body" });
    expect(body.getAttribute("aria-selected")).toBe("true");
    expect(screen.getByRole("tabpanel", { name: "Body" }).textContent).toBe("sections");
  });

  it("a segmented control is a labelled group of pressed buttons", () => {
    const onChange = vi.fn();
    inRoot(
      <SegmentedControl
        label="View"
        value="grid"
        onChange={onChange}
        options={[
          { value: "grid", label: "Grid" },
          { value: "list", label: "List" },
        ]}
      />,
    );
    expect(screen.getByRole("group", { name: "View" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Grid" }).getAttribute("aria-pressed")).toBe("true");
    fireEvent.click(screen.getByRole("button", { name: "List" }));
    expect(onChange).toHaveBeenCalledWith("list");
  });
});

describe("Notice, EmptyState and StudioGroup", () => {
  it("a success notice is a polite status led by a check; an error is an alert", () => {
    render(
      <>
        <Notice tone="success">Saved.</Notice>
        <Notice tone="error">Failed.</Notice>
      </>,
    );
    const status = screen.getByRole("status");
    expect(status.textContent).toBe(" Saved.");
    expect(status.querySelector("svg")).toBeTruthy();
    expect(status.className).toBe("ain-notice ain-notice--success");
    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe("Failed.");
    expect(alert.querySelector("svg")).toBeNull();
  });

  it("a panel error keeps the studio pane's class and is an alert", () => {
    render(<Notice tone="error" panel>Could not load.</Notice>);
    const alert = screen.getByRole("alert");
    expect(alert.className).toBe("ain-studio__error");
    expect(alert.textContent).toBe("Could not load.");
  });

  it("an empty state emits the list or the stage family, with the hint only on request", () => {
    const { container } = render(
      <>
        <EmptyState icon={<svg data-testid="glyph" />}>Nothing here yet.</EmptyState>
        <EmptyState variant="stage" hint="Ask the agent.">Your preview appears here.</EmptyState>
      </>,
    );
    const [list, stage] = Array.from(container.children);
    expect(list.className).toBe("ain-browser__empty");
    expect(list.querySelector("p")?.textContent).toBe("Nothing here yet.");
    expect(screen.getByTestId("glyph").parentElement).toBe(list);
    expect(stage.className).toBe("ain-pagepreview__empty");
    expect(stage.querySelector(".ain-pagepreview__hint")?.textContent).toBe("Ask the agent.");
  });

  it("a studio group titles its fields; actions — even none yet — keep the title row", () => {
    const { container } = render(
      <>
        <StudioGroup id="g1" title="Tones" note="At least one stays.">
          <input aria-label="Warm" />
        </StudioGroup>
        <StudioGroup title="Sections" actions={false} />
      </>,
    );
    const [plain, withRow] = Array.from(container.children);
    expect(plain.id).toBe("g1");
    expect(screen.getByRole("heading", { name: "Tones" }).parentElement).toBe(plain);
    expect(plain.querySelector(".ain-studio__groupnote")?.textContent).toBe("At least one stays.");
    expect(withRow.querySelector(".ain-studio__grouphead h3")?.textContent).toBe("Sections");
    expect(withRow.querySelector(".ain-studio__groupnote")).toBeNull();
  });

  it("a bare studio group keeps the plain title; a note or a title row makes it static", () => {
    const { container } = render(
      <>
        <StudioGroup title="Page" />
        <StudioGroup title="Block" note="Reusable." />
      </>,
    );
    const [bare, noted] = Array.from(container.querySelectorAll("h3"));
    expect(bare.className).toBe("ain-studio__grouptitle");
    expect(noted.className).toBe("ain-studio__grouptitle ain-studio__grouptitle--static");
  });

  it("a loading state is the busy list itself: skeleton rows, thumb and trailing on request", () => {
    const { container } = render(
      <>
        <LoadingState label="Loading pages" rows={3} trailing />
        <LoadingState label="Loading the shelf" rows={2} thumb />
      </>,
    );
    const pages = screen.getByRole("list", { name: "Loading pages" });
    expect(pages.getAttribute("aria-busy")).toBe("true");
    expect(pages.className).toBe("ain-browser__list ain-loading");
    expect(pages.querySelectorAll("li")).toHaveLength(3);
    expect(pages.querySelectorAll(".ain-loading__badge")).toHaveLength(3);
    expect(pages.querySelector(".ain-loading__thumb")).toBeNull();
    const shelf = screen.getByRole("list", { name: "Loading the shelf" });
    expect(shelf.querySelectorAll(".ain-loading__thumb")).toHaveLength(2);
    expect(container.querySelectorAll('[aria-hidden="true"].ain-skeleton').length).toBeGreaterThan(0);
  });

  it("a studio status shows dirty over saved over the resting line, and marks only dirty", () => {
    const { rerender } = render(
      <StudioStatus dirty={<>2 unsaved changes</>} saved="Published.">
        Matches the saved page
      </StudioStatus>,
    );
    const line = screen.getByRole("status");
    expect(line.className).toBe("ain-studio__status");
    expect(line.textContent).toBe("2 unsaved changes");
    expect(line.hasAttribute("data-dirty")).toBe(true);

    rerender(<StudioStatus dirty={false} saved="Published.">Matches the saved page</StudioStatus>);
    expect(line.textContent).toBe(" Published.");
    expect(line.querySelector("svg")).not.toBeNull();
    expect(line.hasAttribute("data-dirty")).toBe(false);

    rerender(<StudioStatus dirty={false} saved={null}>Matches the saved page</StudioStatus>);
    expect(line.textContent).toBe("Matches the saved page");
    expect(line.querySelector("svg")).toBeNull();
  });
});
