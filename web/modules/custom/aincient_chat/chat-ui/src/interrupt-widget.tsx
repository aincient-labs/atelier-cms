import { useThreadRuntime } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { useState } from "react";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import { settings } from "./adapter";
import { stageInterruptAnswer } from "./interrupt-state";
import { CheckIcon, CircleQuestionIcon, ShieldCheckIcon, XIcon } from "./icons";

/**
 * Human-in-the-loop request card.
 *
 * The backend (a FlowDrop pause node) streams an `interrupt` event; the
 * adapter turns it into a `flowdrop_choice` tool-call part rendered by this
 * card: a labelled header ("Approval required" / "Input requested"), the
 * prompt, and the choices (Approve/Decline buttons for confirmations, radios
 * or checkboxes otherwise).
 *
 * Answering is a USER turn, not a widget-internal round-trip: on click the
 * card stages the structured answer (see `interrupt-state.ts`) and appends a
 * plain user message with the chosen label. The send adapter routes that run
 * to the resolve endpoint, so the resumed workflow streams into a NEW
 * assistant message — thinking indicator, collapsible node trail, result
 * text — and the conversation reads request bubble → answer bubble → outcome
 * bubble, each timestamped. The card itself just settles into a static
 * "answered" state (mirrored on reload from the persisted interrupt).
 */

type Schema = {
  type?: string;
  enum?: string[];
  enumLabels?: string[];
  enumDescriptions?: (string | null)[];
  items?: { enum?: string[]; enumLabels?: string[]; enumDescriptions?: (string | null)[] };
  multiple?: boolean;
  // Confirmation interrupts (e.g. the operator's approval guardrail).
  presentation?: string;
  confirmLabel?: string;
  declineLabel?: string;
};

/** A boolean Approve/Decline pause (FlowDrop's confirmation node). */
function isConfirmation(schema: Schema): boolean {
  return schema.presentation === "confirmation" || schema.type === "boolean";
}

/** The button labels for a confirmation schema. */
function confirmationLabels(schema: Schema): { confirm: string; decline: string } {
  return { confirm: schema.confirmLabel ?? "Yes", decline: schema.declineLabel ?? "No" };
}

type Args = {
  uuid: string;
  prompt: string;
  schema: Schema;
  threadId: string;
  // Lifecycle of the persisted interrupt, set on re-hydration: "pending",
  // "resolved", or a dismissal ("cancelled"/"expired" — the question was
  // asked but never answered, e.g. the user just typed something else).
  status?: string;
  resolved?: boolean;
  answer?: unknown;
};

type Option = { value: string; label: string; description?: string };

/** Render an answer (string, boolean, or array) as a readable string. */
function formatAnswer(answer: unknown, schema?: Schema): string {
  if (schema && isConfirmation(schema)) {
    const { confirm, decline } = confirmationLabels(schema);
    const truthy = answer === true || answer === "true" || answer === "1";
    return truthy ? confirm : decline;
  }
  if (Array.isArray(answer)) return answer.map(String).join(", ");
  return answer == null ? "" : String(answer);
}

/** Flatten an interrupt schema into renderable options + a multi-select flag. */
function optionsOf(schema: Schema): { options: Option[]; multiple: boolean } {
  const multiple = schema.type === "array" || schema.multiple === true;
  const src = multiple ? schema.items ?? {} : schema;
  const values = src.enum ?? [];
  const labels = src.enumLabels ?? [];
  const descriptions = src.enumDescriptions ?? [];
  return {
    multiple,
    options: values.map((value, i) => ({
      value,
      label: labels[i] ?? value,
      description: descriptions[i] ?? undefined,
    })),
  };
}

/** Whether a confirmation answer counts as the affirmative button. */
function isAffirmative(answer: unknown): boolean {
  return answer === true || answer === "true" || answer === "1";
}

function ChoiceWidget({ uuid, prompt, schema, threadId, status, resolved, answer }: Args) {
  const thread = useThreadRuntime();
  const confirmation = isConfirmation(schema);
  const [selected, setSelected] = useState<string[]>([]);
  // The settled outcome: re-hydrated from the persisted interrupt on reload,
  // or set the moment the user clicks (the card freezes; the answer itself
  // travels as a real user message).
  const [chosen, setChosen] = useState<{ label: string; declined: boolean } | null>(
    resolved ? { label: formatAnswer(answer, schema), declined: confirmation && !isAffirmative(answer) } : null,
  );

  const dismissed = !chosen && (status === "cancelled" || status === "expired");
  const state = chosen ? "answered" : dismissed ? "dismissed" : "pending";

  const toggle = (value: string, multiple: boolean) => {
    if (multiple) {
      setSelected((s) => (s.includes(value) ? s.filter((v) => v !== value) : [...s, value]));
    } else {
      setSelected([value]);
    }
  };

  const submit = (response: string | string[] | boolean) => {
    const label = formatAnswer(response, schema);
    const declined = confirmation && !isAffirmative(response);
    setChosen({ label, declined });
    // Stage the structured answer, then send the label as a real user turn —
    // the adapter's next run resumes the paused workflow with it. The
    // metadata marks the turn as an ACTION (the user clicked, didn't type),
    // so it renders as an event chip; on reload the backend rebuilds the
    // same chip from the persisted interrupt's resolver.
    stageInterruptAnswer(threadId, { uuid, response });
    thread.append({
      role: "user",
      content: [{ type: "text", text: label }],
      metadata: {
        custom: {
          hitlAction: {
            verb: confirmation ? (declined ? "declined" : "approved") : "chose",
            by: settings().user ?? "",
          },
        },
      },
    });
  };

  const { options, multiple } = optionsOf(schema);

  return (
    <div className="ain-hitl" data-state={state}>
      <div className="ain-hitl__head">
        {confirmation ? (
          <ShieldCheckIcon className="ain-hitl__headicon" />
        ) : (
          <CircleQuestionIcon className="ain-hitl__headicon" />
        )}
        <span className="ain-hitl__title">
          {confirmation ? "Approval required" : "Input requested"}
        </span>
        {chosen && (
          <span className="ain-hitl__badge" data-declined={chosen.declined || undefined}>
            {chosen.declined ? <XIcon /> : <CheckIcon />}
            {chosen.label}
          </span>
        )}
        {dismissed && (
          <span className="ain-hitl__badge ain-hitl__badge--muted">
            {status === "expired" ? "Expired" : "Not answered"}
          </span>
        )}
      </div>

      {/* The prompt is agent prose — render it as markdown (entities decode,
          **emphasis** works) instead of printing raw source at the owner. */}
      <div className="ain-hitl__prompt">
        <ReactMarkdown remarkPlugins={[remarkGfm]}>{prompt}</ReactMarkdown>
      </div>

      {state === "pending" && confirmation && (
        <div className="ain-hitl__actions">
          <button className="ain-btn ain-topbtn ain-topbtn--primary" onClick={() => submit(true)}>
            {confirmationLabels(schema).confirm}
          </button>
          <button className="ain-btn ain-topbtn" onClick={() => submit(false)}>
            {confirmationLabels(schema).decline}
          </button>
        </div>
      )}

      {state === "pending" && !confirmation && (
        <>
          <div className="ain-hitl__options" role={multiple ? "group" : "radiogroup"}>
            {options.map((o) => (
              <label
                key={o.value}
                className={`ain-hitl__option${selected.includes(o.value) ? " is-selected" : ""}`}
              >
                <input
                  type={multiple ? "checkbox" : "radio"}
                  name={`hitl-${uuid}`}
                  checked={selected.includes(o.value)}
                  onChange={() => toggle(o.value, multiple)}
                />
                <span className="ain-hitl__label">{o.label}</span>
                {o.description && o.description !== o.label && (
                  <span className="ain-hitl__desc">{o.description}</span>
                )}
              </label>
            ))}
          </div>
          <button
            className="ain-btn ain-topbtn ain-topbtn--primary"
            disabled={selected.length === 0}
            onClick={() => submit(multiple ? selected : selected[0])}
          >
            Submit
          </button>
        </>
      )}
    </div>
  );
}

/**
 * Registers the choice UI for the `flowdrop_choice` tool. Mount once inside the
 * AssistantRuntimeProvider; it renders nothing itself.
 */
export const FlowDropChoiceToolUI = makeSafeAssistantToolUI<Args, unknown>({
  toolName: "flowdrop_choice",
  // Keyed by interrupt uuid: lazy-loading older pages shifts message indices
  // and the runtime reuses component instances by position — without the key
  // a widget keeps the PREVIOUS card's useState (its settled answer) while
  // the props under it change to a different interrupt.
  render: ({ args }) => <ChoiceWidget key={args.uuid} {...args} />,
});
