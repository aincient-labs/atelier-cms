import { useEffect, useRef, useState } from "react";
import {
  Button,
  IconButton,
  PanelBar,
  SegmentedControl,
  StudioActionsPortal,
  XIcon,
  RotateCcwIcon,
  WrenchIcon,
  SparkleIcon,
  ChevronUpIcon,
  ChevronDownIcon,
  Notice,
  StudioStatus,
} from "@console/kit";
import { useThreadState } from "@console/aui";
import {
  useConsoleChat,
  useConsoleThread,
  getAuditLang,
  getAuditNode,
  setAuditNode,
  subscribeAuditNode,
  clearDocEnd,
  setDocEnd,
  getPageDraft,
  setPageDraft,
  subscribePageDraft,
  getPageBaseline,
  getPageOrigins,
  subscribePageBaseline,
  changedPaths,
  fieldChanges,
  fieldValue,
  withFieldValue,
  getPageNode,
  getPageLang,
  getModeration,
  subscribeModeration,
  loadPageIntoStudio,
  saveDraft,
  publishDoc,
  runTransition,
  PageLifecycleBar,
  transitionNotice,
  reloadPreview,
  DocLoadError,
  RevisionConflictError,
  LockConflictError,
  offerWrapup,
  consoleNav,
  apiUrl,
  setSelectedSection,
  revealMessage,
  takeRequestedFieldAnchor,
  REVEAL_FIELD_EVENT,
  PAGE_LENS,
} from "@console/sdk";
import type { PageMeta, Transition } from "@console/sdk";
import {
  SEVERITY_LABEL,
  auditedLabel,
  batchInstruction,
  buildRail,
  filterRail,
  isAiFixable,
  isChanged,
  needsYou,
  rowFindings,
  rowHolds,
  rowNeedsYou,
  stepRow,
  wordDiff,
  type AuditReport,
  type Finding,
  type FilteredSection,
  type MetaFieldDef,
  type RailContext,
  type RailFilter,
  type Row,
} from "./checks-rows";
import { lensForRow, rowSections } from "./checks-lenses";
import { focusSection, onRowRequest, publishRail, resetCanvas, setCanvasLens } from "./checks-canvas";

/**
 * The Checks studio — a page-health FIX LOOP (the findings rail beside the shared
 * live preview). Pick a page and it loads that page's draft into the shared
 * page-state store (so the {@link PagePreview} centre canvas renders it) and
 * fetches the deterministic audit from `/atelier/audit/{node}/report`, grouped
 * by check with worst-first ordering and passes folded away.
 *
 * Each actionable finding is now a DEAD-END no longer: "Fix with AI" auto-sends a
 * finding-specific instruction to the repair agent, which stages a `preview_page`
 * op into the same draft (the preview updates live); the human reviews and
 * Publishes. The studio asks for the latest saved DRAFT (`revision=draft` — the
 * server defaults to the live page, DECISIONS 0450), so after Save draft / Publish a
 * re-run recomputes from {@link AuditEngine} and the cleared finding drops off —
 * no UI-side "resolved" bookkeeping.
 *
 * The staged-draft → Publish substrate is SHARED with the Content studio (one
 * HITL write path — the AI only suggests, the human Publishes); Plan A's
 * single-writer editor lock (keyed `(nid, langcode)`, `studio` provenance)
 * guarantees only one studio holds the draft at a time. Every actionable finding
 * is now fixable end-to-end: page title + broken internal links (Phase 1) and the
 * SEO/meta findings — description, canonical, Open Graph (Phase 2) — the latter
 * via the draft's `meta` block, which the repair agent stages with `set_meta` and
 * the manual SEO editor stages inline; both persist to `field_metatag` on Publish.
 *
 * The review loop (DECISIONS 0453): staged changes are graded on the unsaved
 * draft, and the rail reviews them — a banner ("N changes staged · M need you",
 * a reviewed meter, Previous / Next on J / K), filters (Needs you · Changed ·
 * All), and rows the chat card's field links select (REVEAL_FIELD_EVENT).
 */

/** The report endpoint for a page, in the TRANSLATION being audited, always
 *  for the latest saved DRAFT: the fix loop grades what's about to ship (the
 *  server's default is the live page — DECISIONS 0450). A German page is graded
 *  on its German title, copy and links. No langcode = the source. */
const reportUrl = (nid: string, langcode: string | null) =>
  apiUrl(
    `/audit/${encodeURIComponent(nid)}/report?revision=draft` +
      (langcode ? `&langcode=${encodeURIComponent(langcode)}` : ""),
  );

/** The same endpoint, POSTed `{schema, langcode}`: graded on the UNSAVED draft
 *  by the server's own checks (DECISIONS 0453). */
const unsavedReportUrl = (nid: string) => apiUrl(`/audit/${encodeURIComponent(nid)}/report`);

/** How long the draft must sit still before it is re-graded. */
const REAUDIT_DEBOUNCE_MS = 600;

/** The last fix turn the rail sent (see `needs` in {@link ChecksStudio}). */
type FixTurn = {
  /** Counts turns, so the rail can react once per finished one. */
  key: number;
  /** The finding ids the turn asked the agent to fix. */
  sent: ReadonlySet<string>;
  /** The thread's message count before the ask — the reply comes after it. */
  after: number;
  started: boolean;
  done: boolean;
  replyId: string | null;
};

const NO_FINDINGS: ReadonlySet<string> = new Set();

/** Whether the shared draft differs from its saved baseline. */
const draftIsDirty = (): boolean => {
  const draft = getPageDraft();
  return !!draft && changedPaths(getPageBaseline(), draft).length > 0;
};

export function ChecksStudio({ onClose }: { onClose: () => void }) {
  const runtime = useConsoleChat();
  const thread = useConsoleThread();
  const [nodeId, setNodeId] = useState<string | null>(null);
  const [langcode, setLangcode] = useState<string | null>(null);
  // The report on the SAVED draft (GET), and — while the draft has unsaved
  // changes — the one graded on the unsaved draft (POST). The rail shows the
  // latter when there is one; the former is its "before".
  const [savedReport, setSavedReport] = useState<AuditReport | null>(null);
  const [unsavedReport, setUnsavedReport] = useState<AuditReport | null>(null);
  const report = unsavedReport ?? savedReport;
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [writing, setWriting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [writeError, setWriteError] = useState<string | null>(null);
  const [moderation, setModerationState] = useState(() => getModeration());
  // Re-render on every draft / baseline change: row values, origins and the
  // dirty flag are read from the page-state store during render.
  const [, setDraftTick] = useState(0);
  const dirty = draftIsDirty();
  const staged = dirty ? fieldChanges(getPageBaseline(), getPageDraft()).length : 0;
  // A re-grade of the unsaved draft is scheduled or in flight: the report may
  // not reflect the latest change yet, so "needs you" waits for it.
  const [regrading, setRegrading] = useState(false);
  // The last fix turn: which findings it sent, and — once the agent has
  // finished — its reply. "Needs you" = sent and still failing (DECISIONS 0453);
  // the studio's own memory of the turn, no server field.
  const [fixTurn, setFixTurn] = useState<FixTurn | null>(null);
  const running = useThreadState((t) => t.isRunning);
  const doneTurn = fixTurn?.done && !regrading ? fixTurn : null;
  const needs = doneTurn && report ? needsYou(report, doneTurn.sent) : NO_FINDINGS;
  // A `?field=` deep link (a chat card's field link opened in a new tab).
  const [initialField] = useState(() => takeRequestedFieldAnchor());

  // The audited translation is part of the document's name here: two rooms of
  // the same page differ only by language, so the header has to say which.
  const docName = (report?.title || "Checks") + (langcode ? ` (${langcode})` : "");
  // Writes need the shared draft to be THIS page and edit access on it.
  const canWrite = !!nodeId && getPageNode() === nodeId && moderation.canEdit;

  // Run the deterministic audit for a node. Held in a ref so effects/handlers
  // call the latest closure without re-subscribing.
  const runAudit = useRef<(nid: string, lang?: string | null) => void>(() => {});
  runAudit.current = (nid: string, lang: string | null = getAuditLang()) => {
    setLoading(true);
    setError(null);
    fetch(reportUrl(nid, lang), { credentials: "same-origin" })
      .then((r) => {
        // An inaccessible (403) / missing (404) page is a deep-link dead-end —
        // route it to the shared end-state pane, not the inline error.
        if (r.status === 403 || r.status === 404) {
          setDocEnd({ kind: r.status === 403 ? "denied" : "gone", docKind: "audit", id: nid });
          return null;
        }
        return r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`));
      })
      .then((data: AuditReport | null) => {
        if (data) {
          clearDocEnd();
          setSavedReport(data);
        }
      })
      .catch((e) => setError(`Couldn’t run the audit: ${e instanceof Error ? e.message : e}`))
      .finally(() => setLoading(false));
  };

  // Adopt an audited node: load its draft into the SHARED page-state store (so
  // the preview renders it and the repair agent can stage fixes), then audit it.
  // Skips the load when the draft is already this page (a re-audit, or arriving
  // from the Content→Checks handover with the page already open — preserves any
  // staged edits). A load access failure routes to the doc-end pane.
  const openForChecks = useRef<(nid: string, lang?: string | null) => void>(() => {});
  openForChecks.current = (nid: string, lang: string | null = getAuditLang()) => {
    const load =
      getPageNode() === nid && getPageLang() === lang
        ? Promise.resolve()
        : loadPageIntoStudio(nid, lang, "checks");
    void load
      .then(() => runAudit.current(nid, lang))
      .catch((e: unknown) => {
        const status = e instanceof DocLoadError ? e.status : 0;
        if (status === 403) setDocEnd({ kind: "denied", docKind: "audit", id: nid });
        else if (status === 404) setDocEnd({ kind: "gone", docKind: "audit", id: nid });
        else setError(`Couldn’t open page ${nid}: ${e instanceof Error ? e.message : e}`);
      });
  };

  // The audited node lives in the shared audit-state store so url-sync reflects it
  // as ?audit=<nid> and drives it on back/forward. This studio is a VIEW over that
  // store: it adopts external changes and routes its own picks back through it.
  // Seed on mount from the ?audit deep link, else the last audited node, else the
  // page already open (the Content→Checks handover lands here).
  useEffect(() => {
    const unsub = subscribeAuditNode(() => {
      const id = getAuditNode();
      const lang = getAuditLang();
      setNodeId(id);
      setLangcode(lang);
      setSavedReport(null);
      setUnsavedReport(null);
      setFixTurn(null);
      resetCanvas();
      if (id) openForChecks.current(id, lang);
    });
    // url-sync owns the URL now (a /checks/node/N deep link resolves through the
    // machine → reconcileAudit → setAuditNode, which fires before this effect
    // subscribes). The getAuditNode() fallback catches that already-set node (the
    // subscription notify would have been missed); getPageNode() covers arriving
    // from the Content→Checks handover with the page already open.
    const seed = getAuditNode() ?? getPageNode();
    // The seed's language comes from whichever store supplied it: the audit
    // store when the deep link already resolved, else the page open in Content
    // (the handover — its translation is the one to audit).
    const seedLang = getAuditNode() ? getAuditLang() : getPageLang();
    if (seed) {
      // setAuditNode no-ops (no notify) when the store already holds `seed`, so
      // load directly in that case; otherwise let the subscription do it.
      if (seed === getAuditNode() && seedLang === getAuditLang()) {
        setNodeId(seed);
        setLangcode(seedLang);
        openForChecks.current(seed, seedLang);
      } else {
        setAuditNode(seed, seedLang);
      }
    }
    return unsub;
  }, []);

  // Grade the UNSAVED draft whenever it changes (an agent op, a manual edit, a
  // revert): debounced, aborting the call in flight, so the rail reads "Fixed in
  // draft" from the server's own checks before Save. A clean draft drops the
  // unsaved report and the rail shows the saved-draft one (today's GET).
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight: AbortController | null = null;
    const regrade = () => {
      setDraftTick((n) => n + 1);
      clearTimeout(timer);
      inFlight?.abort();
      inFlight = null;
      const nid = getAuditNode();
      const draft = getPageDraft();
      if (!nid || getPageNode() !== nid || !draft || !draftIsDirty()) {
        setUnsavedReport(null);
        setRegrading(false);
        return;
      }
      setRegrading(true);
      timer = setTimeout(() => {
        const ctl = new AbortController();
        inFlight = ctl;
        fetch(unsavedReportUrl(nid), {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ schema: draft, langcode: getPageLang() }),
          signal: ctl.signal,
        })
          .then((r) => (r.ok ? (r.json() as Promise<AuditReport>) : Promise.reject(new Error(`HTTP ${r.status}`))))
          .then((data) => {
            if (!ctl.signal.aborted) setUnsavedReport(data);
          })
          // A failed re-grade keeps the saved-draft report: never a partial one.
          .catch(() => {
            if (!ctl.signal.aborted) setUnsavedReport(null);
          })
          .finally(() => {
            if (!ctl.signal.aborted) setRegrading(false);
          });
      }, REAUDIT_DEBOUNCE_MS);
    };
    const offDraft = subscribePageDraft(regrade);
    const offBaseline = subscribePageBaseline(regrade);
    // Arriving with staged edits (the Content → Checks handover) grades them now.
    regrade();
    return () => {
      offDraft();
      offBaseline();
      clearTimeout(timer);
      inFlight?.abort();
    };
  }, [nodeId]);
  useEffect(() => subscribeModeration(() => setModerationState(getModeration())), []);

  // Follow the fix turn: it starts when the thread starts running, and is done
  // when it stops — the reply is the last assistant message after the ask.
  useEffect(() => {
    setFixTurn((t) => {
      if (!t || t.done) return t;
      if (running) return t.started ? t : { ...t, started: true };
      if (!t.started) return t;
      const reply = thread
        .getState()
        .messages.slice(t.after)
        .filter((m) => m.role === "assistant")
        .pop();
      return { ...t, done: true, replyId: reply?.id ?? null };
    });
  }, [running, thread]);

  const handleWriteError = (verb: string, e: unknown) => {
    if (e instanceof LockConflictError) {
      setWriteError("Someone else took over editing this page. Re-open it from the list to continue.");
      return;
    }
    if (e instanceof RevisionConflictError) {
      setWriteError("This page changed since you opened it. Re-run checks to load the latest, then re-apply the fix.");
      return;
    }
    setWriteError(`Couldn’t ${verb}: ${e instanceof Error ? e.message : e}`);
  };

  // Save draft — persist the staged fix as a forward revision WITHOUT going live.
  // Re-runs the audit (which reads the latest revision) so a cleared finding drops.
  const saveDraftAction = async () => {
    const draft = getPageDraft();
    if (!nodeId || !draft) return;
    setWriting(true);
    setWriteError(null);
    setNotice(null);
    try {
      await saveDraft(draft, "page", getPageNode(), getPageLang());
      reloadPreview();
      setNotice("Draft saved");
      runAudit.current(nodeId);
    } catch (e) {
      handleWriteError("save the draft", e);
    } finally {
      setWriting(false);
    }
  };

  // Publish — make the staged fix live. The genuine "done" beat → offers the
  // cancelable wrap-up (as the page/brand studios do on publish).
  const publishAction = async () => {
    const draft = getPageDraft();
    const node = getPageNode();
    if (!nodeId || !draft || !node) return;
    setWriting(true);
    setWriteError(null);
    setNotice(null);
    try {
      const result = await publishDoc(draft, "page", node, getPageLang());
      reloadPreview();
      setNotice("Published");
      offerWrapup(runtime.activeThread().remoteId, {
        ...(typeof result?.url === "string" ? { url: result.url as string } : {}),
        node,
      });
      runAudit.current(nodeId);
    } catch (e) {
      handleWriteError("publish", e);
    } finally {
      setWriting(false);
    }
  };

  // A workflow transition from the shared lifecycle bar (DECISIONS 0454) — the
  // same set Content offers, so Checks never shows a Publish the workflow
  // would refuse. One that moves the page forward saves the staged fix first.
  const transitionAction = async (t: Transition, { saveFirst }: { saveFirst: boolean }) => {
    const node = getPageNode();
    if (!nodeId || !node) return;
    setWriting(true);
    setWriteError(null);
    setNotice(null);
    try {
      const draft = getPageDraft();
      if (saveFirst && draft) await saveDraft(draft, "page", node, getPageLang());
      const result = await runTransition(t.id, "page", node);
      reloadPreview();
      setNotice(t.to_published ? "Published" : transitionNotice(t));
      if (t.to_published) {
        offerWrapup(runtime.activeThread().remoteId, {
          ...(typeof result?.url === "string" ? { url: result.url as string } : {}),
          node,
        });
      }
      runAudit.current(nodeId);
    } catch (e) {
      handleWriteError(t.label.toLowerCase(), e);
    } finally {
      setWriting(false);
    }
  };

  // Discard the staged fix: back to the saved draft (the page-state baseline).
  const discardAction = () => {
    const baseline = getPageBaseline();
    if (baseline) setPageDraft(baseline);
    setNotice(null);
    setWriteError(null);
  };

  // Send a finding (or a batch) to the repair agent as a normal user turn — it
  // has the page draft as context + the preview_page tool, so it stages a fix the
  // human then reviews and Publishes. The staged op flips `dirty` via the draft
  // subscription; nothing is written until Publish/Save.
  const sendFix = (findings: Finding[]) => {
    const fixable = findings.filter(isAiFixable);
    if (fixable.length === 0) return;
    setFixTurn((t) => ({
      key: (t?.key ?? 0) + 1,
      sent: new Set(fixable.map((f) => f.id)),
      after: thread.getState().messages.length,
      started: false,
      done: false,
      replyId: null,
    }));
    thread.append({
      role: "user",
      content: [{ type: "text", text: batchInstruction(fixable) }],
      metadata: { custom: { fixAction: { count: fixable.length } } },
    });
  };

  // Back-to-list with a page open: enter the Checks studio landing room. The
  // machine clears the audit node (reconcileAudit) and closes the shared doc —
  // releasing the editor lock — via commitSwitch, so the centre canvas returns to
  // the ContentBrowser. One machine navigation instead of two direct store pokes.
  const backOrClose = () => {
    if (!nodeId) return onClose();
    consoleNav.enterRoom({ kind: "studio", studio: "checks" });
  };

  return (
    <div className="ain-studio__rail" data-testid="studio-rail" data-studio="checks">
      {/* The page's lifecycle pins to the top bar — the SAME bar Content shows
          (DECISIONS 0454). Re-run is Checks' own verb, so it lives in the rail
          head beside the results it refreshes. */}
      <StudioActionsPortal>
        {/* Only once the shared draft IS this page — a gone or denied page
            (the end-state pane) has no lifecycle to show. */}
        {nodeId && getPageNode() === nodeId && (
          <PageLifecycleBar
            moderation={moderation}
            dirty={dirty}
            dirtyLabel="fix staged"
            busy={writing}
            canWrite={canWrite}
            note={moderation.canEdit ? null : "You don’t have access to edit this page right now."}
            onDiscard={discardAction}
            onSaveDraft={() => void saveDraftAction()}
            onPublish={() => void publishAction()}
            onTransition={(t, opts) => void transitionAction(t, opts)}
          />
        )}
        <IconButton
          className="ain-topbar__leave"
          onClick={backOrClose}
          label={nodeId ? "Back to page list" : "Close checks studio"}
          title={nodeId ? "Back to the page list" : "Leave checks studio"}
        >
          <XIcon />
        </IconButton>
      </StudioActionsPortal>

      <PanelBar
        title={docName}
        actions={
          nodeId ? (
            <Button
              size="sm"
              onClick={() => runAudit.current(nodeId)}
              disabled={loading || writing}
              title="Run the checks again on the saved draft"
            >
              <RotateCcwIcon /> {loading ? "Checking…" : "Re-run"}
            </Button>
          ) : undefined
        }
      />

      <div className="ain-checks__body">
        {writeError && <Notice tone="error" panel>{writeError}</Notice>}
        {notice && !writeError && <StudioStatus saved={notice} />}
        {error ? (
          <Notice tone="error" panel>{error}</Notice>
        ) : !nodeId ? (
          <p className="ain-studio__hint">
            Pick a page from the canvas to audit its SEO, meta tags, and internal links —
            then fix issues right here.
          </p>
        ) : loading && !report ? (
          <p className="ain-checks__loading">Running checks…</p>
        ) : report ? (
          <AuditReportView
            report={report}
            base={unsavedReport ? savedReport : null}
            staged={staged}
            needs={needs}
            replyId={doneTurn?.replyId ?? null}
            fixTurnKey={doneTurn?.key ?? null}
            initialField={initialField}
            canWrite={canWrite}
            busy={writing}
            onFix={sendFix}
          />
        ) : null}
      </div>
    </div>
  );
}

/** The rail's view of the shared draft: values, baseline and origins by path. */
const draftContext = (base: AuditReport | null): RailContext => {
  const draft = getPageDraft();
  const baseline = getPageBaseline();
  const origins = getPageOrigins();
  return {
    base,
    draftValue: (path) => fieldValue(draft, path),
    baselineValue: (path) => fieldValue(baseline, path),
    origin: (path) => origins.get(path),
  };
};

const ORIGIN_LABEL = { agent: "Atelier", user: "You" } as const;

/** Show a picked row in the canvas: its section scrolled into view on the
 *  page, or the lens that draws its field. A row with neither leaves it be. */
function followRow(row: Row): void {
  const lens = lensForRow(row);
  if (lens !== PAGE_LENS) {
    if (lens) setCanvasLens(lens);
    return;
  }
  const [section] = rowSections(row);
  if (section) {
    setSelectedSection(section);
    focusSection(section);
  } else setCanvasLens(PAGE_LENS);
}

/** A key press that belongs to a text control (the composer, an inline editor)
 *  — J / K must never steal typing. */
const isTypingTarget = (t: EventTarget | null): boolean =>
  t instanceof HTMLElement && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

function AuditReportView({
  report,
  base,
  staged,
  needs,
  replyId,
  fixTurnKey,
  initialField,
  canWrite,
  busy,
  onFix,
}: {
  report: AuditReport;
  /** The saved-draft report, when `report` graded the unsaved draft. */
  base: AuditReport | null;
  /** How many fields the draft changed since the last save. */
  staged: number;
  /** Findings sent in the last fix turn that still fail. */
  needs: ReadonlySet<string>;
  /** The agent's reply to the last fix turn, to link a row that still needs you. */
  replyId: string | null;
  /** Changes once per finished (and re-graded) fix turn — the rail then opens on Changed. */
  fixTurnKey: number | null;
  /** A field to land on once (a `?field=` deep link). */
  initialField: string | null;
  canWrite: boolean;
  busy: boolean;
  onFix: (findings: Finding[]) => void;
}) {
  const { summary } = report;
  const rail = buildRail(report, draftContext(base));
  const [filter, setFilter] = useState<RailFilter>("all");
  const view = filterRail(rail, filter, needs);
  const { counts } = view;
  // One selected row at a time: it expands to the diff, the gauge and Revert.
  const [selected, setSelected] = useState<string | null>(null);
  // Rows looked at since the changes were staged — a reading aid only: it never
  // gates Save or Publish (DECISIONS 0453).
  const [reviewed, setReviewed] = useState<ReadonlySet<string>>(() => new Set());
  const rootRef = useRef<HTMLDivElement>(null);
  const everyRow = rail.sections.flatMap((s) => [...s.actionable, ...s.passes]);
  const changedRows = everyRow.filter(isChanged);
  const reviewedCount = changedRows.filter((r) => reviewed.has(r.finding.id)).length;

  // The canvas draws pins and lens counts from this rail (DECISIONS 0453, S3).
  useEffect(() => publishRail(rail));
  useEffect(() => () => publishRail(null), []);

  // Select a row (marking it reviewed) and bring it into view. `follow` shows
  // it in the canvas too: the lens that draws its field, or its section in the
  // page — off when the canvas itself asked (a pin is already in view).
  const select = (id: string | null, { scroll = false, follow = true } = {}) => {
    setSelected(id);
    if (!id) return;
    setReviewed((cur) => (cur.has(id) ? cur : new Set(cur).add(id)));
    const row = follow ? everyRow.find((r) => r.finding.id === id) : undefined;
    if (row) followRow(row);
    if (scroll) {
      requestAnimationFrame(() => {
        const head = rootRef.current?.querySelector<HTMLElement>(`[data-finding="${CSS.escape(id)}"] .ain-checks-audit__rowhead`);
        head?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
        head?.focus({ preventScroll: true });
      });
    }
  };
  const step = (dir: 1 | -1) => select(stepRow(view.order, selected, dir), { scroll: true });
  const stepRef = useRef(step);
  stepRef.current = step;

  // Land on the row for a field: keep the filter if it shows the row, else the
  // one that does. A field with no finding (a section prop no check reads)
  // selects its section in the preview instead.
  const revealField = useRef<(path: string) => void>(() => {});
  revealField.current = (path: string) => {
    const row = everyRow.find((r) => r.field === path || !!r.members?.some((m) => m.field === path));
    if (!row) {
      const [head, id] = path.split(".");
      if (head === "sections" && id) setSelectedSection(id);
      return;
    }
    if (!view.order.includes(row) && !(filter === "all" && everyRow.includes(row))) {
      setFilter(isChanged(row) ? "changed" : "all");
    }
    select(row.finding.id, { scroll: true });
  };

  // A pin or a lens list item asks for a row: show it, whatever the filter.
  const revealRow = useRef<(id: string) => void>(() => {});
  revealRow.current = (id: string) => {
    // A lens lists a group's members: their id opens the group.
    const row = everyRow.find((r) => rowHolds(r, id));
    if (!row) return;
    if (!view.order.includes(row)) setFilter(isChanged(row) ? "changed" : "all");
    select(row.finding.id, { scroll: true, follow: false });
  };
  useEffect(() => onRowRequest((id) => revealRow.current(id)), []);

  // The chat card's field links (REVEAL_FIELD_EVENT) and J / K.
  useEffect(() => {
    const onReveal = (e: Event) => {
      const path = (e as CustomEvent<unknown>).detail;
      if (typeof path === "string") revealField.current(path);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.metaKey || e.ctrlKey || e.altKey || isTypingTarget(e.target)) return;
      if (e.key === "j" || e.key === "J") stepRef.current(1);
      else if (e.key === "k" || e.key === "K") stepRef.current(-1);
      else return;
      e.preventDefault();
    };
    window.addEventListener(REVEAL_FIELD_EVENT, onReveal);
    window.addEventListener("keydown", onKey);
    return () => {
      window.removeEventListener(REVEAL_FIELD_EVENT, onReveal);
      window.removeEventListener("keydown", onKey);
    };
  }, []);

  // A `?field=` deep link lands once, on the first report.
  useEffect(() => {
    if (initialField) revealField.current(initialField);
  }, [initialField]);

  // After a fix turn (once its changes are graded), open on what changed —
  // ONCE per turn: the key drops to null during every later re-grade and comes
  // back, which must not yank a reader who has since picked another filter.
  const openedFor = useRef<number | null>(null);
  useEffect(() => {
    if (fixTurnKey === null || openedFor.current === fixTurnKey) return;
    openedFor.current = fixTurnKey;
    if (counts.changed > 0) setFilter("changed");
    // Only the turn key decides; the count is read at that moment.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fixTurnKey]);

  // Saving resets the staged set, and with it what was reviewed.
  useEffect(() => {
    if (staged === 0 && changedRows.length === 0) setReviewed((cur) => (cur.size === 0 ? cur : new Set()));
  }, [staged, changedRows.length]);

  const showBanner = staged > 0 || counts.needs > 0;
  return (
    <div className="ain-checks-audit" ref={rootRef}>
      <div className="ain-checks-audit__summary">
        {report.url && (
          <a className="ain-checks-audit__url" href={report.url} target="_blank" rel="noreferrer">
            {report.url} ↗
          </a>
        )}
        {report.audited && (
          <span className="ain-checks-audit__audited" data-revision={report.audited.revision}>
            {auditedLabel(report.audited)}
          </span>
        )}
        <div className="ain-checks-audit__counts">
          <span className="ain-checks-audit__count" data-severity="fail">{summary.fail} fail</span>
          <span className="ain-checks-audit__count" data-severity="warn">{summary.warn} warn</span>
          <span className="ain-checks-audit__count" data-severity="pass">{summary.pass} pass</span>
          {rail.fixedCount > 0 && (
            <span className="ain-checks-audit__count" data-severity="fixed">{rail.fixedCount} fixed in draft</span>
          )}
        </div>
        {/* Fix all the writeable findings in one batched, minimal-diff turn. */}
        {canWrite && rail.anyFixable && (
          <Button
            size="sm"
            className="ain-checks-audit__fixall"
            onClick={() => onFix(rail.all)}
            disabled={busy}
            title="Ask the repair agent to fix every issue it can, in one pass"
          >
            <SparkleIcon /> Fix all issues
          </Button>
        )}
      </div>
      {showBanner && (
        <div className="ain-checks-audit__banner" role="status" data-testid="checks-banner">
          <span className="ain-checks-audit__banner-text">
            {plural(staged, "change", "changes")} staged
            {counts.needs > 0 && <> · <strong>{counts.needs} need{counts.needs === 1 ? "s" : ""} you</strong></>}
          </span>
          {changedRows.length > 0 && (
            <span className="ain-checks-audit__meter" title="Rows you have opened since these changes were staged — a reading aid, never required to Save or Publish">
              <span className="ain-checks-audit__meter-bar" aria-hidden="true">
                <span style={{ width: `${(reviewedCount / changedRows.length) * 100}%` }} />
              </span>
              {reviewedCount} of {changedRows.length} reviewed
            </span>
          )}
          <span className="ain-checks-audit__banner-nav">
            <IconButton label="Previous row" title="Previous (K)" onClick={() => step(-1)} disabled={view.order.length === 0}>
              <ChevronUpIcon />
            </IconButton>
            <IconButton label="Next row" title="Next (J)" onClick={() => step(1)} disabled={view.order.length === 0}>
              <ChevronDownIcon />
            </IconButton>
          </span>
        </div>
      )}
      <SegmentedControl
        className="ain-checks-audit__filters"
        label="Show findings"
        value={filter}
        onChange={setFilter}
        options={[
          { value: "needs", label: <>Needs you <span className="ain-checks-audit__filtercount">{counts.needs}</span></> },
          { value: "changed", label: <>Changed <span className="ain-checks-audit__filtercount">{counts.changed}</span></> },
          { value: "all", label: <>All <span className="ain-checks-audit__filtercount">{counts.all}</span></> },
        ]}
      />
      {view.sections.length === 0 && filter !== "all" && (
        <p className="ain-checks-audit__empty">
          {filter === "needs"
            ? "Nothing needs you — every issue sent in the last fix is fixed or passing."
            : "No staged changes. Fixes from Atelier or your own edits show up here before you save."}
        </p>
      )}
      {view.sections.map((section) => (
        <CheckSection
          key={section.key}
          section={section}
          canWrite={canWrite}
          busy={busy}
          onFix={onFix}
          selected={selected}
          onSelect={(id) => select(selected === id ? null : id)}
          needs={needs}
          replyId={replyId}
        />
      ))}
    </div>
  );
}

type RowProps = {
  canWrite: boolean;
  busy: boolean;
  onFix: (findings: Finding[]) => void;
  selected: string | null;
  onSelect: (id: string) => void;
  needs: ReadonlySet<string>;
  replyId: string | null;
};

/** How many rows a check renders before "Show more" — a page with hundreds of
 *  broken paths stays one scrollable list without mounting every row. */
const ROW_WINDOW = 100;

/**
 * One check's rows (ordered and folded by {@link buildRail}, filtered by
 * {@link filterRail}): the rows, then — under All — the passes behind a
 * "N passing" toggle, which opens itself when the selection is in there.
 * Past {@link ROW_WINDOW} rows the rest wait behind "Show more"; the window
 * always reaches the selected row, so J / K and a pin never land on a hidden one.
 */
function CheckSection({ section, ...rowProps }: { section: FilteredSection } & RowProps) {
  const { rows, passes, fixable } = section;
  const { canWrite, busy, onFix, selected } = rowProps;
  const [showPasses, setShowPasses] = useState(false);
  const [limit, setLimit] = useState(ROW_WINDOW);
  const passesOpen = showPasses || passes.some((r) => r.finding.id === selected);
  const shown = Math.max(limit, rows.findIndex((r) => r.finding.id === selected) + 1);
  const hidden = rows.length - shown;

  return (
    <section className="ain-checks-audit__check">
      <div className="ain-checks-audit__checkhead-row">
        <h3 className="ain-checks-audit__checkhead">{section.label}</h3>
        {canWrite && fixable.length > 1 && (
          <Button
            size="sm"
            onClick={() => onFix(fixable)}
            disabled={busy}
            title="Fix every writeable issue in this check"
          >
            Fix this check
          </Button>
        )}
      </div>
      {rows.length > 0 && (
        <ul className="ain-checks-audit__findings">
          {rows.slice(0, shown).map((row) => (
            <FindingRow key={row.finding.id} row={row} {...rowProps} />
          ))}
        </ul>
      )}
      {hidden > 0 && (
        <button type="button" className="ain-btn ain-checks-audit__passtoggle" onClick={() => setLimit(shown + ROW_WINDOW)}>
          Show {Math.min(hidden, ROW_WINDOW)} more of {hidden}
        </button>
      )}
      {passes.length > 0 && (
        <>
          <button
            type="button"
            className="ain-btn ain-checks-audit__passtoggle"
            onClick={() => setShowPasses(!passesOpen)}
            aria-expanded={passesOpen}
          >
            {passesOpen ? "Hide" : "Show"} {passes.length} passing
          </button>
          {passesOpen && (
            <ul className="ain-checks-audit__findings">
              {passes.map((row) => (
                <FindingRow key={row.finding.id} row={row} {...rowProps} />
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}

/** The one-line current value a row shows: the field's draft value, or what an
 *  empty one means; a link finding shows its href and how often the page links
 *  there; a group, how many of its fields are set. */
function valueLine(row: Row): string | null {
  if (row.members) {
    const set = row.members.filter((m) => m.value !== "").length;
    return `${set} of ${row.members.length} set`;
  }
  if (row.field) {
    if (row.value !== "") return row.value;
    return row.field.startsWith("meta.") ? "Not set · site default applies" : "Not set";
  }
  const target = row.finding.remediation?.target;
  if (!target?.href) return null;
  const n = target.occurrences ?? 0;
  return n > 1 ? `${target.href} · ${n} links` : target.href;
}

const statusLabel = (status: Row["status"]) => (status === "fixed" ? "Fixed in draft" : SEVERITY_LABEL[status]);

function FindingRow({ row, canWrite, busy, onFix, selected, onSelect, needs, replyId }: { row: Row } & RowProps) {
  const { finding, editor, status } = row;
  const [editing, setEditing] = useState(false);
  // "Fix with AI" is for what still fails; Edit is for any field with an inline
  // editor — a row the agent already fixed in the draft must stay hand-editable.
  const showFix = canWrite && row.fixable;
  const showEdit = canWrite && !!editor;
  const isSelected = selected === finding.id;
  const value = valueLine(row);
  const stillFailing = rowNeedsYou(row, needs);
  // A row reads as status + title + value until opened; the detail, location and
  // actions wait behind the selection. A resolved row folds to one line.
  const expanded = isSelected || editing;
  const compact = !expanded && !row.members && !stillFailing && (status === "pass" || status === "fixed");

  return (
    <li
      className="ain-checks-audit__finding"
      data-finding={finding.id}
      data-severity={status}
      data-selected={isSelected || undefined}
      data-needs-you={stillFailing || undefined}
      data-group={row.members ? true : undefined}
      data-compact={compact || undefined}
    >
      <div className="ain-checks-audit__finding-body">
        <button
          type="button"
          className="ain-checks-audit__rowhead"
          onClick={() => onSelect(finding.id)}
          aria-expanded={isSelected}
        >
          <span className="ain-checks-audit__kicker">
            <span className="ain-checks-audit__status" data-severity={status}>
              {compact && status === "fixed" ? (
                // One line has no room for "in draft"; the staged banner says it.
                <>
                  Fixed<span className="ain-checks-audit__sr"> in draft</span>
                </>
              ) : (
                statusLabel(status)
              )}
            </span>
            {finding.dimension && (
              <span className="ain-checks-audit__dim" data-dim={finding.dimension}>
                {finding.dimension}
              </span>
            )}
            {row.origin && <OriginChip origin={row.origin} />}
          </span>
          <span className="ain-checks-audit__finding-title">
            {compact ? (finding.remediation?.label ?? finding.title) : finding.title}
          </span>
          {value !== null && (
            <span className="ain-checks-audit__value" data-empty={row.field !== null && row.value === "" ? true : undefined}>
              {value}
            </span>
          )}
          {expanded && status !== "fixed" && finding.detail && (
            <span className="ain-checks-audit__finding-detail">{finding.detail}</span>
          )}
          {expanded && finding.location && <span className="ain-checks-audit__finding-loc">{finding.location}</span>}
        </button>
        {stillFailing && (
          <p className="ain-checks-audit__needs">
            Still failing after Atelier’s fix.
            {replyId && (
              <>
                {" "}
                <button type="button" className="ain-checks-audit__replylink" onClick={() => revealMessage(replyId)}>
                  See Atelier’s reply
                </button>
              </>
            )}
          </p>
        )}
        {isSelected &&
          (row.members ? (
            <ul className="ain-checks-audit__members">
              {row.members.map((m) => (
                <MemberDetail key={m.finding.id} row={m} canWrite={canWrite} busy={busy} />
              ))}
            </ul>
          ) : (
            <RowDetail row={row} canWrite={canWrite} busy={busy} />
          ))}
        {expanded && (showFix || showEdit) && (
          <div className="ain-checks-audit__finding-actions">
            {showFix && (
              <Button
                size="sm"
                onClick={() => onFix(rowFindings(row))}
                disabled={busy}
                title={
                  row.members
                    ? "Ask the repair agent to fix every tag on this card in one pass — you review and Publish"
                    : "Ask the repair agent to fix this — you review and Publish"
                }
              >
                <SparkleIcon /> {row.members ? "Fix the card with AI" : "Fix with AI"}
              </Button>
            )}
            {showEdit && editor && (
              <Button
                size="sm"
                onClick={() => setEditing((v) => !v)}
                disabled={busy}
                title={editor.kind === "title" ? "Edit the page title yourself" : "Edit this meta tag yourself"}
              >
                <WrenchIcon /> Edit
              </Button>
            )}
          </div>
        )}
        {editing && editor && <InlineEditor editor={editor} onDone={() => setEditing(false)} />}
      </div>
    </li>
  );
}

function OriginChip({ origin }: { origin: NonNullable<Row["origin"]> }) {
  return (
    <span className="ain-checks-audit__origin" data-origin={origin}>
      <span className="ain-checks-audit__sr"> · changed by </span>
      {ORIGIN_LABEL[origin]}
    </span>
  );
}

function InlineEditor({ editor, onDone }: { editor: NonNullable<Row["editor"]>; onDone: () => void }) {
  return editor.kind === "title" ? <ManualTitleEditor onDone={onDone} /> : <ManualMetaEditor field={editor.field} onDone={onDone} />;
}

/**
 * One finding inside an opened group (the Share card): its tag, status and
 * value, then the same diff / gauge / Revert as a plain row, and its own Edit.
 */
function MemberDetail({ row, canWrite, busy }: { row: Row; canWrite: boolean; busy: boolean }) {
  const [editing, setEditing] = useState(false);
  const label = row.finding.remediation?.label ?? row.finding.title;
  return (
    <li className="ain-checks-audit__member" data-finding={row.finding.id} data-severity={row.status}>
      <div className="ain-checks-audit__member-head">
        <span className="ain-checks-audit__badge" data-severity={row.status}>
          {statusLabel(row.status)}
        </span>
        <span className="ain-checks-audit__member-label">
          {label}
          {row.origin && <OriginChip origin={row.origin} />}
        </span>
        {canWrite && row.editor && (
          <Button size="sm" onClick={() => setEditing((v) => !v)} disabled={busy} title={`Edit the ${label.toLowerCase()} yourself`}>
            <WrenchIcon /> Edit
          </Button>
        )}
      </div>
      <span className="ain-checks-audit__value" data-empty={row.value === "" ? true : undefined}>
        {valueLine(row)}
      </span>
      <RowDetail row={row} canWrite={canWrite} busy={busy} />
      {editing && row.editor && <InlineEditor editor={row.editor} onDone={() => setEditing(false)} />}
    </li>
  );
}

/** A schema location as a reader names it: the section's place on the page,
 *  its component and the prop ("Section 2 (cta) · cta_url") — two sections of
 *  one component only differ by place. */
function locationLabel(section: string, prop: string): string {
  const sections = getPageDraft()?.sections ?? [];
  const at = sections.findIndex((x) => x.id === section);
  return at < 0 ? `Removed section · ${prop}` : `Section ${at + 1} (${sections[at].component}) · ${prop}`;
}

/**
 * The selected row, opened: what the field held when last saved against what
 * it holds now (a word diff), the character gauge when the check bounds it,
 * and Revert — which writes the saved value back as YOUR edit, so the origin
 * clears and the row is re-graded like any other change. A link finding
 * lists every place the page writes it instead, each one a jump to its section.
 */
function RowDetail({ row, canWrite, busy }: { row: Row; canWrite: boolean; busy: boolean }) {
  const changed = row.field !== null && row.value !== row.baseValue;
  const len = row.value.length;
  const [min, max] = row.bounds ?? [0, 0];
  const revert = () => {
    const draft = getPageDraft();
    if (draft && row.field) setPageDraft(withFieldValue(draft, row.field, row.baseValue), { source: "user" });
  };
  if (!row.field) {
    const locations = row.finding.remediation?.target?.locations ?? [];
    if (locations.length === 0) {
      return <p className="ain-checks-audit__detail-note">This finding isn’t tied to one field.</p>;
    }
    return (
      <div className="ain-checks-audit__detail">
        <p className="ain-checks-audit__detail-note">
          {locations.length === 1 ? "Written in one place:" : `Written in ${locations.length} places — one fix changes them all:`}
        </p>
        <ul className="ain-checks-audit__locations">
          {locations.map((l) => (
            <li key={`${l.section}:${l.prop}`}>
              <button
                type="button"
                className="ain-checks-audit__replylink"
                onClick={() => {
                  setSelectedSection(l.section);
                  focusSection(l.section);
                }}
              >
                {locationLabel(l.section, l.prop)}
              </button>
            </li>
          ))}
        </ul>
      </div>
    );
  }
  return (
    <div className="ain-checks-audit__detail">
      {changed ? (
        <p className="ain-checks-audit__diff" aria-label="Change since your last save">
          {wordDiff(row.baseValue, row.value).map((part, i) =>
            part.kind === "same" ? (
              <span key={i}>{part.text}</span>
            ) : part.kind === "del" ? (
              <del key={i}>{part.text}</del>
            ) : (
              <ins key={i}>{part.text}</ins>
            ),
          )}
        </p>
      ) : (
        <p className="ain-checks-audit__detail-note">Unchanged since your last save.</p>
      )}
      {row.bounds && (
        <span className="ain-checks-audit__manual-count" data-ok={len >= min && len <= max}>
          {len} / {min}–{max} characters
        </span>
      )}
      {changed && canWrite && (
        <div className="ain-checks-audit__manual-btns">
          <Button size="sm" onClick={revert} disabled={busy} title="Put back the value from your last save">
            <RotateCcwIcon /> Revert
          </Button>
        </div>
      )}
    </div>
  );
}

/**
 * Inline manual fix for the title finding: a text field bound to the shared
 * draft's title. Writes stage into the draft (setPageDraft) — the preview
 * updates live and Save/Publish persists — never a separate write path.
 */
function ManualTitleEditor({ onDone }: { onDone: () => void }) {
  const [value, setValue] = useState<string>(() => (getPageDraft()?.title ?? "") as string);
  const commit = () => {
    const draft = getPageDraft();
    if (draft) setPageDraft({ ...draft, title: value.trim() });
    onDone();
  };
  return (
    <div className="ain-checks-audit__manual">
      <input
        className="ain-field__input"
        type="text"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder="Page title (aim for 30–60 characters)"
        aria-label="Page title"
        autoFocus
      />
      <div className="ain-checks-audit__manual-btns">
        <Button size="sm" onClick={commit}>
          Apply
        </Button>
        <Button size="sm" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </div>
  );
}

/**
 * Inline manual fix for a meta finding: a field bound to the shared draft's
 * `meta` override block (the parallel of {@link ManualTitleEditor} for SEO tags).
 * Applying stages into the draft (`setPageDraft`) — the preview updates and
 * Save/Publish persists it to `field_metatag` — never a separate write path. A
 * blank value clears the override, so the page inherits the site default again.
 */
function ManualMetaEditor({ field, onDone }: { field: MetaFieldDef; onDone: () => void }) {
  const [value, setValue] = useState<string>(
    () => ((getPageDraft()?.meta ?? {}) as PageMeta)[field.key] ?? "",
  );
  const commit = () => {
    const draft = getPageDraft();
    if (draft) {
      const meta: PageMeta = { ...(draft.meta ?? {}) };
      const trimmed = value.trim();
      if (trimmed) meta[field.key] = trimmed;
      else delete meta[field.key];
      setPageDraft({ ...draft, meta });
    }
    onDone();
  };
  const len = value.trim().length;
  const [min, max] = field.counter ?? [0, 0];
  const inRange = len >= min && len <= max;
  return (
    <div className="ain-checks-audit__manual">
      {field.multiline ? (
        <textarea
          className="ain-field__input"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          rows={3}
          placeholder={field.placeholder}
          aria-label={field.label}
          autoFocus
        />
      ) : (
        <input
          className="ain-field__input"
          type="text"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder={field.placeholder}
          aria-label={field.label}
          autoFocus
        />
      )}
      {field.counter && (
        <span className="ain-checks-audit__manual-count" data-ok={inRange}>
          {len} / {min}–{max} characters
        </span>
      )}
      <div className="ain-checks-audit__manual-btns">
        <Button size="sm" onClick={commit}>
          Apply
        </Button>
        <Button size="sm" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </div>
  );
}
