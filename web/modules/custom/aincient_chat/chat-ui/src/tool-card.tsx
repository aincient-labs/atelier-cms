import { useState } from "react";
import type { ToolCallMessagePartProps } from "@assistant-ui/react";
import { CheckIcon, SpinnerIcon } from "./icons";

/**
 * Fallback renderer for tool-call parts with no dedicated UI.
 *
 * The legacy agent lane streams `tool_call`/`tool_result` frames that the
 * adapter turns into tool parts — without this fallback they render NOTHING
 * (assistant-ui only renders registered tools), so tool usage silently
 * vanished from the chat. Rendered as a quiet mono ACTIVITY LINE (study 02,
 * Plate 9) — "✓ Generate alt text" — never a third bubble style: the
 * conversation stays two voices, and the work stays legible between them.
 * Clicking the line discloses the raw arguments/result for debugging.
 *
 * Registered via `components.tools.Fallback`, so the dedicated UIs
 * (flowdrop_choice, aincient_progress) keep precedence.
 */
export function ToolUsageCard({ toolName, args, result }: ToolCallMessagePartProps) {
  const [open, setOpen] = useState(false);
  const done = result !== undefined;

  let argsText = "";
  try {
    const t = JSON.stringify(args);
    argsText = t === "{}" ? "" : t;
  } catch {
    /* unserializable args — show nothing */
  }
  const resultText = typeof result === "string" ? result : JSON.stringify(result, null, 2);

  return (
    <div className="ain-activity" data-done={done || undefined}>
      <button
        type="button"
        className="ain-activity__line"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
      >
        {done ? (
          <CheckIcon className="ain-activity__state ain-activity__state--done" />
        ) : (
          <SpinnerIcon className="ain-activity__state ain-activity__state--busy" />
        )}
        <span className="ain-activity__name">{humanizeToolName(toolName)}</span>
      </button>
      {open && (
        <div className="ain-activity__body">
          {argsText && (
            <div className="ain-activity__section">
              <span className="ain-activity__caption">Arguments</span>
              <pre className="ain-activity__pre">{argsText}</pre>
            </div>
          )}
          {done && resultText && (
            <div className="ain-activity__section">
              <span className="ain-activity__caption">Result</span>
              <pre className="ain-activity__pre">{resultText}</pre>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * Owner words for a machine tool id: strip the module prefix, swap the
 * underscores, sentence-case ("aincient_generate_alt_text" → "Generate alt
 * text"). Best-effort — an unknown id still reads better than raw snake_case.
 */
function humanizeToolName(toolName: string): string {
  const bare = toolName.replace(/^aincient(_\w+?)?[:_](?=\w)/, "").replace(/_/g, " ").trim();
  return bare ? bare.charAt(0).toUpperCase() + bare.slice(1) : toolName;
}
