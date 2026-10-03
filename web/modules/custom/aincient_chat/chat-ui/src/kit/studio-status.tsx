import type { ReactNode } from "react";
import { cx } from "./cx";
import { CheckIcon } from "./icons";

/**
 * The draft-state line under a studio's action bar (kit/studio-status.css):
 * one row that says whether the rail matches what is saved. Three states, the
 * first that applies wins — `dirty` (the unsaved count, warning-coloured; any
 * falsy value means clean), then `saved` (the outcome of the last Publish or
 * apply, led by a check), then `children` (what the rail looks like at rest).
 * Announced politely (`role="status"`), so a Publish is heard as well as seen.
 *
 * Not a `Notice`: a Notice is an outcome under the control that caused it and
 * comes and goes; this row belongs to the rail and stays.
 */
export function StudioStatus({
  dirty,
  saved,
  className,
  children,
}: {
  dirty?: ReactNode;
  saved?: ReactNode;
  className?: string;
  children?: ReactNode;
}) {
  const isDirty = Boolean(dirty);
  return (
    <p className={cx("ain-studio__status", className)} data-dirty={isDirty || undefined} role="status">
      {isDirty ? dirty : saved ? <><CheckIcon /> {saved}</> : children}
    </p>
  );
}
