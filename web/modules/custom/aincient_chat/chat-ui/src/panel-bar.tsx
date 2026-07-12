import type { ReactNode } from "react";

/**
 * The shared head for the three workspace panels — chat, preview and the editor
 * rail. One height (`--ain-bar-h`) and one bottom border so the column heads
 * line up flush across their seams, however the workspace is split.
 *
 * It's layout only: each panel supplies its own pieces.
 *  - `lead`    — a control pinned to the left edge (e.g. the chat's conversations
 *                burger). Sits before the title.
 *  - `title`   — the panel label ("Live preview", "Brand studio") or, for chat,
 *                the active conversation's title. Truncates with an ellipsis.
 *  - `actions` — right-aligned controls (Open ↗, New, language switch, …).
 *
 * `titleClassName` lets a panel restyle the title — chat passes the readable
 * `--convo` variant for its prose conversation titles.
 */
export function PanelBar({
  title,
  lead,
  actions,
  className,
  titleClassName,
}: {
  title?: ReactNode;
  lead?: ReactNode;
  actions?: ReactNode;
  className?: string;
  titleClassName?: string;
}) {
  return (
    <div className={`ain-panelbar${className ? ` ${className}` : ""}`}>
      {lead}
      {title != null && (
        <span className={`ain-panelbar__title${titleClassName ? ` ${titleClassName}` : ""}`}>
          {title}
        </span>
      )}
      {actions != null && <div className="ain-panelbar__actions">{actions}</div>}
    </div>
  );
}
