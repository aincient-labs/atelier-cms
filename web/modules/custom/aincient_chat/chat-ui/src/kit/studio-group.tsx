import { useId, useState, type ReactNode } from "react";
import { cx } from "./cx";
import { ChevronDownIcon } from "./icons";

/**
 * One group in a studio's editor rail (kit/studio-group.css). Under rule R2 (0516),
 * a group is an ink rule and its heading, never a box: the rule opens the group,
 * an optional `note` says what it governs, and the fields sit on the same paper.
 *
 * `actions` sit at the right end of the title row (Sections' "Expand all").
 * Passing it at all, even `false` while there is nothing to offer yet, keeps
 * the row, so the group's geometry doesn't jump when the action appears.
 * `id` is the deep-link anchor a Checks finding scrolls to.
 *
 * `collapsible` turns the heading into a chevron toggle: no fill and no icon
 * tile. The body is unmounted while closed. Pass `open` + `onOpenChange` to
 * control it, or `defaultOpen` to let the group keep its own state.
 */
export function StudioGroup({
  title,
  note,
  actions,
  id,
  className,
  children,
  collapsible = false,
  open: openProp,
  defaultOpen = true,
  onOpenChange,
}: {
  title: ReactNode;
  note?: ReactNode;
  actions?: ReactNode;
  id?: string;
  className?: string;
  children?: ReactNode;
  collapsible?: boolean;
  open?: boolean;
  defaultOpen?: boolean;
  onOpenChange?: (open: boolean) => void;
}) {
  const [ownOpen, setOwnOpen] = useState(defaultOpen);
  const open = openProp ?? ownOpen;
  const bodyId = useId();
  const toggle = () => {
    const next = !open;
    if (openProp === undefined) setOwnOpen(next);
    onOpenChange?.(next);
  };

  // A bare title keeps its own gap to the first field; under a note or in the
  // title row, `--static` hands the spacing to them.
  const bare = note == null && actions === undefined && !collapsible;
  const heading = (
    <h3 className={cx("ain-studio__grouptitle", !bare && "ain-studio__grouptitle--static")}>
      {collapsible ? (
        <button
          type="button"
          className="ain-studio__grouptoggle"
          aria-expanded={open}
          aria-controls={bodyId}
          onClick={toggle}
        >
          <ChevronDownIcon className="ain-studio__groupchevron" aria-hidden="true" />
          {title}
        </button>
      ) : (
        title
      )}
    </h3>
  );
  const head =
    actions !== undefined ? (
      <div className="ain-studio__grouphead">
        {heading}
        {actions}
      </div>
    ) : (
      heading
    );

  if (!collapsible) {
    return (
      <section className={cx("ain-studio__group", className)} id={id}>
        {head}
        {note != null && <p className="ain-studio__groupnote">{note}</p>}
        {children}
      </section>
    );
  }
  return (
    <section className={cx("ain-studio__group", "ain-studio__group--collapsible", className)} id={id} data-open={open || undefined}>
      {head}
      {open && (
        <div className="ain-studio__groupbody" id={bodyId}>
          {note != null && <p className="ain-studio__groupnote">{note}</p>}
          {children}
        </div>
      )}
    </section>
  );
}
