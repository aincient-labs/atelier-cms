import { useSyncExternalStore } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import {
  findAgent,
  serverDefaultStudio,
  studioDefaultAgent,
  studioOfAgent,
  type StudioKey,
} from "./studios";

/**
 * Console workspace state.
 *
 * The console is a STUDIO switcher: it is always in exactly one studio (the
 * workspace), the default being the server's `defaultStudio`. A studio scopes
 * the conversation history, the new-chat agent, and — for the specialised
 * studios — a live-preview editor pane. A conversation pins its AGENT (a
 * FlowDrop workflow) when its first message creates the backend session, so the
 * agent is a per-thread fact chosen at conversation start, never switched
 * mid-thread; the agent's studio is what buckets the thread in history.
 *
 * This module owns:
 *  - the thread → pinned-agent map (fed by the /threads list and by the send
 *    adapter when a first message pins a new thread),
 *  - the ACTIVE studio (the workspace), kept in sync with the open thread's
 *    pinned agent by the shell,
 *  - the per-studio agent pick for the NEXT new conversation (starts at the
 *    studio's default, then sticks to whatever the user last chose IN that
 *    studio).
 *
 * The studio CATALOG (which studios exist, their agents) lives in `studios.ts`;
 * the studio COMPONENTS (name/icon/editor) in `studio-registry.tsx`.
 */

export type WorkflowRef = {
  id: string;
  label: string;
  /** Heading shown on a fresh conversation (falls back to the console default). */
  welcomeText?: string;
  /** Line beneath the heading (falls back to the console default). */
  description?: string;
  /** Suggested prompts shown as chips (falls back to the console defaults). */
  sampleAsks?: string[];
  /** Suppress the sample-ask chips entirely — the flow takes only custom requests. */
  freeformOnly?: boolean;
};

const listeners = new Set<() => void>();
const threadFlows = new Map<string, WorkflowRef>();
/** The active studio (workspace); undefined until the user/shell sets one. */
let activeStudio: StudioKey | undefined;
/** Per-studio next-new-conversation agent pick. */
const selectedAgentByStudio = new Map<StudioKey, string>();

let version = 0;

function emit() {
  version++;
  for (const l of listeners) l();
}

export function subscribe(cb: () => void): () => void {
  listeners.add(cb);
  return () => listeners.delete(cb);
}

/** A monotonic counter bumped on every flow change — a `useSyncExternalStore`
 * snapshot for components that just need to re-render when pins/selection move. */
export function flowVersion(): number {
  return version;
}

/* ----------------------------------------------------------------- studios */

/** The active studio (workspace) — the server default until one is chosen. */
export function activeStudioKey(): StudioKey {
  return activeStudio ?? serverDefaultStudio();
}

/**
 * Set the active studio (the workspace). Idempotent. The shell calls this both
 * when the user switches studio in the breadcrumb and when an open thread's
 * pinned agent resolves to a different studio (so the workspace follows the
 * conversation you're looking at).
 */
export function setActiveStudio(key: StudioKey): void {
  if (activeStudio === key) return;
  activeStudio = key;
  emit();
}

/** Ensure a given studio is the active one (alias used by the studio widgets). */
export function ensureStudio(key: StudioKey): void {
  setActiveStudio(key);
}

/** The active studio, reactively. */
export function useActiveStudio(): StudioKey {
  return useSyncExternalStore(subscribe, activeStudioKey);
}

/* ------------------------------------------------------------------ agents */

/** The agent a NEW conversation in a studio will run (its pick, then default). */
export function selectedAgentId(key: StudioKey = activeStudioKey()): string | undefined {
  return selectedAgentByStudio.get(key) ?? studioDefaultAgent(key);
}

/** Record the next-new-conversation agent pick for a studio. */
export function selectAgent(key: StudioKey, agentId: string): void {
  if (selectedAgentByStudio.get(key) === agentId) return;
  selectedAgentByStudio.set(key, agentId);
  emit();
}

/** The agent a NEW conversation runs (the active studio's pick). */
export function selectedWorkflowId(): string | undefined {
  return selectedAgentId(activeStudioKey());
}

/** Whether an agent belongs to the Design System studio (drives per-turn brand
 *  context — the brand/Foundations agent lives there since the taxonomy reshape). */
export function isBrandAgent(id: string | undefined): boolean {
  return studioOfAgent(id) === "design_system";
}

/** Whether an agent belongs to the Content studio (drives per-turn page context). */
export function isPageAgent(id: string | undefined): boolean {
  return studioOfAgent(id) === "content";
}

/** Whether an agent belongs to the Checks studio (the repair agent) — it edits
 *  the same page draft as the page agent, so it gets the same per-turn page
 *  context to reason about and stage minimal fixes against. */
export function isAuditAgent(id: string | undefined): boolean {
  return studioOfAgent(id) === "checks";
}

/** Whether an agent belongs to the Globals studio (drives per-turn chrome context). */
export function isChromeAgent(id: string | undefined): boolean {
  return studioOfAgent(id) === "globals";
}

/** Whether an agent belongs to the Media studio (the Nano Banana image agent) —
 *  drives per-turn media context (the open item's token → image→image edits). */
export function isMediaAgent(id: string | undefined): boolean {
  return studioOfAgent(id) === "media";
}

/* ------------------------------------------------------------ thread pins */

/** The workflow a thread is pinned to, once known. */
export function threadWorkflow(threadId: string | undefined): WorkflowRef | undefined {
  return threadId ? threadFlows.get(threadId) : undefined;
}

/** The studio a thread belongs to (its pinned agent's studio, else the default). */
export function studioOfThread(threadId: string | undefined): StudioKey {
  return studioOfAgent(threadWorkflow(threadId)?.id) ?? serverDefaultStudio();
}

/** Record a thread's pinned workflow (server data wins; no-op on same value). */
export function rememberThreadWorkflow(threadId: string, ref: WorkflowRef): void {
  const prev = threadFlows.get(threadId);
  if (prev && prev.id === ref.id && prev.label === ref.label) return;
  threadFlows.set(threadId, ref);
  emit();
}

/** The active (main) thread's pinned workflow, reactively. */
export function useActiveThreadWorkflow(): WorkflowRef | undefined {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => {
      const unsubStore = subscribe(cb);
      const unsubItem = runtime.threads.mainItem.subscribe(cb);
      return () => {
        unsubStore();
        unsubItem();
      };
    },
    () => threadWorkflow(runtime.threads.mainItem.getState().remoteId),
  );
}

/* ------------------------------------------------------- new-conversation */

/** The next-new-conversation agent pick (active studio), reactively. */
export function useSelectedWorkflowId(): string | undefined {
  return useSyncExternalStore(subscribe, selectedWorkflowId);
}

/** The catalog entry for the next-new-conversation pick, reactively. */
export function useSelectedWorkflow(): WorkflowRef | undefined {
  const id = useSelectedWorkflowId();
  return findAgent(id);
}
