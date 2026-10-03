import type { ComponentProps, ReactNode } from "react";
import { cx } from "./cx";

/**
 * The console's buttons (kit/button.css). A button is a promise, and the kit
 * has four voices for it:
 *
 *   secondary  raised paper, hairline — the default
 *   primary    the ONE filled cinnabar control per view
 *   quiet      text only, a ground on hover — actions that repeat per row/card
 *   danger     the brick-outlined CONFIRM step (never filled: only the primary fills); the trigger before it stays quiet
 *
 * `size="sm"` is the in-field metric (a reference field's Edit / Change).
 * `pressed` makes it a toggle (Compare): the accent tint plus `aria-pressed`.
 * `type` defaults to "button" — a kit button never submits a form by accident;
 * pass `type="submit"` when it should.
 */
export type ButtonVariant = "secondary" | "primary" | "quiet" | "danger";

export type ButtonProps = ComponentProps<"button"> & {
  variant?: ButtonVariant;
  size?: "md" | "sm";
  pressed?: boolean;
};

export function Button({ variant = "secondary", size = "md", pressed, className, type = "button", ...rest }: ButtonProps) {
  return (
    <button
      type={type}
      aria-pressed={pressed}
      className={cx(
        "ain-btn ain-topbtn",
        variant !== "secondary" && `ain-topbtn--${variant}`,
        size === "sm" && "ain-topbtn--sm",
        pressed && "ain-topbtn--on",
        className,
      )}
      {...rest}
    />
  );
}

/**
 * A ghost icon button. `label` is required: an icon has no text, so the label
 * is its accessible name and its tooltip. `pressed` = the toggled-on tint.
 */
export type IconButtonProps = Omit<ComponentProps<"button">, "children"> & {
  label: string;
  pressed?: boolean;
  children: ReactNode;
};

export function IconButton({ label, pressed, className, type = "button", title, ...rest }: IconButtonProps) {
  return (
    <button
      type={type}
      aria-label={label}
      title={title ?? label}
      aria-pressed={pressed}
      className={cx("ain-btn ain-iconbtn", pressed && "ain-iconbtn--on", className)}
      {...rest}
    />
  );
}
