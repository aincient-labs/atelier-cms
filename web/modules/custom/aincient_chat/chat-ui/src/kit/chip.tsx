import type { ComponentProps } from "react";
import { cx } from "./cx";

/**
 * A small toggle — a preset, a filter value (kit/chip.css). `pressed` is the
 * on state: accent border, `aria-pressed`. A selection control, not an action:
 * it deliberately does not build on `Button`.
 */
export function Chip({ pressed, className, children, type = "button", ...rest }: ComponentProps<"button"> & { pressed?: boolean }) {
  return (
    <button type={type} aria-pressed={pressed} className={cx("ain-chip", pressed && "ain-chip--on", className)} {...rest}>
      <span className="ain-chip__label">{children}</span>
    </button>
  );
}
