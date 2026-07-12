import { Component, type ErrorInfo, type ReactNode } from "react";
import { makeAssistantToolUI } from "@assistant-ui/react";

/**
 * Render-error containment for the console.
 *
 * React has no recovery from a throw during render: with no boundary, ANY
 * thrown error unmounts the whole tree — a blank white screen. The console's
 * sharpest edge is exactly this: switching studio/thread can re-render a
 * message-bound tool widget against a thread whose store just emptied, and
 * assistant-ui's part lookup throws mid-render ("tapClientLookup: Index N out
 * of bounds (length: 0)"). The `emitNextTick` deferrals in App.tsx avoid
 * landing in that batch, but they're timing-dependent band-aids; this boundary
 * is the net under them, so a slip degrades to a recoverable fallback — never a
 * white screen.
 *
 * Two modes:
 *  - `autoReset` — clear the error on the next tick and re-render the children.
 *    Right for TRANSIENT races (a thread switch settles a frame later): the
 *    children render cleanly the second time. A small retry budget (within
 *    {@link RESET_WINDOW_MS}) stops an infinite loop if the error is persistent
 *    — after that it gives up and shows the fallback.
 *  - `resetKeys` — clear the error whenever one of these values changes (pass
 *    the active thread id, so a fresh navigation always remounts clean).
 */

const RESET_WINDOW_MS = 4000;
const DEFAULT_MAX_RESETS = 3;

type Props = {
  children: ReactNode;
  /** Shown while in the error state. A function receives a manual retry callback. */
  fallback?: ReactNode | ((retry: () => void) => ReactNode);
  /** Auto-clear the error a tick later, for transient render races. */
  autoReset?: boolean;
  /** Clear the error when any of these change (referential / Object.is compare). */
  resetKeys?: readonly unknown[];
  /** Auto-resets allowed within the window before giving up (default 3). */
  maxResets?: number;
  /** Tag for console diagnostics. */
  label?: string;
};

type State = { error: Error | null; gaveUp: boolean };

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null, gaveUp: false };
  private resetCount = 0;
  private windowStart = 0;
  private resetTimer: number | undefined;

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    const tag = this.props.label ? ` (${this.props.label})` : "";
    console.error(`[aincient] render error${tag}:`, error, info.componentStack);
    if (this.props.autoReset) this.scheduleAutoReset();
  }

  componentDidUpdate(prev: Props): void {
    if (this.state.error && keysChanged(prev.resetKeys, this.props.resetKeys)) {
      this.clear();
    }
  }

  componentWillUnmount(): void {
    if (this.resetTimer !== undefined) clearTimeout(this.resetTimer);
  }

  /** Defer a clear to the next macrotask, after the offending commit unwinds. */
  private scheduleAutoReset(): void {
    const now = performance.now();
    if (now - this.windowStart > RESET_WINDOW_MS) {
      this.windowStart = now;
      this.resetCount = 0;
    }
    if (this.resetCount >= (this.props.maxResets ?? DEFAULT_MAX_RESETS)) {
      // Persistent, not transient — stop retrying and show the fallback.
      this.setState({ gaveUp: true });
      return;
    }
    this.resetCount++;
    this.resetTimer = window.setTimeout(this.clear, 0);
  }

  private clear = (): void => {
    this.resetTimer = undefined;
    this.setState({ error: null, gaveUp: false });
  };

  render(): ReactNode {
    if (this.state.error) {
      // While an auto-reset is pending we render nothing (the retry is about to
      // re-mount the children) — no fallback flicker on a transient race.
      if (this.props.autoReset && !this.state.gaveUp) return null;
      const { fallback } = this.props;
      return typeof fallback === "function" ? fallback(this.clear) : (fallback ?? null);
    }
    return this.props.children;
  }
}

function keysChanged(a: readonly unknown[] | undefined, b: readonly unknown[] | undefined): boolean {
  if (a === b) return false;
  if (!a || !b || a.length !== b.length) return true;
  return a.some((v, i) => !Object.is(v, b[i]));
}

/**
 * `makeAssistantToolUI`, but the widget's rendered output is wrapped in an
 * auto-resetting {@link ErrorBoundary}. A throw inside one widget's body (a
 * malformed payload, a bad enum) then renders that ONE widget as nothing
 * instead of taking down the whole transcript — the same bail-to-null
 * philosophy the widgets already follow for missing payload fields, made
 * structural. Drop-in: identical signature and return type.
 */
type ToolUIConfig<TArgs, TResult> = Parameters<typeof makeAssistantToolUI<TArgs, TResult>>[0];

export function makeSafeAssistantToolUI<TArgs, TResult>(config: ToolUIConfig<TArgs, TResult>) {
  // `render` is a component type (function OR class), so render it as an element
  // rather than calling it — matching how assistant-ui mounts it internally.
  const Render = config.render;
  if (!Render) return makeAssistantToolUI<TArgs, TResult>(config);
  return makeAssistantToolUI<TArgs, TResult>({
    ...config,
    render: (props) => (
      <ErrorBoundary autoReset label={`tool:${config.toolName}`}>
        <Render {...props} />
      </ErrorBoundary>
    ),
  });
}
