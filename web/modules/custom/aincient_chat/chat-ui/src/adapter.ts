import type { ChatModelAdapter, ChatModelRunResult, ThreadMessageLike } from "@assistant-ui/react";
import { isAuditAgent, isBrandAgent, isChromeAgent, isMediaAgent, isPageAgent, rememberThreadWorkflow, selectedWorkflowId, threadWorkflow } from "./flow";
import { findAgent, type StudioCatalog } from "./studios";
import { takeStagedInterruptAnswer } from "./interrupt-state";
import { getBrandOverrides, getPendingFonts } from "./brand-state";
import { getPageDraft, getPageLang, getPageMode, getPageNode } from "./page-state";
import { getMediaDetail } from "./media-state";
import { getChromeDraft } from "./globals-state";
import { rememberThreadActivity, rememberThreadTitle } from "./thread-meta";
import { rememberThreadWorkingNode, threadWorkingNode } from "./thread-working-node";
import { clearRunStatus, setRunStatus } from "./run-status";
import { addSessionUsage, addUsage, EMPTY_USAGE, type UsageTotal } from "./usage-state";
import { apiUrl } from "./console-config";

/**
 * Adapters connect the assistant-ui runtime to a backend.
 *
 * Two adapters live here:
 *  - `mockAdapter`  — client-side canned reply, no network (frontend-first dev).
 *  - `httpAdapter`  — POSTs to Drupal's `/atelier/chat` and parses the typed SSE
 *                     event protocol (status/token/tool_call/tool_result/result/
 *                     error/done) into cumulative assistant-ui content.
 *
 * Swap is a config flag: `drupalSettings.aincientChat.mock = false` → real backend.
 */

export type AincientSettings = {
  endpoint?: string;
  /**
   * The SPA client-route base the console is mounted at (e.g. "/atelier"),
   * server-injected from the console route. Read via {@link consoleBase}; the
   * URL codec anchors every deep link and same-surface check here.
   */
  basePath?: string;
  /**
   * The JSON-API prefix every console fetch is built against — decoupled from
   * {@link basePath} so the backend can be relocated. Read via {@link apiBase}
   * / {@link apiUrl}; defaults to basePath when unset.
   */
  apiBase?: string;
  mock?: boolean;
  /** Legacy bare display name (pre-`viewer` shell). Prefer {@link viewer}. */
  user?: string;
  /**
   * Read-only identity card for the account flyout — so the operator never has
   * to visit Drupal's /user/N page. Restricted to Atelier-meaningful fields;
   * `roles` excludes the locked "Authenticated user" role. Server-injected.
   */
  viewer?: {
    /** The EARNED display name ('' until the owner offers one — study 02,
     *  Plate 14: never the machine username, never mined from the email). */
    name: string;
    email?: string | null;
    avatarUrl?: string | null;
    initial: string;
    roles?: string[];
    /** Joined date in owner words — "Jul 2" (with year when not this year). */
    since?: string;
  };
  /** Drupal's "User account menu" links (access-checked, sorted) — e.g. Log out. */
  accountMenu?: { title: string; url: string }[];
  /**
   * Whether to offer the "Studio backend" (/admin — the curated Atelier
   * landing) link in the account flyout. Server-gated on the admin-overview
   * permission so a non-admin never sees a door they can't open.
   */
  canAdmin?: boolean;
  theme?: string;
  allowThemeSwitch?: boolean;
  /**
   * The studio → agents catalog. The console is always in exactly one studio;
   * each studio owns a set of agents (FlowDrop workflows, with per-flow welcome
   * metadata) and a default a new conversation pins. Keyed by studio key (shared
   * with the front-end registry + the backend `Studio` enum).
   */
  studios?: Record<string, StudioCatalog>;
  /** The studio a fresh console session opens in. */
  defaultStudio?: string;
  /**
   * The studio keys this user is permitted to enter (General + every specialised
   * studio they hold `use aincient studio <key>` for). The authoritative access
   * gate: the switcher only offers studios in this list, so EDITOR-ONLY studios
   * (e.g. Globals, which has no server agent and is enabled client-side) are
   * gated too — not just the agent-bearing ones already filtered out of
   * `studios`. Absent (older shell) ⇒ no gate (every registered studio shown).
   */
  studioAccess?: string[];
  /**
   * First-run onboarding. Present (and `needed`) only on an unconfigured site;
   * when set, the console renders the full-screen wizard instead of the chat.
   * Injected server-side by aincient_onboarding (hook_aincient_console_settings_alter).
   */
  onboarding?: OnboardingSettings;
};

/** An AI provider (or key group) offered in the wizard's multi-connect picker. */
export type OnboardingProvider = {
  id: string;
  label: string;
  description?: string;
  /** How the connect step authenticates: an API key, or a server URL (Ollama). */
  auth?: "api_key" | "host";
  /** What this provider (or key group) can do — drives the Chat/Image badges. */
  capabilities?: { chat?: boolean; image?: boolean };
  /** The highlighted "first choice" slot (sponsorship seam). */
  recommended?: boolean;
  /** Reserved for the promotion layer — render a "Sponsored" label when true. */
  sponsored?: boolean;
  /** Already configured and ready to use. */
  usable?: boolean;
};

/** An Atelier model role — a semantic capability tier the user binds a model to. */
export type OnboardingRole = {
  id: string;
  label: string;
  description: string;
  /** Which model pool the role's picker draws from. Defaults to "chat". */
  pool?: "chat" | "image";
  /** Skippable — never blocks finishing (Vision falls back; Image is off-by-default). */
  optional?: boolean;
};

/** A connected provider's returned models + per-role suggestions (provider:model). */
export type OnboardingConnectResult = {
  models?: { chat?: Record<string, string>; image?: Record<string, string> };
  suggested?: Record<string, string>;
};

export type OnboardingSettings = {
  needed?: boolean;
  /** Multi-connect: validate + store ONE provider's credential; returns its models. */
  connectProviderUrl?: string;
  /** Multi-connect: bind role → provider:model across all connected providers. */
  finalizeUrl?: string;
  /** Legacy single-provider endpoints (the in-chat onboarding panel). */
  validateUrl?: string;
  saveUrl?: string;
  providers?: OnboardingProvider[];
  recommended?: string;
  /** The model-role taxonomy behind the per-role pickers (display order). */
  roles?: OnboardingRole[];
  /** Whether THIS user may configure AI (else show "ask your admin"). */
  canConfigure?: boolean;
  /** A re-run on a configured site (vs genuine first-run): skippable + pre-filled. */
  forced?: boolean;
  /** Admin on a configured site — the user menu may offer re-entry (no wizard shown). */
  canReenter?: boolean;
  /** Existing role bindings as `{role: "provider:model"}` — seeds the models step. */
  current?: Record<string, string>;
};

export function settings(): AincientSettings {
  const w = window as unknown as {
    aincientChat?: AincientSettings;
    drupalSettings?: { aincientChat?: AincientSettings };
  };
  // Primary: inline config the shell injects (reliable in a chrome-less page).
  // Fallback: Drupal's drupalSettings, if present.
  return w.aincientChat ?? w.drupalSettings?.aincientChat ?? {};
}

function lastUserText(messages: readonly { role: string; content: readonly unknown[] }[]): string {
  const last = [...messages].reverse().find((m) => m.role === "user");
  if (!last) return "";
  return (last.content as { type: string; text?: string }[])
    .filter((p) => p.type === "text")
    .map((p) => p.text ?? "")
    .join(" ");
}

// MOCK — streams a canned reply word-by-word. No network, no backend.
// First simulates a short workflow run (thinking pause + node trail) so the
// loading states can be shaped frontend-first.
const mockAdapter: ChatModelAdapter = {
  async *run({ messages, abortSignal }) {
    const text = lastUserText(messages);

    // Thinking pause — exercises the typing indicator.
    await new Promise((r) => setTimeout(r, 900));
    if (abortSignal.aborted) return;

    // A fake node trail — exercises the progress widget (incl. a tool step).
    const steps: NodeStep[] = [];
    const fakeNodes: [string, string, boolean?][] = [
      ["memory_read", "Read conversation memory"],
      ["agent_reason", "Reason about the request"],
      ["create_content", "Create content", true],
      ["invoke_capability", "Run the capability"],
    ];
    for (const [nodeId, label, tool] of fakeNodes) {
      await new Promise((r) => setTimeout(r, 650));
      if (abortSignal.aborted) return;
      steps.push({ nodeId, label, status: "completed", nodeTypeId: nodeId, elapsedMs: 412, ...(tool ? { tool } : {}) });
      yield { content: [progressPart(steps)] };
    }

    const reply =
      `(mock) Got it — you said: "${text}". ` +
      `I'm the Atelier operator console. Right now I'm running on a mock backend so we can ` +
      `shape the experience first. Soon I'll create content, run FlowDrop workflows, and more — ` +
      `all from this chat.`;
    let acc = "";
    for (const word of reply.split(" ")) {
      if (abortSignal.aborted) return;
      acc += (acc ? " " : "") + word;
      await new Promise((r) => setTimeout(r, 22));
      yield { content: [progressPart(steps), { type: "text", text: acc }] };
    }
  },
};

/* ------------------------------------------------------------- node progress */

/** One executed workflow node, as relayed by the backend's `node` frames. */
export type NodeStep = {
  nodeId: string;
  label: string;
  status: string;
  nodeTypeId?: string;
  elapsedMs?: number;
  error?: string;
  /** TRUE when this step was a tool call (recorded as a pipeline job). */
  tool?: boolean;
};

/**
 * The live execution trail as a single synthetic tool-call part. It is NOT a
 * real tool call — it reuses the tool-part channel so the trail renders inside
 * the message like tool output (see `progress-widget.tsx`). One stable id per
 * message; every yield replaces it with the grown steps list.
 */
function progressPart(steps: NodeStep[]) {
  return {
    type: "tool-call" as const,
    toolCallId: "aincient_progress",
    toolName: "aincient_progress",
    args: { steps } as unknown as JsonObject,
    argsText: "",
  };
}

/**
 * The per-turn token-usage footer as a single synthetic tool-call part — same
 * channel as the progress trail (see `progressPart`), rendered by
 * `usage-footer.tsx`. It sums every `usage` frame the turn emitted (operator
 * step + any sub-agent calls). One stable id per message; every yield replaces
 * it with the grown total. Live-only, like the trail (not re-hydrated on
 * reload).
 */
function usagePart(usage: UsageTotal) {
  return {
    type: "tool-call" as const,
    toolCallId: "aincient_usage",
    toolName: "aincient_usage",
    args: { usage } as unknown as JsonObject,
    argsText: "",
  };
}

/* --------------------------------------------------------------- SSE parsing */

type SseFrame = { event: string; data: Record<string, unknown> };

// Structurally matches assistant-ui's ReadonlyJSONObject (from assistant-stream,
// a transitive dep) without importing across the package boundary.
type JsonValue = string | number | boolean | null | readonly JsonValue[] | { readonly [k: string]: JsonValue };
type JsonObject = { readonly [k: string]: JsonValue };

/**
 * Parse a `ReadableStream` of `text/event-stream` bytes into typed frames.
 *
 * Frames are `event: <type>\n` + `data: <json>\n\n`. We buffer across chunk
 * boundaries (a frame can split mid-flush) and split on the blank-line delimiter.
 */
export async function* parseSse(
  body: ReadableStream<Uint8Array>,
  abortSignal: AbortSignal,
): AsyncGenerator<SseFrame> {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";

  try {
    while (true) {
      if (abortSignal.aborted) return;
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });

      // SSE separates events with a blank line; tolerate \r\n too.
      let sep: number;
      while ((sep = indexOfFrameEnd(buffer)) !== -1) {
        const raw = buffer.slice(0, sep);
        buffer = buffer.slice(sep).replace(/^(\r?\n){1,2}/, "");
        const frame = toFrame(raw);
        if (frame) yield frame;
      }
    }
    // Flush any trailing frame without a closing blank line.
    const frame = toFrame(buffer);
    if (frame) yield frame;
  } finally {
    reader.cancel().catch(() => {});
  }
}

function indexOfFrameEnd(buf: string): number {
  const lf = buf.indexOf("\n\n");
  const crlf = buf.indexOf("\r\n\r\n");
  if (lf === -1) return crlf;
  if (crlf === -1) return lf;
  return Math.min(lf, crlf);
}

function toFrame(raw: string): SseFrame | null {
  let event = "message";
  const dataLines: string[] = [];
  for (const line of raw.split(/\r?\n/)) {
    if (line.startsWith("event:")) {
      event = line.slice(6).trim();
    } else if (line.startsWith("data:")) {
      dataLines.push(line.slice(5).replace(/^ /, ""));
    }
    // Ignore comments (`:`) and other fields (id/retry) — we don't use them.
  }
  if (dataLines.length === 0 && event === "message") return null;
  let data: Record<string, unknown> = {};
  const payload = dataLines.join("\n");
  if (payload) {
    try {
      data = JSON.parse(payload);
    } catch {
      data = { text: payload };
    }
  }
  return { event, data };
}

/* ----------------------------------------------------- backend thread listing */

export const base = (): string => settings().endpoint ?? apiUrl("/chat");

export type ThreadSummary = {
  remoteId: string;
  title: string;
  lastActivity: number;
  status?: "regular" | "archived";
  /** Wrapped up — read-only but still listed (distinct from archived/hidden). */
  locked?: boolean;
  /** The published page a wrapped-up thread produced (for the celebration link). */
  published?: { url?: string; node?: string } | null;
  /**
   * The resource this thread is homed to (identity only, plus a display title
   * resolved at list time) — what buckets it under a Node(nid, lang) room in
   * resource-first navigation, and labels that room. NULL for General/singleton
   * threads and threads that never touched a saved node; `title` is absent when
   * the node was deleted (the console falls back to "Page {nid}").
   */
  workingNode?: { nid: number; langcode: string | null; title?: string } | null;
  /** The FlowDrop workflow this thread's session is pinned to. */
  workflow?: { id: string; label: string };
};

/** GET the current user's threads for the sidebar (lightweight metadata only). */
export async function fetchThreads(): Promise<ThreadSummary[]> {
  const res = await fetch(`${base()}/threads`, {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  if (!res.ok) return [];
  const data = (await res.json()) as { threads?: ThreadSummary[] };
  return Array.isArray(data.threads) ? data.threads : [];
}

/** Archive or unarchive a thread (hides/shows it in the main sidebar list). */
export async function archiveThread(threadId: string, archived: boolean): Promise<void> {
  await fetch(`${base()}/thread/${encodeURIComponent(threadId)}/archive`, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ archived }),
  });
}

/**
 * (Un)seal a thread — wrap it up read-only. Sealing optionally records the
 * published page so the celebration pane can link to it on a cold reload.
 * NB: the server route (`/lock`) and JSON key (`locked`) keep their legacy wire
 * names — only the client concept is renamed to "seal" (console-state-model §1).
 */
export async function sealThread(
  threadId: string,
  sealed: boolean,
  published?: { url?: string; node?: string },
): Promise<void> {
  await fetch(`${base()}/thread/${encodeURIComponent(threadId)}/lock`, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      locked: sealed,
      ...(published?.url ? { page_url: published.url } : {}),
      ...(published?.node ? { page_node: published.node } : {}),
    }),
  });
}

/**
 * Seal a thread for a fresh start on the same resource (studio-navigation.md §4).
 * The plain-clear face of the seal primitive: wraps the current thread read-only
 * (no publish milestone) and returns the resource it was homed to, so the caller
 * can open a fresh buffer on the same Node(nid, lang). The fresh thread id is
 * minted client-side; this just seals and hands back the homing.
 */
export async function clearThread(
  threadId: string,
): Promise<{ workingNode: { nid: number; langcode: string | null } | null }> {
  const res = await fetch(`${base()}/thread/${encodeURIComponent(threadId)}/clear`, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
  });
  const data = (await res.json().catch(() => null)) as {
    workingNode?: { nid: number; langcode: string | null } | null;
  } | null;
  return { workingNode: data?.workingNode ?? null };
}

/** Permanently delete a thread and all its turns. */
export async function deleteThread(threadId: string): Promise<void> {
  await fetch(`${base()}/thread/${encodeURIComponent(threadId)}`, {
    method: "DELETE",
    credentials: "same-origin",
  });
}

/** One window of a thread's history, newest-anchored. */
export type ThreadPage = {
  messages: ThreadMessageLike[];
  /** Whether turns OLDER than this window remain on the server. */
  hasMore: boolean;
  /** The oldest turn id in the window — the `before` cursor for the next page up. */
  oldestId?: string;
};

/**
 * GET a window of a thread's persisted turns, mapped to assistant-ui messages.
 *
 * Newest-anchored: no opts → everything; `limit` → the newest N turns;
 * `before` → the N turns preceding that turn id (older-page fetches while
 * the user scrolls up; see thread-pages.ts).
 */
export async function fetchThreadPage(
  threadId: string,
  opts: { limit?: number; before?: string } = {},
): Promise<ThreadPage> {
  const params = new URLSearchParams();
  if (opts.limit) params.set("limit", String(opts.limit));
  if (opts.before) params.set("before", opts.before);
  const query = params.size ? `?${params}` : "";
  const res = await fetch(`${base()}/thread/${encodeURIComponent(threadId)}/events${query}`, {
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  if (!res.ok) return { messages: [], hasMore: false };
  type Turn = {
    id: number | string;
    role: string;
    text: string;
    created?: number;
    // HITL answers are actions, not typed chat: `kind` marks them, `verb` is
    // approved/declined/chose, `by` is whoever resolved the interrupt (it can
    // be answered outside this thread, e.g. from a pending-interrupts inbox).
    kind?: string;
    verb?: string;
    by?: string;
    interrupt?: { uuid: string; prompt: string; schema: JsonObject; status?: string; resolved: boolean; answer?: unknown };
    // Generative-UI widgets to re-render inline (e.g. a weather card): each is a
    // registered tool name + its payload. The live stream delivers these as
    // tool_call frames; on reload the backend hands them back so the same card
    // reappears above the prose. See SessionThreadStore + weather-widget.tsx.
    widgets?: { widget: string; payload: JsonObject }[];
  };
  const data = (await res.json()) as { turns?: Turn[]; has_more?: boolean };
  const turns = Array.isArray(data.turns) ? data.turns : [];
  // Is this thread HOMED to a saved page (server truth from the /threads listing,
  // loaded before any history fetch)? A homed thread's node room loads the saved
  // page from the server on entry (beginDocLoad → loadPageIntoStudio), so its
  // replayed page_preview ops must NOT re-apply on top (page ops aren't idempotent
  // — that double-applies + reskins). An UN-homed thread (never saved) has no
  // server copy, so replaying its ops from the empty draft is the ONLY thing that
  // rebuilds the preview — that replay must still run. page-preview-tool.tsx reads
  // `__homed` (with `__historical`) to tell the two apart. See homed-vs-unhomed.
  const homed = !!threadWorkingNode(threadId);
  const messages = turns
    .filter((t) => t.role === "user" || t.role === "assistant")
    .map((t): ThreadMessageLike => {
      // Backend sends unix seconds; assistant-ui wants a Date.
      const createdAt = t.created ? new Date(t.created * 1000) : undefined;
      // Re-hydrate a HITL request: rebuild the same `flowdrop_choice` part the
      // live stream produces, so the widget reappears on reload — interactive
      // if still pending, static (showing the outcome) once settled. The
      // matching answer arrives as a plain user turn right after.
      if (t.interrupt) {
        return {
          role: "assistant",
          createdAt,
          content: [
            {
              type: "tool-call",
              toolCallId: `flowdrop_choice-${t.id}`,
              toolName: "flowdrop_choice",
              args: {
                uuid: t.interrupt.uuid,
                prompt: t.interrupt.prompt,
                schema: t.interrupt.schema,
                threadId,
                status: t.interrupt.status ?? (t.interrupt.resolved ? "resolved" : "pending"),
                resolved: t.interrupt.resolved,
                answer: (t.interrupt.answer ?? null) as JsonValue,
              },
              ...(t.interrupt.resolved
                ? { result: { selected: (t.interrupt.answer ?? null) as JsonValue } }
                : {}),
            },
          ],
        };
      }
      // Re-hydrate generative-UI widgets: rebuild the tool-call part(s) the live
      // stream produced so the card re-renders above the prose, in one bubble
      // (mirrors the adapter's `tool_call` case + snapshot ordering).
      if (t.role === "assistant" && Array.isArray(t.widgets) && t.widgets.length > 0) {
        const text = String(t.text ?? "");
        return {
          role: "assistant",
          createdAt,
          content: [
            ...t.widgets.map((w, i) => ({
              type: "tool-call" as const,
              toolCallId: `${w.widget}-${t.id}-${i}`,
              toolName: w.widget,
              // Tag rehydrated widgets HISTORICAL. Unlike the live stream (which
              // arrives as the agent acts), these are replayed from storage on
              // load / thread-switch. Widgets that mutate the live studio draft
              // (brand preview + picker) read this flag to render read-only
              // instead of re-applying old ops onto the CURRENT design — re-opening
              // a thread must never reskin the preview. See brand-preview-tool.tsx.
              // `__homed` rides along for page_preview: a homed thread's saved page
              // is loaded from the server on entry, so its replay is read-only too;
              // an un-homed draft has no server copy, so its replay must rebuild.
              args: { ...w.payload, __historical: true, __homed: homed },
            })),
            ...(text ? [{ type: "text" as const, text }] : []),
          ],
        };
      }
      // A resolved-interrupt action renders as an event chip, not a bubble —
      // the metadata flag routes it to the ActionEvent component.
      if (t.kind === "hitl_answer") {
        return {
          role: "user",
          createdAt,
          content: [{ type: "text", text: String(t.text ?? "") }],
          metadata: { custom: { hitlAction: { verb: t.verb ?? "chose", by: t.by ?? "" } } },
        };
      }
      return {
        role: t.role as "user" | "assistant",
        createdAt,
        content: [{ type: "text", text: String(t.text ?? "") }],
      };
    });
  return {
    messages,
    hasMore: data.has_more === true,
    oldestId: turns.length ? String(turns[0].id) : undefined,
  };
}

/**
 * The live brand preview draft to send as per-turn context: the unsaved token
 * overrides (keyed by css-var) + any staged web fonts. Returns undefined when
 * the draft is empty, so a clean preview sends no context.
 */
function brandDraftContext(): { overrides: Record<string, string>; fonts: string[] } | undefined {
  const overrides = getBrandOverrides();
  const fonts = getPendingFonts() ?? [];
  if (Object.keys(overrides).length === 0 && fonts.length === 0) return undefined;
  return { overrides, fonts };
}

/**
 * The live page draft to send as per-turn context for the page agent: the whole
 * working page-schema the user is previewing in the studio. The backend shows it
 * to the agent as the LIVE PAGE STATE (so ops target the right section ids).
 * When the studio is editing a translation (not the source language) we also
 * carry the active `langcode` + layout `mode`, so the agent knows to translate
 * the copy into that language (and that structure is locked in symmetric mode).
 * Returns undefined for an empty draft so a fresh page sends no context.
 */
function pageDraftContext():
  | { schema: ReturnType<typeof getPageDraft>; langcode?: string; mode?: string }
  | undefined {
  const schema = getPageDraft();
  if (!schema) return undefined;
  const sections = Array.isArray(schema.sections) ? schema.sections : [];
  if (schema.type !== "blog" && sections.length === 0) return undefined;
  const langcode = getPageLang();
  const mode = getPageMode();
  return {
    schema,
    ...(langcode ? { langcode } : {}),
    ...(mode ? { mode } : {}),
  };
}

/**
 * The live chrome draft to send as per-turn context for the chrome agent: the
 * whole Globals working draft (identity + header/footer layout + menus) the user
 * is previewing. The backend renders it to the agent as the LIVE CHROME STATE
 * (so it knows the current settings to build on; menus ride along read-only).
 * Returns undefined when there's no draft yet (the studio hasn't seeded one).
 */
function chromeDraftContext(): ReturnType<typeof getChromeDraft> | undefined {
  return getChromeDraft() ?? undefined;
}

/* ---------------------------------------------------------------- HTTP adapter */

// Monotonic across the whole SPA session (NOT per-run): the live `toolCallId`
// must be unique for every tool call in the thread, because the widget tools
// (page_preview, brand_preview) de-dupe op-applies by toolCallId in a
// module-global Set. A per-run counter resets to 0 each turn, so the first
// widget of turn N collided with turn 1's (`page_preview-0`) and its ops were
// silently skipped — a followup edit never reached the preview. The reload path
// keys ids on the message id, so it's unaffected; only the live path needs this.
let liveToolSeq = 0;

// REAL — POSTs to the Drupal SSE endpoint and parses our event protocol into
// cumulative assistant-ui content (each yield carries the FULL content so far).
// The active thread's backend id is supplied by `getThreadId` (the remote
// thread-list runtime owns thread identity now; see runtime.tsx).
export function makeHttpAdapter(getThreadId: () => Promise<string>): ChatModelAdapter {
  return {
  async *run({ messages, abortSignal }) {
    const { endpoint } = settings();
    const threadId = await getThreadId();

    // An answered HITL pause: the widget staged the structured answer and
    // appended the user bubble; this run resumes the paused workflow instead
    // of starting a turn. The continuation streams the same event protocol,
    // so everything below (trail, interrupts, result) renders as a normal
    // new assistant message.
    const stagedAnswer = takeStagedInterruptAnswer(threadId);

    // Slash prefixes pin a FlowDrop lane: `/op` (or `/operator`) → the operator
    // workflow (intent-router → allow-listed capability); `/choose` → the HITL
    // ChoiceNode demo. Everything else hits the agent tier.
    const message = lastUserText(messages);
    const flow = /^\s*\/(op|operator)\b/i.test(message)
      ? "operator"
      : /^\s*\/(choose|flowdrop)\b/i.test(message)
        ? "flowdrop"
        : undefined;

    // The workflow this turn should run: the thread's pin once it has one,
    // otherwise the user's pick for a new conversation. The backend only
    // consults it when the first message creates the session — for an
    // existing thread it's a harmless no-op.
    const workflowId = threadWorkflow(threadId)?.id ?? selectedWorkflowId();

    // For the branding agent, ship the LIVE preview draft (the unsaved token
    // overrides + staged fonts the user is previewing in the studio) as
    // transient per-turn context, so the agent reasons about what's on screen
    // and builds on it. Omitted for every other flow, and when there's no draft.
    const brandContext = isBrandAgent(workflowId) ? brandDraftContext() : undefined;
    // The page agent gets the live page draft on the same per-turn channel — and
    // so does the Checks repair agent, which edits the same shared draft to stage
    // minimal fixes grounded in the actual page.
    const pageContext =
      isPageAgent(workflowId) || isAuditAgent(workflowId) ? pageDraftContext() : undefined;
    // The chrome agent gets the live Globals draft (identity + layout + menus).
    const chromeContext = isChromeAgent(workflowId) ? chromeDraftContext() : undefined;
    // The image agent gets the open media item's token so "edit this image" turns
    // resolve to an image→image call on the current item. Undefined when no item
    // is open (a pure text→image generation needs no source).
    const mediaContext =
      isMediaAgent(workflowId) ? getMediaDetail()?.token ?? undefined : undefined;
    // The page agent additionally wants the site's already-known context (brand
    // identity + header/footer nav) so creators don't restate it. We only flag
    // the turn — the backend reads that from persisted config server-side. Sent
    // for every content-studio turn (unlike page_context, which a fresh page
    // omits), since a blank page is exactly when the brief helps most. The image
    // agent gets it too: its system prompt has a SITE BRAND block so generated /
    // edited images match the site's palette, style, and mood.
    const siteContext = isPageAgent(workflowId) || isMediaAgent(workflowId) ? true : undefined;
    // Resource homing (studio-navigation.md §3.4): a page/audit turn on a SAVED
    // node carries its identity so the thread homes to that Node(nid, lang) room.
    // Identity only — the draft rides page_context. A fresh (unsaved) page has
    // no node id yet, so it homes later, on the first turn after it's saved.
    const homingNode =
      isPageAgent(workflowId) || isAuditAgent(workflowId) ? getPageNode() : null;
    const workingNode = homingNode
      ? { nid: homingNode, ...(getPageLang() ? { langcode: getPageLang() } : {}) }
      : undefined;

    const res = await fetch(
      stagedAnswer
        ? `${base()}/interrupt/${encodeURIComponent(stagedAnswer.uuid)}`
        : endpoint ?? apiUrl("/chat"),
      {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "text/event-stream" },
        credentials: "same-origin",
        body: JSON.stringify(
          stagedAnswer
            ? { response: stagedAnswer.response, thread_id: threadId }
            : {
                message,
                thread_id: threadId,
                ...(flow ? { flow } : {}),
                ...(workflowId ? { workflow: workflowId } : {}),
                ...(brandContext ? { brand_context: brandContext } : {}),
                ...(pageContext ? { page_context: pageContext } : {}),
                ...(chromeContext ? { chrome_context: chromeContext } : {}),
                ...(mediaContext ? { media_context: mediaContext } : {}),
                ...(siteContext ? { site_context: true } : {}),
                ...(workingNode ? { working_node: workingNode } : {}),
              },
        ),
        signal: abortSignal,
      },
    );

    // Sending IS activity — record it now so a fresh thread sorts to the top
    // of the sidebar (and under "Today") without waiting for the next
    // /threads refresh; the listing's server truth overwrites it later.
    if (!stagedAnswer) {
      rememberThreadActivity(threadId, Math.floor(Date.now() / 1000));
    }
    // First message of a new thread pins its flow — record it right away so
    // the speaker caption and the top-bar picker reflect it without waiting
    // for the next /threads refresh. (Not for the /choose demo lane: the
    // server pins its own demo workflow there.)
    if (!stagedAnswer && flow !== "flowdrop" && workflowId && !threadWorkflow(threadId)) {
      const ref = findAgent(workflowId);
      if (ref) rememberThreadWorkflow(threadId, ref);
    }
    // Optimistically home the thread to the node this turn worked on, so it jumps
    // into its Node room in the tree immediately (home-once mirrors the backend;
    // a resolved title arrives on the next /threads refresh if the draft has none).
    if (!stagedAnswer && workingNode) {
      const draftTitle = getPageDraft()?.title;
      rememberThreadWorkingNode(threadId, {
        nid: Number(workingNode.nid),
        langcode: workingNode.langcode ?? null,
        ...(draftTitle ? { title: draftTitle } : {}),
      });
    }

    if (!res.ok || !res.body) {
      throw new Error(`Chat request failed (HTTP ${res.status}).`);
    }

    // Assembled content. `text` is the running answer; `steps` is the live
    // node-execution trail (one synthetic progress part, first); `tools` are
    // tool-call parts rendered above the text. All re-emitted on every yield.
    let text = "";
    const steps: NodeStep[] = [];
    const tools: {
      type: "tool-call";
      toolCallId: string;
      toolName: string;
      args: JsonObject;
      argsText: string;
      result?: unknown;
    }[] = [];
    const toolIndexByName = new Map<string, number>();
    // Running token/cost total for THIS turn (the footer); the session total
    // lives in the per-thread side store.
    let usage: UsageTotal = EMPTY_USAGE;

    const snapshot = (): ChatModelRunResult => ({
      content: [
        ...(steps.length ? [progressPart(steps)] : []),
        ...tools,
        ...(text ? [{ type: "text" as const, text }] : []),
        ...(usage.calls > 0 ? [usagePart(usage)] : []),
      ],
    });

    try {
      for await (const { event, data } of parseSse(res.body, abortSignal)) {
        switch (event) {
          case "token":
            // Incremental delta — append. (Backend currently sends one chunk.)
            text += String(data.text ?? "");
            yield snapshot();
            break;
  
          case "result":
            // Authoritative final text — replace, so it's idempotent whether or
            // not a `token` frame preceded it.
            text = String(data.text ?? text);
            yield snapshot();
            break;
  
          case "tool_call": {
            const name = String(data.name ?? "tool");
            const args = (data.arguments as JsonObject) ?? {};
            const idx = tools.push({
              type: "tool-call",
              toolCallId: `${name}-${liveToolSeq++}`,
              toolName: name,
              args,
              argsText: JSON.stringify(args),
            }) - 1;
            toolIndexByName.set(name, idx);
            yield snapshot();
            break;
          }
  
          case "tool_result": {
            const name = String(data.name ?? "tool");
            const idx = toolIndexByName.get(name);
            if (idx !== undefined) tools[idx].result = data.output;
            yield snapshot();
            break;
          }
  
          case "interrupt": {
            // A human-in-the-loop pause (e.g. a FlowDrop ChoiceNode). Render it
            // as a tool-call part; `FlowDropChoiceToolUI` shows the choices and
            // owns the resolve→resume round-trip. The turn ends after `done`.
            tools.push({
              type: "tool-call",
              toolCallId: `flowdrop_choice-${liveToolSeq++}`,
              toolName: "flowdrop_choice",
              args: {
                uuid: String(data.uuid ?? ""),
                prompt: String(data.prompt ?? "Please choose:"),
                schema: (data.schema as JsonObject) ?? {},
                threadId,
              },
              argsText: "",
            });
            yield snapshot();
            break;
          }
  
          case "usage": {
            // One metered AI call (input/output/cached tokens + estimated USD
            // cost). Fold into the turn footer AND the running session total.
            const delta = {
              input: Number(data.input ?? 0),
              output: Number(data.output ?? 0),
              cached: Number(data.cached ?? 0),
              cost: Number(data.cost_usd ?? 0),
            };
            usage = addUsage(usage, delta);
            addSessionUsage(threadId, delta);
            yield snapshot();
            break;
          }

          case "error":
            throw new Error(String(data.message ?? "The assistant returned an error."));
  
          case "status":
            // Transient progress — not message content. Feeds the thinking
            // indicator via the run-status side store.
            setRunStatus(threadId, String(data.message ?? ""));
            break;
  
          case "node": {
            // One workflow node finished — grow the live execution trail.
            steps.push({
              nodeId: String(data.node_id ?? ""),
              label: String(data.label ?? data.node_id ?? "node"),
              status: String(data.status ?? "completed"),
              ...(data.node_type_id ? { nodeTypeId: String(data.node_type_id) } : {}),
              ...(typeof data.elapsed_ms === "number" ? { elapsedMs: data.elapsed_ms } : {}),
              ...(data.error ? { error: String(data.error) } : {}),
              ...(data.tool ? { tool: true } : {}),
            });
            yield snapshot();
            break;
          }
  
          case "thread_title":
            // The studio named the thread after its first exchange — rename
            // the sidebar row live (the /threads listing persists it).
            if (typeof data.title === "string" && data.title) {
              rememberThreadTitle(String(data.thread_id ?? threadId), data.title);
            }
            break;

          case "done":
          default:
            // `done` only carries thread_id; nothing to render.
            break;
        }
      }
    }
    finally {
      // The run is over (or failed/aborted) — drop the transient status line.
      clearRunStatus(threadId);
    }

    // Guard against an empty stream so the message isn't left blank.
    if (!text && steps.length === 0 && tools.length === 0) {
      yield { content: [{ type: "text", text: "" }] };
    }
  },
  };
}

export { mockAdapter };

export function isMock(): boolean {
  return settings().mock !== false;
}
