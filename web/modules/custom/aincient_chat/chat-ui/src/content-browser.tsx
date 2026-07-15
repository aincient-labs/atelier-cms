import { useEffect, useRef, useState } from "react";
import { ChevronLeftIcon, ChevronRightIcon, DocumentIcon, PlusIcon, SpinnerIcon } from "./icons";
import { apiUrl } from "./console-config";

/**
 * The content browser: a paginated, searchable page directory rendered as the
 * EMPTY STATE of a context-bearing studio's canvas (Content's preview pane,
 * Checks' panel). Instead of hiding "what do you want to work on?" in a dropdown,
 * the otherwise-dead canvas becomes the pick surface — choose a page and the
 * studio loads it through its normal flow (URL reflection, moderation, access all
 * unchanged).
 *
 * Server-paginated against GET /atelier/page/list?offset&limit&q — the same
 * route the legacy dropdown picker uses (which still reads its capped `pages`
 * feed when called with no params). A pick just calls `onPick(id)`; this
 * component owns no studio state of its own.
 */

const LIST_URL = apiUrl("/page/list");
const PAGE_SIZE = 12;
const SEARCH_DEBOUNCE_MS = 250;

/** One page in the directory (GET /atelier/page/list, paged shape). */
export type BrowserItem = {
  id: string;
  title: string;
  changed: number;
  url: string;
  /** Section count for the row's mono fact line ("path · N sections"). */
  sections?: number;
  /** Editorial state machine id (e.g. "published"); "" when not moderated. */
  state?: string;
  /** Human label for {@link state} (e.g. "Published"). */
  state_label?: string;
  /** A forward draft sits ahead of the published default. */
  has_pending_draft?: boolean;
};

/** The toolbar's primary state facet (study 02, Plate 10 — tier 1 filter). */
type StateFacet = "all" | "live" | "drafts";
const STATE_FACETS: { key: StateFacet; label: string }[] = [
  { key: "all", label: "All" },
  { key: "live", label: "Live" },
  { key: "drafts", label: "Drafts" },
];

type DirectoryResponse = {
  pages?: BrowserItem[];
  total?: number;
  offset?: number;
  limit?: number;
};

/**
 * Bare tabular time for the ledger column — "5h", "4d", "Jul 3" (study 02,
 * Plate 10: "Edited" is implied; the column IS the edited time).
 */
function bareChanged(unixSeconds: number): string {
  const sec = Math.round(Date.now() / 1000 - unixSeconds);
  if (sec < 60) return "now";
  const min = Math.round(sec / 60);
  if (min < 60) return `${min}m`;
  const hr = Math.round(min / 60);
  if (hr < 24) return `${hr}h`;
  const day = Math.round(hr / 24);
  if (day < 7) return `${day}d`;
  return new Date(unixSeconds * 1000).toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

/** The path shown in the fact line — the url's pathname, origin shed. */
function pathOf(url: string): string {
  try {
    return new URL(url, window.location.origin).pathname;
  } catch {
    return url;
  }
}

export function ContentBrowser({
  onPick,
  onNew,
  currentId,
  verb,
}: {
  /** Load the picked page into the studio. */
  onPick: (id: string) => void;
  /** Start a fresh doc (Content only); omitted hides the "New" card. */
  onNew?: () => void;
  /** The doc already open, marked as current in the list. */
  currentId?: string | null;
  /** Action verb for titles/empties ("Open" in Content, "Check" in Checks). */
  verb: "Open" | "Check";
}) {
  const [items, setItems] = useState<BrowserItem[] | null>(null);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [facet, setFacet] = useState<StateFacet>("all");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  // Guards a slow response from overwriting a newer one (paging fast / typing).
  const reqSeq = useRef(0);

  // Debounce the search term, and reset to the first page whenever it changes.
  useEffect(() => {
    const t = window.setTimeout(() => {
      setDebouncedQ(q.trim());
      setOffset(0);
    }, SEARCH_DEBOUNCE_MS);
    return () => window.clearTimeout(t);
  }, [q]);

  // Fetch the current window whenever the offset, query, or facet changes.
  useEffect(() => {
    const seq = ++reqSeq.current;
    setLoading(true);
    const params = new URLSearchParams({ offset: String(offset), limit: String(PAGE_SIZE) });
    if (debouncedQ) params.set("q", debouncedQ);
    if (facet !== "all") params.set("state", facet);
    fetch(`${LIST_URL}?${params.toString()}`, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: DirectoryResponse) => {
        if (seq !== reqSeq.current) return;
        setItems(Array.isArray(data.pages) ? data.pages : []);
        setTotal(typeof data.total === "number" ? data.total : 0);
        setError(null);
      })
      .catch((e) => {
        if (seq !== reqSeq.current) return;
        setItems([]);
        setError(`Couldn’t load pages: ${e instanceof Error ? e.message : e}`);
      })
      .finally(() => {
        if (seq === reqSeq.current) setLoading(false);
      });
  }, [offset, debouncedQ, facet]);

  const from = total === 0 ? 0 : offset + 1;
  const to = Math.min(offset + PAGE_SIZE, total);
  const canPrev = offset > 0;
  const canNext = offset + PAGE_SIZE < total;

  const filtered = facet !== "all" || debouncedQ !== "";

  return (
    <div className="ain-browser">
      <div className="ain-browser__bar">
        <input
          className="ain-field__input ain-browser__search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search…"
          spellCheck={false}
          aria-label="Search pages"
        />
        <div className="ain-seg" role="tablist" aria-label="Filter by state">
          {STATE_FACETS.map((f) => (
            <button
              key={f.key}
              type="button"
              role="tab"
              aria-selected={facet === f.key}
              className="ain-btn ain-seg__btn"
              onClick={() => {
                setFacet(f.key);
                setOffset(0);
              }}
            >
              {f.label}
            </button>
          ))}
        </div>
        {onNew && (
          <button type="button" className="ain-btn ain-topbtn ain-topbtn--primary ain-browser__new" onClick={onNew}>
            <PlusIcon /> New page
          </button>
        )}
      </div>
      {/* An active filter is always visible (never silently applied): the mono
          result count under the toolbar (study 02, Plate 10). */}
      {filtered && items !== null && (
        <div className="ain-browser__count">
          {total === 1 ? "1 page" : `${total} pages`}
          {debouncedQ ? ` matching “${debouncedQ}”` : ""}
        </div>
      )}

      {error ? (
        <p className="ain-studio__error">{error}</p>
      ) : items === null ? (
        <p className="ain-browser__empty">
          <SpinnerIcon /> Loading pages…
        </p>
      ) : items.length === 0 ? (
        <div className="ain-browser__empty">
          <DocumentIcon />
          <p>
            {debouncedQ
              ? `No pages match “${debouncedQ}” — clear the filter or make one.`
              : filtered
                ? "Nothing in this state — switch the filter back to All."
                : "Nothing here yet — describe a page in the chat and it lands here."}
          </p>
        </div>
      ) : (
        <ul className="ain-browser__list" role="listbox" aria-label={`${verb} a page`}>
          {items.map((page) => {
            const current = page.id === currentId;
            // Mirror the page studio's editorial badge exactly (DECISIONS 0094):
            // a forward draft over a published default tints to "Published" with a
            // non-uppercase "· draft pending" sub-label — same class, so the
            // directory and the open-doc strip never drift apart.
            const draftPending = page.has_pending_draft;
            const stateLabel = page.state_label || "";
            const showBadge = draftPending || stateLabel;
            const sections = page.sections ?? 0;
            return (
              <li key={page.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={current}
                  className="ain-browser__row"
                  data-current={current || undefined}
                  onClick={() => onPick(page.id)}
                  title={current ? "Currently open" : `${verb} “${page.title || "Untitled"}”`}
                >
                  <span className="ain-browser__cell">
                    <span className="ain-browser__title">{page.title || "Untitled page"}</span>
                    <span className="ain-browser__facts">
                      {pathOf(page.url)}
                      {sections > 0 && ` · ${sections} ${sections === 1 ? "section" : "sections"}`}
                    </span>
                  </span>
                  {showBadge && (
                    <span
                      className="ain-studio__statebadge"
                      data-state={draftPending ? "published" : page.state || undefined}
                      data-pending={draftPending || undefined}
                    >
                      {/* "Live" is the owner's word for published (study 02, Plate 5). */}
                      {draftPending ? "Live" : stateLabel === "Published" ? "Live" : stateLabel}
                      {draftPending && (
                        <span className="ain-studio__statebadge-sub"> · draft pending</span>
                      )}
                    </span>
                  )}
                  <span className="ain-browser__changed">{bareChanged(page.changed)}</span>
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {total > PAGE_SIZE && (
        <div className="ain-browser__pager">
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--sm ain-browser__page"
            onClick={() => setOffset((o) => Math.max(0, o - PAGE_SIZE))}
            disabled={!canPrev || loading}
            aria-label="Previous page"
          >
            <ChevronLeftIcon /> Prev
          </button>
          <span className="ain-browser__range">
            {from}–{to} of {total}
          </span>
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--sm ain-browser__page"
            onClick={() => setOffset((o) => o + PAGE_SIZE)}
            disabled={!canNext || loading}
            aria-label="Next page"
          >
            Next <ChevronRightIcon />
          </button>
        </div>
      )}
    </div>
  );
}
