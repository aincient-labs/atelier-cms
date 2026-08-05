import { useState } from "react";
import { useMessage, useThread, useThreadRuntime } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { technicalDetail } from "./console-config";
import { describeStep, describeSteps, summarizeSteps, visibleSteps } from "./step-vocabulary";
import type { NodeStep } from "./adapter";
import { providerFailureCard, type ProviderFailureCard } from "./provider-failure";
import { AlertCircleIcon, CheckIcon, ChevronDownIcon, SpinnerIcon, WorkflowIcon, WrenchIcon, XIcon } from "./icons";

/**
 * The live work trail — what the assistant is doing, in the owner's words.
 *
 * The backend relays one `node` SSE frame per executed workflow node (FlowDrop
 * JobCompletedEvent); the adapter folds them into a single synthetic
 * `aincient_progress` tool part. This widget renders that part like tool output
 * — but it is NOT the engine's job list any more: every frame goes through
 * `step-vocabulary.ts`, which turns node types into owner words and marks the
 * WIRING (chat_input, prompt_template, gateways, buffer reads) as plumbing.
 *
 * So, once a turn is DONE: plumbing is hidden, machine ids are never shown, and
 * the header names the work ("Generated an image · checked the brand") instead
 * of counting nodes — a count of internal jobs is noise, and it jitters whenever
 * a flow is edited. A turn that ran nothing but wiring leaves NO panel at all.
 *
 * WHILE IT RUNS the trail is ephemeral and shows everything, wiring included
 * (`visibleSteps`): a turn can sit ~50s inside one reasoning node with no frame
 * to show, so the early ticks are the only honest evidence the machine is
 * moving. They drop out when the turn finishes. The clock for that wait lives in
 * the thinking indicator (App.tsx), which is also the one live region.
 *
 * With the console-wide technical-detail flag on
 * (`aincient_chat.settings:features.technical_detail`) the trail becomes the
 * engine view again: every step, plus node id and node type id — the shape you
 * want when debugging a flow, and the reason those ids are still on the wire.
 *
 * A failure is the exception to all quiet: it is always shown, always expanded,
 * with the engine's error text (adapter.ts records why — a silent trail is how
 * three backend failures got reported as "it did nothing").
 */

function statusGlyph(status: string) {
  switch (status) {
    case "failed":
      return <XIcon className="ain-trail__glyph ain-trail__glyph--failed" />;
    case "interrupted":
      return <SpinnerIcon className="ain-trail__glyph ain-trail__glyph--waiting" />;
    default:
      return <CheckIcon className="ain-trail__glyph ain-trail__glyph--done" />;
  }
}

/**
 * A provider fault, said calmly, with at most one action.
 *
 * Rendered OUTSIDE the collapsible trail and always visible: the reader should not
 * have to expand an engine panel to learn that their key expired. One sentence
 * (the backend's, never the provider's wire text) plus the link that this failure
 * KIND earns — `auth` and `model_missing` go to the models form, everything else
 * says its sentence and stops.
 *
 * RETRY IS A GRANT, NOT A GUESS. `card.retry` is TRUE only when the backend said
 * so — a transient kind (rate limit, unreachable provider, unclassified) AND a turn
 * in which no tool had applied anything yet. Nothing here reads the kind to decide,
 * because re-sending a turn that half-succeeded is how you get a second page or a
 * brand applied twice (the hazard StaleTurnRecovery exists for). When the grant is
 * withheld for that reason the backend also hands over `note`, so the absence of the
 * button is explained rather than mysterious.
 *
 * THREE LOCKS AGAINST A DOUBLE SEND, all needed:
 *   1. It disarms on click and never re-arms — a card is the record of ONE past
 *      failure, not a live control.
 *   2. It is only armed on the newest turn. An old card scrolled back into view
 *      would re-send a message whose work may since have landed.
 *   3. It is disabled while a turn is running, so it cannot stack a second turn on
 *      a held lock.
 * The send itself is `thread.append` — the same path the composer, the interrupt
 * widget and the data-table actions use. There is NO resume: it is a new turn
 * carrying the same words.
 */
export function ProviderFailureNotice({ card }: { card: ProviderFailureCard }) {
  const thread = useThreadRuntime();
  const messageId = useMessage((m) => m.id);
  const running = useThread((t) => t.isRunning);
  const messages = useThread((t) => t.messages);
  const [sent, setSent] = useState(false);

  // Lock 2: only the newest turn may be re-sent, and only if we can still find
  // the words the reader actually typed.
  const index = messages.findIndex((m) => m.id === messageId);
  const isNewest = index !== -1 && index === messages.length - 1;
  const words = isNewest ? lastUserText(messages.slice(0, index)) : "";
  const canRetry = card.retry === true && words !== "";

  const retry = () => {
    // Locks 1 and 3, re-checked at the moment of the click: `disabled` is a
    // rendering, and a rendering can be one paint behind a running turn.
    if (sent || running || !canRetry) return;
    setSent(true);
    thread.append({ role: "user", content: [{ type: "text", text: words }] });
  };

  return (
    <div className="ain-provfail" data-kind={card.kind} role="status">
      <AlertCircleIcon className="ain-provfail__glyph" />
      <span className="ain-provfail__text">
        {card.sentence}
        {card.note && <span className="ain-provfail__note">{card.note}</span>}
      </span>
      {card.action && (
        <a className="ain-provfail__action" href={card.action.href}>
          {card.action.label}
        </a>
      )}
      {canRetry && (
        <button
          type="button"
          className="ain-provfail__retry"
          onClick={retry}
          disabled={sent || running}
        >
          {sent ? "Sending again…" : "Send again"}
        </button>
      )}
    </div>
  );
}

/**
 * The text of the newest user message in `earlier`, or "" if there is none.
 *
 * Retry re-sends the reader's own words, so they come from the thread rather than
 * from anything the failed turn stashed — nothing about a retry is remembered
 * anywhere, which is the point: no attempt counter, no "am I a retry" flag.
 */
function lastUserText(earlier: readonly { role: string; content: readonly unknown[] }[]): string {
  for (let i = earlier.length - 1; i >= 0; i--) {
    const message = earlier[i];
    if (message.role !== "user") continue;
    const text = message.content
      .filter((p): p is { type: "text"; text: string } => {
        const part = p as { type?: unknown; text?: unknown };
        return part.type === "text" && typeof part.text === "string";
      })
      .map((p) => p.text)
      .join("\n")
      .trim();
    return text;
  }
  return "";
}

function ProgressTrail({ steps }: { steps: NodeStep[] }) {
  const running = useMessage((m) => m.status?.type === "running");
  // NULL = "the user hasn't decided" — a failed, finished trail then opens
  // itself, while either explicit click still wins.
  const [override, setOverride] = useState<boolean | null>(null);
  const technical = technicalDetail();
  if (steps.length === 0) return null;

  const described = describeSteps(steps);
  const failures = described.filter((d) => d.step.status === "failed");
  // A PROVIDER fault, if this run had one — the card below, not a red node.
  const failure = providerFailureCard(steps);
  // Ephemeral while running (the wiring ticks too, so a long wait shows
  // movement), work-only once finished — see `visibleSteps`.
  const shown = visibleSteps(described, { running, technical });

  // Nothing worth reporting: the turn was a conversation the engine merely
  // routed. Don't leave a workflow panel under a plain reply — but DO say a
  // provider fault, which is the one thing that must never be swallowed by the
  // trail's quiet (the node that failed can easily be plumbing).
  if (shown.length === 0) {
    return failure ? <ProviderFailureNotice card={failure} /> : null;
  }

  const latest = shown[shown.length - 1];
  const summary = summarizeSteps(steps);
  // A provider fault does NOT force the engine panel open: the card above already
  // says what happened, and expanding node machinery under it would re-stage the
  // crash the card exists to replace. An explicit click still wins.
  const open = override ?? (!running && failures.length > 0 && !failure);

  const title = running
    ? "Working"
    : failure
      // Not "something went wrong": nothing in the flow did. The provider did not
      // answer, and the card says so in full.
      ? "Stopped early"
      : failures.length > 0
        ? "Something went wrong"
        : summary || "Done";
  // The trailing note next to the title: the newest step while running, the
  // failing step once stopped. Never both, never a node count.
  const note = running
    ? latest.phrase.past
    : failures.length > 0
      ? describeStep(failures[0].step).present
      : "";

  return (
    <>
      {failure && <ProviderFailureNotice card={failure} />}
    <div className="ain-trail" data-running={running || undefined} data-technical={technical || undefined}>
      <button
        type="button"
        className="ain-trail__header"
        onClick={() => setOverride(!open)}
        aria-expanded={open}
      >
        {running ? <SpinnerIcon className="ain-trail__spin" /> : <WorkflowIcon />}
        <span className="ain-trail__title">{title}</span>
        {note && <span className="ain-trail__latest">{note}</span>}
        <ChevronDownIcon className="ain-trail__chevron" data-open={open || undefined} />
      </button>
      {open && (
        <ol className="ain-trail__steps">
          {shown.map(({ step, phrase }, i) => (
            <li
              key={i}
              className="ain-trail__step"
              data-status={step.status}
              data-tool={step.tool || undefined}
              data-kind={phrase.kind}
            >
              {statusGlyph(step.status)}
              {/* Tool calls (recorded as pipeline jobs) get a wrench so tool
                  usage reads at a glance among the workflow's own steps. */}
              {step.tool && <WrenchIcon className="ain-trail__toolglyph" />}
              <span className="ain-trail__label">{phrase.past}</span>
              {/* Machine identity is a DEBUGGING affordance, not chat content —
                  it appears only under the technical-detail flag. */}
              {technical && step.nodeTypeId && (
                <span className="ain-trail__type">{step.nodeTypeId}</span>
              )}
              {technical && step.nodeId && step.nodeId !== step.nodeTypeId && (
                <span className="ain-trail__type">{step.nodeId}</span>
              )}
              {typeof step.elapsedMs === "number" && (
                <span className="ain-trail__time">
                  {step.elapsedMs >= 1000 ? `${(step.elapsedMs / 1000).toFixed(1)}s` : `${step.elapsedMs}ms`}
                </span>
              )}
              {/* A provider fault's sentence belongs to the card, not to a red
                  line inside the engine panel — showing both says it twice and
                  puts the calm version next to the alarming one. */}
              {step.error && !step.errorKind && (
                <span className="ain-trail__error">{step.error}</span>
              )}
            </li>
          ))}
        </ol>
      )}
    </div>
    </>
  );
}

/**
 * Registers the trail for the synthetic `aincient_progress` part. Mount once
 * inside the AssistantRuntimeProvider; it renders nothing itself.
 */
export const NodeProgressToolUI = makeSafeAssistantToolUI<{ steps: NodeStep[] }, unknown>({
  toolName: "aincient_progress",
  render: ({ args }) => <ProgressTrail steps={args.steps ?? []} />,
});
