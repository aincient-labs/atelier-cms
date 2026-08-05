// @vitest-environment jsdom
/**
 * `ProviderFailureNotice`, rendered — the three locks against a double send.
 *
 * `provider-failure.test.ts` tests the DOM-free half: reading a fault out of a
 * run's steps and shaping it into card props. It stops exactly where the
 * interesting part starts. The card's Retry button is guarded by THREE
 * conditions that only exist inside the component and only interact once it is
 * mounted:
 *
 *   1. it disarms on click and never re-arms (local `sent` state),
 *   2. it is armed only on the NEWEST turn (this message's index in the thread),
 *   3. it is disabled while a turn is running (`isRunning`),
 *
 * plus the words to re-send, which are dug out of the thread rather than
 * remembered anywhere. None of that is prop→markup, which is why this is a
 * component worth the render environment. (The capability chip row earned one
 * too, once it started scoping itself to the studio and the room's agent — see
 * `capability-chips.test.tsx`.)
 *
 * The assistant-ui hooks are mocked to a plain thread snapshot — the component
 * uses them purely as selectors over `{ isRunning, messages }` and one `append`.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import type { ProviderFailureCard } from "./provider-failure";

type Msg = { id: string; role: string; content: { type: string; text: string }[] };

const stub = vi.hoisted(() => ({
  append: vi.fn(),
  state: { isRunning: false, messages: [] as Msg[], messageId: "" },
}));

vi.mock("@assistant-ui/react", () => ({
  useThreadRuntime: () => ({ append: stub.append }),
  useMessage: (sel: (m: { id: string }) => unknown) => sel({ id: stub.state.messageId }),
  useThread: (sel: (t: typeof stub.state) => unknown) => sel(stub.state),
  // `error-boundary.tsx` calls this at import time for the tool UI export.
  makeAssistantToolUI: (config: unknown) => config,
}));

const { ProviderFailureNotice } = await import("./progress-widget");

const text = (id: string, role: string, body: string): Msg => ({
  id,
  role,
  content: [{ type: "text", text: body }],
});

/** A turn: the reader's words, then the assistant message carrying the card. */
function thread(failedId = "a1", running = false) {
  stub.state.messages = [text("u1", "user", "make me a page"), text(failedId, "assistant", "")];
  stub.state.messageId = failedId;
  stub.state.isRunning = running;
}

const card = (over: Partial<ProviderFailureCard> = {}): ProviderFailureCard => ({
  sentence: "The provider is rate limiting this key.",
  kind: "rate_limit",
  retry: true,
  ...over,
});

const retryButton = () => screen.queryByRole("button", { name: /send again/i });

beforeEach(() => {
  stub.append.mockClear();
  thread();
});

afterEach(cleanup);

describe("ProviderFailureNotice", () => {
  it("always says the sentence, and links only when the kind earned one", () => {
    render(<ProviderFailureNotice card={card({ retry: false })} />);
    expect(screen.getByRole("status").textContent).toContain("rate limiting");
    expect(screen.queryByRole("link")).toBeNull();

    cleanup();
    render(
      <ProviderFailureNotice
        card={card({ kind: "auth", retry: false, action: { label: "Open models", href: "/x" } })}
      />,
    );
    expect(screen.getByRole("link", { name: "Open models" }).getAttribute("href")).toBe("/x");
  });

  it("re-sends the reader's own words when the backend granted a retry", () => {
    render(<ProviderFailureNotice card={card()} />);
    fireEvent.click(retryButton()!);

    expect(stub.append).toHaveBeenCalledTimes(1);
    expect(stub.append.mock.calls[0][0]).toEqual({
      role: "user",
      content: [{ type: "text", text: "make me a page" }],
    });
  });

  it("lock 1: disarms on click and never re-arms", () => {
    render(<ProviderFailureNotice card={card()} />);
    const button = retryButton()!;
    fireEvent.click(button);
    expect(button.textContent).toBe("Sending again…");
    expect((button as HTMLButtonElement).disabled).toBe(true);

    // A second click on a card that is a record, not a live control.
    fireEvent.click(button);
    expect(stub.append).toHaveBeenCalledTimes(1);
  });

  it("lock 2: an older card is not armed at all", () => {
    // The same failed message, now scrolled back behind a newer turn.
    thread("a1");
    stub.state.messages.push(text("u2", "user", "try again"), text("a2", "assistant", ""));
    render(<ProviderFailureNotice card={card()} />);
    expect(retryButton()).toBeNull();
  });

  it("lock 3: disabled while a turn is running", () => {
    thread("a1", true);
    render(<ProviderFailureNotice card={card()} />);
    const button = retryButton()!;
    expect((button as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(button);
    expect(stub.append).not.toHaveBeenCalled();
    // NOTE: that last assertion is carried by `disabled` alone — the DOM does not
    // deliver a click to a disabled control, so the handler's own `running`
    // re-check (which exists because a rendering can be one paint behind a
    // running turn) is not observable from here. Verified: removing `running`
    // from the handler guard leaves this file green. Nothing in the suite covers
    // that re-check; it would need a real runtime whose state changes mid-click.
  });

  it("offers no retry without the grant, and shows the note explaining why", () => {
    render(
      <ProviderFailureNotice card={card({ retry: false, note: "Part of this turn already applied." })} />,
    );
    expect(retryButton()).toBeNull();
    expect(screen.getByRole("status").textContent).toContain("already applied");
  });

  it("offers no retry when the reader's words cannot be recovered", () => {
    // A granted retry, but the failed turn is the first message — nothing to re-send.
    stub.state.messages = [text("a1", "assistant", "")];
    stub.state.messageId = "a1";
    render(<ProviderFailureNotice card={card()} />);
    expect(retryButton()).toBeNull();
  });
});
