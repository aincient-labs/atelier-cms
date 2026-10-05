import { useState, type ReactElement, type ReactNode } from "react";
import { DropdownMenu as M } from "radix-ui";
import { usePortalContainer } from "./portal";
import { CheckIcon } from "./icons";
import { cx } from "./cx";

/**
 * An action menu (kit/menu.css), on Radix: arrow keys and typeahead move
 * through the items, Enter / Space choose, Escape and an outside click close,
 * focus returns to the trigger. The look is the console's `.ain-menu`.
 *
 * `trigger` is ONE element that forwards props and ref (a kit `IconButton`).
 * It carries `data-open` while the menu is open — the hook the console's
 * trigger styles already use. Items are `MenuItem`s; a destructive one is
 * `danger` — brick text, never a fill (the confirm step that follows it is a
 * brick-outlined `Button`; only the primary fills). A menu that PICKS one of several values (a crumb, the
 * agent picker) holds a `MenuRadioGroup` of `MenuRadioItem`s instead: the
 * current one carries an ink check and `aria-checked`.
 */
export function Menu({
  trigger,
  children,
  label,
  align = "end",
  sideOffset = 4,
  className,
}: {
  trigger: ReactElement;
  children: ReactNode;
  label?: string;
  align?: "start" | "center" | "end";
  sideOffset?: number;
  className?: string;
}) {
  const container = usePortalContainer();
  const [open, setOpen] = useState(false);
  return (
    <M.Root open={open} onOpenChange={setOpen}>
      <M.Trigger asChild data-open={open || undefined}>{trigger}</M.Trigger>
      <M.Portal container={container}>
        <M.Content className={cx("ain-menu ain-menu--anchored", className)} align={align} sideOffset={sideOffset} collisionPadding={12}
          // Radix names the menu by its trigger (aria-labelledby); an explicit
          // label replaces that rather than losing to it.
          {...(label ? { "aria-label": label, "aria-labelledby": undefined } : {})}
        >
          {children}
        </M.Content>
      </M.Portal>
    </M.Root>
  );
}

export function MenuItem({
  children,
  onSelect,
  icon,
  danger,
  disabled,
  asChild,
}: {
  children: ReactNode;
  onSelect?: () => void;
  icon?: ReactNode;
  danger?: boolean;
  disabled?: boolean;
  /**
   * The item IS its one child — an `<a href>` for a menu entry that navigates
   * (the account flyout's System and Drupal account-menu links). Radix lends it
   * the item role, focus and highlight; Enter clicks it, so the link follows
   * its href. `icon` is ignored: the child owns its own content.
   */
  asChild?: boolean;
}) {
  return (
    <M.Item asChild={asChild} className={cx("ain-menu__item", danger && "ain-menu__item--danger")} onSelect={onSelect} disabled={disabled}>
      {asChild ? children : <>{icon}{children}</>}
    </M.Item>
  );
}

/** The single-choice set inside a `Menu`: `value` is the checked item's. */
export function MenuRadioGroup({
  value,
  onValueChange,
  children,
}: {
  value: string;
  onValueChange: (value: string) => void;
  children: ReactNode;
}) {
  return (
    <M.RadioGroup value={value} onValueChange={onValueChange}>
      {children}
    </M.RadioGroup>
  );
}

export function MenuRadioItem({ value, children, icon }: { value: string; children: ReactNode; icon?: ReactNode }) {
  return (
    <M.RadioItem className="ain-menu__item ain-menu__item--radio" value={value}>
      {/* The column keeps its width when empty, so the labels line up. */}
      <span className="ain-menu__check" aria-hidden>
        <M.ItemIndicator>
          <CheckIcon />
        </M.ItemIndicator>
      </span>
      {icon}
      {children}
    </M.RadioItem>
  );
}

/** A group heading inside a `Menu` (the lifecycle bar's "Send back"): small
 *  muted caps, not focusable — arrow keys skip it. */
export function MenuLabel({ children }: { children: ReactNode }) {
  return <M.Label className="ain-menu__label">{children}</M.Label>;
}

/** A hairline between groups inside a `Menu`. */
export function MenuSeparator() {
  return <M.Separator className="ain-menu__sep" />;
}
