import { describe, expect, it } from "vitest";
import { providerFailureCard, providerFailureFields } from "./provider-failure";

/**
 * The reading side of a provider fault: frame → step fields → card props.
 *
 * Tested at the DOM-free seam (vitest runs `environment: "node"` here — no jsdom,
 * no @testing-library), the same pattern as `brand-preview-card.ts` and
 * `tour-model.ts`. What is asserted is the DECISION the card renders from: does
 * this run have a provider fault, which sentence, and is there an action.
 *
 * NOT asserted here, because it needs a browser: that the Retry button disarms on
 * click and never re-arms, that it is dead on anything but the newest turn, that it
 * is disabled while a turn runs, that the anchor renders, that the
 * notice sits outside the collapsible trail, and that the trail's inline red error
 * line is suppressed for a classified fault. Those are one-line JSX consequences of
 * these props — see the manual checklist in the commit.
 */

const failed = (extra: Record<string, unknown> = {}) => ({
  status: "failed",
  error: "Anthropic rejected the key Atelier has for it.",
  ...extra,
});

describe("providerFailureFields", () => {
  it("carries the kind and the action a frame declares", () => {
    expect(
      providerFailureFields({
        error_kind: "auth",
        error_action: { label: "Reconnect provider", url: "/admin/config/aincient/models" },
      }),
    ).toEqual({
      errorKind: "auth",
      errorAction: { label: "Reconnect provider", href: "/admin/config/aincient/models" },
    });
  });

  it("carries a kind with no action for the kinds that offer none", () => {
    expect(providerFailureFields({ error_kind: "rate_limit" })).toEqual({
      errorKind: "rate_limit",
    });
  });

  it("carries the re-send grant and the note exactly as the frame set them", () => {
    expect(providerFailureFields({ error_kind: "rate_limit", error_retry: true })).toEqual({
      errorKind: "rate_limit",
      errorRetry: true,
    });
    expect(
      providerFailureFields({ error_kind: "rate_limit", error_note: "  Part of this already took effect.  " }),
    ).toEqual({ errorKind: "rate_limit", errorNote: "Part of this already took effect." });
  });

  it("treats anything but a literal true as no grant", () => {
    // A truthy-but-odd value on the wire is an accident, and the safe reading of
    // an accident is "do not offer to re-send".
    for (const value of [1, "true", "yes", {}, [], false, null, undefined]) {
      expect(providerFailureFields({ error_kind: "rate_limit", error_retry: value }))
        .toEqual({ errorKind: "rate_limit" });
    }
  });

  it("adds nothing to a failure that is not a provider fault", () => {
    // A graph mistake or a tool blowing up must keep rendering as a failed node.
    expect(providerFailureFields({})).toEqual({});
    expect(providerFailureFields({ error_kind: 42 })).toEqual({});
  });

  it("drops a half-built action rather than rendering a dead link", () => {
    expect(providerFailureFields({ error_kind: "auth", error_action: { label: "Reconnect provider" } }))
      .toEqual({ errorKind: "auth" });
    expect(providerFailureFields({ error_kind: "auth", error_action: { url: "/models" } }))
      .toEqual({ errorKind: "auth" });
  });
});

describe("providerFailureCard", () => {
  it("renders nothing when no step was a provider fault", () => {
    expect(providerFailureCard([])).toBeNull();
    expect(providerFailureCard([{ status: "completed" }])).toBeNull();
    // A plain failed node is still a plain failed node.
    expect(providerFailureCard([{ status: "failed", error: "The capability exploded." }])).toBeNull();
  });

  it("offers the link for the kinds that earn one", () => {
    const card = providerFailureCard([
      { status: "completed" },
      failed({ errorKind: "auth", errorAction: { label: "Reconnect provider", href: "/models" } }),
    ]);
    expect(card).toEqual({
      sentence: "Anthropic rejected the key Atelier has for it.",
      kind: "auth",
      action: { label: "Reconnect provider", href: "/models" },
    });
  });

  it("is a sentence alone when the backend offered no action", () => {
    // The 429 case (#6): the limit is the provider's, so there is nothing to
    // click and nothing to re-send.
    const card = providerFailureCard([
      failed({ error: "Anthropic is rate-limiting this key.", errorKind: "rate_limit" }),
    ]);
    expect(card?.kind).toBe("rate_limit");
    expect(card?.sentence).toBe("Anthropic is rate-limiting this key.");
    expect(card?.action).toBeUndefined();
  });

  it("offers Retry only when the frame granted it", () => {
    const granted = providerFailureCard([
      failed({ error: "Anthropic is rate-limiting this key.", errorKind: "rate_limit", errorRetry: true }),
    ]);
    expect(granted?.retry).toBe(true);
    expect(granted?.note).toBeUndefined();
  });

  it("never invents Retry from the kind, however transient it looks", () => {
    // The gate is server-side: it knows whether this turn already created a page.
    // A client that read "rate_limit ⇒ retryable" would re-open exactly the
    // double-apply hole the backend closes.
    for (const kind of ["rate_limit", "unavailable", "unknown", "auth", "too_long"]) {
      expect(providerFailureCard([failed({ errorKind: kind })])?.retry).toBeUndefined();
    }
  });

  it("shows the note instead of the button when work already landed", () => {
    // Gate 2's visible shape: the sentence, an honest reason, and NO button.
    const card = providerFailureCard([
      failed({
        error: "Anthropic is rate-limiting this key.",
        errorKind: "rate_limit",
        errorNote: "Part of this request already took effect.",
      }),
    ]);
    expect(card?.retry).toBeUndefined();
    expect(card?.note).toBe("Part of this request already took effect.");
  });

  it("never derives an action from the kind itself", () => {
    // The kind→action table is server-side and tested there. If the backend sent
    // `auth` with no action (e.g. it could not resolve the route), the card must
    // NOT invent the link — that is how a lit affordance and a real remedy drift.
    expect(providerFailureCard([failed({ errorKind: "auth" })])?.action).toBeUndefined();
  });

  it("reports the FIRST fault, not the last", () => {
    const card = providerFailureCard([
      failed({ error: "First fault.", errorKind: "unavailable" }),
      failed({ error: "Downstream fallout.", errorKind: "unknown" }),
    ]);
    expect(card?.sentence).toBe("First fault.");
  });

  it("ignores a kind on a step that did not fail", () => {
    expect(providerFailureCard([{ status: "completed", errorKind: "auth" }])).toBeNull();
  });

  it("never renders an empty card", () => {
    // An empty bubble is the exact failure shape this whole path removes.
    const card = providerFailureCard([{ status: "failed", errorKind: "too_long", error: "   " }]);
    expect(card?.sentence).toBe("The model provider could not complete this request.");
  });
});
