import { useState } from "react";
import type { ComponentProps, ComponentType, ReactNode, SVGProps } from "react";
import { SparkleIcon, ShieldCheckIcon, CircleQuestionIcon, StarIcon } from "./kit/icons";
import { Button } from "./kit/button";

/** Public source repo the star nudge links to. */
const GITHUB_REPO_URL = "https://github.com/aincient-labs/atelier-cms";
/** localStorage flag: the star nudge is a one-time ask, never a recurring nag. */
const STAR_NUDGE_KEY = "aincient-star-nudge-seen";

/**
 * A single, gentle "star us" ask, shown once on the FIRST publish celebration
 * and never again once the user acts on it (star or dismiss). No telemetry: it's
 * a plain link — the appliance phones nothing home (proxy-only measurement,
 * DECISIONS 0239). Renders nothing on subsequent celebrations.
 */
function StarNudge() {
  const [seen, setSeen] = useState(() => {
    try {
      return localStorage.getItem(STAR_NUDGE_KEY) === "1";
    } catch {
      return false;
    }
  });
  if (seen) return null;
  const seal = () => {
    try {
      localStorage.setItem(STAR_NUDGE_KEY, "1");
    } catch {
      /* private mode / storage disabled — still dismiss for this session */
    }
    setSeen(true);
  };
  return (
    <div className="ain-starnudge" role="note">
      <p className="ain-starnudge__text">
        Enjoying Atelier? A quick star helps other builders find it.
      </p>
      <div className="ain-starnudge__actions">
        <a
          className="ain-btn ain-topbtn ain-starnudge__cta"
          href={GITHUB_REPO_URL}
          target="_blank"
          rel="noreferrer"
          onClick={seal}
        >
          <StarIcon aria-hidden />
          Star on GitHub ↗
        </a>
        <button type="button" className="ain-starnudge__dismiss" onClick={seal}>
          Not now
        </button>
      </div>
    </div>
  );
}

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

/**
 * `TitleAs` swaps the heading for another one (the kit `DialogTitle`, when the
 * card is a Dialog's own — the shell's dead-end overlay); any other prop (the
 * dialog's role, ids, ref, focus handlers) lands on the card.
 */
export function ThreadEndState({
  variant,
  actions,
  className,
  TitleAs,
  ...rest
}: {
  variant: EndStateVariant;
  actions: EndStateAction[];
  className?: string;
  TitleAs?: ComponentType<{ className?: string; children: ReactNode }>;
} & Omit<ComponentProps<"div">, "children">) {
  const { Icon, title, body } = COPY[variant];
  return (
    <div
      role="status"
      {...rest}
      className={`ain-endstate${className ? ` ${className}` : ""}`}
      data-variant={variant}
    >
      <Icon className="ain-endstate__icon" aria-hidden />
      {TitleAs ? <TitleAs className="ain-endstate__title">{title}</TitleAs> : <h2 className="ain-endstate__title">{title}</h2>}
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
            <Button key={a.label} variant={a.primary ? "primary" : "secondary"} onClick={a.onClick}>
              {a.label}
            </Button>
          ),
        )}
      </div>
      {variant === "published" && <StarNudge />}
    </div>
  );
}
