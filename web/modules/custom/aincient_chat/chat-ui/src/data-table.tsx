import { useState } from "react";
import { useThreadRuntime } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { activeStudioKey } from "./flow";
import { pageDeepLink } from "./studios";
import { consoleBase, opensNewTab } from "./console-url";
import { openSurface } from "./surface-nav";
import { openPageInPlace } from "./url-sync";

/**
 * Generic `data_table` generative-UI widget.
 *
 * Any flow/capability can emit a `{ "__widget__": "data_table", "payload": {…} }`
 * envelope (the same contract as `page_preview`/`brand_picker`) and the console
 * renders this inline table. It is deliberately content-agnostic — columns +
 * rows + an optional per-row action — so it serves pages today and workflow /
 * content / brand-revision listings later. Its first producer is the
 * `aincient_pages:list_pages` capability.
 *
 * A row's `action` is a small declarative descriptor the widget maps to a
 * handler, so the table stays generic while specific actions stay snappy:
 *   - `open_page`  → open the page `node` in whichever studio fits the CURRENT
 *                    workspace: the Content editor (/atelier?page=<nid>) when
 *                    editing, the Checks audit (/atelier/checks/<flow>?audit=)
 *                    when auditing. The producer (e.g. `list_pages`) stays
 *                    studio-agnostic — it emits a node id and the console
 *                    resolves the destination from the active studio
 *                    ({@link pageDeepLink}), so one page table is correct in
 *                    every studio. Loads IN PLACE (the studio opens beside the
 *                    chat — the conversation isn't lost), so a plain click keeps
 *                    you in one tab; a modifier/middle click opens the durable
 *                    URL in a new tab natively (DECISIONS 0034 revisited).
 *   - `link`       → open `href` in a new tab: the row's first cell renders as a
 *                    real anchor (hover-previewable, right-/middle-clickable) and
 *                    the whole row opens it too. The generic "deep-link a fixed
 *                    URL" action for tables that aren't page listings.
 *   - `send`       → append `message` as a user turn: the generic fallback for
 *                    any table the console hasn't been taught a native action for.
 * Unknown kinds render as a plain (non-interactive) row. An optional `href`
 * adds a "View ↗" link to the live target in a new tab.
 *
 * When the producer sets `pageSize`, rows beyond it are paged client-side with a
 * Prev/Next footer (no round-trip) — the table stays a fixed height however many
 * rows arrive. `summary` (when set) renders above the table as a caption; pages
 * use it to flag that the loaded rows are themselves a cap of a larger total.
 */

type CellValue = string | number | boolean | null;

type Column = { key: string; label: string; format?: "datetime" };

type RowAction =
  | { kind: "open_page"; node: string }
  | { kind: "link"; href: string }
  | { kind: "send"; message: string };

type Row = {
  id: string;
  cells: Record<string, CellValue>;
  action?: RowAction;
  href?: string;
};

export type DataTablePayload = {
  columns?: Column[];
  rows?: Row[];
  /** Shown when there are no rows. */
  empty?: string;
  /** Caption rendered above the table (e.g. "showing 50 of 120"). */
  summary?: string;
  /** Rows per page; rows beyond it page client-side. Unset = all on one page. */
  pageSize?: number;
};

/** Render a cell by its column's format (datetime = a short local date). */
function formatCell(value: CellValue, format?: Column["format"]): string {
  if (value === null || value === undefined) return "";
  if (format === "datetime" && typeof value === "number") {
    // The server sends a unix (seconds) timestamp; show a short local date.
    return new Date(value * 1000).toLocaleDateString(undefined, {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  }
  return String(value);
}

function DataTableCard({ payload }: { payload: DataTablePayload }) {
  const thread = useThreadRuntime();
  const columns = payload.columns ?? [];
  const rows = payload.rows ?? [];

  // Client-side paging: with a pageSize set, show one page of rows and a
  // Prev/Next footer. No round-trip — the producer sends every row up front
  // (capped on its side), the widget just windows them.
  const pageSize = payload.pageSize && payload.pageSize > 0 ? payload.pageSize : rows.length;
  const pageCount = Math.max(1, Math.ceil(rows.length / Math.max(1, pageSize)));
  const [page, setPage] = useState(0);
  const current = Math.min(page, pageCount - 1);
  const start = current * pageSize;
  const visible = rows.slice(start, start + pageSize);

  // Resolve a navigational action to its target URL — `link` carries its own,
  // `open_page` is studio-relative (built from the active studio so the same
  // page table lands in the editor or the audit, whichever the workspace is).
  // `send` and unknown kinds have no href. Computed at render: the active studio
  // is stable while a thread is open.
  const studio = activeStudioKey();
  const base = consoleBase();
  const hrefFor = (action: RowAction | undefined): string | undefined => {
    if (action?.kind === "link") return action.href;
    if (action?.kind === "open_page") return pageDeepLink(studio, action.node, base);
    return undefined;
  };

  const runAction = (
    action: RowAction | undefined,
    e?: { metaKey: boolean; ctrlKey: boolean; shiftKey: boolean; button: number },
  ) => {
    if (!action) return;
    // A modifier/middle click opens the durable URL in a new tab (handled
    // natively by the cell anchor; the row handler honours it too).
    if (e && opensNewTab(e)) {
      const href = hrefFor(action);
      if (href) openSurface(href, "output");
      return;
    }
    if (action.kind === "open_page") {
      // A page row is a within-workspace move: load the doc beside the chat in
      // the same tab, conversation intact (surface-nav policy).
      openPageInPlace(studio, action.node);
    } else if (action.kind === "link") {
      // A fixed external URL → a new tab (it leaves the console).
      openSurface(action.href, "output");
    } else if (action.kind === "send") {
      thread.append({ role: "user", content: [{ type: "text", text: action.message }] });
    }
  };

  if (rows.length === 0) {
    return <div className="ain-dtable ain-dtable--empty">{payload.empty ?? "Nothing to show."}</div>;
  }

  return (
    <div className="ain-dtable">
      {payload.summary && <p className="ain-dtable__summary">{payload.summary}</p>}
      <table className="ain-dtable__table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th key={col.key}>{col.label}</th>
            ))}
            <th className="ain-dtable__actioncol" aria-label="Open" />
          </tr>
        </thead>
        <tbody>
          {visible.map((row) => {
            const interactive = !!row.action;
            return (
              <tr
                key={row.id}
                className="ain-dtable__row"
                data-interactive={interactive || undefined}
                role={interactive ? "button" : undefined}
                tabIndex={interactive ? 0 : undefined}
                onClick={interactive ? (e) => runAction(row.action, e) : undefined}
                onKeyDown={
                  interactive
                    ? (e) => {
                        if (e.key === "Enter" || e.key === " ") {
                          e.preventDefault();
                          runAction(row.action);
                        }
                      }
                    : undefined
                }
              >
                {columns.map((col, i) => {
                  const text = formatCell(row.cells[col.key] ?? null, col.format);
                  // For a navigational row (`link`/`open_page`), the first column
                  // is a real anchor to the resolved target (hover-previewable,
                  // modifier-/middle-clickable into a new tab). An `open_page`
                  // anchor loads IN PLACE on a plain click (preventDefault →
                  // openPageInPlace); a `link` (external) keeps target="_blank".
                  // stopPropagation avoids the row's onClick double-firing.
                  const action = i === 0 ? row.action : undefined;
                  const href = hrefFor(action);
                  const inPlace = action?.kind === "open_page";
                  return (
                    <td key={col.key}>
                      {href ? (
                        <a
                          className="ain-dtable__link"
                          href={href}
                          target={inPlace ? undefined : "_blank"}
                          rel="noreferrer"
                          onClick={(e) => {
                            e.stopPropagation();
                            if (inPlace && action?.kind === "open_page" && !opensNewTab(e)) {
                              e.preventDefault();
                              openPageInPlace(studio, action.node);
                            }
                          }}
                        >
                          {text}
                        </a>
                      ) : (
                        text
                      )}
                    </td>
                  );
                })}
                <td className="ain-dtable__actioncell">
                  {row.href && (
                    <a
                      className="ain-preview__open"
                      href={row.href}
                      target="_blank"
                      rel="noreferrer"
                      onClick={(e) => e.stopPropagation()}
                    >
                      View ↗
                    </a>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {pageCount > 1 && (
        <div className="ain-dtable__pager">
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--sm ain-dtable__pagebtn"
            disabled={current === 0}
            onClick={() => setPage(current - 1)}
          >
            ‹ Prev
          </button>
          <span className="ain-dtable__pagestat">
            {start + 1}–{Math.min(start + pageSize, rows.length)} of {rows.length}
          </span>
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--sm ain-dtable__pagebtn"
            disabled={current >= pageCount - 1}
            onClick={() => setPage(current + 1)}
          >
            Next ›
          </button>
        </div>
      )}
    </div>
  );
}

/**
 * Registers the `data_table` widget. Mount once inside the
 * AssistantRuntimeProvider; `args` is the payload the dispatcher passed through.
 */
export const DataTableToolUI = makeSafeAssistantToolUI<DataTablePayload, unknown>({
  toolName: "data_table",
  render: ({ args }) => <DataTableCard payload={args ?? {}} />,
});
