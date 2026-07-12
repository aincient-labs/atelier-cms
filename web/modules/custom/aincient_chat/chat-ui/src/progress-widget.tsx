import { useState } from "react";
import { useMessage } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import type { NodeStep } from "./adapter";
import { CheckIcon, ChevronDownIcon, SpinnerIcon, WorkflowIcon, WrenchIcon, XIcon } from "./icons";

/**
 * The live node-execution trail.
 *
 * The backend relays one `node` SSE frame per executed workflow node (FlowDrop
 * JobCompletedEvent); the adapter folds them into a single synthetic
 * `aincient_progress` tool part. This widget renders that part like tool
 * output — the chat-side cousin of the session view's job list: a compact
 * header ("Running the workflow — 7 steps") with the latest step inline, and
 * the full trail on expand. While the turn is still running it stays "live"
 * (spinner, auto-shows the newest step); once the message completes it
 * collapses into a quiet summary of what ran.
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
  const [open, setOpen] = useState(false);
  if (steps.length === 0) return null;

  const latest = steps[steps.length - 1];
  const failed = steps.some((s) => s.status === "failed");

  return (
    <div className="ain-trail" data-running={running || undefined}>
      <button
        type="button"
        className="ain-trail__header"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
      >
        {running ? <SpinnerIcon className="ain-trail__spin" /> : <WorkflowIcon />}
        <span className="ain-trail__title">
          {running ? "Running the workflow" : failed ? "Workflow ran with errors" : "Workflow ran"}
          <span className="ain-trail__count"> · {steps.length} step{steps.length === 1 ? "" : "s"}</span>
        </span>
        {running && <span className="ain-trail__latest">{latest.label}</span>}
        <ChevronDownIcon className="ain-trail__chevron" data-open={open || undefined} />
      </button>
      {open && (
        <ol className="ain-trail__steps">
          {steps.map((s, i) => (
            <li key={i} className="ain-trail__step" data-status={s.status} data-tool={s.tool || undefined}>
              {statusGlyph(s.status)}
              {/* Tool calls (recorded as pipeline jobs) get a wrench so tool
                  usage reads at a glance among the workflow's own nodes. */}
              {s.tool && <WrenchIcon className="ain-trail__toolglyph" />}
              <span className="ain-trail__label">{s.label}</span>
              {s.nodeTypeId && s.nodeTypeId !== s.label && (
                <span className="ain-trail__type">{s.nodeTypeId}</span>
              )}
              {typeof s.elapsedMs === "number" && (
                <span className="ain-trail__time">
                  {s.elapsedMs >= 1000 ? `${(s.elapsedMs / 1000).toFixed(1)}s` : `${s.elapsedMs}ms`}
                </span>
              )}
              {s.error && <span className="ain-trail__error">{s.error}</span>}
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
