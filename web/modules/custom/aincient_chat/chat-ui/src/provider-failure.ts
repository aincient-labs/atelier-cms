/**
 * A provider fault, as the chat surface should read it — DOM-free on purpose.
 *
 * An expired key, a 429 and a 503 all used to arrive as the same thing: a red
 * FAILED node in the work trail, wearing the provider's own wire text. Three
 * separate bug reports (atelier-cms #4, #5, #6) were that one shape. A key the
 * reader can replace in thirty seconds is not a crash, and it should not look like
 * one.
 *
 * So the backend classifies the fault into a `kind` and — for the two kinds that
 * earn it — hands over the ONE action that kind deserves, already resolved to a
 * URL (`ProviderFailureSurface` in aincient_chat). This module holds the reading
 * side: pick the fault out of a run's steps, and shape it into card props.
 *
 * TWO THINGS IT DELIBERATELY DOES NOT DO:
 *   - It never decides WHICH kinds get a link. That table is server-side, tested
 *     once there; duplicating it here is how a lit affordance and a real remedy
 *     come to disagree.
 *   - It never decides whether a re-send is allowed either. RETRY EXISTS NOW, but
 *     as a grant: the frame says `error_retry` or it does not, and the backend
 *     withholds it for any turn that already applied part of its work (a second
 *     page, a brand applied twice — the hazard StaleTurnRecovery exists for). A
 *     client that inferred "429 ⇒ retryable" would re-open exactly that hole, so
 *     nothing here looks at the kind to decide.
 *
 * Kept free of React and of `window` so the props→render decision is unit-testable
 * (vitest runs `environment: "node"` here — no jsdom), the same seam as
 * `brand-preview-card.ts` and `tour-model.ts`.
 */

/** The step fields this module reads — a structural subset of `NodeStep`. */
export type FailureStepLike = {
  status: string;
  error?: string;
  errorKind?: string;
  errorAction?: { label: string; href: string };
  errorRetry?: boolean;
  errorNote?: string;
};

/** What the card renders. `action` absent = the sentence stands alone. */
export type ProviderFailureCard = {
  /** The one sentence, from the backend's `describe()` — never wire text. */
  sentence: string;
  /** Kind, for styling/telemetry hooks. Never used to derive the action. */
  kind: string;
  /** The link to offer, when this kind earns one. */
  action?: { label: string; href: string };
  /**
   * TRUE only when the frame GRANTED a re-send. Never inferred from the kind.
   */
  retry?: boolean;
  /** Why there is no Retry, when the backend chose to say so. */
  note?: string;
};

/**
 * Reads a `node` SSE frame's provider-failure fields, if it has any.
 *
 * A frame with no `error_kind` is not a provider fault — a graph mistake, a tool
 * blowing up, a plain bug — and must keep rendering as the failed node it is.
 * Returns the fields to spread onto a `NodeStep`, so the adapter has no branching
 * of its own.
 */
export function providerFailureFields(data: {
  error_kind?: unknown;
  error_action?: unknown;
  error_retry?: unknown;
  error_note?: unknown;
}): {
  errorKind?: string;
  errorAction?: { label: string; href: string };
  errorRetry?: boolean;
  errorNote?: string;
} {
  const kind = typeof data.error_kind === "string" ? data.error_kind : "";
  if (!kind) return {};
  const raw = data.error_action as { label?: unknown; url?: unknown } | undefined;
  const label = typeof raw?.label === "string" ? raw.label : "";
  const url = typeof raw?.url === "string" ? raw.url : "";
  // Strictly `true`: a truthy-but-odd value is a wire accident, and the safe read
  // of an accident is "no re-send".
  const retry = data.error_retry === true;
  const note = typeof data.error_note === "string" ? data.error_note.trim() : "";

  return {
    errorKind: kind,
    // A label without a URL (or the reverse) is a half-built affordance: drop it
    // and keep the sentence, which is still true and still useful.
    ...(label && url ? { errorAction: { label, href: url } } : {}),
    ...(retry ? { errorRetry: true } : {}),
    ...(note ? { errorNote: note } : {}),
  };
}

/**
 * The card for this run, or NULL when nothing in it was a provider fault.
 *
 * The FIRST step carrying an `errorKind` wins: a turn stops at the fault that
 * ended it, and any later one is a consequence, not news. Selection is on the
 * KIND, not the status, on purpose. A provider fault reaches the trail two ways
 * now: a one-shot caller (`AiGateway`, `ChatCompleter`) that bypasses the reason
 * node still fails its node, but the reason node itself STOP-AND-REPORTS — it
 * catches the fault and COMPLETES carrying the classified kind on its output, so
 * the run ends cleanly with the failure as its reply (aincient_flows
 * `AincientReason`; DECISIONS 0366). Gating on `status === "failed"` would drop
 * that completed-but-failed case and lose the card, its Retry and its note. The
 * kind is the authoritative signal either way: the backend sets `error_kind`
 * only for a classified provider fault, never for a graph mistake or a plain bug,
 * which keep rendering as the failed nodes they are.
 */
export function providerFailureCard(
  steps: readonly FailureStepLike[],
): ProviderFailureCard | null {
  const step = steps.find((s) => Boolean(s.errorKind));
  if (!step) return null;
  const sentence = (step.error ?? "").trim();

  return {
    // Never blank: an empty card is the empty-bubble failure this whole path was
    // built to remove.
    sentence: sentence || "The model provider could not complete this request.",
    kind: step.errorKind as string,
    ...(step.errorAction ? { action: step.errorAction } : {}),
    ...(step.errorRetry === true ? { retry: true } : {}),
    ...(step.errorNote ? { note: step.errorNote } : {}),
  };
}
