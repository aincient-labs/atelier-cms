// @vitest-environment jsdom
/**
 * The page lifecycle bar on a phone-width top bar (audit N3): Discard leaves the
 * bar and becomes the last item of the primary's menu, so it stays reachable.
 * Its own file: one Radix menu opens per file in jsdom (kit/menu.test.tsx).
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { PageLifecycleBar } from "./page-lifecycle-bar";
import type { Moderation, Transition } from "./page-state";

beforeAll(() => {
  globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
  window.matchMedia = ((query: string) => ({
    matches: /max-width:\s*(\d+)px/.test(query) && 390 <= Number(/(\d+)px/.exec(query)![1]),
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia;
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

const draft: Moderation = {
  state: "draft", stateLabel: "Draft", stateWeight: 0, statePublished: false,
  hasPendingDraft: false, canEdit: true, transitions: [], baseVid: 7,
};

describe("PageLifecycleBar on a phone", () => {
  it("moves Discard into the primary's menu, even when Publish has no other actions", () => {
    const onDiscard = vi.fn();
    render(
      <PageLifecycleBar
        moderation={draft}
        isNew
        dirty
        busy={false}
        canWrite
        onDiscard={onDiscard}
        onSaveDraft={vi.fn()}
        onPublish={vi.fn()}
        onTransition={vi.fn()}
      />,
      { container: root },
    );
    expect(screen.queryByRole("button", { name: "Discard" })).toBeNull();
    fireEvent.keyDown(screen.getByRole("button", { name: "More workflow actions" }), { key: "Enter" });
    fireEvent.click(screen.getByRole("menuitem", { name: "Discard changes" }));
    expect(onDiscard).toHaveBeenCalledOnce();
  });

  it("puts the workflow menu on Save draft's caret when there is no primary", () => {
    const archive: Transition = {
      id: "archive", label: "Archive", to: "archived", to_label: "Archived", weight: 5, to_weight: 3,
      to_published: false, to_default_revision: true,
    };
    render(
      <PageLifecycleBar
        moderation={{ ...draft, state: "published", stateLabel: "Published", stateWeight: 2, statePublished: true, transitions: [archive] }}
        dirty={false}
        busy={false}
        canWrite
        onSaveDraft={vi.fn()}
        onPublish={vi.fn()}
        onTransition={vi.fn()}
      />,
      { container: root },
    );
    expect(screen.queryByRole("button", { name: /Move to/ })).toBeNull();
    const split = screen.getByRole("button", { name: "Save draft" }).parentElement!;
    expect(split.classList.contains("ain-split")).toBe(true);
    expect(split.querySelector('[aria-label="Workflow actions"]')).toBeTruthy();
  });
});
