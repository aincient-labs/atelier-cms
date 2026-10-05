import { cx } from "./cx";

/**
 * The loading state for a canvas that renders a PAGE or a COMPONENT (Law 09,
 * kit/composing-state.css). A list skeleton would say "rows are coming"; a
 * preview is not a list. This one has the shape of what is coming — sections
 * laid out like a page — with placeholder bars that pulse like every other
 * skeleton, invisible for the first 150ms (a fast render shows nothing).
 *
 * `shape="section"` — one component (the Components studio's selection): a
 * single opener-shaped section. `shape="page"` (default) — a page: an opener,
 * a statement band and a row of cards. `label` names the wait for assistive
 * tech. `bare` drops the page's own frame and padding, for a canvas that
 * already draws one (a browser window). `header` adds the site header's row
 * (brand + navigation) above the first section, for a full page. Decorative wireframe only: neutral ink,
 * no colour, no glow.
 */
export function ComposingState({
  label,
  shape = "page",
  bare = false,
  header = false,
  className,
}: {
  label: string;
  shape?: "page" | "section";
  bare?: boolean;
  header?: boolean;
  className?: string;
}) {
  return (
    <div className={cx("ain-composing", `ain-composing--${shape}`, bare && "ain-composing--bare", className)} role="status" aria-busy="true" aria-label={label}>
      <div className="ain-composing__page" aria-hidden="true">
        {header && (
          <div className="ain-composing__header">
            <i className="ain-composing__ink ain-composing__ink--brand" />
            <span className="ain-composing__nav">
              <i className="ain-composing__ink ain-composing__ink--navitem" />
              <i className="ain-composing__ink ain-composing__ink--navitem" />
              <i className="ain-composing__ink ain-composing__ink--navitem" />
            </span>
          </div>
        )}
        <section className="ain-composing__sec ain-composing__sec--opener">
          <div className="ain-composing__copy">
            <i className="ain-composing__ink ain-composing__ink--pill" />
            <i className="ain-composing__ink ain-composing__ink--display" />
            <i className="ain-composing__ink ain-composing__ink--display ain-composing__ink--short" />
            <i className="ain-composing__ink ain-composing__ink--text" />
            <i className="ain-composing__ink ain-composing__ink--text ain-composing__ink--shorter" />
            <span className="ain-composing__actions">
              <i className="ain-composing__ink ain-composing__ink--action" />
              <i className="ain-composing__ink ain-composing__ink--link" />
            </span>
          </div>
          <div className="ain-composing__media" />
        </section>
        {shape === "page" && (
          <>
            <section className="ain-composing__sec ain-composing__sec--band">
              <i className="ain-composing__ink ain-composing__ink--heading" />
              <i className="ain-composing__ink ain-composing__ink--text ain-composing__ink--short" />
            </section>
            <section className="ain-composing__sec ain-composing__sec--cards">
              {[0, 1, 2].map((c) => (
                <div key={c} className="ain-composing__card">
                  <i className="ain-composing__ink ain-composing__ink--icon" />
                  <i className="ain-composing__ink ain-composing__ink--title" />
                  <i className="ain-composing__ink ain-composing__ink--text" />
                  <i className="ain-composing__ink ain-composing__ink--text ain-composing__ink--short" />
                </div>
              ))}
            </section>
          </>
        )}
      </div>
    </div>
  );
}
