import type { ReactNode } from "react";
import { cx } from "./cx";

/**
 * The kit's one multi-choice chrome — a filter, a view, a mode
 * (kit/segmented.css). Exactly one option is on; it is RAISED PAPER, never the
 * accent fill (study 02, Plate 12). Each option is a button with
 * `aria-pressed`, the group is labelled by `label`.
 *
 * Choosing between PANELS of content is `Tabs` (same look, tab semantics);
 * this is for a value that filters or switches what one panel shows.
 */
export type SegmentedOption<V extends string> = { value: V; label: ReactNode; title?: string; disabled?: boolean };

export function SegmentedControl<V extends string>({
  label,
  options,
  value,
  onChange,
  className,
}: {
  label: string;
  options: readonly SegmentedOption<V>[];
  value: V;
  onChange: (value: V) => void;
  className?: string;
}) {
  return (
    <div className={cx("ain-seg", className)} role="group" aria-label={label}>
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          className="ain-btn ain-seg__btn"
          aria-pressed={o.value === value}
          title={o.title}
          disabled={o.disabled}
          onClick={() => onChange(o.value)}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}
