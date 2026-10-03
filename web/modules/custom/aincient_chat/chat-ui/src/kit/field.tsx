import { createContext, useContext, useId, type ComponentProps, type ReactNode } from "react";
import { cx } from "./cx";

/**
 * Form fields (kit/field.css). `Field` is the column — a whisper label, the
 * control, then a hint or an error — and it WIRES the control: the label's
 * `htmlFor`, the control's `id`, `aria-describedby` to the hint/error and
 * `aria-invalid` while there is an error all come from the field, so a control
 * placed inside one needs no ids of its own.
 *
 *   <Field label="Title" hint="Shown in search results">
 *     <TextInput value={t} onChange={…} />
 *   </Field>
 *
 * `dirty` draws the warning rail a changed field carries; `revert` is the slot
 * for a `FieldRevert` on the label line. Controls work outside a Field too —
 * they then take whatever `id` / aria props are passed.
 */
type FieldWiring = { id: string; describedBy?: string; invalid: boolean };

const FieldContext = createContext<FieldWiring | null>(null);

/** The wiring a control inside a `Field` takes, merged under its own props. */
export function useFieldProps<P extends { id?: string; "aria-describedby"?: string; "aria-invalid"?: ComponentProps<"input">["aria-invalid"] }>(props: P): P {
  const field = useContext(FieldContext);
  if (!field) return props;
  return {
    ...props,
    id: props.id ?? field.id,
    "aria-describedby": props["aria-describedby"] ?? field.describedBy,
    "aria-invalid": props["aria-invalid"] ?? (field.invalid || undefined),
  };
}

export type FieldProps = {
  label: ReactNode;
  children: ReactNode;
  hint?: ReactNode;
  error?: ReactNode;
  dirty?: boolean;
  revert?: ReactNode;
  className?: string;
};

export function Field({ label, children, hint, error, dirty, revert, className }: FieldProps) {
  const id = useId();
  const hintId = hint != null ? `${id}-hint` : undefined;
  const errorId = error != null && error !== false ? `${id}-error` : undefined;
  const describedBy = [errorId, hintId].filter(Boolean).join(" ") || undefined;
  return (
    <FieldContext.Provider value={{ id, describedBy, invalid: errorId != null }}>
      <div className={cx("ain-field", className)} data-dirty={dirty || undefined}>
        <label className="ain-field__label" htmlFor={id}>
          {revert ? (
            <>
              <span className="ain-field__labeltext">{label}</span>
              {revert}
            </>
          ) : (
            label
          )}
        </label>
        {children}
        {errorId && (
          <span id={errorId} className="ain-field__error" role="alert">
            {error}
          </span>
        )}
        {hintId && (
          <span id={hintId} className="ain-field__hint">
            {hint}
          </span>
        )}
      </div>
    </FieldContext.Provider>
  );
}

export function TextInput({ className, type = "text", ...rest }: ComponentProps<"input">) {
  return <input type={type} className={cx("ain-field__input", className)} {...useFieldProps(rest)} />;
}

/** A long-form text box; `mono` sets it in the console's monospace (Markdown source). */
export function Textarea({ className, mono, ...rest }: ComponentProps<"textarea"> & { mono?: boolean }) {
  return <textarea className={cx("ain-field__input", mono && "ain-field__textarea", className)} {...useFieldProps(rest)} />;
}

/**
 * A native select in the input body. Right for short, flat lists; a long or
 * grouped list (timezones) wants a filterable listbox instead.
 */
export function Select({ className, ...rest }: ComponentProps<"select">) {
  return <select className={cx("ain-field__input", className)} {...useFieldProps(rest)} />;
}

/** A boolean: checkbox · label on one row (it is its own field, no `Field` around it). */
export function Checkbox({ label, className, ...rest }: Omit<ComponentProps<"input">, "type"> & { label: ReactNode }) {
  return (
    <label className={cx("ain-field ain-field--check", className)}>
      <input type="checkbox" className="ain-field__checkbox" {...rest} />
      <span className="ain-field__label">{label}</span>
    </label>
  );
}

/**
 * One choice of several as a card: radio · label + the consequence of picking
 * it. Group them in a `<fieldset>` with a legend; they share a `name`.
 */
export function RadioCard({ label, hint, className, ...rest }: Omit<ComponentProps<"input">, "type"> & { label: ReactNode; hint?: ReactNode }) {
  return (
    <label className={cx("ain-field ain-field--radio", className)}>
      <input type="radio" className="ain-field__radio" {...rest} />
      <span className="ain-field__radiobody">
        <span className="ain-field__label">{label}</span>
        {hint != null && <span className="ain-field__hint">{hint}</span>}
      </span>
    </label>
  );
}
