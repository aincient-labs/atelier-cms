import { createContext, Fragment, useCallback, useContext, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import type { ComponentType, SVGProps } from "react";
import {
  AssistantRuntimeProvider,
  ThreadPrimitive,
  MessagePrimitive,
  ComposerPrimitive,
  ActionBarPrimitive,
  ThreadListPrimitive,
  ThreadListItemPrimitive,
  useAssistantRuntime,
  useComposer,
  useComposerRuntime,
  useMessage,
  useThreadList,
  useThreadListItem,
  useThreadRuntime,
  WebSpeechDictationAdapter,
} from "@assistant-ui/react";
import { MarkdownTextPrimitive } from "@assistant-ui/react-markdown";
import remarkGfm from "remark-gfm";
import { isMock, sealThread, settings } from "./adapter";
import {
  flowVersion,
  selectAgent,
  setActiveStudio,
  subscribe as subscribeFlows,
  useActiveStudio,
  useActiveThreadWorkflow,
  useSelectedWorkflow,
  useSelectedWorkflowId,
  type WorkflowRef,
} from "./flow";
import { PanelBar } from "./panel-bar";
import { AccountPane } from "./account-pane";
import { agentsForStudio, serverDefaultStudio, type StudioKey } from "./studios";
import { studioDef, studioHasEditor } from "./studio-registry";
import { visibleTiers, visibleDestinationCount, type ResolvedGroup } from "./nav-model";
import {
  activeRoom,
  roomActiveThread,
  roomAgents,
  roomBadge,
  roomIcon,
  roomOfThread,
  roomStudio,
  sameRoom,
  sectionRoom,
  COLLECTION_STUDIO,
  MEDIA_STUDIO,
  type Room,
  type ThreadRow,
} from "./rooms";
import { subscribeWorkingNodes, workingNodeVersion } from "./thread-working-node";
import { bindRuntime, consoleNav, roomVersion, subscribeRoom } from "./console-nav";
import { getPageNode, subscribePageNode } from "./page-state";
import { getAuditNode, subscribeAuditNode } from "./audit-state";
import { subscribeThreadTitles, threadActivity, threadTitle, threadTitleVersion } from "./thread-meta";
import { loadOlderPage, useActiveThreadWindowEdge } from "./thread-pages";
import { syncPendingInterrupt } from "./thread-sync";
import { useActiveThreadRunStatus } from "./run-status";
import { useAincientRuntime } from "./runtime";
import { subscribeBlockedLink } from "./preview-nav";
import { isConsoleHref, openSurface } from "./surface-nav";
import { parseUrl } from "./console-url";
import { useConsoleUrl } from "./url-sync";
import { FlowDropChoiceToolUI } from "./interrupt-widget";
import { NodeProgressToolUI } from "./progress-widget";
import { SessionUsageChip, UsageFooterToolUI } from "./usage-footer";
import { WeatherCardToolUI } from "./weather-widget";
import { BrandPickerToolUI } from "./brand-picker";
import { BrandStatusProposalToolUI } from "./brand-status-proposal";
import { BrandPreviewToolUI } from "./brand-preview-tool";
import { OnboardingToolUI } from "./onboarding";
import { StudioTourToolUI } from "./studio-tour";
import { PagePreviewToolUI } from "./page-preview-tool";
import { ChromePreviewToolUI } from "./chrome-preview-tool";
import { DataTableToolUI } from "./data-table";
import { MediaResultToolUI } from "./media-result";
import { ToolUsageCard } from "./tool-card";
import {
  MenuIcon,
  Wordmark,
  Chip,
  SparkleIcon,
  SunIcon,
  MoonIcon,
  GlobeIcon,
  PlusIcon,
  MoreHorizontalIcon,
  ArchiveIcon,
  TrashIcon,
  SendIcon,
  StopIcon,
  MicIcon,
  PersonIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  SlidersIcon,
  CopyIcon,
  CheckIcon,
  XIcon,
  ArrowDownIcon,
} from "./icons";
import { StudioUIContext, useStudioUI } from "./studio-ui";
import { NewPageForm } from "./new-page-form";
import { isPageDirty } from "./page-dirty";
import { ErrorBoundary } from "./error-boundary";
import { ThreadEndState } from "./thread-end-state";
import { getDocEnd, subscribeDocEnd } from "./doc-end-state";
import {
  getPendingWrapup,
  isThreadSealed,
  sealVersion,
  rememberThreadSeal,
  requestWrapup,
  subscribeSeals,
  subscribeWrapup,
  threadPublished,
} from "./thread-seal";
import { subscribeLock } from "./page-lock";
import { composerMode } from "./console-machine";
import { consoleBase } from "./console-config";

/* -------------------------------------------------------------- switch guard */
/**
 * A guarded thread switch: run `doSwitch` now, unless the open page/block draft
 * has unsaved edits — then stage a discard-confirm first (studio-navigation.md
 * dirty-guard). A thread switch drops the open document (url-sync clear-on-switch)
 * and can't confirm after the fact (the switch has already committed), so every
 * switch initiator that could strand an unsaved draft routes through here. The
 * default is a pass-through so a component works with no provider above it.
 */
const SwitchGuardContext = createContext<(doSwitch: () => void) => void>((fn) => fn());
const useGuardedSwitch = () => useContext(SwitchGuardContext);

/* ------------------------------------------------------------------ markdown */
/**
 * Chat links are real links. A link INTO the console (`/atelier/*`) is a
 * within-workspace move — it opens in the SAME tab (surface-nav policy), so the
 * agent can hand you to another room without spawning tabs. Everything else is
 * output: a live-site link ("view the page at /node/5") or an external URL opens
 * in a NEW tab so the conversation never navigates away mid-stream (external
 * also gets noreferrer).
 */
function ChatLink({ href, children, ...rest }: React.AnchorHTMLAttributes<HTMLAnchorElement>) {
  if (!href) return <span {...rest}>{children}</span>;
  let internal = true;
  try {
    internal = new URL(href, window.location.origin).origin === window.location.origin;
  } catch {
    /* unparsable → treat as external, be strict */
    internal = false;
  }
  const workspace = isConsoleHref(href);
  return (
    <a
      {...rest}
      href={href}
      target={workspace ? undefined : "_blank"}
      rel={internal ? "noopener" : "noopener noreferrer"}
    >
      {children}
    </a>
  );
}

function MarkdownText() {
  // remark-gfm autolinks bare URLs ("https://…") the agent emits as plain text.
  return <MarkdownTextPrimitive remarkPlugins={[remarkGfm]} components={{ a: ChatLink }} />;
}

/* ------------------------------------------------------------------- messages */
/** "2:32 PM" today, "Jun 5 · 2:32 PM" otherwise. Empty when unknown. */
function messageTime(d: Date | undefined): string {
  if (!d) return "";
  const time = d.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
  return d.toDateString() === new Date().toDateString()
    ? time
    : `${d.toLocaleDateString([], { month: "short", day: "numeric" })} · ${time}`;
}

/** The shape the HITL widget/adapter stamp on resolved-interrupt turns. */
type HitlAction = { verb: string; by?: string };

/**
 * A resolved HITL interrupt as a system-style event chip ("✓ admin approved ·
 * 8:56 PM"), centered between the bubbles. It IS a user-role message (the
 * answer resumes the workflow like any turn), but visually it's an action the
 * account performed — clicked, not typed. `by` names whoever resolved the
 * interrupt: an approval can also happen outside this thread (a pending-
 * interrupts inbox), so the actor isn't assumed to be the viewer.
 */
function ActionEvent({ action }: { action: HitlAction }) {
  const time = messageTime(useMessage((m) => m.createdAt));
  const label = useMessage((m) =>
    m.content
      .filter((p): p is { type: "text"; text: string } => p.type === "text")
      .map((p) => p.text)
      .join(" ")
      .trim(),
  );
  const declined = action.verb === "declined";
  return (
    <MessagePrimitive.Root className="ain-msg ain-msg--action">
      <div className="ain-action" data-declined={declined || undefined}>
        {declined ? <XIcon className="ain-action__icon" /> : <CheckIcon className="ain-action__icon" />}
        <span>
          <strong>{action.by || "Someone"}</strong>{" "}
          {action.verb === "chose" ? (
            <>chose <strong>{label}</strong></>
          ) : (
            action.verb
          )}
        </span>
        {time && <span className="ain-action__time">· {time}</span>}
      </div>
    </MessagePrimitive.Root>
  );
}

function UserMessage() {
  const time = messageTime(useMessage((m) => m.createdAt));
  // A HITL answer is an action, not typed chat — render the event chip.
  const action = useMessage(
    (m) => (m.metadata?.custom as { hitlAction?: HitlAction } | undefined)?.hitlAction,
  );
  if (action) return <ActionEvent action={action} />;
  // No byline, no avatar (study 02, Plate 9): the user's words sit
  // right-aligned in the ONE cinnabar-tinted surface on screen — that
  // placement + tint IS the attribution. The timestamp surfaces on hover.
  return (
    <MessagePrimitive.Root className="ain-msg ain-msg--user">
      <div className="ain-msg__col">
        <div className="ain-bubble">
          <MessagePrimitive.Parts />
        </div>
        {time && <span className="ain-msg__hovertime">{time}</span>}
      </div>
    </MessagePrimitive.Root>
  );
}

/**
 * The live "the backend is working" row: a 5-cell pixel bar + the latest
 * transient `status` frame ("Routing your request…", "Starting the FlowDrop
 * workflow…"). Shown inside the bubble while the turn runs and no answer text
 * has arrived — the FlowDrop turn executes synchronously server-side, so
 * without this the bubble sat empty (and felt stuck) for the whole run.
 *
 * The bar escalates with elapsed time (styling keyed off data-stage) so a
 * long run is visibly acknowledged instead of looping the same calm
 * animation forever: 0–10s a calm blink, 10–30s a sweep, 30s+ a hot fast
 * sweep — the logo's spectrum heating up. The stage label is only the
 * FALLBACK text: a live status frame is more specific and keeps precedence.
 * "Running", not "Thinking" — a workflow turn may not involve AI at all.
 */
const RUN_STAGES = [
  { at: 0, label: "Running…" },
  { at: 10_000, label: "Still working…" },
  { at: 30_000, label: "Heavy lifting…" },
] as const;

function ThinkingIndicator() {
  const status = useActiveThreadRunStatus();
  const [stage, setStage] = useState(0);
  useEffect(() => {
    const timers = RUN_STAGES.slice(1).map((s, i) =>
      window.setTimeout(() => setStage(i + 1), s.at),
    );
    return () => timers.forEach(clearTimeout);
  }, []);
  return (
    <div className="ain-thinking" role="status" aria-live="polite" data-stage={stage}>
      <span className="ain-thinking__cells" aria-hidden>
        <span /><span /><span /><span /><span />
      </span>
      <span className="ain-thinking__text">{status || RUN_STAGES[stage].label}</span>
    </div>
  );
}

function AssistantMessage() {
  const time = messageTime(useMessage((m) => m.createdAt));
  const running = useMessage((m) => m.status?.type === "running");
  const hasText = useMessage((m) =>
    m.content.some((p) => p.type === "text" && p.text.trim().length > 0),
  );
  // The studio signs with the A-monogram + "Atelier" — one voice, whichever
  // agent served the turn (study 02, Plate 9); the per-thread workflow stays
  // visible in the top-bar picker. Timestamps surface on hover.
  return (
    <MessagePrimitive.Root className="ain-msg ain-msg--assistant">
      <div className="ain-msg__col">
        <span className="ain-msg__name">
          <Chip className="ain-msg__mark" aria-hidden />
          <span>Atelier</span>
          {time && <span className="ain-msg__time"> · {time}</span>}
        </span>
        <div className="ain-bubble">
          {/* Dedicated tool UIs (choice widget, progress trail) keep
              precedence; the Fallback card covers every other tool part so
              tool usage never silently vanishes. */}
          <MessagePrimitive.Parts
            components={{ Text: MarkdownText, tools: { Fallback: ToolUsageCard } }}
          />
          {running && !hasText && <ThinkingIndicator />}
        </div>
        <div className="ain-actions">
          <ActionBarPrimitive.Root hideWhenRunning autohide="not-last" className="ain-actionbar">
            {/* Copies the message text via the Clipboard API; the primitive
                sets data-copied for a few seconds, which swaps in the check. */}
            <ActionBarPrimitive.Copy className="ain-btn ain-iconbtn ain-copybtn" aria-label="Copy">
              <CopyIcon className="ain-copybtn__copy" />
              <CheckIcon className="ain-copybtn__check" />
            </ActionBarPrimitive.Copy>
          </ActionBarPrimitive.Root>
        </div>
      </div>
    </MessagePrimitive.Root>
  );
}

/* ------------------------------------------------------------------ composer */
/** Fallback sample asks (a flow's sampleAsks win). Exported for the onboarding
 *  wizard, which stages the first one in the composer as its landing act. */
export const SUGGESTIONS = [
  "Build a landing page for a cozy neighborhood coffee shop",
  "Build a landing page announcing our new feature",
  "Create a landing page for a SaaS analytics tool — bold and modern",
];

/** sessionStorage key the onboarding wizard uses to hand the console a staged
 *  first ask: the wizard ends by LANDING here with the composer focused and a
 *  suggested ask pre-typed (study 02 — onboarding ends when the owner has
 *  made something, not when the form is done). */
export const STAGED_ASK_KEY = "ain-staged-first-ask";

/**
 * The "did something settle elsewhere?" hook. An interrupt answered outside
 * this console (a pending-interrupts inbox, another tab) has no push channel,
 * so user INTENT — focusing/typing in the composer, clicking the scroll-down
 * arrow — triggers a quiet re-check. No-op (throttled) unless the thread is
 * showing an unanswered HITL card; see thread-sync.ts.
 */
function useInteractionSync() {
  const runtime = useAssistantRuntime();
  const thread = useThreadRuntime();
  return () => {
    void syncPendingInterrupt(thread, runtime.threads.mainItem.getState().remoteId);
  };
}

/**
 * Mic button: voice-to-text via the browser's native Web Speech API (wired up
 * as the runtime's dictation adapter in runtime.tsx). No model, no API key —
 * recognised speech streams straight into the composer, which the user reviews
 * and sends manually. Hidden where the browser has no Web Speech support.
 */
const DICTATION_SUPPORTED = WebSpeechDictationAdapter.isSupported();

function DictateButton() {
  const composer = useComposerRuntime();
  const active = useComposer((c) => {
    const type = c.dictation?.status.type;
    return type === "starting" || type === "running";
  });
  if (!DICTATION_SUPPORTED) return null;
  return (
    <button
      type="button"
      className={"ain-composer__mic" + (active ? " ain-composer__mic--active" : "")}
      aria-label={active ? "Stop dictation" : "Dictate"}
      aria-pressed={active}
      onClick={() => (active ? composer.stopDictation() : composer.startDictation())}
    >
      <MicIcon />
    </button>
  );
}

/** Tallest the dictation height-lock will hold; matches the input's max-height. */
const DICTATION_MAX_LOCK = 200;

function Composer() {
  const checkExternal = useInteractionSync();
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const composerRuntime = useComposerRuntime();

  // The onboarding wizard's landing act: if it staged a first ask, pre-type it
  // into the focused composer (never auto-send — the first make is the owner's
  // gesture). One-shot: the key is consumed on read.
  useEffect(() => {
    let staged: string | null = null;
    try {
      staged = sessionStorage.getItem(STAGED_ASK_KEY);
      if (staged) sessionStorage.removeItem(STAGED_ASK_KEY);
    } catch {
      /* storage unavailable (private mode) — land with an empty composer */
    }
    if (!staged) return;
    composerRuntime.setText(staged);
    inputRef.current?.focus();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  // Interim speech results churn the text (lines appear, then get replaced),
  // which would make the auto-sizing textarea jump as it re-fits. While
  // dictating we lock min-height to the tallest height seen so it can only
  // grow; the lock is released once the composer empties (i.e. after send),
  // so a stopped-but-unsent dictation keeps its grown size.
  const dictating = useComposer((c) => {
    const type = c.dictation?.status.type;
    return type === "starting" || type === "running";
  });
  const isEmpty = useComposer((c) => c.isEmpty);

  useEffect(() => {
    const ta = inputRef.current;
    if (!ta) return;
    if (isEmpty) {
      ta.style.minHeight = "";
      return;
    }
    if (!dictating) return;
    const lock = () => {
      const current = ta.offsetHeight;
      const min = parseFloat(ta.style.minHeight) || 0;
      if (current > min) ta.style.minHeight = `${Math.min(current, DICTATION_MAX_LOCK)}px`;
    };
    lock();
    const observer = new ResizeObserver(lock);
    observer.observe(ta);
    return () => observer.disconnect();
  }, [dictating, isEmpty]);

  const { convoOpen, toggleConvo } = useStudioUI();
  return (
    <ComposerPrimitive.Root className="ain-composer">
      {/* Lift/drop the conversation over the preview canvas. Only shown when the
          chat is docked to the bottom (editor studio, phone width) — CSS gates
          it; inert everywhere else. */}
      <button
        type="button"
        className="ain-btn ain-iconbtn ain-composer__convotoggle"
        onClick={toggleConvo}
        aria-pressed={convoOpen}
        aria-label={convoOpen ? "Hide conversation" : "Show conversation"}
        title={convoOpen ? "Hide conversation" : "Show conversation"}
      >
        {convoOpen ? <ChevronDownIcon /> : <ChevronUpIcon />}
      </button>
      <ComposerPrimitive.Input
        ref={inputRef}
        className="ain-composer__input"
        placeholder="How can I help you today?"
        autoFocus
        rows={1}
        onFocus={checkExternal}
        onInput={checkExternal}
      />
      <DictateButton />
      <ThreadPrimitive.If running={false}>
        <ComposerPrimitive.Send className="ain-btn ain-composer__send" aria-label="Send"><SendIcon /></ComposerPrimitive.Send>
      </ThreadPrimitive.If>
      <ThreadPrimitive.If running>
        <ComposerPrimitive.Cancel className="ain-btn ain-composer__send ain-composer__send--stop" aria-label="Stop"><StopIcon /></ComposerPrimitive.Cancel>
      </ThreadPrimitive.If>
    </ComposerPrimitive.Root>
  );
}

/* -------------------------------------------------------------------- thread */
/**
 * The "load earlier messages" sentinel at the top of the viewport. A thread
 * opens on its newest page (thread-pages.ts); when older turns remain, this
 * renders a button that ALSO auto-fires as it scrolls into view — so reaching
 * the top of the history pulls the next page in.
 *
 * Scroll anchoring: the message that was on screen must stay put. The anchor
 * is captured synchronously at IMPORT time (not request time — the fetch
 * takes long enough for the user to keep scrolling): the topmost rendered
 * message's viewport offset. After the prepend, that same message sits at
 * index `added`, so once the DOM has demonstrably grown we nudge scrollTop
 * by exactly how far it moved. The rAF poll matters: a single frame can fire
 * before React commits the new messages, which corrected against the OLD
 * layout — the "scroll jumps on load" bug.
 */
function LoadEarlier({ viewportRef }: { viewportRef: React.RefObject<HTMLDivElement | null> }) {
  const runtime = useAssistantRuntime();
  const thread = useThreadRuntime();
  const edge = useActiveThreadWindowEdge();
  const sentinelRef = useRef<HTMLDivElement>(null);
  // The latest load callback, readable from the (once-mounted) observer.
  const loadRef = useRef<() => void>(() => {});

  loadRef.current = () => {
    const threadId = runtime.threads.mainItem.getState().remoteId;
    const viewport = viewportRef.current;
    if (!threadId || !viewport) return;

    let anchor: { top: number; count: number } | null = null;
    loadOlderPage(thread, threadId, () => {
      // Right before the import: where is the topmost message now?
      const messages = viewport.querySelectorAll(".ain-msg");
      if (messages.length > 0) {
        anchor = { top: messages[0].getBoundingClientRect().top, count: messages.length };
      }
    })
      .then((added) => {
        if (added <= 0 || !anchor) return;
        const { top, count } = anchor;
        // Wait until the prepended messages are actually in the DOM, then
        // shift by how far the anchored message moved. ~1s cap.
        let frames = 60;
        const settle = () => {
          const messages = viewport.querySelectorAll(".ain-msg");
          if (messages.length >= count + added) {
            const moved = messages[added].getBoundingClientRect().top - top;
            // behavior: "instant" bypasses the viewport's scroll-behavior:
            // smooth — a plain scrollTop assignment would ANIMATE the
            // correction (the visible jump-and-glide on load).
            viewport.scrollTo({ top: viewport.scrollTop + moved, behavior: "instant" });
          } else if (--frames > 0) {
            requestAnimationFrame(settle);
          }
        };
        requestAnimationFrame(settle);
      })
      .catch((e: unknown) => console.error("[aincient] load earlier failed:", e));
  };

  const hasMore = edge?.hasMore === true;
  useEffect(() => {
    const sentinel = sentinelRef.current;
    if (!hasMore || !sentinel) return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) loadRef.current();
      },
      // Start fetching a little before the user actually hits the top.
      { root: viewportRef.current, rootMargin: "200px 0px 0px 0px" },
    );
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasMore, viewportRef]);

  if (!hasMore) return null;
  return (
    <div ref={sentinelRef} className="ain-loadearlier">
      <button className="ain-btn ain-topbtn" onClick={() => loadRef.current()} disabled={edge?.loading}>
        {edge?.loading ? "Loading…" : "Load earlier messages"}
      </button>
    </div>
  );
}

/**
 * Brand wordmark — one inline SVG (spectrum chip + "Atelier" lettering) drawn
 * with `currentColor`, so the SPA renders the brand with no display font and a
 * single asset themes itself from the element's CSS `color` (see .ain-wordmark
 * → --ain-mark-ring). Inline (not <img>) so currentColor resolves.
 */
/**
 * The brand wordmark. With `onGoHome` it becomes the HOME affordance — clicking
 * it enters the General studio (the ambient operator-chat home), the way the nav
 * re-tier (increment #3b) replaces General's old bar tab. Without it, a plain mark.
 */
function BrandLogo({ className, onGoHome }: { className?: string; onGoHome?: () => void }) {
  const mark = <Wordmark className={className ? `ain-wordmark ${className}` : "ain-wordmark"} />;
  if (!onGoHome) return mark;
  return (
    <button type="button" className="ain-btn ain-brand__home" onClick={onGoHome} aria-label="Home — operator chat" title="Home">
      {mark}
    </button>
  );
}

/** The active (main) thread's title, reactively — empty until the server names it. */
function useActiveThreadTitle(): string {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().title ?? "",
  );
}

/**
 * The active (main) thread's local id, reactively. Used as the transcript
 * boundary's reset key: when the user navigates to another thread, the id
 * changes and the boundary clears any error from the switch — so a stale-index
 * throw mid-transition can never stick across the navigation that caused it.
 */
function useActiveThreadId(): string {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => runtime.threads.subscribe(cb),
    () => runtime.threads.getState().mainThreadId,
  );
}

/** The active (main) thread's backend id, reactively — "" for a fresh/unsent
 *  thread. WIP rows compare against it to mark the one you're in. */
function useActiveRemoteId(): string {
  const runtime = useAssistantRuntime();
  return useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().remoteId ?? "",
  );
}

/** True on phone-width viewports (the sidebar overlays; the section menu
 *  collapses to a dropdown). Reactive to viewport changes. */
function useIsNarrow(): boolean {
  return useSyncExternalStore(
    (cb) => {
      const m = window.matchMedia("(max-width: 768px)");
      m.addEventListener("change", cb);
      return () => m.removeEventListener("change", cb);
    },
    () => window.matchMedia("(max-width: 768px)").matches,
  );
}

function ChatThread({ onToggleSidebar }: { onToggleSidebar: () => void }) {
  const checkExternal = useInteractionSync();
  const runtime = useAssistantRuntime();
  const guardedSwitch = useGuardedSwitch();
  const viewportRef = useRef<HTMLDivElement>(null);
  // Reset key for the transcript boundary below: a thread change remounts it
  // clean, so a render race during the switch never persists past it.
  const threadId = useActiveThreadId();
  // The active thread's backend id + wrapped-up state: a sealed thread swaps the
  // composer for the celebration end-state (read-only), and a just-published
  // thread shows the cancelable wrap-up offer in the same slot.
  const remoteId = useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().remoteId ?? "",
  );
  const sealed = useSyncExternalStore(subscribeSeals, () => isThreadSealed(remoteId));
  // An archived thread is read-only history (studio-navigation.md §4): opened to
  // read, never re-entered as live. Like a lock it swaps the composer for a
  // read-only pane — the way to continue is a fresh thread on the same resource.
  const archived = useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().status === "archived",
  );
  // Already on a fresh (unsent) thread: the runtime's new-thread is a singleton,
  // so "New chat" would dry-fire (switchToThread(sameId) early-returns). Law 12 —
  // no silent controls: dim the "+" and teach the fresh-context idea on hover.
  const fresh = useSyncExternalStore(
    (cb) => runtime.threads.mainItem.subscribe(cb),
    () => runtime.threads.mainItem.getState().status === "new",
  );
  // The open page's editor lock, read from the machine's lock region (reflected
  // from page-lock by console-nav). `elsewhere`/`lost` both mean the pen isn't
  // ours — freeze the composer so a turn can't produce edits that would fail the
  // fence and be lost on take-over. subscribeLock drives the reflection (a module-
  // load subscriber that runs before this one), so the region is current here.
  const lockRegion = useSyncExternalStore(subscribeLock, () => consoleNav.lockRegion());
  const pendingWrapup = useSyncExternalStore(subscribeWrapup, getPendingWrapup);
  const pendingHere = pendingWrapup && pendingWrapup.threadId === remoteId ? pendingWrapup : null;
  const published = threadPublished(remoteId);
  // No agent in this studio's catalog (e.g. the image role came unbound) — the
  // transcript stays readable but nothing can take a turn.
  const noAgent = agentsForStudio(useActiveStudio()).length === 0;
  // The composer mode is a pure projection of thread status + the lock region
  // (INV‑4): sealed → archived → pendingWrapup → noAgent → lockElsewhere → live.
  const mode = composerMode({ sealed, archived, pendingWrapup: !!pendingHere, noAgent }, lockRegion);
  // Hoisted so they narrow to a concrete string for the optional `href`
  // (exactOptionalPropertyTypes rejects string | undefined there).
  const sealedViewUrl = published?.url;
  const pendingViewUrl = pendingHere?.published.url;
  // Commit the offered wrap-up (D8): seal the backend (which auto-archives the
  // thread out of the room in the same save) + flip locally so the row drops at
  // once, then SEAL the machine — it opens a fresh thread in this same room.
  const commitWrapup = useCallback(() => {
    if (!pendingHere) return;
    const tid = pendingHere.threadId;
    void sealThread(tid, true, pendingHere.published);
    rememberThreadSeal(tid, true, pendingHere.published);
    requestWrapup(null);
    consoleNav.seal();
  }, [pendingHere]);
  // The conversation's own head: the burger summons the thread list, the title
  // names the open chat, New starts a fresh one — the chat-specific controls
  // that used to live in the global top bar now sit on the panel they act on.
  const chatTitle = useActiveThreadTitle();
  // The fresh-thread welcome follows the flow: a pinned thread's workflow if
  // known, else the next-new-conversation pick. Empty fields fall back to the
  // console defaults; a freeform-only flow (e.g. the brand agent) shows no chips.
  const pinned = useActiveThreadWorkflow();
  const selected = useSelectedWorkflow();
  const flow = pinned ?? selected;
  const heading = flow?.welcomeText || "What would you like to create?";
  const body = flow?.description || "Pages, posts, whole sites — just say the word.";
  const asks = flow?.freeformOnly ? [] : flow?.sampleAsks?.length ? flow.sampleAsks : SUGGESTIONS;
  return (
    <ThreadPrimitive.Root className="ain-thread">
      <PanelBar
        className="ain-panelbar--chat"
        lead={
          <button className="ain-btn ain-iconbtn" onClick={onToggleSidebar} aria-label="Conversations" title="Conversations">
            <MenuIcon />
          </button>
        }
        title={chatTitle || "New chat"}
        titleClassName="ain-panelbar__title--convo"
        actions={
          <>
            {/* Which agent a new conversation in this room runs — only offered
                where the room has a real choice (see RoomAgentPicker). */}
            <RoomAgentPicker />
            {/* Not ThreadListPrimitive.New: the primitive switches on click, which
                would drop an unsaved page draft. Route through the dirty-guard. */}
            <button
              type="button"
              className="ain-btn ain-iconbtn"
              aria-label={fresh ? "You're already in a fresh chat — it starts with a clean slate" : "New chat — starts fresh, with a clean context"}
              title={fresh ? "You're already in a fresh chat — it starts with a clean slate" : "New chat — starts fresh, with a clean context"}
              disabled={fresh}
              onClick={() => guardedSwitch(() => void runtime.threads.switchToNewThread())}
            >
              <PlusIcon />
            </button>
          </>
        }
      />
      <ThreadPrimitive.Viewport className="ain-viewport" ref={viewportRef}>
        <LoadEarlier viewportRef={viewportRef} />
        <ThreadPrimitive.Empty>
          <div className="ain-welcome">
            <Chip className="ain-logo" />
            <h1>{heading}</h1>
            {body ? <p>{body}</p> : null}
            {asks.length ? (
              <div className="ain-suggestions">
                {asks.map((s) => (
                  <ThreadPrimitive.Suggestion key={s} className="ain-suggestion" prompt={s} method="replace" autoSend>
                    {s}
                  </ThreadPrimitive.Suggestion>
                ))}
              </div>
            ) : null}
          </div>
        </ThreadPrimitive.Empty>

        {/* The transcript is the console's crash-prone region: switching studio
            or thread can re-render a message-bound tool widget against a thread
            whose store just emptied, and assistant-ui's part lookup throws
            mid-render. This boundary keeps that contained to the message list —
            the shell (top bar, sidebar, composer, studio) stays alive — and
            recovers it: auto-reset rides out the one-frame race, and the
            thread-id reset key remounts clean on a real navigation. Only a
            persistent failure surfaces the "Try again" fallback. */}
        <ErrorBoundary
          label="transcript"
          autoReset
          resetKeys={[threadId]}
          fallback={(retry) => (
            <div className="ain-transcript-error" role="alert">
              <p>This conversation couldn’t be displayed.</p>
              <button className="ain-btn ain-topbtn" onClick={retry}>Try again</button>
            </div>
          )}
        >
          <ThreadPrimitive.Messages components={{ UserMessage, AssistantMessage }} />
        </ErrorBoundary>
        <div className="ain-viewport-spacer" />

        <ThreadPrimitive.ScrollToBottom className="ain-btn ain-scrollbtn" aria-label="Scroll to bottom" onClick={checkExternal}><ArrowDownIcon /></ThreadPrimitive.ScrollToBottom>
      </ThreadPrimitive.Viewport>

      <div className="ain-composer-dock">
        <SessionUsageChip />
        {mode === "sealed" ? (
          // Wrapped up (read-only) — history stays readable above; the composer
          // is gone so the finished conversation can't keep running.
          <ThreadEndState
            variant="published"
            className="ain-endstate--composer"
            actions={[
              ...(sealedViewUrl
                ? [{ label: "View page ↗", href: sealedViewUrl, onClick: () => {} }]
                : []),
              {
                label: "Start a new thread",
                primary: true,
                onClick: () => consoleNav.newThread(),
              },
            ]}
          />
        ) : mode === "archived" ? (
          // Archived (read-only history) — the transcript stays readable above;
          // to continue this line of work, start fresh (the resource has moved on).
          <div className="ain-composer-locked" role="status">
            This conversation is archived (read-only).{" "}
            <button
              type="button"
              className="ain-btn ain-linkbtn"
              onClick={() => consoleNav.newThread()}
            >
              Start a new thread
            </button>{" "}
            to keep working.
          </div>
        ) : mode === "pendingWrapup" ? (
          // First publish just happened — offer to wrap up, cancelably.
          <ThreadEndState
            variant="published"
            className="ain-endstate--composer"
            actions={[
              ...(pendingViewUrl
                ? [{ label: "View page ↗", href: pendingViewUrl, onClick: () => {} }]
                : []),
              { label: "Start a new thread", primary: true, onClick: commitWrapup },
              { label: "Keep editing", onClick: () => requestWrapup(null) },
            ]}
          />
        ) : mode === "noAgent" ? (
          // The studio's assistant is gone from the catalog (its provider came
          // unbound) — history stays readable above; the composer can't run a
          // turn, so it says why instead of silently failing.
          <div className="ain-composer-locked" role="status">
            This room’s assistant isn’t connected right now, so the conversation is
            read-only. Connect a provider to pick it back up.
          </div>
        ) : mode === "lockElsewhere" ? (
          // The open page's pen is held by another session — freeze chat so a
          // turn can't produce edits that would fail the fence and be lost. The
          // "Take over" affordance is in the studio banner alongside this.
          <div className="ain-composer-locked" role="status">
            This page is being edited in another session. Take over in the studio to make changes.
          </div>
        ) : (
          <>
            <Composer />
            <p className="ain-disclaimer">Atelier can make mistakes. Review important changes.</p>
          </>
        )}
      </div>
    </ThreadPrimitive.Root>
  );
}

/* ------------------------------------------------------------------- sidebar */
/**
 * Per-thread "⋯" dropdown (shadcn-style): the row stays all title; Archive and
 * Delete collapse into a small menu. Position is fixed (anchored to the button
 * rect) so the list's overflow scroll never clips it.
 */
function ThreadItemMenu({ remoteId }: { remoteId: string }) {
  const runtime = useAssistantRuntime();
  const [open, setOpen] = useState(false);
  const [pos, setPos] = useState({ top: 0, left: 0 });
  const btnRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  // Finish wraps the thread up read-only (the manual parallel of publish's offer);
  // the seal auto-archives it out of the room in the same save (D8) — the local
  // flip drops the row at once. If it's the thread we're in, SEAL the machine to
  // land on a fresh thread in the same room. "Reopen" is retired: to continue,
  // start fresh.
  const finish = () => {
    setOpen(false);
    if (!remoteId) return;
    void sealThread(remoteId, true);
    rememberThreadSeal(remoteId, true);
    if (runtime.threads.mainItem.getState().remoteId === remoteId) consoleNav.seal();
  };

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: PointerEvent) => {
      const t = e.target as Node;
      if (btnRef.current?.contains(t) || menuRef.current?.contains(t)) return;
      setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
    const onScroll = (e: Event) => {
      if (menuRef.current?.contains(e.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", onPointer, true);
    document.addEventListener("keydown", onKey);
    document.addEventListener("scroll", onScroll, true);
    return () => {
      document.removeEventListener("pointerdown", onPointer, true);
      document.removeEventListener("keydown", onKey);
      document.removeEventListener("scroll", onScroll, true);
    };
  }, [open]);

  const toggle = () => {
    const r = btnRef.current?.getBoundingClientRect();
    if (r) setPos({ top: r.bottom + 4, left: r.right });
    setOpen((v) => !v);
  };

  return (
    <>
      <button
        ref={btnRef}
        className="ain-btn ain-iconbtn ain-tli__more"
        data-open={open || undefined}
        onClick={toggle}
        aria-label="Thread options"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <MoreHorizontalIcon />
      </button>
      {open && (
        <div ref={menuRef} className="ain-menu" role="menu" style={{ top: pos.top, left: pos.left }}>
          <button className="ain-menu__item" role="menuitem" onClick={finish}>
            <SparkleIcon /> Finish &amp; wrap up
          </button>
          <ThreadListItemPrimitive.Archive className="ain-menu__item" role="menuitem" onClick={() => setOpen(false)}>
            <ArchiveIcon /> Archive
          </ThreadListItemPrimitive.Archive>
          <ThreadListItemPrimitive.Delete className="ain-menu__item ain-menu__item--danger" role="menuitem" onClick={() => setOpen(false)}>
            <TrashIcon /> Delete
          </ThreadListItemPrimitive.Delete>
        </div>
      )}
    </>
  );
}

/** Compact sidebar time: "now", "5m", "3h", "2d", then "Jun 2". */
function relTime(epochSec: number | undefined): string {
  if (!epochSec) return "";
  const diff = Date.now() / 1000 - epochSec;
  if (diff < 60) return "now";
  if (diff < 3600) return `${Math.floor(diff / 60)}m`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
  if (diff < 7 * 86400) return `${Math.floor(diff / 86400)}d`;
  return new Date(epochSec * 1000).toLocaleDateString([], { month: "short", day: "numeric" });
}

/**
 * Re-render whenever anything that changes a thread's room membership or the
 * active room moves: flow pins, homing, seals, the open page, the audited node.
 * The room tree derives its whole shape from these, so every room-aware view
 * subscribes to the same tick.
 */
function useRoomTick(): void {
  // The console statechart's room is the source of truth (activeRoom() reads it);
  // subscribe first so a room transition re-renders every room-aware view.
  useSyncExternalStore(subscribeRoom, roomVersion);
  useSyncExternalStore(subscribeFlows, flowVersion);
  useSyncExternalStore(subscribeWorkingNodes, workingNodeVersion);
  useSyncExternalStore(subscribeSeals, sealVersion);
  useSyncExternalStore(subscribePageNode, getPageNode);
  useSyncExternalStore(subscribeAuditNode, getAuditNode);
}

/**
 * The user's threads as room rows (regular + archived), rebuilt when the list,
 * pins, homing, or seals change. Feeds both the room tree (which Node rooms
 * exist) and room navigation (which live thread to land on).
 */
function useThreadRows(): ThreadRow[] {
  const runtime = useAssistantRuntime();
  const flowV = useSyncExternalStore(subscribeFlows, flowVersion);
  const wnV = useSyncExternalStore(subscribeWorkingNodes, workingNodeVersion);
  const sealV = useSyncExternalStore(subscribeSeals, sealVersion);
  // A stable key that changes when threads are added / removed / (un)archived.
  const idsKey = useThreadList(
    (s) => `${s.threadIds.join(",")}|${s.archivedThreadIds.join(",")}`,
  );
  return useMemo(() => {
    const s = runtime.threads.getState();
    const mk = (tid: string, archived: boolean): ThreadRow | null => {
      const remoteId = s.threadItems[tid]?.remoteId;
      return remoteId ? { remoteId, archived, sealed: isThreadSealed(remoteId) } : null;
    };
    return [
      ...s.threadIds.map((tid) => mk(tid, false)),
      ...s.archivedThreadIds.map((tid) => mk(tid, true)),
    ].filter((r): r is ThreadRow => r !== null);
    // idsKey/flowV/wnV/sealV are the reactive triggers; runtime is stable.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [runtime, idsKey, flowV, wnV, sealV]);
}

/**
 * The workspace's chat column. Hidden for an editor-only studio (no agent in the
 * catalog) — UNLESS the section already holds live conversations: history must
 * stay reachable even when a studio's agent is dropped (e.g. the image role
 * coming unbound removes the media agent, but yesterday's image threads keep
 * their transcript). In that state the COMPOSER goes read-only (`noAgent` mode)
 * — the pane itself never disappears over someone's messages. Lives as its own
 * component (inside the AssistantRuntimeProvider) because the thread rows are
 * runtime-bound.
 */
function ChatColumn({
  section,
  hasAgents,
  onToggleSidebar,
}: {
  section: StudioKey;
  hasAgents: boolean;
  onToggleSidebar: () => void;
}) {
  useRoomTick();
  const rows = useThreadRows();
  // A persisted conversation is OPEN right now (e.g. a ?thr= deep link). With
  // the studio's agent dropped its threads re-bucket to the default section
  // (studioOfThread's fallback), so the section scan below can't see them —
  // but hiding the column over an open transcript is exactly the regression.
  const activeRemote = useActiveRemoteId();
  const hasThreads =
    !hasAgents &&
    rows.some((r) => !r.sealed && !r.archived && roomStudio(roomOfThread(r.remoteId)) === section);
  if (!hasAgents && !hasThreads && !activeRemote) return null;
  return (
    <div className="ain-workspace__chat">
      <ChatThread onToggleSidebar={onToggleSidebar} />
    </div>
  );
}

/**
 * One WIP row in the section sidebar (Phase B). Rendered for EVERY regular thread
 * (via the crash-safe {@link ThreadListPrimitive.Items}) and self-filters to the
 * current SECTION — a row shows only when its home room's studio is the one we're
 * in. Its badge names the Content work-in-progress kind ("New" draft / "#<nid>"
 * page); other sections carry none. Only LIVE threads render — sealed/archived
 * history isn't listed (D8). Time order is applied via CSS `order` (most-recent
 * first) since the primitive iterates in store order.
 */
function WipRow() {
  const remoteId = useThreadListItem((t) => t.remoteId);
  const archived = useThreadListItem((t) => t.status === "archived");
  const guardedSwitch = useGuardedSwitch();
  const activeRemote = useActiveRemoteId();
  useRoomTick();
  const sealed = isThreadSealed(remoteId);
  // Not switchable / not history / not this section → no row. A thread without a
  // backend id is the active fresh composition (shown in the chat panel), not a
  // WIP row.
  if (!remoteId || sealed || archived) return null;
  const room = roomOfThread(remoteId);
  if (roomStudio(room) !== roomStudio(activeRoom())) return null;
  const active = remoteId === activeRemote;
  const badge = roomBadge(room);
  const activity = threadActivity(remoteId);
  const time = relTime(activity);
  // Not ThreadListItemPrimitive.Trigger: the primitive switches on click, which
  // would drop an unsaved page draft. A row can belong to a DIFFERENT room than
  // the one we're in (the WIP list spans the whole section), so entering it is a
  // full ENTER_ROOM (re-derives studio + doc) unless it's already the active room
  // — then a same-room thread switch suffices. The guard stages a discard-confirm
  // first if the open draft is unsaved.
  const enter = () =>
    guardedSwitch(() => {
      if (sameRoom(activeRoom(), room)) consoleNav.switchThread(remoteId);
      else consoleNav.enterRoom(room, remoteId);
    });
  return (
    <ThreadListItemPrimitive.Root
      className="ain-tli"
      data-active={active || undefined}
      // Time-order via flex order (most-recent first); the primitive renders rows
      // in store order. Epoch seconds fit a CSS integer; no activity → 0 (bottom).
      style={{ order: -(activity ?? 0) }}
    >
      <button type="button" className="ain-tli__trigger" onClick={enter}>
        {/* Title renders bare text (no element), so the ellipsis class needs our own span. */}
        <span className="ain-tli__title">
          <StudioTitle remoteId={remoteId} />
        </span>
        <span className="ain-tli__meta">
          {room.kind === "draft" && <span className="ain-tli__dot" aria-hidden />}
          {badge && (
            <span className="ain-tli__badge" data-kind={room.kind}>{badge}</span>
          )}
          {time && <span className="ain-tli__time">{badge ? `· ${time}` : time}</span>}
        </span>
      </button>
      <ThreadItemMenu remoteId={remoteId ?? ""} />
    </ThreadListItemPrimitive.Root>
  );
}

/**
 * The sidebar's date-group eyebrows — Today · This week · Earlier (study 02,
 * Plate 8). WipRows position themselves with `order: -activity`, so each
 * eyebrow is a sibling flex item whose order lands exactly at its bucket's
 * boundary: "Today" pins to the top, "This week" sorts after every today-row
 * (order > -startOfToday), "Earlier" after every this-week row. An eyebrow
 * renders only when its bucket actually holds a live row of this section,
 * so a fresh list never opens with headers over nothing.
 */
function ThreadGroups({ rows, section }: { rows: ThreadRow[]; section: StudioKey }) {
  const startToday = new Date().setHours(0, 0, 0, 0) / 1000;
  const startWeek = startToday - 6 * 86400;
  let today = false;
  let week = false;
  let earlier = false;
  for (const r of rows) {
    if (r.sealed || r.archived || roomStudio(roomOfThread(r.remoteId)) !== section) continue;
    const at = threadActivity(r.remoteId) ?? 0;
    if (at >= startToday) today = true;
    else if (at >= startWeek) week = true;
    else earlier = true;
  }
  // Solo "Today" says nothing (everything is today) — eyebrows earn their ink
  // only once the list spans more than one bucket.
  if (!week && !earlier) return null;
  return (
    <>
      {today && <div className="ain-tlgroup" style={{ order: -2147483647 }}>Today</div>}
      {week && <div className="ain-tlgroup" style={{ order: -Math.round(startToday) + 1 }}>This week</div>}
      {earlier && <div className="ain-tlgroup" style={{ order: -Math.round(startWeek) + 1 }}>Earlier</div>}
    </>
  );
}

/**
 * A WIP row's display title: the studio-given name when one has streamed in
 * this session (`thread_title` frame → thread-meta override), else the
 * runtime's list title (which is the studio name once persisted, or the
 * raw-first-message fallback for threads named before this feature).
 */
function StudioTitle({ remoteId }: { remoteId: string | undefined }) {
  useSyncExternalStore(subscribeThreadTitles, threadTitleVersion);
  const override = threadTitle(remoteId);
  return override ? <>{override}</> : <ThreadListItemPrimitive.Title fallback="New chat" />;
}

/**
 * The section sidebar (Phase B): the current section's live-thread ("WIP") list,
 * time-ordered. Sections themselves live in the header ({@link SectionMenu}) now;
 * the sidebar is scoped to whichever one you're in ({@link roomStudio}(activeRoom)).
 *
 * Each row is a live conversation in the section, badged by the Content work-in-
 * progress kind it composes ("New" draft / "#<nid>" page). Content also pins two
 * affordances on top: "+ New page" (the birth form) and "Browse pages" (the List
 * directory). Rows render through the crash-safe {@link ThreadListPrimitive.Items}
 * (each {@link WipRow} self-filters to the section); ordering is by last activity
 * via CSS `order`.
 */
function Sidebar({
  open,
  onNavigate,
  onEnterRoom,
}: {
  open: boolean;
  onNavigate: () => void;
  onEnterRoom: (room: Room, rows: ThreadRow[]) => void;
}) {
  useRoomTick();
  // The thread rows are runtime-bound, so they're computed HERE (inside the
  // AssistantRuntimeProvider) — not in App's body, which sits outside it. They
  // feed onEnterRoom's landing-thread pick AND the section's empty check.
  const rows = useThreadRows();
  const isLoading = useThreadList((s) => s.isLoading);
  const current = activeRoom();
  const section = roomStudio(current);
  const isContent = section === COLLECTION_STUDIO;
  // Empty = no LIVE thread homes to this section. Sealed/archived history isn't
  // listed (D8), so a section whose only threads are wrapped up reads as empty.
  const empty =
    !isLoading &&
    !rows.some(
      (r) => !r.sealed && !r.archived && roomStudio(roomOfThread(r.remoteId)) === section,
    );

  // The `+` birth form (studio-navigation.md §3.2). A new page is minted with a
  // title + type BEFORE the conversation, then we enter its Node room — reusing
  // navigateToRoom, which starts a fresh thread and loads the page. The thread
  // homes to the node on its first turn (adapter stamps working_node), so the
  // List stays clean: content threads are born on a node, never in limbo.
  const [creating, setCreating] = useState(false);
  const onCreated = (nid: string, langcode: string | null, title: string) => {
    setCreating(false);
    onEnterRoom({ kind: "node", doc: "page", nid: Number(nid), langcode, title }, rows);
    onNavigate();
  };
  const listRoom: Room = { kind: "list" };
  const listActive = sameRoom(current, listRoom);
  const BrowseIcon = roomIcon(listRoom);
  // The media family pins its browse room too — the Library shelf (0168).
  const isMedia = section === MEDIA_STUDIO;
  const shelfRoom: Room = { kind: "shelf" };
  const shelfActive = sameRoom(current, shelfRoom);
  const ShelfIcon = roomIcon(shelfRoom);
  return (
    <aside
      className={`ain-sidebar${open ? "" : " ain-sidebar--closed"}`}
      // Picking a thread should dismiss the overlay on mobile; rows render via a
      // provider (no per-row callback), so the close signal is read off the
      // bubbling click instead of threaded through.
      onClick={(e) => {
        if ((e.target as Element).closest(".ain-tli__trigger")) onNavigate();
      }}
    >
      {/* Content pins its two entry points on top of the WIP list: create, browse. */}
      {isContent && (
        <div className="ain-wipactions">
          <button
            type="button"
            className="ain-wipaction ain-wipaction--new"
            onClick={() => setCreating(true)}
          >
            <PlusIcon aria-hidden /> <span>New page</span>
          </button>
          <button
            type="button"
            className="ain-wipaction"
            data-active={listActive || undefined}
            aria-current={listActive ? "page" : undefined}
            onClick={() => {
              onEnterRoom(listRoom, rows);
              onNavigate();
            }}
          >
            {BrowseIcon && <BrowseIcon aria-hidden />} <span>Browse pages</span>
          </button>
        </div>
      )}
      {/* The media family pins its browse room: the Library shelf. No "+ New
          image" twin — generating is the shelf chat's verb (0168). */}
      {isMedia && (
        <div className="ain-wipactions">
          <button
            type="button"
            className="ain-wipaction"
            data-active={shelfActive || undefined}
            aria-current={shelfActive ? "page" : undefined}
            onClick={() => {
              onEnterRoom(shelfRoom, rows);
              onNavigate();
            }}
          >
            {ShelfIcon && <ShelfIcon aria-hidden />} <span>Browse the Library</span>
          </button>
        </div>
      )}
      <div className="ain-roomthreads">
        <div className="ain-roomthreads__head">{studioDef(section)?.name ?? "Chats"}</div>
        <ThreadListPrimitive.Root className="ain-threadlist">
          <div className="ain-threadlist__scroll">
            {/* Date-group eyebrows (study 02, Plate 8). Rows sort by CSS order
                (-activity), so each eyebrow is placed with an order just past
                its bucket's boundary — no interleaving logic needed — and only
                renders when its bucket has rows. */}
            <ThreadGroups rows={rows} section={section} />
            {/* Every regular thread renders a WipRow, which self-filters to the
                current section + drops sealed/archived history (D8). */}
            <ThreadListPrimitive.Items components={{ ThreadListItem: WipRow }} />
            {empty && (
              <p className="ain-threadlist__empty">
                {isContent
                  ? "No pages in progress — start one with “New page”."
                  : "No conversations here yet — start one with “New”."}
              </p>
            )}
          </div>
        </ThreadListPrimitive.Root>
      </div>
      {creating && <NewPageForm onClose={() => setCreating(false)} onCreated={onCreated} />}
    </aside>
  );
}

/**
 * The section menu (Phase B) — the studios, relocated from the sidebar into the
 * header. Each enabled+accessible studio is a section tab; the active one is the
 * studio of the room we're in ({@link roomStudio}(activeRoom)). Picking a section
 * enters its canonical room ({@link sectionRoom} — Content's is the List directory,
 * every other its singleton studio room). Picking a section always lands on that
 * canonical room, so clicking Pages while editing a node returns to the listing;
 * only re-picking the section you're already ON (its canonical room) is a no-op.
 *
 * On phone-width screens the tab row collapses to a "Section ▾" dropdown (the
 * shared CrumbMenu listbox), so the header never overflows.
 */
/** One Site-Information-style dropdown that folds several studios behind a crumb. */
function SectionGroup({
  group,
  active,
  onGo,
}: {
  group: ResolvedGroup;
  active: StudioKey | undefined;
  onGo: (studio: StudioKey) => void;
}) {
  const containsActive = group.children.some((c) => c.key === active);
  return (
    <CrumbMenu
      ariaLabel={group.name}
      className={`ain-crumb--group${containsActive ? " is-active" : ""}`}
      // A group trigger is a nav LINK like its siblings — same type, a bare ▾
      // (study 02, Plate 13). The wrench is retired, and menu rows drop their
      // icon column: two-to-five text rows don't need wayfinding pictures.
      trigger={<span className="ain-crumb__value">{group.name}</span>}
      options={group.children.map(({ key, def }) => ({
        key,
        label: def.name,
        selected: key === active,
      }))}
      onChoose={onGo}
    />
  );
}

/**
 * The top-level nav — the tiered IA (Library increment #3b), replacing the old
 * flat studio row. Reads {@link visibleTiers} (the nav model, role-gated per
 * studio) and renders Tier 1 studios as peer buttons and Tier 2 groups as
 * dropdowns, with a divider between tiers. General is NOT here — it's the home
 * surface reached via the brand wordmark ({@link BrandLogo}).
 *
 * On narrow viewports the whole thing collapses to one crumb listing every
 * reachable studio flat (the tier grouping is a desktop nicety).
 */
function SectionMenu({
  onEnterRoom,
}: {
  onEnterRoom: (room: Room, rows: ThreadRow[]) => void;
}) {
  useRoomTick();
  const rows = useThreadRows();
  const narrow = useIsNarrow();
  const tiers = visibleTiers();
  const active = roomStudio(activeRoom());
  const go = (studio: StudioKey) => {
    // Land on the section's canonical room (Content → the List directory), not
    // just "the current studio". Guarding on studio-equality made re-picking
    // Pages a no-op while EDITING a node (the node room drives Content too), so
    // the tab couldn't take you back to the listing. Guard on the room instead:
    // only a true no-op (already ON the canonical room) is skipped.
    const target = sectionRoom(studio);
    if (sameRoom(target, activeRoom())) return;
    onEnterRoom(target, rows);
  };
  if (visibleDestinationCount(tiers) <= 1) return null;
  if (narrow) {
    // Flatten every reachable destination (tier-1 studios + group children) into
    // a single crumb — the tiering is a desktop affordance.
    const flat = tiers.flatMap((tier) =>
      tier.items.flatMap((item) =>
        item.kind === "studio" ? [item] : item.children.map((c) => ({ kind: "studio" as const, key: c.key, def: c.def })),
      ),
    );
    const activeDef = studioDef(active);
    return (
      <CrumbMenu
        ariaLabel="Section"
        className="ain-crumb--studio ain-sections__crumb"
        trigger={
          <>
            {activeDef?.Icon && <activeDef.Icon className="ain-crumb__icon" aria-hidden />}
            <span className="ain-crumb__value">{activeDef?.name ?? "Section"}</span>
          </>
        }
        options={flat.map(({ key, def }) => ({
          key,
          label: def.name,
          Icon: def.Icon,
          selected: key === active,
        }))}
        onChoose={go}
      />
    );
  }
  return (
    <nav className="ain-sections" aria-label="Sections">
      {tiers.map((tier, i) => (
        <Fragment key={tier.id}>
          {i > 0 && <span className="ain-sections__divider" aria-hidden />}
          <div className="ain-sections__tier" data-tier={tier.id}>
            {tier.items.map((item) => {
              if (item.kind === "group") {
                return <SectionGroup key={item.id} group={item} active={active} onGo={go} />;
              }
              const on = item.key === active;
              // Nav = quiet text links with an active underline (study 02,
              // Plate 13) — never pill buttons, no per-item icons.
              return (
                <button
                  key={item.key}
                  type="button"
                  className="ain-section"
                  data-active={on || undefined}
                  aria-current={on ? "page" : undefined}
                  title={item.def.name}
                  onClick={() => go(item.key)}
                >
                  <span className="ain-section__label">{item.def.name}</span>
                </button>
              );
            })}
          </div>
        </Fragment>
      ))}
    </nav>
  );
}

/* -------------------------------------------------------------- breadcrumb */

type CrumbIcon = ComponentType<SVGProps<SVGSVGElement>>;
type CrumbOption = { key: string; label: string; Icon?: CrumbIcon; selected?: boolean };

/**
 * A themed listbox crumb (the .ain-menu anatomy shared with the account and
 * thread menus) — the reusable dropdown behind both breadcrumb segments. Same
 * WAI-ARIA wiring as UserMenu/the old flow picker: listbox/option roles, focus
 * landing on the selected option, arrow/Home/End/Escape keys, click-outside.
 */
function CrumbMenu({
  ariaLabel,
  className,
  trigger,
  options,
  onChoose,
}: {
  ariaLabel: string;
  className?: string;
  trigger: React.ReactNode;
  options: CrumbOption[];
  onChoose: (key: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const items = () =>
    Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="option"]') ?? []);

  useEffect(() => {
    if (!open) return;
    const list = items();
    (list.find((el) => el.getAttribute("aria-selected") === "true") ?? list[0])?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: PointerEvent) => {
      if (rootRef.current?.contains(e.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", onPointer, true);
    return () => document.removeEventListener("pointerdown", onPointer, true);
  }, [open]);

  const onTriggerKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp" || e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      setOpen(true);
    }
  };

  const onMenuKeyDown = (e: React.KeyboardEvent) => {
    const list = items();
    const idx = list.indexOf(document.activeElement as HTMLElement);
    switch (e.key) {
      case "Escape":
        e.preventDefault();
        setOpen(false);
        btnRef.current?.focus();
        break;
      case "ArrowDown":
        e.preventDefault();
        list[(idx + 1) % list.length]?.focus();
        break;
      case "ArrowUp":
        e.preventDefault();
        list[(idx - 1 + list.length) % list.length]?.focus();
        break;
      case "Home":
        e.preventDefault();
        list[0]?.focus();
        break;
      case "End":
        e.preventDefault();
        list[list.length - 1]?.focus();
        break;
      case "Tab":
        setOpen(false);
        break;
    }
  };

  const choose = (key: string) => {
    setOpen(false);
    btnRef.current?.focus();
    onChoose(key);
  };

  return (
    <div className={`ain-crumb${className ? ` ${className}` : ""}`} ref={rootRef}>
      <button
        ref={btnRef}
        className="ain-crumb__trigger"
        data-open={open || undefined}
        onClick={() => setOpen((v) => !v)}
        onKeyDown={onTriggerKeyDown}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={ariaLabel}
      >
        {trigger}
        <ChevronDownIcon className="ain-crumb__caret" aria-hidden />
      </button>
      {open && (
        <div
          ref={menuRef}
          className="ain-menu ain-crumb__menu"
          role="listbox"
          aria-label={ariaLabel}
          onKeyDown={onMenuKeyDown}
        >
          {options.map((o) => (
            <button
              key={o.key}
              className="ain-menu__item ain-crumb__option"
              role="option"
              aria-selected={!!o.selected}
              onClick={() => choose(o.key)}
            >
              <span className="ain-crumb__check" aria-hidden>{o.selected && <CheckIcon />}</span>
              {o.Icon && <o.Icon className="ain-crumb__icon" aria-hidden />}
              {o.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Run a flow-store emit (studio / agent change) on the NEXT tick, after the
 * current click's thread switch has committed.
 *
 * Leaving or switching a studio fires a thread switch AND a studio/agent store
 * emit from the same click. The thread switch must run the way the "New" button
 * (ThreadListPrimitive.New) does — synchronously inside the click handler — so
 * React batches it and unmounts the old thread's message-bound tool widgets
 * (the weather card, the brand/page preview appliers) cleanly as the thread
 * empties. If the studio/agent emit lands in that SAME batch, assistant-ui
 * re-renders one of those widgets against the not-yet-populated new thread
 * before it unmounts — it reads a message index that no longer exists and
 * throws during render ("tapClientLookup: Index N out of bounds (length: 0)"),
 * white-screening the whole console (the "crash switching to the brand studio").
 * Deferring the EMIT keeps the switch in the safe batched path and runs the
 * emit in isolation a tick later.
 *
 * (An earlier version deferred the SWITCH instead. But a setTimeout'd switch
 * runs OUTSIDE React's batching, so the store notify hit the still-mounted tool
 * widget synchronously and crashed for any thread carrying one — a plain-text
 * thread has nothing at the stale index, which is why that looked safe.)
 */
function emitNextTick(emit: () => void): void {
  setTimeout(emit, 0);
}

/**
 * The chat-head AGENT picker (the studio switcher retired with the breadcrumb —
 * navigation is the resource tree now). A room's studio may offer more than one
 * agent (Content: page agent + operator); this lets you pick which one a NEW
 * conversation runs. It only renders when the active room has a real CHOICE (>1
 * agent) — a single-agent room shows a static label, and an agentless room
 * (Globals, editor-only) shows nothing.
 *
 * A conversation pins its agent at start and can't switch midway, so choosing a
 * different agent on a pinned thread confirms, then starts a fresh conversation
 * (the current one stays in the sidebar). The switch follows the same safe
 * batched path as everywhere else — thread switch synchronous, selectAgent emit
 * deferred a tick (see {@link emitNextTick}).
 */
function RoomAgentPicker() {
  const guardedSwitch = useGuardedSwitch();
  useRoomTick();
  const pinned = useActiveThreadWorkflow();
  const selectedId = useSelectedWorkflowId();
  const [confirming, setConfirming] = useState<WorkflowRef | null>(null);

  const room = activeRoom();
  const studio = roomStudio(room);
  const agents = roomAgents(room);
  const agentValue = pinned?.id ?? selectedId ?? "";
  const currentAgent = agents.find((a) => a.id === agentValue) ?? agents[0];

  const pickAgent = (id: string) => {
    if (pinned) {
      // An active thread can't switch agents midway — confirm a new chat.
      if (id !== pinned.id) setConfirming(agents.find((a) => a.id === id) ?? null);
      return;
    }
    selectAgent(studio, id);
  };

  const startNewChat = () => {
    if (!confirming) return;
    const agentId = confirming.id;
    setConfirming(null);
    // Fresh thread in the SAME room via the statechart (a same-room switch — no
    // studio/doc re-derive), then record the new chat's agent a tick later: the
    // selectAgent emit + the thread switch must not land in one batch (see
    // emitNextTick). The empty new thread isn't sent until the pick has settled,
    // so the deferred selectAgent still pins the right agent. Guarded so a
    // pending unsaved page draft isn't dropped without a confirm.
    guardedSwitch(() => {
      consoleNav.newThread();
      emitNextTick(() => selectAgent(studio, agentId));
    });
  };

  if (agents.length === 0) return null;
  return (
    <>
      {agents.length > 1 ? (
        <CrumbMenu
          ariaLabel="Agent"
          className="ain-crumb--agent"
          trigger={<span className="ain-crumb__value">{currentAgent?.label ?? "Select agent"}</span>}
          options={agents.map((a) => ({ key: a.id, label: a.label, selected: a.id === agentValue }))}
          onChoose={(id) => pickAgent(id)}
        />
      ) : (
        <span className="ain-crumb ain-crumb--agent ain-crumb--static">
          <span className="ain-crumb__value">{currentAgent?.label}</span>
        </span>
      )}
      {confirming && (
        <div className="ain-confirm__overlay" role="dialog" aria-modal="true" aria-label="Switch agent">
          <div className="ain-confirm">
            <p className="ain-confirm__text">
              This conversation runs on <strong>{pinned?.label}</strong> and can&apos;t switch agents midway.
              Start a <strong>new conversation</strong> on <strong>{confirming.label}</strong>? The current one stays in the sidebar.
            </p>
            <div className="ain-confirm__actions">
              <button className="ain-btn ain-topbtn" onClick={() => setConfirming(null)}>Cancel</button>
              <button className="ain-btn ain-topbtn ain-topbtn--primary" onClick={startNewChat}>Start new chat</button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

/* ----------------------------------------------------------------- user menu */
/**
 * Account flyout (WAI-ARIA menu-button pattern): an avatar chip — the user's
 * initial, falling back to a person glyph — replaces the bare username and
 * Log out link in the top bar. Items come from Drupal's "User account menu"
 * (My account, Log out, plus whatever a site builder adds). Opening moves
 * focus into the menu; arrows/Home/End cycle the items, Escape (or an
 * outside click / tabbing away) closes and Escape returns focus to the
 * trigger.
 */
function UserMenu() {
  // Re-read on demand: the account pane mutates window.aincientChat.viewer after
  // a save, then bumps this so the flyout re-renders with the fresh card.
  const [, bumpViewer] = useState(0);
  const { viewer, accountMenu = [] } = settings();
  // Names are EARNED (study 02, Plate 14): show one only when the owner
  // entered it; otherwise the email is the single primary line — the machine
  // username never appears in chrome.
  const name = viewer?.name || "";
  const email = viewer?.email || "";
  const initial = viewer?.initial ?? (name || email).trim().charAt(0).toUpperCase();
  const [open, setOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const btnRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const items = () =>
    Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

  // Focus enters the menu when it opens (keyboard and mouse alike).
  useEffect(() => {
    if (open) items()[0]?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onPointer = (e: PointerEvent) => {
      if (rootRef.current?.contains(e.target as Node)) return;
      setOpen(false);
    };
    document.addEventListener("pointerdown", onPointer, true);
    return () => document.removeEventListener("pointerdown", onPointer, true);
  }, [open]);

  if (!viewer && accountMenu.length === 0) return null;

  const onTriggerKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown" || e.key === "ArrowUp" || e.key === "Enter" || e.key === " ") {
      e.preventDefault(); // Suppress the synthetic click — open exactly once.
      setOpen(true);
    }
  };

  const onMenuKeyDown = (e: React.KeyboardEvent) => {
    const list = items();
    const idx = list.indexOf(document.activeElement as HTMLElement);
    switch (e.key) {
      case "Escape":
        e.preventDefault();
        setOpen(false);
        btnRef.current?.focus();
        break;
      case "ArrowDown":
        e.preventDefault();
        list[(idx + 1) % list.length]?.focus();
        break;
      case "ArrowUp":
        e.preventDefault();
        list[(idx - 1 + list.length) % list.length]?.focus();
        break;
      case "Home":
        e.preventDefault();
        list[0]?.focus();
        break;
      case "End":
        e.preventDefault();
        list[list.length - 1]?.focus();
        break;
      case "Tab":
        // Let focus move on naturally; the menu just closes behind it.
        setOpen(false);
        break;
    }
  };

  return (
    <div className="ain-usermenu" ref={rootRef}>
      <button
        ref={btnRef}
        className="ain-btn ain-iconbtn ain-usermenu__trigger"
        data-open={open || undefined}
        onClick={() => setOpen((v) => !v)}
        onKeyDown={onTriggerKeyDown}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={name || email ? `Account: ${name || email}` : "Account"}
      >
        {viewer?.avatarUrl ? (
          <img className="ain-usermenu__avatar ain-usermenu__avatar--img" src={viewer.avatarUrl} alt="" aria-hidden />
        ) : initial ? (
          <span className="ain-usermenu__avatar" aria-hidden>{initial}</span>
        ) : (
          <PersonIcon />
        )}
      </button>
      {open && (
        <div
          ref={menuRef}
          className="ain-menu ain-usermenu__menu"
          role="menu"
          aria-label="Account"
          onKeyDown={onMenuKeyDown}
        >
          {viewer && (
            <div className="ain-usermenu__card" role="presentation">
              {/* Plate 14: the earned name leads (email dim beneath) — or the
                  email stands alone. No ACTIVE pill (a chip that can only ever
                  say one thing is noise), no tenure arithmetic. */}
              <div className="ain-usermenu__cardhead">
                {viewer.avatarUrl ? (
                  <img className="ain-usermenu__cardavatar ain-usermenu__cardavatar--img" src={viewer.avatarUrl} alt="" />
                ) : initial ? (
                  <span className="ain-usermenu__cardavatar" aria-hidden>{initial}</span>
                ) : null}
                <div className="ain-usermenu__ident">
                  {name ? (
                    <>
                      <strong className="ain-usermenu__name">{name}</strong>
                      {email && <div className="ain-usermenu__email">{email}</div>}
                    </>
                  ) : (
                    email && <strong className="ain-usermenu__name">{email}</strong>
                  )}
                </div>
              </div>
              {((viewer.roles?.length ?? 0) > 0 || viewer.since) && (
                <div className="ain-usermenu__roles">
                  {(viewer.roles ?? []).map((r) => (
                    <span key={r} className="ain-usermenu__role">{r}</span>
                  ))}
                  {viewer.since && <span className="ain-usermenu__since">since {viewer.since}</span>}
                </div>
              )}
            </div>
          )}
          {viewer && (
            <button
              type="button"
              className="ain-menu__item"
              role="menuitem"
              onClick={() => {
                setOpen(false);
                setAccountOpen(true);
              }}
            >
              Manage account
            </button>
          )}
          {/* System (/admin — the curated Atelier landing, the basement). Named
              "System" so it never collides with the studios that live INSIDE the
              console (Content/Globals/…). A plain same-tab anchor is the
              declarative workspace form (surface-nav: within-workspace = same
              tab). Server-gated on canAdmin (the admin-overview permission), so a
              non-admin never sees a door they can't open. */}
          {settings().canAdmin && (
            <a className="ain-menu__item" role="menuitem" href="/admin">
              System
            </a>
          )}
          {/* Re-entry into the onboarding wizard — the v1 settings surface (Law 14).
              Server-gated on canReenter (admin on a configured site), so a non-admin
              never sees a door they can't open. */}
          {settings().onboarding?.canReenter && (
            <button
              type="button"
              className="ain-menu__item"
              role="menuitem"
              onClick={() => openSurface(`${consoleBase()}?onboarding=1`, "workspace")}
            >
              Set up AI providers
            </button>
          )}
          {accountMenu.map((item) => (
            <a key={item.url} className="ain-menu__item" role="menuitem" href={item.url}>
              {item.title}
            </a>
          ))}
        </div>
      )}
      {accountOpen && (
        <AccountPane
          onClose={() => {
            setAccountOpen(false);
            btnRef.current?.focus();
          }}
          onViewerChange={() => bumpViewer((v) => v + 1)}
        />
      )}
    </div>
  );
}

/**
 * The studio split-pane (editor rail + live preview), driven by the active
 * studio's registry entry. Lives in its own component rendered INSIDE the
 * AssistantRuntimeProvider so useActiveStudio's runtime-bound hooks resolve.
 * Only studios with editor components reach here (App gates on studioHasEditor).
 */
function StudioPane({ studioKey, onClose }: { studioKey: StudioKey; onClose: () => void }) {
  const def = studioDef(studioKey);
  const Studio = def?.Studio;
  const Preview = def?.Preview;
  if (!Studio) return null;
  // A studio with both a Preview and an editor renders Preview first (centre
  // canvas), then the editor rail (right). A PANEL-ONLY studio (Checks) omits the
  // Preview — its Studio is the centre canvas itself. Both are flat siblings of
  // the chat inside .ain-workspace so each collapses on its own as the viewport
  // narrows — no wrapping element to fight the responsive cascade.
  return (
    <>
      {Preview && <Preview />}
      <Studio onClose={onClose} />
    </>
  );
}

/**
 * "Links are disabled in the preview" modal. The live-preview iframes are
 * interactive, but following a link would navigate the frame off the preview
 * and drop the brand overlay / unsaved page draft — so preview-nav.ts cancels
 * anchor clicks and fires here. We explain that, and offer to open the link in
 * a new tab so the user can still get where they were headed. Esc / overlay
 * click / "Got it" dismiss; reusing the .ain-confirm dialog anatomy.
 */
function PreviewLinkBlockedModal() {
  const [href, setHref] = useState<string | null>(null);
  useEffect(() => subscribeBlockedLink(setHref), []);
  useEffect(() => {
    if (href === null) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setHref(null); };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [href]);
  if (href === null) return null;
  // Show a readable destination; keep the full href for the new-tab action.
  let label = href;
  try {
    const u = new URL(href);
    label = u.host + u.pathname + u.search;
  } catch {
    /* unparsable → show it raw */
  }
  return (
    <div
      className="ain-confirm__overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Links disabled in preview"
      onClick={() => setHref(null)}
    >
      <div className="ain-confirm" onClick={(e) => e.stopPropagation()}>
        <p className="ain-confirm__text">
          <strong>Links are disabled in the live preview.</strong> This is a working
          preview — following <span className="ain-confirm__code">{label}</span> would
          navigate away and lose your current changes.
        </p>
        <div className="ain-confirm__actions">
          <button className="ain-btn ain-topbtn" onClick={() => setHref(null)}>Got it</button>
          <button
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={() => {
              openSurface(href, "output");
              setHref(null);
            }}
          >
            Open in new tab ↗
          </button>
        </div>
      </div>
    </div>
  );
}

/* ----------------------------------------------------------------- top menu */
function TopBar({
  theme,
  onToggleTheme,
  studioActive,
  onEnterRoom,
}: {
  theme: string;
  onToggleTheme: () => void;
  studioActive: boolean;
  onEnterRoom: (room: Room, rows: ThreadRow[]) => void;
}) {
  const { allowThemeSwitch = true } = settings();
  const { editOpen, toggleEdit } = useStudioUI();
  // The wordmark is the HOME affordance: it enters General (the operator-chat
  // home), which the tiered nav (increment #3b) dropped from the bar. Reuses the
  // dirty-guarded room navigation, so an unsaved draft still prompts first.
  const rows = useThreadRows();
  const goHome = () => onEnterRoom(sectionRoom("general"), rows);
  return (
    <header className="ain-topbar">
      <div className="ain-topbar__left">
        <BrandLogo className="ain-brand" onGoHome={goHome} />
        {isMock() && <span className="ain-tag">mock backend</span>}
        {/* Phase B: the SECTIONS (studios) live here now, not in the sidebar —
            the sidebar became the section's WIP list. The agent picker sits on
            the chat panel's own head (RoomAgentPicker), beside its burger + New. */}
        <SectionMenu onEnterRoom={onEnterRoom} />
      </div>
      <div className="ain-topbar__right">
        {/* Reveal the editor rail when it's a summoned sheet (tablet/phone).
            Hidden on desktop, where the rail is always in view — CSS gates it. */}
        {studioActive && (
          <button
            className="ain-btn ain-topbtn ain-topbar__edittoggle"
            onClick={toggleEdit}
            aria-pressed={editOpen}
            aria-label="Edit values"
            title="Edit values"
          >
            <SlidersIcon /> <span className="ain-topbtn__label">Edit</span>
          </button>
        )}
        {/* Studio actions (Discard / Publish / leave) portal into this slot from
            the active studio so they stay reachable when the rail is collapsed. */}
        <span className="ain-studio-actions" id="ain-studio-actions" />
        {allowThemeSwitch && (
          <button className="ain-btn ain-iconbtn" onClick={onToggleTheme} aria-label="Toggle theme" title="Toggle theme">
            {theme === "dark" ? <SunIcon /> : <MoonIcon />}
          </button>
        )}
        {/* View the live site — the console's output. New tab, always (the
            surface-nav rule: protect the open draft + thread behind it). */}
        <button
          className="ain-btn ain-iconbtn"
          onClick={() => openSurface("/", "output")}
          aria-label="View site"
          title="View site"
        >
          <GlobeIcon />
        </button>
        <UserMenu />
      </div>
    </header>
  );
}

/* ---------------------------------------------------------------------- app */
export function App() {
  // Seed the active studio from the URL synchronously, on the very first render,
  // BEFORE `useActiveStudio()` (and thus `paneStudio`) is read below. A deep link
  // into an editor studio (Content, Library) otherwise paints once in the default
  // chat-only studio and only flips to `--studio` when `useConsoleUrl` resolves
  // the URL in a post-mount effect — and on that flip the sidebar's `transform`
  // animates from on-screen to `translateX(-100%)`, flashing the listing across
  // the screen before it slides away. Seeding here makes the shell paint in the
  // right studio with the sidebar already off-screen (no transition on mount).
  // Idempotent: `setActiveStudio` no-ops once set, and the lazy initializer runs
  // exactly once per mount.
  useState(() => {
    setActiveStudio(roomStudio(parseUrl().room));
    return null;
  });
  const runtime = useAincientRuntime();
  // Bind the runtime the console statechart drives thread switches through
  // (console-nav.ts). Runs before any user-driven ENTER_ROOM / SWITCH_THREAD.
  useEffect(() => bindRuntime(runtime), [runtime]);
  // URL ⇄ console machine (Phase 2, D3): the room owns the path
  // (/atelier/<studio>[/…/<nid>]) and the active thread rides as ?thr=. This one
  // hook projects the machine's room/thread into the address bar and resolves
  // deep links / back-forward back into the machine (console-url is the codec).
  useConsoleUrl(runtime);
  // The sidebar (chat/thread listing) starts closed on every fresh load, on
  // all viewports — the conversation gets the room and the listing is one
  // toggle away. On phones it already overlays the conversation (see the
  // responsive section in styles.css); on desktop we now keep it collapsed too.
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const closeOnMobile = () => {
    if (window.matchMedia("(max-width: 768px)").matches) setSidebarOpen(false);
  };

  // The dirty-guard: a thread switch that would strand an unsaved page draft
  // stages a discard-confirm first (studio-navigation.md). `pendingSwitch` holds
  // the switch to run once the user confirms; `guardedSwitch` is handed to every
  // switch initiator (the room tree here, plus the thread list + New/agent-picker
  // buttons, via SwitchGuardContext).
  const [pendingSwitch, setPendingSwitch] = useState<(() => void) | null>(null);
  const guardedSwitch = useCallback((doSwitch: () => void) => {
    if (isPageDirty()) setPendingSwitch(() => doSwitch);
    else doSwitch();
  }, []);
  const confirmSwitch = () => {
    const doSwitch = pendingSwitch;
    setPendingSwitch(null);
    // Run the staged switch as-is. Don't force dirty=false here: if the switch
    // turns out to be a no-op (e.g. New chat while already on a fresh thread) the
    // draft is still open and still dirty, and the studio's effect only re-syncs
    // the flag when `dirty` CHANGES — so an optimistic clear would desync it and
    // silently disarm the next switch. A real switch clears the doc (load resets
    // the baseline, or the studio unmounts), which resets the flag on its own.
    doSwitch?.();
  };

  // Enter a room from the tree (or the New-page form): the console statechart is
  // the navigation authority now. `enterRoom` sets `context.room`, commits the
  // runtime thread switch synchronously (landing on the room's most-recent live
  // thread, or a fresh one), then DERIVES the studio + open document from the
  // room one tick later — the machine owns the emitNextTick console-crash rule
  // (see console-nav.ts). `rows` comes from the Sidebar (it owns the runtime-
  // bound thread list, which App's body sits outside of). The dirty-guard stages
  // a discard-confirm first when a switch would strand an unsaved draft.
  const navigateToRoom = useCallback(
    (room: Room, rows: ThreadRow[]) => {
      if (sameRoom(activeRoom(), room)) return;
      guardedSwitch(() => {
        const landing = roomActiveThread(room, rows);
        consoleNav.enterRoom(room, landing ?? null);
      });
    },
    [guardedSwitch],
  );

  // The console is always in exactly one studio. A studio with editor components
  // (Design System, Globals, Content) renders a split-pane beside the chat;
  // General has none, so it's full-width chat. `paneStudio` is the active studio
  // iff it has an editor. An EDITOR-ONLY studio (Globals: no chat agent yet) has
  // no agents in the catalog — `hasAgents` gates the chat column off so the
  // workspace is just preview + editor rail (the composer is gated by construction).
  const activeStudio = useActiveStudio();
  const paneStudio = studioHasEditor(activeStudio) ? activeStudio : null;
  const hasAgents = agentsForStudio(activeStudio).length > 0;
  // A deep link to a document the user can't open (403) or that's gone (404)
  // raises a dead-end; the shell overlays the workspace with the shared
  // end-state pane so there's always a clear next action.
  const docEnd = useSyncExternalStore(subscribeDocEnd, getDocEnd);
  // Entering an editor studio collapses the sidebar so the split-pane gets the
  // room (it overlays like the mobile drawer). We no longer auto-restore it on
  // leave — the listing defaults to closed everywhere, so a studio exit keeps
  // whatever state the user last chose. Runs only when the editor presence
  // flips, so manual sidebar toggles within a studio still hold.
  useEffect(() => {
    if (paneStudio) setSidebarOpen(false);
  }, [paneStudio]);

  // Transient studio layout state: the editor rail and the conversation become
  // summoned sheets on narrow screens (see studio-ui.tsx). Mutually exclusive —
  // opening one closes the other. Reset whenever the editor studio changes so a
  // sheet never lingers across a switch.
  const [editOpen, setEditOpen] = useState(false);
  const [convoOpen, setConvoOpen] = useState(false);
  useEffect(() => {
    setEditOpen(false);
    setConvoOpen(false);
  }, [paneStudio]);
  const studioUI = useMemo(
    () => ({
      editOpen,
      convoOpen,
      toggleEdit: () => { setEditOpen((v) => !v); setConvoOpen(false); },
      toggleConvo: () => { setConvoOpen((v) => !v); setEditOpen(false); },
      closeSheets: () => { setEditOpen(false); setConvoOpen(false); },
    }),
    [editOpen, convoOpen],
  );
  // The studio editor's close (X) drops back to chat: enter the default studio
  // room. The machine switches to a fresh conversation there, derives the studio,
  // and (via commitSwitch) closes any open doc + releases its lock — all on the
  // safe batched path (commitThreadSwitch defers the settle a tick, §1).
  const leaveStudio = useCallback(() => {
    consoleNav.enterRoom(sectionRoom(serverDefaultStudio()));
  }, []);

  // Theme: default comes from Drupal config (aincient_assistant_ui); the user's
  // runtime choice is remembered in localStorage.
  const { theme: defaultTheme = "light" } = settings();
  const [theme, setTheme] = useState<string>(
    () => localStorage.getItem("aincient-theme") || defaultTheme,
  );
  useEffect(() => {
    document.getElementById("aincient-chat-root")?.setAttribute("data-ain-theme", theme);
  }, [theme]);
  const toggleTheme = () => {
    const next = theme === "dark" ? "light" : "dark";
    localStorage.setItem("aincient-theme", next);
    setTheme(next);
  };

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      {/* Registers the human-in-the-loop choice widget (FlowDrop ChoiceNode). */}
      <FlowDropChoiceToolUI />
      {/* Registers the live node-execution trail (the `aincient_progress` part). */}
      <NodeProgressToolUI />
      {/* Registers the per-turn token-usage + cost footer (the `aincient_usage` part). */}
      <UsageFooterToolUI />
      {/* Registers the weather card (the `weather_card` generative-UI tool). */}
      <WeatherCardToolUI />
      {/* Registers the quick brand picker (the `brand_picker` generative-UI tool). */}
      <BrandPickerToolUI />
      {/* Registers the brand-status HITL confirm card (the `brand_status_proposal` tool). */}
      <BrandStatusProposalToolUI />
      {/* Registers the live brand preview applier (the `brand_preview` generative-UI tool). */}
      <BrandPreviewToolUI />
      {/* Registers the live page preview applier (the `page_preview` generative-UI tool). */}
      <PagePreviewToolUI />
      {/* Registers the live chrome preview applier (the `chrome_preview` generative-UI tool). */}
      <ChromePreviewToolUI />
      {/* Registers the generic `data_table` widget (e.g. list_pages → open in studio). */}
      <DataTableToolUI />
      {/* Registers the generated-image card (the `media_result` generative-UI tool). */}
      <MediaResultToolUI />
      {/* Registers the first-run AI-connect panel (the `onboarding` generative-UI tool). */}
      <OnboardingToolUI />

      {/* The onboarding studio-tour map (the `studio_tour` generative-UI tool). */}
      <StudioTourToolUI />
      <SwitchGuardContext.Provider value={guardedSwitch}>
      <StudioUIContext.Provider value={studioUI}>
      <div
        className={`ain-shell${sidebarOpen ? "" : " ain-shell--collapsed"}${paneStudio ? " ain-shell--studio" : ""}`}
        data-studio-edit={paneStudio && editOpen ? "open" : undefined}
        data-studio-convo={paneStudio && convoOpen ? "open" : undefined}
      >
        {/* Remount the sidebar when the layout MODE flips (chat-only ⇄ editor
            studio) so it reappears in its new closed representation WITHOUT a CSS
            transition. A persisted element would animate `transform` from the
            non-studio value (in-flow, width-collapsed → effectively 0) to the
            studio drawer's translateX(-100%), sliding the closed listing across
            the screen on the first general→pages switch. A fresh mount has no
            prior style to transition from. Toggles WITHIN a mode keep the same
            key, so the open/close drawer slide is preserved. */}
        <Sidebar
          key={paneStudio ? "studio" : "chat"}
          open={sidebarOpen}
          onNavigate={closeOnMobile}
          onEnterRoom={navigateToRoom}
        />
        {/* Mobile-only backdrop behind the overlaying sidebar (display: none
            on wider screens); a tap outside the drawer dismisses it. */}
        {sidebarOpen && <div className="ain-shell__scrim" onClick={() => setSidebarOpen(false)} aria-hidden />}
        <div className="ain-main">
          <TopBar
            theme={theme}
            onToggleTheme={toggleTheme}
            studioActive={!!paneStudio}
            onEnterRoom={navigateToRoom}
          />
          {/* Workspace order is chat · preview · edit: the preview is the centre
              canvas, flanked by the two ways to drive it. StudioPane renders the
              preview then the editor rail as flat siblings of the chat so the
              three collapse independently as the viewport narrows. */}
          <div className={`ain-workspace${paneStudio ? " ain-workspace--split" : ""}`}>
            {/* The chat column — hidden for an editor-only studio (no agent)
                with no history, so the workspace collapses to preview + editor
                rail; a section that HOLDS conversations keeps its column (the
                composer alone goes read-only — see ChatColumn). */}
            <ChatColumn
              section={activeStudio}
              hasAgents={hasAgents}
              onToggleSidebar={() => setSidebarOpen((v) => !v)}
            />
            {paneStudio && <StudioPane studioKey={paneStudio} onClose={leaveStudio} />}
            {/* Scrim behind a summoned sheet (editor rail / conversation) on
                narrow screens; a tap dismisses. Inert on desktop (display:none). */}
            {paneStudio && (
              <div className="ain-studio__scrim" onClick={studioUI.closeSheets} aria-hidden />
            )}
          </div>
        </div>
        {/* Shell-level overlay: explains links are disabled in the live-preview
            iframes. Inside .ain-shell so it inherits the console font/theme. */}
        <PreviewLinkBlockedModal />
        {/* Shell-level dead-end: a deep-linked document the user can't open
            (denied) or that's gone. Reuses the confirm-overlay anatomy; the
            actions clear the dead-end and route the user somewhere useful. */}
        {docEnd && (
          <div className="ain-confirm__overlay" role="dialog" aria-modal="true" aria-label="Document unavailable">
            <ThreadEndState
              variant={docEnd.kind}
              className="ain-endstate--overlay"
              actions={[
                {
                  label: "Start a new thread",
                  primary: true,
                  // Enter the default section's canonical room (sectionRoom, so a
                  // collection default lands on its browse room, never a ghost
                  // studio room) — the machine switches to a fresh thread and
                  // commitThreadSwitch clears the dead-end for us.
                  onClick: () => consoleNav.enterRoom(sectionRoom(serverDefaultStudio())),
                },
                {
                  label: "Browse pages",
                  onClick: () => consoleNav.enterRoom({ kind: "list" }),
                },
              ]}
            />
          </div>
        )}
        {/* Shell-level dirty-guard: a thread switch that would drop the open
            page/block draft's unsaved edits confirms first. Reuses the confirm
            anatomy; Cancel keeps the draft, Discard runs the staged switch (whose
            clear-on-switch then drops the draft). */}
        {pendingSwitch && (
          <div
            className="ain-confirm__overlay"
            role="presentation"
            onClick={(e) => {
              if (e.target === e.currentTarget) setPendingSwitch(null);
            }}
          >
            <div className="ain-confirm" role="dialog" aria-modal="true" aria-label="Unsaved changes">
              <p className="ain-confirm__text">
                This page has unsaved changes. Switching conversations will discard them — save or
                publish first to keep them.
              </p>
              <div className="ain-confirm__actions">
                <button className="ain-btn ain-topbtn" onClick={() => setPendingSwitch(null)}>
                  Cancel
                </button>
                <button className="ain-btn ain-topbtn ain-topbtn--primary" onClick={confirmSwitch}>
                  Discard &amp; switch
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
      </StudioUIContext.Provider>
      </SwitchGuardContext.Provider>
    </AssistantRuntimeProvider>
  );
}
