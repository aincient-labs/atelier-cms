import type { ReactNode } from "react";
import { cx } from "./cx";

/**
 * One titled group in a studio's editor rail (kit/studio-group.css): a mono
 * uppercase title, an optional faint `note` saying what the group governs, then
 * the fields. `actions` sit at the right end of the title row (Sections'
 * "Expand all"); passing it at all — even `false` while there is nothing to
 * offer yet — keeps the row, so the group's geometry doesn't jump when the
 * action appears. `id` is the deep-link anchor a Checks finding scrolls to.
 * A group with neither note nor actions (the Page group) keeps a plain title,
 * spaced from the first field below it.
 */
export function StudioGroup({
  title,
  note,
  actions,
  id,
  className,
  children,
}: {
  title: ReactNode;
  note?: ReactNode;
  actions?: ReactNode;
  id?: string;
  className?: string;
  children?: ReactNode;
}) {
  // A bare title keeps its own gap to the first field; under a note or in the
  // title row, `--static` hands the spacing to them.
  const bare = note == null && actions === undefined;
  const heading = <h3 className={cx("ain-studio__grouptitle", !bare && "ain-studio__grouptitle--static")}>{title}</h3>;
  return (
    <section className={cx("ain-studio__group", className)} id={id}>
      {actions !== undefined ? (
        <div className="ain-studio__grouphead">
          {heading}
          {actions}
        </div>
      ) : (
        heading
      )}
      {note != null && <p className="ain-studio__groupnote">{note}</p>}
      {children}
    </section>
  );
}
