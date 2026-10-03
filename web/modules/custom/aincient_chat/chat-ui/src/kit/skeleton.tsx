import type { CSSProperties } from "react";
import { cx } from "./cx";

/**
 * A loading placeholder in the real geometry of what it stands for (Law 09,
 * kit/skeleton.css): `input` a field body, `avatar` a round 44px, `btn` a
 * button, `short` a 60% line, `line` (default) a full-width bar of the current
 * height. Decorative — the region that is loading carries `aria-busy`.
 */
export function Skeleton({
  variant = "line",
  className,
  style,
}: {
  variant?: "line" | "input" | "avatar" | "btn" | "short";
  className?: string;
  style?: CSSProperties;
}) {
  return <span aria-hidden="true" className={cx("ain-skeleton", variant !== "line" && `ain-skeleton--${variant}`, className)} style={style} />;
}
