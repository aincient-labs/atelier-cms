// @vitest-environment jsdom
/**
 * The #10 regression, RENDERED.
 *
 * `thread-seal.test.ts` can only model the dependency semantics of "is the thread
 * I'm looking at sealed?" — it has no DOM, so it asserts recompute counts against
 * a memoization stand-in. That catches a wrong memo key; it cannot catch someone
 * re-introducing a `useState` cache inside the hook, which is the actual bug shape
 * of issues #10/#11a: a verdict seeded once and refreshed on only ONE of its two
 * inputs (the seal store), so leaving a sealed thread kept a stale `true` under a
 * brand-new conversation.
 *
 * This file renders a component through `useActiveThreadSealed()` and drives BOTH
 * inputs — the seal store and the active thread — asserting the output flips each
 * way. A cached implementation fails the thread-switch case here; nothing else in
 * the suite would notice.
 *
 * The runtime is mocked rather than mounted: `useThreadRemoteId` only ever reads
 * `runtime.threads.mainItem`, so a two-method fake is the whole surface, and it
 * lets a test move the active thread deterministically.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { rememberThreadSeal, resetSealsForTests } from "./thread-seal";

const runtime = vi.hoisted(() => {
  let remoteId = "";
  const listeners = new Set<() => void>();
  return {
    threads: {
      mainItem: {
        subscribe(cb: () => void) {
          listeners.add(cb);
          return () => {
            listeners.delete(cb);
          };
        },
        getState() {
          return { remoteId };
        },
      },
    },
    /** Move the active thread the way a sidebar click or a `?thr=` link does. */
    setActive(id: string) {
      remoteId = id;
      for (const l of listeners) l();
    },
  };
});

vi.mock("@assistant-ui/react", () => ({
  useAssistantRuntime: () => runtime,
}));

const { useActiveThreadSealed, useThreadSealed } = await import("./thread-seal-hooks");

/** The read-only axis, as a studio or the composer would consume it. */
function ActiveSealFlag() {
  return <span data-testid="active">{useActiveThreadSealed() ? "sealed" : "open"}</span>;
}

/** A sidebar row, which feeds its OWN id rather than the active one. */
function RowSealFlag({ id }: { id: string }) {
  return <span data-testid={`row-${id}`}>{useThreadSealed(id) ? "sealed" : "open"}</span>;
}

const active = () => screen.getByTestId("active").textContent;

beforeEach(() => {
  resetSealsForTests();
  act(() => runtime.setActive(""));
});

afterEach(cleanup);

describe("useActiveThreadSealed", () => {
  it("flips to not-sealed when the active thread moves off a sealed one (#10)", () => {
    rememberThreadSeal("thread-A", true);
    act(() => runtime.setActive("thread-A"));

    render(<ActiveSealFlag />);
    expect(active()).toBe("sealed");

    // The whole regression: thread B was never sealed, so the pane must clear —
    // even though the seal STORE did not change and emitted nothing.
    act(() => runtime.setActive("thread-B"));
    expect(active()).toBe("open");
  });

  it("flips back to sealed when the reader returns to the sealed thread", () => {
    rememberThreadSeal("thread-A", true);
    act(() => runtime.setActive("thread-B"));
    render(<ActiveSealFlag />);
    expect(active()).toBe("open");

    act(() => runtime.setActive("thread-A"));
    expect(active()).toBe("sealed");
  });

  it("reacts to a seal that lands on the thread already on screen", () => {
    act(() => runtime.setActive("thread-A"));
    render(<ActiveSealFlag />);
    expect(active()).toBe("open");

    // A wrap-up commit flips the store locally, with no thread switch.
    act(() => rememberThreadSeal("thread-A", true));
    expect(active()).toBe("sealed");
  });

  it("treats a fresh thread with no backend id as open", () => {
    rememberThreadSeal("thread-A", true);
    act(() => runtime.setActive("thread-A"));
    render(<ActiveSealFlag />);
    expect(active()).toBe("sealed");

    // A brand-new conversation has no remote id yet — the SEAL/new-thread path.
    act(() => runtime.setActive(""));
    expect(active()).toBe("open");
  });
});

describe("useThreadSealed", () => {
  it("gives each row its own verdict, and updates them all on a seal", () => {
    render(
      <>
        <RowSealFlag id="thread-A" />
        <RowSealFlag id="thread-B" />
      </>,
    );
    expect(screen.getByTestId("row-thread-A").textContent).toBe("open");
    expect(screen.getByTestId("row-thread-B").textContent).toBe("open");

    act(() => rememberThreadSeal("thread-B", true));
    expect(screen.getByTestId("row-thread-A").textContent).toBe("open");
    expect(screen.getByTestId("row-thread-B").textContent).toBe("sealed");
  });
});
