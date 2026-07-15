import { useEffect, useRef, useState } from "react";
import { useAssistantRuntime, useThreadRuntime } from "@assistant-ui/react";
import { PanelBar } from "./panel-bar";
import { StudioActionsPortal } from "./studio-ui";
import { XIcon, RotateCcwIcon, WrenchIcon, SparkleIcon } from "./icons";
import { getAuditNode, setAuditNode, subscribeAuditNode } from "./audit-state";
import { clearDocEnd, setDocEnd } from "./doc-end-state";
import type { PageMeta } from "./page-state";
import {
  getPageDraft,
  setPageDraft,
  subscribePageDraft,
  getPageNode,
  getPageLang,
  getModeration,
  subscribeModeration,
  loadPageIntoStudio,
  saveDraft,
  publishDoc,
  reloadPreview,
  DocLoadError,
  RevisionConflictError,
  LockConflictError,
} from "./page-state";
import { offerWrapup } from "./thread-seal";
import { consoleNav } from "./console-nav";
import { apiUrl } from "./console-config";

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
 * Publishes. The audit reads the LATEST revision, so after Save draft / Publish a
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
 */

type Severity = "pass" | "warn" | "fail";

/** The axis a finding touches (Phase 2, DECISIONS 0133). */
type Dimension = "meta" | "content" | "structure";

/**
 * The declarative remediation descriptor a finding carries (Phase 2) — the
 * SINGLE source both this UI and the repair agent read; it is authored in the
 * PHP check. `edit_field` names a meta field to edit inline (or via the agent);
 * `edit_prop` is a content fix the agent applies (no inline editor in v1);
 * `aiFixable` gates the "Fix with AI" affordance. Replaces the old hardcoded
 * finding→field maps that lived in both this file and the agent prompt.
 */
type Remediation = {
  action: "edit_field" | "edit_prop" | "none";
  aiFixable: boolean;
  field?: string;
  input?: "text" | "textarea" | "url";
  label?: string;
  constraints?: { min?: number; max?: number };
  target?: { href?: string; section?: string; prop?: string };
};

type Finding = {
  id: string;
  severity: Severity;
  title: string;
  detail: string;
  location: string;
  dimension?: Dimension;
  remediation?: Remediation | null;
};

type Check = { key: string; label: string; findings: Finding[] };

type AuditReport = {
  node_id: string;
  title: string;
  url: string;
  summary: { pass: number; warn: number; fail: number; total: number };
  checks: Check[];
};

const reportUrl = (nid: string) => apiUrl(`/audit/${encodeURIComponent(nid)}/report`);

/**
 * Whether a finding has an AI write path — the finding's OWN remediation says so
 * (`remediation.aiFixable`). Phase 2 (DECISIONS 0133) replaced the hardcoded
 * id-prefix guess with this declarative flag, authored in the PHP check. The
 * repair agent stages the fix; the human Publishes.
 */
function isAiFixable(f: Finding): boolean {
  return f.severity !== "pass" && f.remediation?.aiFixable === true;
}

/** A finding whose manual fix is the inline page-title field (binds draft.title)
 *  — an `edit_field` remediation targeting the special `title` field. */
const isTitleFinding = (f: Finding): boolean =>
  f.remediation?.action === "edit_field" && f.remediation.field === "title";

/** An inline manual SEO field — the descriptor for a meta finding's own editor.
 *  `key` is the draft.meta override to write (a Metatag plugin id); `counter`,
 *  when set, shows a live [min, max] character gauge. Derived from the finding's
 *  remediation (see {@link metaFieldFromRemediation}). */
type MetaFieldDef = {
  key: keyof PageMeta;
  label: string;
  placeholder: string;
  multiline?: boolean;
  counter?: [number, number];
};

/**
 * Derive the inline meta-editor descriptor from a finding's remediation — an
 * `edit_field` action on a meta field OTHER than the page title (which has its
 * own {@link ManualTitleEditor}). Returns null when there's no inline meta
 * editor. The field key, label, input type and character bounds now come from
 * the remediation the check authored — no hardcoded finding→field map.
 */
function metaFieldFromRemediation(f: Finding): MetaFieldDef | null {
  const rem = f.remediation;
  if (!rem || rem.action !== "edit_field" || !rem.field || rem.field === "title") return null;
  const { min, max } = rem.constraints ?? {};
  const counter = min != null && max != null ? ([min, max] as [number, number]) : undefined;
  const label = rem.label ?? rem.field;
  const placeholder = counter
    ? `A ${min}–${max} character ${label.toLowerCase()}`
    : rem.input === "url"
      ? "https://…"
      : label;
  return { key: rem.field as keyof PageMeta, label, placeholder, multiline: rem.input === "textarea", counter };
}

/** One finding → a minimal-diff instruction for the repair agent. */
function fixInstruction(f: Finding): string {
  return (
    `On the page currently open in Checks, fix this issue and change nothing else — ` +
    `${f.title}: ${f.detail}${f.location ? ` (${f.location})` : ""}. ` +
    `Make the smallest edit that clears it.`
  );
}

/** Several findings → one batched, still-minimal instruction. */
function batchInstruction(findings: Finding[]): string {
  if (findings.length === 1) return fixInstruction(findings[0]);
  const items = findings.map((f) => `• ${f.title}: ${f.detail}`).join("\n");
  return (
    `On the page currently open in Checks, fix these issues with the smallest edits ` +
    `that clear them, and change nothing else:\n${items}`
  );
}

export function ChecksStudio({ onClose }: { onClose: () => void }) {
  const runtime = useAssistantRuntime();
  const thread = useThreadRuntime();
  const [nodeId, setNodeId] = useState<string | null>(null);
  const [report, setReport] = useState<AuditReport | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [writing, setWriting] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [writeError, setWriteError] = useState<string | null>(null);
  const [moderation, setModerationState] = useState(() => getModeration());
  // Best-effort "unsaved fix staged" flag: a draft edit (agent op / manual field)
  // flips it on; a load or a write resets it (see openForChecks / the writes).
  const [dirty, setDirty] = useState(false);

  const docName = report?.title || "Checks";
  // Writes need the shared draft to be THIS page and edit access on it.
  const canWrite = !!nodeId && getPageNode() === nodeId && moderation.canEdit;

  // Run the deterministic audit for a node. Held in a ref so effects/handlers
  // call the latest closure without re-subscribing.
  const runAudit = useRef<(nid: string) => void>(() => {});
  runAudit.current = (nid: string) => {
    setLoading(true);
    setError(null);
    fetch(reportUrl(nid), { credentials: "same-origin" })
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
          setReport(data);
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
  const openForChecks = useRef<(nid: string) => void>(() => {});
  openForChecks.current = (nid: string) => {
    const load =
      getPageNode() === nid
        ? Promise.resolve()
        : loadPageIntoStudio(nid, null, "checks");
    void load
      .then(() => {
        setDirty(false);
        runAudit.current(nid);
      })
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
      setNodeId(id);
      setReport(null);
      if (id) openForChecks.current(id);
    });
    // url-sync owns the URL now (a /checks/node/N deep link resolves through the
    // machine → reconcileAudit → setAuditNode, which fires before this effect
    // subscribes). The getAuditNode() fallback catches that already-set node (the
    // subscription notify would have been missed); getPageNode() covers arriving
    // from the Content→Checks handover with the page already open.
    const seed = getAuditNode() ?? getPageNode();
    if (seed) {
      // setAuditNode no-ops (no notify) when the store already holds `seed`, so
      // load directly in that case; otherwise let the subscription do it.
      if (seed === getAuditNode()) {
        setNodeId(seed);
        openForChecks.current(seed);
      } else {
        setAuditNode(seed);
      }
    }
    return unsub;
  }, []);

  // Track staged edits (agent ops / manual field) so Save draft enables only when
  // there's something to write. The load's own draft emit is corrected by the
  // setDirty(false) in openForChecks, which runs right after the load resolves.
  useEffect(() => subscribePageDraft(() => setDirty(true)), []);
  useEffect(() => subscribeModeration(() => setModerationState(getModeration())), []);

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
      setDirty(false);
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
      setDirty(false);
      reloadPreview();
      setNotice("Published");
      offerWrapup(runtime.threads.mainItem.getState().remoteId, {
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

  // Send a finding (or a batch) to the repair agent as a normal user turn — it
  // has the page draft as context + the preview_page tool, so it stages a fix the
  // human then reviews and Publishes. The staged op flips `dirty` via the draft
  // subscription; nothing is written until Publish/Save.
  const sendFix = (findings: Finding[]) => {
    const fixable = findings.filter(isAiFixable);
    if (fixable.length === 0) return;
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

  const anyFixable = (report?.checks ?? []).some((c) => c.findings.some(isAiFixable));

  return (
    <div className="ain-studio__rail">
      {/* Re-run / Save / Publish / leave pin to the top bar (reachable when the
          rail collapses to a sheet). */}
      <StudioActionsPortal>
        {nodeId && (
          <>
            {dirty && canWrite && (
              <span className="ain-studio-actions__dirty" title="Unsaved fix">●</span>
            )}
            <button
              className="ain-btn ain-topbtn"
              onClick={() => nodeId && runAudit.current(nodeId)}
              disabled={loading || writing}
              title="Re-run the audit"
            >
              <RotateCcwIcon /> {loading ? "Checking…" : "Re-run"}
            </button>
            <button
              className="ain-btn ain-topbtn"
              onClick={() => void saveDraftAction()}
              disabled={!canWrite || !dirty || writing}
              title="Save your fix as a draft — not live yet"
            >
              {writing ? "Working…" : "Save draft"}
            </button>
            <button
              className="ain-btn ain-topbtn ain-topbtn--primary"
              onClick={() => void publishAction()}
              disabled={!canWrite || writing}
              title="Publish — make the fix live"
            >
              {writing ? "Publishing…" : "Publish"}
            </button>
          </>
        )}
        <button
          className="ain-btn ain-iconbtn ain-topbar__leave"
          onClick={backOrClose}
          aria-label={nodeId ? "Back to page list" : "Close checks studio"}
          title={nodeId ? "Back to the page list" : "Leave checks studio"}
        >
          <XIcon />
        </button>
      </StudioActionsPortal>

      <PanelBar title={docName} />

      <div className="ain-checks__body">
        {writeError && <p className="ain-studio__error">{writeError}</p>}
        {notice && !writeError && <p className="ain-studio__status">{notice}</p>}
        {error ? (
          <p className="ain-studio__error">{error}</p>
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
            canWrite={canWrite}
            busy={writing}
            anyFixable={anyFixable}
            onFix={sendFix}
          />
        ) : null}
      </div>
    </div>
  );
}

const SEVERITY_LABEL: Record<Severity, string> = { fail: "Fail", warn: "Warn", pass: "Pass" };
// Worst-first: a reader scanning the rail should hit what needs action before what passed.
const SEVERITY_RANK: Record<Severity, number> = { fail: 0, warn: 1, pass: 2 };

function AuditReportView({
  report,
  canWrite,
  busy,
  anyFixable,
  onFix,
}: {
  report: AuditReport;
  canWrite: boolean;
  busy: boolean;
  anyFixable: boolean;
  onFix: (findings: Finding[]) => void;
}) {
  const { summary } = report;
  const allFindings = report.checks.flatMap((c) => c.findings);
  return (
    <div className="ain-audit">
      <div className="ain-audit__summary">
        {report.url && (
          <a className="ain-audit__url" href={report.url} target="_blank" rel="noreferrer">
            {report.url} ↗
          </a>
        )}
        <div className="ain-audit__counts">
          <span className="ain-audit__count" data-severity="fail">{summary.fail} fail</span>
          <span className="ain-audit__count" data-severity="warn">{summary.warn} warn</span>
          <span className="ain-audit__count" data-severity="pass">{summary.pass} pass</span>
        </div>
        {/* Fix all the writeable findings in one batched, minimal-diff turn. */}
        {canWrite && anyFixable && (
          <button
            className="ain-btn ain-topbtn ain-topbtn--sm ain-audit__fixall"
            onClick={() => onFix(allFindings)}
            disabled={busy}
            title="Ask the repair agent to fix every issue it can, in one pass"
          >
            <SparkleIcon /> Fix all issues
          </button>
        )}
      </div>
      {report.checks.map((check) => (
        <CheckSection key={check.key} check={check} canWrite={canWrite} busy={busy} onFix={onFix} />
      ))}
    </div>
  );
}

/**
 * One check's findings, ordered worst-first and with passes folded away — the
 * rail is an action list, so the things that need doing lead and the passing
 * checks sit behind a "N passing" toggle rather than competing for attention.
 */
function CheckSection({
  check,
  canWrite,
  busy,
  onFix,
}: {
  check: Check;
  canWrite: boolean;
  busy: boolean;
  onFix: (findings: Finding[]) => void;
}) {
  const actionable = check.findings
    .filter((f) => f.severity !== "pass")
    .sort((a, b) => SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity]);
  const passes = check.findings.filter((f) => f.severity === "pass");
  const [showPasses, setShowPasses] = useState(false);
  const checkFixable = actionable.filter(isAiFixable);

  return (
    <section className="ain-audit__check">
      <div className="ain-audit__checkhead-row">
        <h3 className="ain-audit__checkhead">{check.label}</h3>
        {canWrite && checkFixable.length > 1 && (
          <button
            className="ain-btn ain-topbtn ain-topbtn--sm"
            onClick={() => onFix(checkFixable)}
            disabled={busy}
            title="Fix every writeable issue in this check"
          >
            Fix this check
          </button>
        )}
      </div>
      {actionable.length > 0 && (
        <ul className="ain-audit__findings">
          {actionable.map((finding) => (
            <FindingRow key={finding.id} finding={finding} canWrite={canWrite} busy={busy} onFix={onFix} />
          ))}
        </ul>
      )}
      {passes.length > 0 && (
        <>
          <button
            type="button"
            className="ain-btn ain-audit__passtoggle"
            onClick={() => setShowPasses((v) => !v)}
            aria-expanded={showPasses}
          >
            {showPasses ? "Hide" : "Show"} {passes.length} passing
          </button>
          {showPasses && (
            <ul className="ain-audit__findings">
              {passes.map((finding) => (
                <FindingRow key={finding.id} finding={finding} canWrite={canWrite} busy={busy} onFix={onFix} />
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}

function FindingRow({
  finding,
  canWrite,
  busy,
  onFix,
}: {
  finding: Finding;
  canWrite: boolean;
  busy: boolean;
  onFix: (findings: Finding[]) => void;
}) {
  const [editing, setEditing] = useState(false);
  const fixable = isAiFixable(finding);
  const showActions = canWrite && finding.severity !== "pass" && fixable;
  // A manual inline editor exists for the page title and every meta field — both
  // derived from the finding's own remediation (Phase 2), no hardcoded id map.
  const metaField = metaFieldFromRemediation(finding);
  const canEditInline = isTitleFinding(finding) || !!metaField;

  return (
    <li className="ain-audit__finding" data-severity={finding.severity}>
      <span className="ain-audit__badge" data-severity={finding.severity}>
        {SEVERITY_LABEL[finding.severity]}
      </span>
      <div className="ain-audit__finding-body">
        {finding.dimension && (
          <span className="ain-audit__dim" data-dim={finding.dimension}>
            {finding.dimension}
          </span>
        )}
        <span className="ain-audit__finding-title">{finding.title}</span>
        {finding.detail && <span className="ain-audit__finding-detail">{finding.detail}</span>}
        {finding.location && <span className="ain-audit__finding-loc">{finding.location}</span>}
        {showActions && (
          <div className="ain-audit__finding-actions">
            <button
              className="ain-btn ain-topbtn ain-topbtn--sm"
              onClick={() => onFix([finding])}
              disabled={busy}
              title="Ask the repair agent to fix this — you review and Publish"
            >
              <SparkleIcon /> Fix with AI
            </button>
            {canEditInline && (
              <button
                className="ain-btn ain-topbtn ain-topbtn--sm"
                onClick={() => setEditing((v) => !v)}
                disabled={busy}
                title={isTitleFinding(finding) ? "Edit the page title yourself" : "Edit this meta tag yourself"}
              >
                <WrenchIcon /> Edit
              </button>
            )}
          </div>
        )}
        {editing && isTitleFinding(finding) && <ManualTitleEditor onDone={() => setEditing(false)} />}
        {editing && metaField && <ManualMetaEditor field={metaField} onDone={() => setEditing(false)} />}
      </div>
    </li>
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
    <div className="ain-audit__manual">
      <input
        className="ain-field__input"
        type="text"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        placeholder="Page title (aim for 30–60 characters)"
        aria-label="Page title"
        autoFocus
      />
      <div className="ain-audit__manual-btns">
        <button className="ain-btn ain-topbtn ain-topbtn--sm" onClick={commit}>
          Apply
        </button>
        <button className="ain-btn ain-topbtn ain-topbtn--sm" onClick={onDone}>
          Cancel
        </button>
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
    <div className="ain-audit__manual">
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
        <span className="ain-audit__manual-count" data-ok={inRange}>
          {len} / {min}–{max} characters
        </span>
      )}
      <div className="ain-audit__manual-btns">
        <button className="ain-btn ain-topbtn ain-topbtn--sm" onClick={commit}>
          Apply
        </button>
        <button className="ain-btn ain-topbtn ain-topbtn--sm" onClick={onDone}>
          Cancel
        </button>
      </div>
    </div>
  );
}
