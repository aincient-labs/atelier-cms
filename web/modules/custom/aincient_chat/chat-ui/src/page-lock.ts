/**
 * Client for the single-writer editor lock (backend {@see EditLock}).
 *
 * content_moderation gives no concurrency control, so this is what stops two
 * studios / tabs / users from clobbering one page's draft. When a page loads into
 * a studio for editing we acquire the lock; the server returns an opaque fencing
 * TOKEN we hold here and attach to every write ({@link lockToken}). If another
 * session took the pen — a second tab of ours, or another user — acquire reports
 * it held ({@link LockStatus}) and the studio offers an explicit take-over
 * (re-acquire with `force`), which mints a fresh token and stales the old one.
 *
 * State is module-global (mirrors page-state.ts) so the non-React write helpers
 * and the React studio chrome read one source of truth. Self-contained: takes
 * everything as arguments, imports nothing from page-state, so there's no cycle.
 */

import { apiUrl } from "./console-config";

/** The holder envelope the server returns (who currently holds the lock). */
export type LockHolder = {
  uid: number;
  name: string;
  studio: string;
  acquired_at: number;
  /** True when the holder is the current user (a second tab, typically). */
  mine: boolean;
};

/** acquire() outcome: we hold it now, or someone else's session does. */
export type LockStatus = "acquired" | "held_self" | "held_other";

/** The current session's lock state for the open page (null token = we don't
 *  hold it — either nothing loaded, or another session took over). */
type LockState = {
  node: string | null;
  langcode: string;
  token: string | null;
  status: LockStatus | null;
  holder: LockHolder | null;
};

let state: LockState = { node: null, langcode: "", token: null, status: null, holder: null };
const subscribers = new Set<() => void>();

/**
 * Cross-reload token carry (studio handover).
 *
 * A studio→studio handover that navigates the page (Content's "Run checks" →
 * Checks is a real navigation) drops this module's in-memory token, so the target
 * studio would re-acquire with no token and see the lock held_self — the pen it
 * legitimately holds, lost on the hop. We stash the token in sessionStorage
 * (same-tab, survives the reload; a genuine SECOND TAB gets fresh sessionStorage
 * and no token, so it still must take over explicitly — the correct multi-tab
 * behaviour). {@link acquireLock} recovers it as the carried token so
 * {@see EditLock::acquire} refreshes the studio and KEEPS the token: the lock is
 * handed over with the editor, not surrendered.
 */
const STASH_KEY = "ain_lock";

function stashKey(node: string, lc: string): string {
  return `${node}::${lc}`;
}

function persistToken(node: string, lc: string, token: string | null): void {
  try {
    if (token) sessionStorage.setItem(STASH_KEY, JSON.stringify({ k: stashKey(node, lc), token }));
    else forgetToken();
  } catch {
    /* storage unavailable (private mode / disabled) — degrade to in-memory only */
  }
}

function recallToken(node: string, lc: string): string | null {
  try {
    const raw = sessionStorage.getItem(STASH_KEY);
    if (!raw) return null;
    const v = JSON.parse(raw) as { k?: string; token?: string };
    return v.k === stashKey(node, lc) && typeof v.token === "string" ? v.token : null;
  } catch {
    return null;
  }
}

function forgetToken(): void {
  try {
    sessionStorage.removeItem(STASH_KEY);
  } catch {
    /* ignore */
  }
}

function emit(): void {
  for (const cb of subscribers) cb();
}

/** Subscribe to lock-state changes (acquire / takeover / lost / release). */
export function subscribeLock(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/** The fencing token to attach to a write, or null when we don't hold the lock. */
export function lockToken(): string | null {
  return state.token;
}

/** The current lock state (read by the studio chrome for the take-over banner). */
export function lockState(): Readonly<LockState> {
  return state;
}

/** Whether we hold the pen for the open page (a write would pass the fence). */
export function holdsLock(): boolean {
  return state.token !== null;
}

/**
 * Acquire (or report) the lock for a node+langcode in a studio.
 *
 * On 'acquired' the returned token is stored and attached to subsequent writes.
 * On 'held_self'/'held_other' no token is stored (we don't hold it) — the caller
 * shows a take-over affordance that re-invokes with `force: true`. A carried
 * token (the current one, for the same session) is sent so a same-tab studio
 * handover keeps the lock rather than reporting it held.
 */
export async function acquireLock(
  node: string,
  langcode: string | null,
  studio: string,
  opts: { force?: boolean } = {},
): Promise<LockStatus> {
  const lc = langcode ?? "";
  // Carry the token when re-acquiring the SAME (node, langcode): from in-memory
  // state normally, or — when this module was just reloaded by a studio handover
  // navigation — recovered from the same-tab stash. Either way EditLock refreshes
  // the studio and keeps the token, so the pen is handed over, not surrendered. A
  // different page starts fresh (never present a stale token for it).
  const carry =
    state.node === node && state.langcode === lc ? state.token : recallToken(node, lc);
  const data = await post(apiUrl("/page/lock/acquire"), {
    node_id: node,
    ...(lc ? { langcode: lc } : {}),
    studio,
    ...(carry ? { token: carry } : {}),
    ...(opts.force ? { force: true } : {}),
  });
  const status = (data.status as LockStatus) ?? "held_other";
  const token = typeof data.token === "string" ? data.token : null;
  state = {
    node,
    langcode: lc,
    token,
    status,
    holder: (data.holder as LockHolder | null) ?? null,
  };
  // Keep the stash in step: persist the live token for a same-tab handover; drop
  // it when we don't hold the pen (a recovered token that turned out stale).
  persistToken(node, lc, token);
  emit();
  return status;
}

/**
 * Release the lock for the open page (clean exit / handover done). No-ops when we
 * hold no token. Clears local state regardless so the studio returns to idle.
 */
export async function releaseLock(): Promise<void> {
  const { node, langcode, token } = state;
  if (node && token) {
    await post(apiUrl("/page/lock/release"), {
      node_id: node,
      ...(langcode ? { langcode } : {}),
      token,
    }).catch(() => undefined);
    // Only drop the stash when we actually released a held pen — NOT on the
    // no-op release loadPageIntoStudio fires before acquire, which on a fresh
    // handover reload must leave the stashed token intact for acquire to recall.
    forgetToken();
  }
  state = { node: null, langcode: "", token: null, status: null, holder: null };
  emit();
}

/**
 * Record that a write just failed the server fence (409 lock_conflict): we no
 * longer hold the pen. Keeps the node/langcode (so the take-over banner knows
 * which page) but drops the token and adopts the server-reported holder.
 */
export function markLockLost(holder: LockHolder | null): void {
  forgetToken();
  state = { ...state, token: null, status: holder?.mine ? "held_self" : "held_other", holder };
  emit();
}

/** Forget lock state without a server round-trip (e.g. switching to a new draft
 *  that has no node yet). */
export function clearLock(): void {
  forgetToken();
  state = { node: null, langcode: "", token: null, status: null, holder: null };
  emit();
}

async function post(path: string, body: Record<string, unknown>): Promise<Record<string, unknown>> {
  const res = await fetch(path, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = (await res.json().catch(() => null)) as Record<string, unknown> | null;
  if (!res.ok) {
    throw new Error((data?.error as string) ?? `HTTP ${res.status}`);
  }
  return data ?? {};
}
