import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  markSomethingMade,
  resetNameInviteForTests,
  settleNameInvite,
  shouldInviteName,
  subscribeNameInvite,
} from "./name-invite-state";

/**
 * The name invite's whole design is its timing, so these tests are about WHEN
 * it may appear rather than what it looks like. Three gates have to be open at
 * once, and each one exists because of a specific way this question can become
 * annoying: asked at the door (before the product has earned it), asked again
 * every time an old thread is reopened, or asked after it was already declined.
 */

/** A localStorage stand-in — the node test env has none of its own. */
function installStorage(): Map<string, string> {
  const store = new Map<string, string>();
  vi.stubGlobal("localStorage", {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
  });
  return store;
}

describe("name invite gating", () => {
  beforeEach(() => {
    installStorage();
    resetNameInviteForTests();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("stays silent until the studio has actually made something", () => {
    // The old wizard asked at the door. Nothing has been built yet, so there is
    // nothing to have earned the question.
    expect(shouldInviteName(false)).toBe(false);
  });

  it("opens once a live build lands and the owner has no name", () => {
    markSomethingMade();
    expect(shouldInviteName(false)).toBe(true);
  });

  it("never asks someone who already told us their name", () => {
    markSomethingMade();
    expect(shouldInviteName(true)).toBe(false);
  });

  it("stops asking for good once settled, across later builds", () => {
    markSomethingMade();
    settleNameInvite();
    expect(shouldInviteName(false)).toBe(false);

    // A second page later in the same session must not revive it.
    markSomethingMade();
    expect(shouldInviteName(false)).toBe(false);
  });

  it("remembers being declined into the next visit", () => {
    markSomethingMade();
    settleNameInvite();

    // A fresh page load: module state resets, localStorage does not. Rebuild the
    // in-memory half only, leaving the persisted flag standing.
    const persisted = (globalThis.localStorage as Storage).getItem("ain-name-invite-settled");
    resetNameInviteForTests();
    (globalThis.localStorage as Storage).setItem("ain-name-invite-settled", persisted ?? "");

    markSomethingMade();
    expect(shouldInviteName(false)).toBe(false);
  });

  it("survives private mode, where localStorage throws", () => {
    vi.stubGlobal("localStorage", {
      getItem: () => {
        throw new Error("SecurityError");
      },
      setItem: () => {
        throw new Error("SecurityError");
      },
      removeItem: () => {
        throw new Error("SecurityError");
      },
    });

    // Unreadable storage must not be mistaken for "already settled" — the invite
    // still works, it just can't remember a refusal past this session.
    markSomethingMade();
    expect(shouldInviteName(false)).toBe(true);
    expect(() => settleNameInvite()).not.toThrow();
    expect(shouldInviteName(false)).toBe(false);
  });

  it("tells subscribers when the moment arrives and when it passes", () => {
    const seen = vi.fn();
    subscribeNameInvite(seen);

    markSomethingMade();
    expect(seen).toHaveBeenCalledTimes(1);

    // Idempotent: the second and third page of a session are not new moments.
    markSomethingMade();
    expect(seen).toHaveBeenCalledTimes(1);

    settleNameInvite();
    expect(seen).toHaveBeenCalledTimes(2);
  });

  it("lets a subscriber unsubscribe", () => {
    const seen = vi.fn();
    subscribeNameInvite(seen)();
    markSomethingMade();
    expect(seen).not.toHaveBeenCalled();
  });
});
