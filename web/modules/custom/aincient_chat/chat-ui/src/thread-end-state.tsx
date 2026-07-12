import type { ComponentType, SVGProps } from "react";
import { SparkleIcon, ShieldCheckIcon, CircleQuestionIcon } from "./icons";

/**
 * The shared "this context is a dead-end — here's what to do next" pane. One
 * component, three variants (decision 2026-06-30), each replacing the place the
 * user would otherwise be stuck:
 *
 *   published — a thread wrapped up after Publish: the composer is swapped for a
 *               celebration so the finished conversation stops here.
 *   denied    — a ?page=/?audit= deep link to a node the user can't access (403).
 *   gone      — a deep link to a node that no longer exists (404).
 *
 * Presentational only: copy + icon per variant, with caller-supplied next-action
 * buttons (the callers own the runtime / navigation). `/clear` and `/compact`
 * will slot in later as further variants of this same shell.
 */

export type EndStateVariant = "published" | "denied" | "gone";

/** One next-action button. `href` renders an anchor (opens a new tab) instead of
 *  a button — for the "View page ↗" link — while still firing `onClick`. */
export type EndStateAction = {
  label: string;
  onClick: () => void;
  primary?: boolean;
  href?: string;
};

const COPY: Record<EndStateVariant, {
  Icon: ComponentType<SVGProps<SVGSVGElement>>;
  title: string;
  body: string;
}> = {
  published: {
    Icon: SparkleIcon,
    title: "Published — nice work!",
    body:
      "Your changes are live. This conversation is wrapped up to keep things tidy — " +
      "start a new thread for your next change.",
  },
  denied: {
    Icon: ShieldCheckIcon,
    title: "You can’t open this document",
    body:
      "It may be a draft, or owned by someone else. Open one of your own pages, " +
      "or start a new thread.",
  },
  gone: {
    Icon: CircleQuestionIcon,
    title: "This document no longer exists",
    body: "It may have been deleted or moved. Pick another page, or start a new thread.",
  },
};

export function ThreadEndState({
  variant,
  actions,
  className,
}: {
  variant: EndStateVariant;
  actions: EndStateAction[];
  className?: string;
}) {
  const { Icon, title, body } = COPY[variant];
  return (
    <div
      className={`ain-endstate${className ? ` ${className}` : ""}`}
      data-variant={variant}
      role="status"
    >
      <Icon className="ain-endstate__icon" aria-hidden />
      <h2 className="ain-endstate__title">{title}</h2>
      <p className="ain-endstate__body">{body}</p>
      <div className="ain-endstate__actions">
        {actions.map((a) =>
          a.href ? (
            <a
              key={a.label}
              className={`ain-btn ain-topbtn${a.primary ? " ain-topbtn--primary" : ""}`}
              href={a.href}
              target="_blank"
              rel="noreferrer"
              onClick={a.onClick}
            >
              {a.label}
            </a>
          ) : (
            <button
              key={a.label}
              type="button"
              className={`ain-btn ain-topbtn${a.primary ? " ain-topbtn--primary" : ""}`}
              onClick={a.onClick}
            >
              {a.label}
            </button>
          ),
        )}
      </div>
    </div>
  );
}
