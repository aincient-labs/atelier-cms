// @vitest-environment jsdom
/**
 * The page lifecycle bar (DECISIONS 0454), rendered: the state chip, Save draft,
 * one primary and the grouped menu. One menu is OPENED in this file, on
 * purpose — jsdom stops toggling a second Radix DropdownMenu (kit/menu.test.tsx).
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { PageLifecycleBar, type PageLifecycleBarProps } from "./page-lifecycle-bar";
import type { Moderation, Transition } from "./page-state";

beforeAll(() => {
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

const tr = (id: string, label: string, to: string, to_label: string, weight: number, to_weight: number, flags: Partial<Transition> = {}): Transition => ({
  id, label, to, to_label, weight, to_weight, to_published: false, to_default_revision: false, ...flags,
});

const SUBMIT = tr("submit_for_review", "Submit for review", "needs_review", "Needs review", 1, 1);
const PUBLISH = tr("publish", "Publish", "published", "Published", 4, 2, { to_published: true, to_default_revision: true });
const APPROVE = tr("approve", "Approve", "published", "Published", 2, 2, { to_published: true, to_default_revision: true });
const REJECT = tr("reject", "Reject", "draft", "Draft", 3, 0);
const ARCHIVE = tr("archive", "Archive", "archived", "Archived", 5, 3, { to_default_revision: true });

const mod = (over: Partial<Moderation>): Moderation => ({
  state: "draft", stateLabel: "Draft", stateWeight: 0, statePublished: false,
  hasPendingDraft: false, canEdit: true, transitions: [], baseVid: 7, ...over,
});

const renderBar = (over: Partial<PageLifecycleBarProps>) => {
  const props: PageLifecycleBarProps = {
    moderation: mod({}),
    dirty: false,
    busy: false,
    canWrite: true,
    onDiscard: vi.fn(),
    onSaveDraft: vi.fn(),
    onPublish: vi.fn(),
    onTransition: vi.fn(),
    ...over,
  };
  render(<PageLifecycleBar {...props} />, { container: root });
  return props;
};

describe("PageLifecycleBar", () => {
  it("shows the state, Save draft and Publish on a dirty draft — and Discard only while dirty", () => {
    const props = renderBar({ moderation: mod({ transitions: [SUBMIT, PUBLISH] }), dirty: true });
    const bar = screen.getByTestId("page-lifecycle");
    expect(within(bar).getByText("Draft")).toBeTruthy();
    expect(within(bar).getByText(/unsaved changes/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Discard" }));
    expect(props.onDiscard).toHaveBeenCalledOnce();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect(props.onPublish).toHaveBeenCalledOnce();
    expect(screen.getByRole("button", { name: "More workflow actions" })).toBeTruthy();
  });

  it("never offers Publish to an editor who can't go live from review (the Checks bug)", () => {
    renderBar({
      moderation: mod({ state: "needs_review", stateLabel: "Needs review", stateWeight: 1, transitions: [SUBMIT] }),
    });
    expect(screen.queryByRole("button", { name: "Publish" })).toBeNull();
    expect(screen.queryByRole("button", { name: /Approve/ })).toBeNull();
    expect(screen.getByText("Needs review")).toBeTruthy();
  });

  it("calls a reviewer's Approve as a transition, labelled as going live", () => {
    const props = renderBar({
      moderation: mod({ state: "needs_review", stateLabel: "Needs review", stateWeight: 1, canEdit: false, transitions: [APPROVE] }),
      canWrite: false,
    });
    expect(screen.queryByRole("button", { name: "Save draft" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Approve & publish" }));
    expect(props.onTransition).toHaveBeenCalledWith(APPROVE, { saveFirst: false });
  });

  it("calls a live page Live, and offers Archive behind a menu with a confirm", () => {
    const props = renderBar({
      moderation: mod({ state: "published", stateLabel: "Published", stateWeight: 2, statePublished: true, transitions: [ARCHIVE] }),
    });
    expect(screen.getByText("Live")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Publish" })).toBeNull();
    // jsdom has no PointerEvent: open the menu the keyboard way.
    fireEvent.keyDown(screen.getByRole("button", { name: /Move to/ }), { key: "Enter" });
    const item = screen.getByRole("menuitem", { name: /Archive/ });
    expect(item.classList.contains("ain-menu__item--danger")).toBe(true);
    fireEvent.click(item);
    // Not yet: a take-offline transition confirms first.
    expect(props.onTransition).not.toHaveBeenCalled();
    const dialog = screen.getByRole("dialog", { name: "Archive this page?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Archive" }));
    expect(props.onTransition).toHaveBeenCalledWith(ARCHIVE, { saveFirst: false });
  });

  it("carries unsaved edits into Submit for review from Draft", () => {
    const props = renderBar({ moderation: mod({ transitions: [SUBMIT] }), dirty: true });
    fireEvent.click(screen.getByRole("button", { name: "Submit for review" }));
    expect(props.onTransition).toHaveBeenCalledWith(SUBMIT, { saveFirst: true });
  });

  it("never saves first from review — a save would move the page back to Draft", () => {
    const props = renderBar({
      moderation: mod({ state: "needs_review", stateLabel: "Needs review", stateWeight: 1, transitions: [APPROVE, REJECT] }),
      dirty: true,
    });
    fireEvent.click(screen.getByRole("button", { name: "Approve & publish" }));
    expect(props.onTransition).toHaveBeenCalledWith(APPROVE, { saveFirst: false });
  });
});
