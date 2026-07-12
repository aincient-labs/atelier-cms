import { makeSafeAssistantToolUI } from "./error-boundary";
import { ArrowDownIcon, ArrowUpIcon } from "./icons";
import { type UsageTotal, useActiveThreadUsage } from "./usage-state";

/**
 * Token-usage + cost display: a per-turn footer under each assistant message,
 * and a running session-total chip pinned just above the composer input.
 *
 * The backend relays one `usage` SSE frame per metered AI call (ai_metering's
 * record-created event; see UsageStreamSubscriber). The adapter sums them into
 * a synthetic `aincient_usage` part per turn (rendered here as the footer) and
 * into a per-thread session total (the chip). Both are live, in-session figures
 * — the authoritative lifetime total lives in ai_metering's dashboard.
 *
 * Input tokens carry an up arrow (sent to the model), output a down arrow
 * (returned). Cost shows only when at least one call reported pricing
 * (`hasCost`); a tokens-only model (or a local provider) shows tokens alone.
 * Both surfaces wear console-chrome tokens — this is operator furniture, never
 * the site's own brand tokens.
 */

/** Compact token count: 940 · 1.2k · 3.4M. */
function fmtTokens(n: number): string {
  if (n < 1000) return String(n);
  if (n < 1_000_000) {
    const k = n / 1000;
    return `${k % 1 === 0 ? k.toFixed(0) : k.toFixed(1)}k`;
  }
  const m = n / 1_000_000;
  return `${m % 1 === 0 ? m.toFixed(0) : m.toFixed(1)}M`;
}

/** Estimated USD cost: $0.04, $0.0042, or <$0.0001 for sub-tenth-cent calls. */
function fmtCost(cost: number): string {
  if (cost > 0 && cost < 0.0001) return "<$0.0001";
  return `$${cost.toFixed(cost < 0.01 ? 4 : 2)}`;
}

/** The shared metric run: ↑ input · ↓ output · (cached) · $cost. */
function UsageBits({ usage }: { usage: UsageTotal }) {
  return (
    <>
      <span className="ain-usage__metric" title="Input tokens (sent to the model)">
        <ArrowUpIcon className="ain-usage__arrow" />
        {fmtTokens(usage.input)}
      </span>
      <span className="ain-usage__metric" title="Output tokens (returned by the model)">
        <ArrowDownIcon className="ain-usage__arrow" />
        {fmtTokens(usage.output)}
      </span>
      {usage.cached > 0 && (
        <span className="ain-usage__metric ain-usage__metric--cached" title="Cached input tokens (prompt-cache hit)">
          {fmtTokens(usage.cached)} cached
        </span>
      )}
      {usage.hasCost && <span className="ain-usage__cost">{fmtCost(usage.cost)}</span>}
    </>
  );
}

function UsageFooter({ usage }: { usage: UsageTotal }) {
  if (!usage || usage.calls === 0) return null;
  return (
    <div className="ain-usage" title="Estimated tokens and cost for this turn">
      <UsageBits usage={usage} />
    </div>
  );
}

/**
 * Registers the footer for the synthetic `aincient_usage` part. Mount once
 * inside the AssistantRuntimeProvider; it renders nothing itself.
 */
export const UsageFooterToolUI = makeSafeAssistantToolUI<{ usage: UsageTotal }, unknown>({
  toolName: "aincient_usage",
  render: ({ args }) => <UsageFooter usage={args.usage} />,
});

/**
 * The running session total, pinned above the composer input. Stays hidden
 * until the first metered call of the conversation lands.
 */
export function SessionUsageChip() {
  const usage = useActiveThreadUsage();
  if (usage.calls === 0) return null;
  return (
    <div
      className="ain-usage ain-usage--session"
      title={`Session total — ${usage.calls} AI call${usage.calls === 1 ? "" : "s"} this session (resets on reload)`}
    >
      <span className="ain-usage__label">Session</span>
      <UsageBits usage={usage} />
    </div>
  );
}
