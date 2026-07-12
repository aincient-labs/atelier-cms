import { ExportedMessageRepository, type ThreadRuntime } from "@assistant-ui/react";
import { fetchThreadPage } from "./adapter";
import { PAGE_SIZE, seedWindow } from "./thread-pages";

/**
 * Interaction-triggered re-sync for externally-resolved interrupts.
 *
 * An approval can be answered outside this console (a pending-interrupts
 * inbox, another tab) — the open thread has no push channel, so it would only
 * notice on a full reload. Instead of polling, user INTENT is the trigger:
 * focusing/typing in the composer or clicking the scroll-down arrow calls
 * `syncPendingInterrupt`, which — only when the thread is showing a
 * still-unanswered HITL card — re-fetches the server turns and re-imports
 * them when that interrupt has settled elsewhere (or the thread grew).
 *
 * Scoping to a visible pending card matters: re-importing replaces the whole
 * message list, which drops session-ephemeral parts (live node trails). A
 * thread without a pending card never re-imports, so normal turns keep their
 * trails; a thread WITH one only re-imports when something actually changed
 * server-side — and what appears (action chip + resumed outcome) is worth
 * the trade.
 */

/** Min ms between server checks per thread — interaction events can be chatty. */
const CHECK_INTERVAL_MS = 4000;

const lastCheck = new Map<string, number>();

type ToolPart = { type: string; toolName?: string; args?: Record<string, unknown> };

/** The unanswered `flowdrop_choice` part of a thread's LAST message, if any. */
function pendingChoiceOf(messages: readonly { role: string; content: readonly unknown[] }[]): ToolPart | undefined {
  const last = messages[messages.length - 1];
  if (!last || last.role !== "assistant") return undefined;
  const part = (last.content as ToolPart[]).find(
    (p) => p.type === "tool-call" && p.toolName === "flowdrop_choice",
  );
  // A locally-answered card is followed by the action-chip user message, so
  // it can't be the last message — `resolved` only guards rehydrated state.
  return part && part.args?.resolved !== true ? part : undefined;
}

/**
 * Re-import the thread from the server if its pending interrupt settled
 * elsewhere. Safe to call from any interaction handler; throttled, and a
 * no-op while a run is in flight or no pending card is on screen.
 */
export async function syncPendingInterrupt(thread: ThreadRuntime, threadId: string | undefined): Promise<void> {
  if (!threadId || thread.getState().isRunning) return;

  const pending = pendingChoiceOf(thread.getState().messages);
  if (!pending) return;

  const now = Date.now();
  if (now - (lastCheck.get(threadId) ?? 0) < CHECK_INTERVAL_MS) return;
  lastCheck.set(threadId, now);

  // The newest page is enough: a pending interrupt is by definition at the
  // thread's tail, and what an external resolve adds lands right after it.
  const page = await fetchThreadPage(threadId, { limit: PAGE_SIZE });
  // A run may have started while we were fetching — don't stomp on it.
  if (thread.getState().isRunning) return;
  if (pendingChoiceOf(thread.getState().messages) !== pending) return;

  // Locate the same interrupt server-side: changed when it's no longer
  // pending (resolved/cancelled/expired elsewhere) or messages follow it.
  const uuid = String(pending.args?.uuid ?? "");
  let settled = false;
  let grewPast = false;
  let found = false;
  for (const message of page.messages) {
    if (found) {
      grewPast = true;
      break;
    }
    for (const part of message.content as ToolPart[]) {
      if (part.type === "tool-call" && part.toolName === "flowdrop_choice" && String(part.args?.uuid ?? "") === uuid) {
        found = true;
        settled = part.args?.resolved === true || (part.args?.status ?? "pending") !== "pending";
      }
    }
  }
  // Unknown server-side (not persisted yet?) or still pending with nothing
  // after it — leave the live state alone.
  if (!found || (!settled && !grewPast)) return;

  // Re-import the newest window and reset the edge: older pages the user had
  // scrolled in are one scroll away again, and the action lives at the tail.
  seedWindow(threadId, page);
  thread.import(ExportedMessageRepository.fromArray(page.messages));
}
