import { createContext, Fragment, useCallback, useContext, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import type { ComponentType, SVGProps } from "react";
// The layout primitives — and only those — come straight from the vendor here.
// They are compound-component namespaces used as MARKUP, re-exporting them would
// buy indirection and nothing else, and App.tsx is ours and is never plugin-facing.
// That exemption is documented in `./aui` and enforced by `aui/seam.test.ts`; it
// covers this file and no other, and it does not extend to hooks — those come
// from the facade below, like everywhere else in the console.
import {
  AssistantRuntimeProvider,
  ThreadPrimitive,
  MessagePrimitive,
  ComposerPrimitive,
  ActionBarPrimitive,
  ThreadListPrimitive,
  ThreadListItemPrimitive,
} from "@assistant-ui/react";
import { MarkdownTextPrimitive } from "@assistant-ui/react-markdown";
import remarkGfm from "remark-gfm";
import {
  DictationAdapter,
  useComposerHandle,
  useComposerState,
  useConsoleChat,
  useConsoleThread,
  useThreadListEntry,
  useThreadListEntryHandle,
  useThreadListState,
  useTurnState,
} from "./aui";
import { isMock, sealThread, settings } from "./adapter";
import {
  flowVersion,
  selectAgent,
  setActiveStudio,
  starterAsks,
  subscribe as subscribeFlows,
  useActiveStudio,
  useActiveThreadWorkflow,
  useSelectedWorkflow,
  useSelectedWorkflowId,
  type WorkflowRef,
} from "./flow";
import { CapabilityChips } from "./capability-chips";
import { takeComposerPrefill } from "./composer-prefill";
import { PanelBar } from "./kit/panel-bar";
import { LoadingState } from "./kit/loading-state";
import { Button, IconButton } from "./kit/button";
import { Dialog, DialogClose, DialogTitle } from "./kit/dialog";
import { Menu, MenuItem, MenuRadioGroup, MenuRadioItem } from "./kit/menu";
import { AccountPane } from "./account-pane";
import {
  agentsForStudio,
  serverDefaultStudio,
  studioOfAgent,
  studioVerbs,
  type StudioKey,
} from "./studios";
import { studioDef, studioHasEditor } from "./studio-registry";
import { useLoadedStudioToolUIs, useStudioModule } from "./studio-loader";
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
import { NameInvite } from "./name-invite";
import { WeatherCardToolUI } from "./weather-widget";
import { OnboardingToolUI } from "./onboarding";
import { StudioTourToolUI } from "./studio-tour";
import { DataTableToolUI } from "./kit/data-table";
import { ToolUsageCard } from "./tool-card";
import {
  MenuIcon,
  Wordmark,
  AtelierMark,
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
  PaperclipIcon,
  SpinnerIcon,
  DocumentIcon,
} from "./kit/icons";
import { MarkdownImage } from "./markdown-image";
import { StudioUIContext, useStudioUI } from "./kit/studio-ui";
import { NewPageForm } from "./new-page-form";
import { subscribeNewPageRequest } from "./new-page-request";
import { isPageDirty } from "./page-dirty";
import { ErrorBoundary } from "./kit/error-boundary";
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
import { useThreadRemoteId, useThreadSealed } from "./thread-seal-hooks";
import { subscribeLock } from "./page-lock";
import { composerMode } from "./console-machine";
import { consoleBase, apiUrl } from "./console-config";
import {
  addAttachment,
  getAttachments,
  removeAttachment,
  subscribeAttachments,
} from "./attachment-state";

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
  return (
    <MarkdownTextPrimitive
      remarkPlugins={[remarkGfm]}
      components={{ a: ChatLink, img: MarkdownImage }}
    />
  );
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
  const time = messageTime(useTurnState((m) => m.createdAt));
  const label = useTurnState((m) =>
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
  const time = messageTime(useTurnState((m) => m.createdAt));
  // A HITL answer is an action, not typed chat — render the event chip.
  const action = useTurnState(
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
 * transient `status` frame ("Getting started…", "Generated an image" — the
 * owner words for the newest completed step; see `step-vocabulary.ts`, and
 * `run-status.ts` for why this is the only live region that narrates a turn).
 * Shown inside the bubble while the turn runs and no answer text
 * has arrived — the FlowDrop turn executes synchronously server-side, so
 * without this the bubble sat empty (and felt stuck) for the whole run.
 *
 * The bar escalates with elapsed time (styling keyed off data-stage) so a
 * long run is visibly acknowledged instead of looping the same calm
 * animation forever: 0–10s a calm blink, 10–30s a sweep, 30s+ a hot fast
 * sweep — the logo's spectrum heating up. The stage label is only the
 * FALLBACK text: a live status frame is more specific and keeps precedence.
 * "Running", not "Thinking" — a workflow turn may not involve AI at all.
 *
 * A RUNNING CLOCK sits beside the text, because the text can legitimately stand
 * still for a long time: a reasoning node routinely takes ~50s and FlowDrop
 * dispatches nothing until it finishes (its orchestrator has no job-STARTED
 * event), so between frames there is genuinely nothing new to say. A second-by
 * -second count is the honest answer — the run is alive, this is how long it has
 * been — and past 30s / 1m it carries the escalation the stage labels used to,
 * which a live status frame otherwise suppressed for the whole run.
 *
 * The clock is `aria-hidden`: this element is the turn's live region, and a
 * per-second counter inside it would announce itself every second.
 */
const RUN_STAGES = [
  { at: 0, label: "Running…" },
  { at: 10_000, label: "Still working…" },
  { at: 30_000, label: "Heavy lifting…" },
] as const;

/** The stage index for an elapsed-milliseconds reading (drives the bar's heat). */
function runStage(elapsedMs: number): number {
  let stage = 0;
  RUN_STAGES.forEach((s, i) => {
    if (elapsedMs >= s.at) stage = i;
  });
  return stage;
}

/**
 * The wait, in words: a clock, and past 30s a reason to keep waiting. Empty for
 * the first few seconds — a counter on a quick turn is just flicker.
 */
function waitNote(elapsedMs: number): string {
  const seconds = Math.floor(elapsedMs / 1000);
  if (seconds < 4) return "";
  const clock = seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  if (seconds >= 120) return `${clock} · still going, this one is long`;
  if (seconds >= 60) return `${clock} · this can take a couple of minutes`;
  if (seconds >= 30) return `${clock} · still working`;
  return clock;
}

function ThinkingIndicator() {
  const status = useActiveThreadRunStatus();
  const [elapsed, setElapsed] = useState(0);
  useEffect(() => {
    // One 1s tick for the whole wait — it drives the clock AND the bar's heat,
    // so there is a single timer per run rather than one per stage.
    const started = Date.now();
    const timer = window.setInterval(() => setElapsed(Date.now() - started), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const stage = runStage(elapsed);
  const note = waitNote(elapsed);
  return (
    <div className="ain-thinking" role="status" aria-live="polite" data-stage={stage}>
      <span className="ain-thinking__cells" aria-hidden>
        <span /><span /><span /><span /><span />
      </span>
      <span className="ain-thinking__text">{status || RUN_STAGES[stage].label}</span>
      {note && (
        <span className="ain-thinking__wait" aria-hidden>
          {note}
        </span>
      )}
    </div>
  );
}

function AssistantMessage() {
  const time = messageTime(useTurnState((m) => m.createdAt));
  const running = useTurnState((m) => m.status?.type === "running");
  const hasText = useTurnState((m) =>
    m.content.some((p) => p.type === "text" && p.text.trim().length > 0),
  );
  // The studio signs with the A-monogram + "Atelier" — one voice, whichever
  // agent served the turn (study 02, Plate 9); the per-thread workflow stays
  // visible in the top-bar picker. Timestamps surface on hover.
  return (
    <MessagePrimitive.Root className="ain-msg ain-msg--assistant">
      <div className="ain-msg__col">
        <span className="ain-msg__name">
          <AtelierMark className="ain-msg__mark" aria-hidden />
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

/**
 * The "did something settle elsewhere?" hook. An interrupt answered outside
 * this console (a pending-interrupts inbox, another tab) has no push channel,
 * so user INTENT — focusing/typing in the composer, clicking the scroll-down
 * arrow — triggers a quiet re-check. No-op (throttled) unless the thread is
 * showing an unanswered HITL card; see thread-sync.ts.
 */
function useInteractionSync() {
  const runtime = useConsoleChat();
  const thread = useConsoleThread();
  return () => {
    void syncPendingInterrupt(thread, runtime.activeThread().remoteId);
  };
}

/**
 * Mic button: voice-to-text via the browser's native Web Speech API (wired up
 * as the runtime's dictation adapter in runtime.tsx). No model, no API key —
 * recognised speech streams straight into the composer, which the user reviews
 * and sends manually. Hidden where the browser has no Web Speech support.
 */
const DICTATION_SUPPORTED = DictationAdapter.isSupported();

function DictateButton() {
  const composer = useComposerHandle();
  const active = useComposerState((c) => {
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

/** File types the attach picker accepts — mirrors the server's allow-list. */
const ATTACH_ACCEPT =
  "image/png,image/gif,image/jpeg,image/webp,image/avif,text/markdown,text/plain,.md,.txt";

/**
 * Attach a file to the next turn. Uploads the pick to this thread's `/attach`
 * endpoint (which stores it privately and prepares it at send time — an image is
 * pre-described by a vision model, a text document's contents are folded in as
 * fenced DATA), then stages the returned `context:<id>` ref in the attachment
 * store — the composer chip row renders it and the send adapter folds it into
 * the turn.
 *
 * A brand-new never-sent thread has no backend id yet; we `initialize()` it at
 * attach time (the endpoint tolerates a fresh thread id) so the upload has a
 * home — the same thread-id resolution the send adapter uses (runtime.tsx).
 */
function AttachButton() {
  const item = useThreadListEntryHandle();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const upload = useCallback(
    async (file: File) => {
      setUploading(true);
      setError(null);
      try {
        const state = item.getState();
        let threadId = state.remoteId;
        if (!threadId) {
          const r = await item.initialize();
          threadId = r.remoteId;
        }
        const body = new FormData();
        body.append("file", file);
        const res = await fetch(apiUrl("/chat/thread/" + encodeURIComponent(threadId) + "/attach"), {
          method: "POST",
          credentials: "same-origin",
          body,
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.item?.ref) throw new Error(data?.error ?? `HTTP ${res.status}`);
        addAttachment({
          ref: data.item.ref,
          filename: data.item.filename,
          kind: data.item.kind === "document" ? "document" : "image",
          thumb: data.item.thumb,
          size: data.item.size,
        });
      } catch (e) {
        setError(`Couldn’t attach that file: ${e instanceof Error ? e.message : e}`);
      } finally {
        setUploading(false);
      }
    },
    [item],
  );

  return (
    <>
      {error ? <p className="ain-composer__attach-error">{error}</p> : null}
      <button
        type="button"
        className={"ain-btn ain-composer__attach" + (uploading ? " ain-composer__attach--busy" : "")}
        aria-label="Attach a file"
        title="Attach an image or a design file (.md, .txt)"
        disabled={uploading}
        onClick={() => inputRef.current?.click()}
      >
        {uploading ? <SpinnerIcon className="ain-spin" /> : <PaperclipIcon />}
      </button>
      <input
        ref={inputRef}
        type="file"
        accept={ATTACH_ACCEPT}
        hidden
        onChange={(e) => {
          const file = e.target.files?.[0];
          if (file) void upload(file);
          e.target.value = "";
        }}
      />
    </>
  );
}

/**
 * The pending-attachment chip row + consent line, rendered above the composer
 * input. Driven by the attachment store; renders nothing until a file is
 * attached. An image chip previews the thumbnail; a document chip shows a file
 * icon and its size. Each names the file and offers a remove button; the consent
 * line reminds the user the attachment leaves for the AI provider (naming the
 * bound provider for images, where the server resolved the vision provider).
 */
function AttachmentChips() {
  const attachments = useSyncExternalStore(subscribeAttachments, getAttachments);
  const provider = settings().provider;
  if (attachments.length === 0) return null;
  const hasDocument = attachments.some((a) => a.kind === "document");
  return (
    <div className="ain-composer__attachments">
      <ul className="ain-attach-chips">
        {attachments.map((a) => (
          <li key={a.ref} className="ain-attach-chip">
            {a.kind === "document" ? (
              <span className="ain-attach-chip__icon" aria-hidden="true">
                <DocumentIcon />
              </span>
            ) : (
              <img className="ain-attach-chip__thumb" src={a.thumb} alt="" width={28} height={28} />
            )}
            <span className="ain-attach-chip__name">{a.filename}</span>
            {a.kind === "document" && a.size ? (
              <span className="ain-attach-chip__size">{formatBytes(a.size)}</span>
            ) : null}
            <button
              type="button"
              className="ain-attach-chip__remove"
              aria-label={"Remove " + a.filename}
              onClick={() => removeAttachment(a.ref)}
            >
              <XIcon />
            </button>
          </li>
        ))}
      </ul>
      <p className="ain-attach-consent">
        {hasDocument
          ? "The attached file’s contents will be sent to your connected AI provider."
          : provider
            ? `This image will be sent to ${provider}.`
            : "This image will be sent to your connected AI provider."}
      </p>
    </div>
  );
}

/** Compact human byte size for a document chip, e.g. "1.2 KB". */
function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const kb = bytes / 1024;
  if (kb < 1024) return `${kb < 10 ? kb.toFixed(1) : Math.round(kb)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
}

/**
 * The receiving half of the one-hop ask carry (see `composer-prefill.ts`).
 *
 * A one-room studio-tour card stages the user's own sentence for the room it
 * hands off to; this drops it into that room's composer on arrival. Renders
 * nothing, and sits OUTSIDE the composer-mode branches so it runs even where the
 * composer is withheld (an editor-only room like Settings) — arriving anywhere
 * clears the stage, which is what makes a stale sentence impossible rather than
 * merely unlikely.
 *
 * The text is never sent: the user reads their own words and presses. The studio
 * key is the trigger, so the fill lands after `enterRoom`'s thread switch has
 * settled — the fresh thread's composer, not the one we left.
 */
function ComposerPrefill() {
  const composer = useComposerHandle();
  const studio = useActiveStudio();
  useEffect(() => {
    // Takes unconditionally: a hop to a room OTHER than the staged one forgets
    // the sentence instead of leaving it to surface on some later navigation.
    const text = takeComposerPrefill(studio);
    if (!text) return;
    try {
      composer.setText(text);
    } catch {
      /* composer not writable in this room — degrade to no prefill */
    }
  }, [studio, composer]);
  return null;
}

/** Tallest the dictation height-lock will hold; matches the input's max-height. */
const DICTATION_MAX_LOCK = 200;

function Composer() {
  const checkExternal = useInteractionSync();
  const inputRef = useRef<HTMLTextAreaElement>(null);

  // The composer starts EMPTY after onboarding. The wizard used to stage a
  // sample ask here, which meant a brand-new owner arrived to someone else's
  // sentence sitting on top of the welcome screen's three one-click asks — a
  // choice turned into a chore, and slower than the chips it obscured. The
  // landing is now the welcome screen itself; see the wizard's docblock.
  //
  // Interim speech results churn the text (lines appear, then get replaced),
  // which would make the auto-sizing textarea jump as it re-fits. While
  // dictating we lock min-height to the tallest height seen so it can only
  // grow; the lock is released once the composer empties (i.e. after send),
  // so a stopped-but-unsent dictation keeps its grown size.
  const dictating = useComposerState((c) => {
    const type = c.dictation?.status.type;
    return type === "starting" || type === "running";
  });
  const isEmpty = useComposerState((c) => c.isEmpty);

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
      {/* Pending image attachments + the provider-consent line, above the input. */}
      <AttachmentChips />
      <ComposerPrimitive.Input
        ref={inputRef}
        className="ain-composer__input"
        placeholder="How can I help you today?"
        autoFocus
        rows={1}
        onFocus={checkExternal}
        onInput={checkExternal}
      />
      {/* Action toolbar — its own row beneath the input, so the text field keeps
          the full width however many controls the composer grows (two-row
          layout). Convo-toggle + attach + dictate sit at the leading edge; send
          is pushed to the trailing edge by the spacer. */}
      <div className="ain-composer__toolbar">
        {/* Lift/drop the conversation over the preview canvas. Only shown when the
            chat is docked to the bottom (editor studio, phone width) — CSS gates
            it; inert everywhere else. */}
        <IconButton
          className="ain-composer__convotoggle"
          onClick={toggleConvo}
          aria-pressed={convoOpen}
          label={convoOpen ? "Hide conversation" : "Show conversation"}
        >
          {convoOpen ? <ChevronDownIcon /> : <ChevronUpIcon />}
        </IconButton>
        <AttachButton />
        <DictateButton />
        <span className="ain-composer__spacer" />
        <ThreadPrimitive.If running={false}>
          <ComposerPrimitive.Send className="ain-btn ain-composer__send" aria-label="Send"><SendIcon /></ComposerPrimitive.Send>
        </ThreadPrimitive.If>
        <ThreadPrimitive.If running>
          <ComposerPrimitive.Cancel className="ain-btn ain-composer__send ain-composer__send--stop" aria-label="Stop"><StopIcon /></ComposerPrimitive.Cancel>
        </ThreadPrimitive.If>
      </div>
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
  const runtime = useConsoleChat();
  const thread = useConsoleThread();
  const edge = useActiveThreadWindowEdge();
  const sentinelRef = useRef<HTMLDivElement>(null);
  // The latest load callback, readable from the (once-mounted) observer.
  const loadRef = useRef<() => void>(() => {});

  loadRef.current = () => {
    const threadId = runtime.activeThread().remoteId;
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
      <Button onClick={() => loadRef.current()} disabled={edge?.loading}>
        {edge?.loading ? "Loading…" : "Load earlier messages"}
      </Button>
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
  const runtime = useConsoleChat();
  return useSyncExternalStore(
    (cb) => runtime.subscribe(cb),
    () => runtime.activeThread().title ?? "",
  );
}

/**
 * The active (main) thread's local id, reactively. Used as the transcript
 * boundary's reset key: when the user navigates to another thread, the id
 * changes and the boundary clears any error from the switch — so a stale-index
 * throw mid-transition can never stick across the navigation that caused it.
 */
function useActiveThreadId(): string {
  const runtime = useConsoleChat();
  return useSyncExternalStore(
    (cb) => runtime.subscribe(cb),
    () => runtime.threadList().activeId,
  );
}

/** The active (main) thread's backend id, reactively — "" for a fresh/unsent
 *  thread. WIP rows compare against it to mark the one you're in. Alias of the
 *  shared binding in thread-seal-hooks.ts (one subscription shape, so the seal
 *  verdict and "which thread am I in?" can never disagree). */
const useActiveRemoteId = useThreadRemoteId;

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
  const runtime = useConsoleChat();
  const guardedSwitch = useGuardedSwitch();
  const viewportRef = useRef<HTMLDivElement>(null);
  // Reset key for the transcript boundary below: a thread change remounts it
  // clean, so a render race during the switch never persists past it.
  const threadId = useActiveThreadId();
  // The active thread's backend id + wrapped-up state: a sealed thread swaps the
  // composer for the celebration end-state (read-only), and a just-published
  // thread shows the cancelable wrap-up offer in the same slot.
  const remoteId = useThreadRemoteId();
  const sealed = useThreadSealed(remoteId);
  // An archived thread is read-only history (studio-navigation.md §4): opened to
  // read, never re-entered as live. Like a lock it swaps the composer for a
  // read-only pane — the way to continue is a fresh thread on the same resource.
  const archived = useSyncExternalStore(
    (cb) => runtime.subscribe(cb),
    () => runtime.activeThread().status === "archived",
  );
  // Already on a fresh (unsent) thread: the runtime's new-thread is a singleton,
  // so "New chat" would dry-fire (switchToThread(sameId) early-returns). Law 12 —
  // no silent controls: dim the "+" and teach the fresh-context idea on hover.
  const fresh = useSyncExternalStore(
    (cb) => runtime.subscribe(cb),
    () => runtime.activeThread().status === "new",
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
  // No agent in this studio's catalog (an editor-only studio, or its workflow is
  // missing) — the transcript stays readable but nothing can take a turn. NOT a
  // capability state: a room is never dropped for what it cannot do.
  const activeStudio = useActiveStudio();
  const noAgent = agentsForStudio(activeStudio).length === 0;
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
  // known, else the next-new-conversation pick. An empty welcome/description
  // falls back to the console defaults; the CHIPS never fall back — they come
  // only from the flow's configured `sample_asks` (see starterAsks), so a room
  // with none configured shows none rather than another room's.
  const pinned = useActiveThreadWorkflow();
  const selected = useSelectedWorkflow();
  const flow = pinned ?? selected;
  const heading = flow?.welcomeText || "What would you like to create?";
  const body = flow?.description || "Pages, posts, whole sites — just say the word.";
  const asks = starterAsks(flow);
  return (
    <ThreadPrimitive.Root className="ain-thread">
      <PanelBar
        className="ain-panelbar--chat"
        lead={
          <IconButton onClick={onToggleSidebar} label="Conversations">
            <MenuIcon />
          </IconButton>
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
            <IconButton
              label={fresh ? "You're already in a fresh chat — it starts with a clean slate" : "New chat — starts fresh, with a clean context"}
              disabled={fresh}
              onClick={() => guardedSwitch(() => void runtime.switchToNewThread())}
            >
              <PlusIcon />
            </IconButton>
          </>
        }
      />
      <ThreadPrimitive.Viewport className="ain-viewport" ref={viewportRef}>
        <LoadEarlier viewportRef={viewportRef} />
        <ThreadPrimitive.Empty>
          <div className="ain-welcome">
            <AtelierMark className="ain-logo" />
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
              <Button onClick={retry}>Try again</Button>
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
        {/* Renders only in the one moment it's earned — after the studio's first
            live build, and only if the owner has no name yet. See name-invite.ts. */}
        <NameInvite />
        {/* Hands the user back the sentence that sent them here (never sends it).
            Outside the branches below on purpose — see ComposerPrefill. */}
        <ComposerPrefill />
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
            {/* What this room can DO — present in every room, always, so the row
                is familiar from a healthy install and a dimmed chip later reads
                as information rather than alarm. Scoped twice: the STUDIO decides
                which verbs the room raises at all (General never mentions
                pictures), the room's own AGENT decides which of those are live.
                Display only: it gates nothing, and each tool still reports its
                own failure. */}
            <CapabilityChips
              studioVerbs={studioVerbs(studioOfAgent(flow?.id) ?? activeStudio)}
              agentVerbs={flow?.verbs}
            />
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
 * Per-thread "⋯" dropdown: the row stays all title; Finish, Archive and
 * Delete collapse into the kit Menu (it portals out of the list, so the list's
 * overflow scroll never clips it).
 */
function ThreadItemMenu({ remoteId }: { remoteId: string }) {
  const runtime = useConsoleChat();
  const item = useThreadListEntryHandle();

  // Finish wraps the thread up read-only (the manual parallel of publish's offer);
  // the seal auto-archives it out of the room in the same save (D8) — the local
  // flip drops the row at once. If it's the thread we're in, SEAL the machine to
  // land on a fresh thread in the same room. "Reopen" is retired: to continue,
  // start fresh.
  const finish = () => {
    if (!remoteId) return;
    void sealThread(remoteId, true);
    rememberThreadSeal(remoteId, true);
    if (runtime.activeThread().remoteId === remoteId) consoleNav.seal();
  };

  return (
    <Menu
      trigger={
        <IconButton className="ain-tli__more" label="Thread options">
          <MoreHorizontalIcon />
        </IconButton>
      }
    >
      <MenuItem icon={<SparkleIcon />} onSelect={finish}>Finish &amp; wrap up</MenuItem>
      <MenuItem icon={<ArchiveIcon />} onSelect={() => item.archive()}>Archive</MenuItem>
      <MenuItem danger icon={<TrashIcon />} onSelect={() => item.delete()}>Delete</MenuItem>
    </Menu>
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
  const runtime = useConsoleChat();
  const flowV = useSyncExternalStore(subscribeFlows, flowVersion);
  const wnV = useSyncExternalStore(subscribeWorkingNodes, workingNodeVersion);
  const sealV = useSyncExternalStore(subscribeSeals, sealVersion);
  // A stable key that changes when threads are added / removed / (un)archived.
  const idsKey = useThreadListState((s) => `${s.ids.join(",")}|${s.archivedIds.join(",")}`);
  return useMemo(() => {
    const s = runtime.threadList();
    const mk = (tid: string, archived: boolean): ThreadRow | null => {
      const remoteId = runtime.threadById(tid)?.remoteId;
      return remoteId ? { remoteId, archived, sealed: isThreadSealed(remoteId) } : null;
    };
    return [
      ...s.ids.map((tid) => mk(tid, false)),
      ...s.archivedIds.map((tid) => mk(tid, true)),
    ].filter((r): r is ThreadRow => r !== null);
    // idsKey/flowV/wnV/sealV are the reactive triggers; runtime is stable.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [runtime, idsKey, flowV, wnV, sealV]);
}

/**
 * The workspace's chat column. Hidden for an editor-only studio (no agent in the
 * catalog) — UNLESS the section already holds live conversations: history must
 * stay reachable even when a studio's agent is dropped (its workflow removed or
 * renamed, say — yesterday's threads keep their transcript). In that state the
 * COMPOSER goes read-only (`noAgent` mode)
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
    <div className="ain-workspace__chat" data-testid="chat-column">
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
  const remoteId = useThreadListEntry((t) => t.remoteId);
  const archived = useThreadListEntry((t) => t.status === "archived");
  const guardedSwitch = useGuardedSwitch();
  const activeRemote = useActiveRemoteId();
  useRoomTick();
  const sealed = useThreadSealed(remoteId);
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
  const isLoading = useThreadListState((s) => s.isLoading);
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
  // The content browser's own "New page" opens this same form (it can't render it
  // — it's a distant sibling), so the TYPE question is asked on every path that
  // starts a page, not just the `+` here. See new-page-request.ts.
  useEffect(() => subscribeNewPageRequest(() => setCreating(true)), []);
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
      data-testid="section-sidebar"
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
 * A crumb that picks one of several places (a section, a group's studios, the
 * agent): a crumb-styled trigger over the kit Menu, the current one checked.
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
  const selected = options.find((o) => o.selected)?.key ?? "";
  return (
    <div className={`ain-crumb${className ? ` ${className}` : ""}`}>
      <Menu
        label={ariaLabel}
        align="start"
        sideOffset={6}
        className="ain-crumb__menu"
        trigger={
          <button className="ain-crumb__trigger" aria-label={ariaLabel}>
            {trigger}
            <ChevronDownIcon className="ain-crumb__caret" aria-hidden />
          </button>
        }
      >
        <MenuRadioGroup value={selected} onValueChange={onChoose}>
          {options.map((o) => (
            <MenuRadioItem key={o.key} value={o.key} icon={o.Icon && <o.Icon className="ain-crumb__icon" aria-hidden />}>
              {o.label}
            </MenuRadioItem>
          ))}
        </MenuRadioGroup>
      </Menu>
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
 * (Settings, editor-only) shows nothing.
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
      <Dialog
        open={confirming != null}
        onOpenChange={(open) => !open && setConfirming(null)}
        title="Switch agent?"
        description={
          <>
            This conversation runs on <strong>{pinned?.label}</strong> and can&apos;t switch agents midway.
            Start a <strong>new conversation</strong> on <strong>{confirming?.label}</strong>? The current one stays in the sidebar.
          </>
        }
        actions={
          <>
            <DialogClose><Button>Cancel</Button></DialogClose>
            <Button variant="primary" onClick={startNewChat}>Start new chat</Button>
          </>
        }
      />
    </>
  );
}

/* ----------------------------------------------------------------- user menu */
/**
 * Account flyout: an avatar chip — the user's initial, falling back to a
 * person glyph — replaces the bare username and Log out link in the top bar.
 * Items come from Drupal's "User account menu" (My account, Log out, plus
 * whatever a site builder adds). A kit `Menu` (Radix): arrows, Home/End and
 * typeahead move, Escape or an outside click closes, focus returns to the
 * trigger. Link entries are `MenuItem asChild` anchors.
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
  const [accountOpen, setAccountOpen] = useState(false);
  const btnRef = useRef<HTMLButtonElement>(null);

  if (!viewer && accountMenu.length === 0) return null;

  return (
    <div className="ain-usermenu">
      <Menu
        label="Account"
        align="end"
        sideOffset={6}
        className="ain-usermenu__menu"
        trigger={
          <button
            ref={btnRef}
            type="button"
            className="ain-btn ain-usermenu__trigger"
            aria-label={name || email ? `Account: ${name || email}` : "Account"}
          >
            {viewer?.avatarUrl ? (
              <img className="ain-usermenu__avatar ain-usermenu__avatar--img" src={viewer.avatarUrl} alt="" aria-hidden />
            ) : initial ? (
              <span className="ain-usermenu__avatar" aria-hidden>{initial}</span>
            ) : (
              <span className="ain-usermenu__avatar" aria-hidden><PersonIcon /></span>
            )}
            <ChevronDownIcon className="ain-usermenu__caret" aria-hidden />
          </button>
        }
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
        {viewer && <MenuItem onSelect={() => setAccountOpen(true)}>Manage account</MenuItem>}
        {/* System (/admin — the curated Atelier landing, the basement). Named
            "System" so it never collides with the studios that live INSIDE the
            console (Content/Globals/…). A plain same-tab anchor is the
            declarative workspace form (surface-nav: within-workspace = same
            tab). Server-gated on canAdmin (the admin-overview permission), so a
            non-admin never sees a door they can't open. */}
        {settings().canAdmin && (
          <MenuItem asChild>
            <a href="/admin">System</a>
          </MenuItem>
        )}
        {/* Re-entry into the onboarding wizard — the v1 settings surface (Law 14).
            Server-gated on canReenter (admin on a configured site), so a non-admin
            never sees a door they can't open. */}
        {settings().onboarding?.canReenter && (
          <MenuItem onSelect={() => openSurface(`${consoleBase()}?onboarding=1`, "workspace")}>
            Set up AI providers
          </MenuItem>
        )}
        {accountMenu.map((item) => (
          <MenuItem key={item.url} asChild>
            <a href={item.url}>{item.title}</a>
          </MenuItem>
        ))}
      </Menu>
      {accountOpen && (
        <AccountPane
          onClose={() => setAccountOpen(false)}
          onViewerChange={() => bumpViewer((v) => v + 1)}
          returnFocus={btnRef}
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
  // The studio's lazy half (studio-loader.ts): the chunk is usually already
  // here — the URL's studio loads before first render, the rest at idle — so
  // the skeleton shows only on a cold switch, for the one round trip.
  const { status, module, retry } = useStudioModule(studioKey);
  if (status === "failed") return <StudioLoadFailed name={studioDef(studioKey)?.name ?? studioKey} retry={retry} />;
  if (!module) return <StudioLoading />;
  const { Studio, Preview } = module;
  // A studio with both a Preview and an editor renders Preview first (centre
  // canvas), then the editor rail (right). A studio without a Preview renders
  // its Studio as the centre canvas itself. Both are flat siblings of the chat
  // inside .ain-workspace so each collapses on its own as the viewport narrows
  // — no wrapping element to fight the responsive cascade.
  return (
    <>
      {Preview && <Preview />}
      <Studio onClose={onClose} />
    </>
  );
}

/**
 * The rail while a studio's chunk is in flight: the rail's own geometry with
 * placeholder bars (Law 09 — a skeleton keeps the layout where it will be),
 * so the workspace does not jump when the real rail lands.
 */
function StudioLoading() {
  return (
    <aside className="ain-studio__rail ain-studio-loading">
      <div className="ain-panelbar">
        <span className="ain-skeleton ain-skeleton--btn" />
      </div>
      <LoadingState variant="fields" label="Loading studio" />
    </aside>
  );
}

/**
 * The rail when its chunk could not be fetched (offline, or a deploy mid-session
 * replaced the files). The chat column still works; this offers the retry.
 */
function StudioLoadFailed({ name, retry }: { name: string; retry: () => void }) {
  return (
    <aside className="ain-studio__rail ain-studio-loading ain-studio-loading--failed" role="alert">
      <div className="ain-panelbar">
        <span className="ain-panelbar__title">{name}</span>
      </div>
      <div className="ain-studio-loading__body">
        <p className="ain-studio-loading__text">
          The {name} studio could not be loaded. Check the connection, or reload if a new version was
          just installed.
        </p>
        <button type="button" className="ain-btn" onClick={retry}>
          Try again
        </button>
      </div>
    </aside>
  );
}

/**
 * Registers every chat widget the LOADED studio modules bring
 * (StudioUiModule.ToolUIs — e.g. the Library studio's `media_result` card).
 * Mounted regardless of which studio is active or on, so cards in stored
 * threads keep rendering; re-renders as each studio's chunk lands (the loader
 * preloads them all at idle after boot).
 */
function StudioToolUIs() {
  return (
    <>
      {useLoadedStudioToolUIs().map(({ key, ToolUI }) => (
        <ToolUI key={key} />
      ))}
    </>
  );
}

/**
 * "Links are disabled in the preview" modal. The live-preview iframes are
 * interactive, but following a link would navigate the frame off the preview
 * and drop the brand overlay / unsaved page draft — so preview-nav.ts cancels
 * anchor clicks and fires here. We explain that, and offer to open the link in
 * a new tab so the user can still get where they were headed. Esc / overlay
 * click / "Got it" dismiss (the kit Dialog).
 */
function PreviewLinkBlockedModal() {
  const [href, setHref] = useState<string | null>(null);
  useEffect(() => subscribeBlockedLink(setHref), []);
  // Show a readable destination; keep the full href for the new-tab action.
  let label = href ?? "";
  try {
    if (href) {
      const u = new URL(href);
      label = u.host + u.pathname + u.search;
    }
  } catch {
    /* unparsable → show it raw */
  }
  return (
    <Dialog
      open={href !== null}
      onOpenChange={(open) => !open && setHref(null)}
      title="Links are disabled in the live preview"
      description={
        <>
          This is a working preview — following <span className="ain-confirm__code">{label}</span> would
          navigate away and lose your current changes.
        </>
      }
      actions={
        <>
          <DialogClose><Button>Got it</Button></DialogClose>
          <Button
            variant="primary"
            onClick={() => {
              if (href) openSurface(href, "output");
              setHref(null);
            }}
          >
            Open in new tab ↗
          </Button>
        </>
      }
    />
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
          <Button
            className="ain-topbar__edittoggle"
            onClick={toggleEdit}
            aria-pressed={editOpen}
            aria-label="Edit values"
            title="Edit values"
          >
            <SlidersIcon /> <span className="ain-topbtn__label">Edit</span>
          </Button>
        )}
        {/* Studio actions (Discard / Publish / leave) portal into this slot from
            the active studio so they stay reachable when the rail is collapsed. */}
        <span className="ain-studio-actions" id="ain-studio-actions" />
        {allowThemeSwitch && (
          <IconButton onClick={onToggleTheme} label="Toggle theme">
            {theme === "dark" ? <SunIcon /> : <MoonIcon />}
          </IconButton>
        )}
        {/* View the live site — the console's output. New tab, always (the
            surface-nav rule: protect the open draft + thread behind it). */}
        <IconButton onClick={() => openSurface("/", "output")} label="View site">
          <GlobeIcon />
        </IconButton>
        <UserMenu />
      </div>
    </header>
  );
}

/* ---------------------------------------------------------------------- app */
/**
 * Binds the console machine and the URL to the chat runtime, and renders nothing.
 *
 * Both used to run in `App()` off the runtime object it had just constructed.
 * They now take {@link ConsoleChat} — our handle, read from the client in context
 * — and App's own body sits OUTSIDE the provider, so the work moved to a child.
 */
function RuntimeBindings() {
  const chat = useConsoleChat();
  // (console-nav.ts) Runs before any user-driven ENTER_ROOM / SWITCH_THREAD.
  useEffect(() => bindRuntime(chat), [chat]);
  // URL ⇄ console machine (Phase 2, D3): the room owns the path
  // (/atelier/<studio>[/…/<nid>]) and the active thread rides as ?thr=. This one
  // hook projects the machine's room/thread into the address bar and resolves
  // deep links / back-forward back into the machine (console-url is the codec).
  useConsoleUrl(chat);
  return null;
}

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
  // The console-machine binding and the URL sync used to live here, reading the
  // runtime object App had just built. They now need the chat CLIENT, which only
  // exists inside the provider below — see {@link RuntimeBindings}.
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
  // iff it has an editor. An EDITOR-ONLY studio (Settings: no chat agent) has
  // no agents in the catalog — `hasAgents` gates the chat column off so the
  // workspace is just preview + editor rail (the composer is gated by construction).
  // Subscribe to the statechart so the shell's `data-room` test anchor tracks
  // room changes WITHIN a studio (content list → node → draft), not just studio
  // switches — `useActiveStudio` alone would leave it stale.
  useRoomTick();
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
      {/* Binds the console statechart + the address bar to the conversation.
          First child on purpose: React runs a child's effects before its
          siblings' below it, so console-nav holds the chat handle before any
          room can be entered. */}
      <RuntimeBindings />
      {/* Registers the human-in-the-loop choice widget (FlowDrop ChoiceNode). */}
      <FlowDropChoiceToolUI />
      {/* Registers the live node-execution trail (the `aincient_progress` part). */}
      <NodeProgressToolUI />
      {/* Registers the per-turn token-usage + cost footer (the `aincient_usage` part). */}
      <UsageFooterToolUI />
      {/* Registers the weather card (the `weather_card` generative-UI tool). */}
      <WeatherCardToolUI />
      {/* Registers the generic `data_table` widget (e.g. list_pages → open in studio). */}
      <DataTableToolUI />
      {/* Every loaded studio's chat widgets (see StudioToolUIs). */}
      <StudioToolUIs />
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
        // Test anchors (tests/e2e). The shell is the single place that knows
        // BOTH which studio is active and which room it resolved to, so the e2e
        // suite reads routing off these two attributes rather than re-deriving
        // it from visible copy. Cheap, and they cannot drift from the truth the
        // shell already renders with.
        data-testid="console-shell"
        data-studio={activeStudio}
        data-room={activeRoom().kind}
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
            (denied) or that's gone: the end-state card IS the dialog (asChild),
            answered only by its actions, which clear the dead-end and route the
            user somewhere useful. */}
        <Dialog open={docEnd != null} onOpenChange={() => {}} dismissible={false} asChild>
          {docEnd && (
            <ThreadEndState
              variant={docEnd.kind}
              className="ain-endstate--overlay"
              TitleAs={DialogTitle}
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
          )}
        </Dialog>
        {/* Shell-level dirty-guard: a thread switch that would drop the open
            page/block draft's unsaved edits confirms first. Cancel keeps the
            draft, Discard runs the staged switch (whose clear-on-switch then
            drops the draft). */}
        <Dialog
          open={pendingSwitch != null}
          onOpenChange={(open) => !open && setPendingSwitch(null)}
          title="Unsaved changes"
          description="This page has unsaved changes. Switching conversations will discard them — save or publish first to keep them."
          actions={
            <>
              <DialogClose><Button>Cancel</Button></DialogClose>
              <Button variant="primary" onClick={confirmSwitch}>
                Discard &amp; switch
              </Button>
            </>
          }
        />
      </div>
      </StudioUIContext.Provider>
      </SwitchGuardContext.Provider>
    </AssistantRuntimeProvider>
  );
}
