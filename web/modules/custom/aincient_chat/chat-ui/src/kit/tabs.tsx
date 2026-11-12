import type { ReactNode } from "react";
import { Tabs as T } from "radix-ui";
import { cx } from "./cx";

/**
 * Panels of content behind a tab list (kit/tabs.css), on Radix: arrow keys move
 * between tabs (roving focus), Home / End jump, each tab controls its panel and
 * the panel is labelled by its tab. The list wears the segmented control's
 * look — a filter that only changes what ONE panel shows is a
 * `SegmentedControl` instead, not a tab list.
 *
 * Inactive panels are unmounted; state that must survive a switch lives above.
 */
export type TabItem<V extends string> = { value: V; label: ReactNode; content: ReactNode; disabled?: boolean };

export function Tabs<V extends string>({
  label,
  items,
  value,
  onValueChange,
  defaultValue,
  className,
  listClassName,
  panelClassName,
}: {
  label: string;
  items: readonly TabItem<V>[];
  value?: V;
  onValueChange?: (value: V) => void;
  defaultValue?: V;
  className?: string;
  /** Extra classes on the tab list and on each panel, for a feature's own layout. */
  listClassName?: string;
  panelClassName?: string;
}) {
  return (
    <T.Root
      className={cx("ain-tabs", className)}
      value={value}
      defaultValue={defaultValue ?? (value === undefined ? items[0]?.value : undefined)}
      onValueChange={onValueChange as ((v: string) => void) | undefined}
    >
      <T.List className={cx("ain-seg ain-tabs__list", listClassName)} aria-label={label}>
        {items.map((t) => (
          <T.Trigger key={t.value} value={t.value} disabled={t.disabled} className="ain-btn ain-seg__btn">
            {t.label}
          </T.Trigger>
        ))}
      </T.List>
      {items.map((t) => (
        <T.Content key={t.value} value={t.value} className={cx("ain-tabs__panel", panelClassName)}>
          {t.content}
        </T.Content>
      ))}
    </T.Root>
  );
}
