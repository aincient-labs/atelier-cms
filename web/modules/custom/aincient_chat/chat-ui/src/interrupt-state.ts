/**
 * Hand-off between the HITL choice widget and the send adapter.
 *
 * Answering an interrupt IS a user turn: the widget stages the structured
 * answer here, then appends a plain user message ("Approve") to the thread.
 * The adapter's next run finds the staged answer and POSTs it to the
 * interrupt-resolve endpoint instead of the chat endpoint — so the resumed
 * workflow streams into a brand-new assistant message (thinking indicator,
 * collapsible node trail, result text), exactly like any other turn.
 *
 * One staged answer per thread: FlowDrop allows a single pending interrupt
 * per session, and the stage is consumed by the very next run.
 */

export type StagedInterruptAnswer = {
  uuid: string;
  response: string | string[] | boolean;
};

const staged = new Map<string, StagedInterruptAnswer>();

/** Stage an answer for the thread's next run (called by the widget on click). */
export function stageInterruptAnswer(threadId: string, answer: StagedInterruptAnswer): void {
  staged.set(threadId, answer);
}

/** Consume the staged answer, if any (called by the adapter at run start). */
export function takeStagedInterruptAnswer(threadId: string): StagedInterruptAnswer | undefined {
  const answer = staged.get(threadId);
  staged.delete(threadId);
  return answer;
}
