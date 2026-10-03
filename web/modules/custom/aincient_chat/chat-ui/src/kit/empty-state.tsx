import type { ReactNode } from "react";
import { cx } from "./cx";

/**
 * What a pane shows when it has nothing to show (kit/empty-state.css), centred
 * in the space the content would fill. Say what will appear and how to get it
 * there. `list` fills a browse pane (Content, Library) and leads with a
 * faint glyph; `stage` fills a preview canvas and may add a fainter `hint`.
 * It is not a loading state — that is `LoadingState` (kit/loading-state.tsx).
 */
export function EmptyState({
  variant = "list",
  icon,
  hint,
  className,
  children,
}: {
  variant?: "list" | "stage";
  icon?: ReactNode;
  hint?: ReactNode;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={cx(variant === "stage" ? "ain-pagepreview__empty" : "ain-browser__empty", className)}>
      {icon}
      <p>{children}</p>
      {hint != null && <p className="ain-pagepreview__hint">{hint}</p>}
    </div>
  );
}

