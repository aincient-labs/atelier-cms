import { useRef, type FormEventHandler, type ReactElement, type ReactNode, type RefObject } from "react";
import { Dialog as D } from "radix-ui";
import { usePortalContainer } from "./portal";
import { XIcon } from "./icons";
import { cx } from "./cx";

/**
 * A modal (kit/dialog.css), on Radix: focus is trapped inside and returns to
 * whatever opened it, Escape and a click on the overlay close it, the page
 * behind is inert to assistive tech, and `title` / `description` name it.
 * Focus goes back to the element that had it when the dialog opened — Radix
 * only knows to return it to a Radix Trigger, and a controlled dialog opened
 * from a plain button (or a menu item) has none.
 *
 * Controlled: the caller owns `open`. `actions` is the button row — Cancel
 * first, the promise last, at most one primary (or one danger, for the confirm
 * step of a destructive flow). `dismissible={false}` keeps Escape and the
 * overlay from closing it, for a step that must be answered.
 *
 * A FORM dialog passes `onSubmit`: the card is then the `<form>`, so Enter in
 * a field submits and a `type="submit"` action is the promise. `closeButton`
 * puts a ✕ beside the title; `initialFocus` names the control focus lands on
 * (default: the first focusable one); on close focus goes back to the opener,
 * or to `returnFocus` when the opener is gone (a menu item). `asChild` hands the whole card to the
 * one child element (it must forward props and ref, and name itself with a
 * `DialogTitle`) — for a card that already exists, like the thread end-state.
 */
export function Dialog({
  open,
  onOpenChange,
  title,
  titleClassName,
  description,
  children,
  actions,
  dismissible = true,
  closeButton = false,
  onSubmit,
  initialFocus,
  returnFocus,
  asChild = false,
  className,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title?: ReactNode;
  titleClassName?: string;
  description?: ReactNode;
  children?: ReactNode;
  actions?: ReactNode;
  dismissible?: boolean;
  closeButton?: boolean;
  onSubmit?: FormEventHandler<HTMLFormElement>;
  initialFocus?: RefObject<HTMLElement | null>;
  returnFocus?: RefObject<HTMLElement | null>;
  asChild?: boolean;
  className?: string;
}) {
  const container = usePortalContainer();
  const guard = dismissible ? undefined : (e: Event) => e.preventDefault();
  const opener = useRef<HTMLElement | null>(null);
  const titleEl = <D.Title className={cx("ain-dialog__title", titleClassName)}>{title}</D.Title>;
  const body = (
    <>
      {closeButton ? (
        <div className="ain-dialog__head">
          {titleEl}
          <D.Close asChild>
            <button type="button" className="ain-btn ain-dialog__close" aria-label="Close" title="Close (Esc)">
              <XIcon />
            </button>
          </D.Close>
        </div>
      ) : (
        titleEl
      )}
      {description != null && <D.Description className="ain-dialog__description">{description}</D.Description>}
      {children}
      {actions != null && <div className="ain-confirm__actions">{actions}</div>}
    </>
  );
  return (
    <D.Root open={open} onOpenChange={onOpenChange}>
      <D.Portal container={container}>
        <D.Overlay className="ain-confirm__overlay">
          <D.Content
            asChild={asChild || onSubmit != null}
            className={asChild ? undefined : cx("ain-confirm ain-dialog", className)}
            onOpenAutoFocus={(e) => {
              // Runs before Radix moves focus in: what has focus now opened us.
              opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
              if (initialFocus?.current) {
                e.preventDefault();
                initialFocus.current.focus();
              }
            }}
            onCloseAutoFocus={(e) => {
              // The opener may be gone by now (a menu item, unmounted with its
              // menu): fall back to `returnFocus`, never to <body>.
              // <body> is no opener: focus had already fallen there.
              const was = opener.current;
              const back = was?.isConnected && was !== document.body ? was : (returnFocus?.current ?? null);
              opener.current = null;
              if (back?.isConnected) {
                e.preventDefault();
                back.focus();
              }
            }}
            onEscapeKeyDown={guard}
            onPointerDownOutside={guard}
            onInteractOutside={guard}
            // Radix wires aria-describedby to the Description; with none, an
            // explicit undefined tells it the omission is deliberate.
            {...(description == null ? { "aria-describedby": undefined } : {})}
          >
            {asChild ? children : onSubmit != null ? <form onSubmit={onSubmit}>{body}</form> : body}
          </D.Content>
        </D.Overlay>
      </D.Portal>
    </D.Root>
  );
}

/** Closes the enclosing Dialog — wrap a Cancel button: `<DialogClose><Button>Cancel</Button></DialogClose>`. */
export function DialogClose({ children }: { children: ReactElement }) {
  return <D.Close asChild>{children}</D.Close>;
}

/** The heading that names an `asChild` Dialog's own card. Renders an `<h2>`. */
export function DialogTitle({ className, children }: { className?: string; children: ReactNode }) {
  return <D.Title className={className}>{children}</D.Title>;
}
