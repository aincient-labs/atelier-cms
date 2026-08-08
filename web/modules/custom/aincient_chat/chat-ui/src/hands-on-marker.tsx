import { HumanSetIcon } from "./icons";
import { isChatReachable } from "./console-config";

/**
 * The "set directly" / hands-on marker — a small badge that flags a studio field
 * the chat agent CANNOT reach, so it doesn't read as identical to one it can.
 *
 * Deliberately POSITIVE framing ("set directly"), not a slash-AI prohibition: the
 * point is to tell the operator "this one is yours to set here", not to scold the
 * agent. The minority is marked; an UNmarked field means chat can help.
 *
 * Whether it renders is DATA-DRIVEN, never hand-maintained: pass the field's
 * namespaced `reachKey` and the marker checks it against the injected
 * chat-reachable set ({@link isChatReachable}, derived server-side from the
 * capability whitelist). When the backend later reports that field reachable, the
 * marker hides itself — no edit here. Omit `reachKey` for a field that is
 * inherently hands-on and can never become chat-reachable (e.g. the immutable
 * Tier-0 base palette).
 *
 * Accessibility: it is a focusable `role="img"` with a real `aria-label` (not a
 * bare `title`), so it is announced and keyboard-reachable with a visible focus
 * ring. The icon is inline SVG (project convention — never an emoji).
 */

const DEFAULT_LABEL = "Set directly — not editable through chat";

export function HandsOnMarker({
  reachKey,
  label = DEFAULT_LABEL,
  text,
}: {
  /** Namespaced field key checked against the injected reachable set. Omit for a
   *  field that is inherently hands-on (never chat-reachable). */
  reachKey?: string;
  /** The announced description; defaults to the standard hands-on wording. */
  label?: string;
  /** Optional visible text beside the icon (e.g. "Set directly"). */
  text?: string;
}) {
  // Reachable → nothing to mark. Only human-only fields carry the badge.
  if (reachKey && isChatReachable(reachKey)) return null;
  return (
    <span
      className="ain-handson"
      role="img"
      aria-label={label}
      title={label}
      tabIndex={0}
    >
      <HumanSetIcon className="ain-handson__icon" />
      {text && <span className="ain-handson__text">{text}</span>}
    </span>
  );
}
