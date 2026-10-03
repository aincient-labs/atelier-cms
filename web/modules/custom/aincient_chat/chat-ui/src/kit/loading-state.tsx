import { cx } from "./cx";
import { Skeleton } from "./skeleton";

// Title bars vary like real titles do; a uniform stack reads as a bar chart.
const TITLE_WIDTHS = ["46%", "62%", "38%", "55%", "41%", "68%", "50%", "35%"];

/**
 * THE loading state for anything that waits to fill a pane (Law 09,
 * kit/loading-state.css). A pane never says "Loading…": it arrives at its
 * final shape with placeholder bars, and `loading-states.test.ts` fails the
 * build on a hand-written "Loading…" line. A BUTTON that waits keeps its own
 * spinner and label — this is for panes, lists and rails.
 *
 * `variant="list"` (default) — a browse pane waiting for its first results:
 * the list itself, at its real size, with each row's title and fact line as
 * pulsing placeholder bars. It stays invisible for 150ms and then fades in, so
 * a fast load shows nothing instead of a flash. `thumb` adds the Library's 44px
 * thumbnail; `trailing` the Content list's badge + time. `label` names the busy
 * list for assistive tech.
 *
 * It emits the browse list's own classes (`ain-browser__list`, `__row`,
 * `__cell`), so the results replace it without the pane moving.
 *
 * `variant="fields"` — a rail or section waiting for what it edits: `rows`
 * (default 3) field-height bars, the last one short, at the rail's padding.
 */
export function LoadingState({
  label,
  variant = "list",
  rows,
  thumb,
  trailing,
  className,
}: {
  label: string;
  variant?: "list" | "fields";
  rows?: number;
  thumb?: boolean;
  trailing?: boolean;
  className?: string;
}) {
  if (variant === "fields") {
    const count = rows ?? 3;
    return (
      <div className={cx("ain-loading ain-loading--fields", className)} aria-busy="true" aria-label={label} role="status">
        {Array.from({ length: count }, (_, i) => (
          <Skeleton key={i} variant="input" className={i === count - 1 && count > 1 ? "ain-loading__last" : undefined} />
        ))}
      </div>
    );
  }
  return (
    <ul className={cx("ain-browser__list ain-loading", className)} aria-busy="true" aria-label={label}>
      {Array.from({ length: rows ?? 6 }, (_, i) => (
        <li key={i}>
          <div className="ain-browser__row ain-loading__row">
            {thumb && <Skeleton className="ain-loading__thumb" />}
            <span className="ain-browser__cell ain-loading__cell">
              <Skeleton className="ain-loading__title" style={{ width: TITLE_WIDTHS[i % TITLE_WIDTHS.length] }} />
              <Skeleton className="ain-loading__facts" />
            </span>
            {trailing && (
              <>
                <Skeleton className="ain-loading__badge" />
                <Skeleton className="ain-loading__time" />
              </>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}
