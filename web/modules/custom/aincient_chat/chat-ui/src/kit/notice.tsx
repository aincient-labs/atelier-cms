import type { ReactNode } from "react";
import { cx } from "./cx";
import { CheckIcon } from "./icons";

type NoticeProps = { className?: string; children: ReactNode } & (
  | { tone: "success"; panel?: never }
  | { tone: "error"; panel?: boolean }
);

/**
 * A one-line outcome under the control that caused it (kit/notice.css):
 * `success` is a muted line led by a success-coloured check and announced
 * politely (`role="status"`); `error` is a danger-coloured line announced at
 * once (`role="alert"`). Render it only while there is something to say — the
 * live region announces on insertion.
 *
 * `panel` is the error that stands in for a whole pane or rail (the manifest
 * failed to load, the preview could not render): padded and a size up, the
 * `.ain-studio__error` every studio wrote by hand.
 */
export function Notice({ tone, panel, className, children }: NoticeProps) {
  const success = tone === "success";
  return (
    <p
      className={cx(panel ? "ain-studio__error" : cx("ain-notice", `ain-notice--${tone}`), className)}
      role={success ? "status" : "alert"}
    >
      {success && <CheckIcon />}
      {success && " "}
      {children}
    </p>
  );
}
