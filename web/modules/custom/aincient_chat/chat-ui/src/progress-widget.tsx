import { useState } from "react";
import { useMessage } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { technicalDetail } from "./console-config";
import { describeStep, describeSteps, summarizeSteps, visibleSteps } from "./step-vocabulary";
import type { NodeStep } from "./adapter";
import { CheckIcon, ChevronDownIcon, SpinnerIcon, WorkflowIcon, WrenchIcon, XIcon } from "./icons";

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

function ProgressTrail({ steps }: { steps: NodeStep[] }) {
  const running = useMessage((m) => m.status?.type === "running");
  // NULL = "the user hasn't decided" — a failed, finished trail then opens
  // itself, while either explicit click still wins.
  const [override, setOverride] = useState<boolean | null>(null);
  const technical = technicalDetail();
  if (steps.length === 0) return null;

  const described = describeSteps(steps);
  const failures = described.filter((d) => d.step.status === "failed");
  // Ephemeral while running (the wiring ticks too, so a long wait shows
  // movement), work-only once finished — see `visibleSteps`.
  const shown = visibleSteps(described, { running, technical });

  // Nothing worth reporting: the turn was a conversation the engine merely
  // routed. Don't leave a workflow panel under a plain reply.
  if (shown.length === 0) return null;

  const latest = shown[shown.length - 1];
  const summary = summarizeSteps(steps);
  const open = override ?? (!running && failures.length > 0);

  const title = running
    ? "Working"
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
              {step.error && <span className="ain-trail__error">{step.error}</span>}
            </li>
          ))}
        </ol>
      )}
    </div>
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
