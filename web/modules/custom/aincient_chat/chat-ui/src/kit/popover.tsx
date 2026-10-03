import type { ReactElement, ReactNode } from "react";
import { Popover as P } from "radix-ui";
import { usePortalContainer } from "./portal";
import { cx } from "./cx";

/**
 * A floating card anchored to its trigger (kit/popover.css), on Radix: focus
 * moves in on open and back to the trigger on close, Escape and an outside
 * click dismiss, the trigger carries `aria-expanded` / `aria-controls`, and the
 * card flips to stay on screen.
 *
 * `trigger` must be ONE element that forwards its props and ref — a kit
 * `Button` / `IconButton` or a plain `<button>`; it becomes the toggle as-is.
 * `title` heads the card and names the dialog it is to assistive tech;
 * without one, pass `label`.
 */
export function Popover({
  trigger,
  children,
  title,
  label,
  open,
  onOpenChange,
  side = "bottom",
  align = "start",
  className,
}: {
  trigger: ReactElement;
  children: ReactNode;
  title?: ReactNode;
  label?: string;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  side?: "top" | "right" | "bottom" | "left";
  align?: "start" | "center" | "end";
  className?: string;
}) {
  const container = usePortalContainer();
  return (
    <P.Root open={open} onOpenChange={onOpenChange}>
      <P.Trigger asChild>{trigger}</P.Trigger>
      <P.Portal container={container}>
        <P.Content className={cx("ain-popover", className)} side={side} align={align} sideOffset={6} collisionPadding={12} aria-label={title ? undefined : label}>
          {title != null && <h2 className="ain-popover__title">{title}</h2>}
          {children}
        </P.Content>
      </P.Portal>
    </P.Root>
  );
}
