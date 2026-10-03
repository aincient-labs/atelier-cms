import { useEffect, useId, useMemo, useRef, useState, type ComponentProps } from "react";
import { Popover as P } from "radix-ui";
import { usePortalContainer } from "./portal";
import { CheckIcon, ChevronDownIcon } from "./icons";
import { useFieldProps } from "./field";
import { cx } from "./cx";

export type FilterSelectOption = { value: string; label: string; group?: string | null };

/**
 * A select for a LONG list (kit/filter-select.css): a field-width trigger and
 * a flyout with a type-to-filter box over the options, grouped under
 * headings. The native `Select` stays the choice for a short list.
 *
 * On Radix Popover for the layer (portal into the console root, Escape and an
 * outside click close, focus back to the trigger, nested inside a Dialog
 * without closing it). The list is the WAI-ARIA combobox pattern: focus stays
 * in the filter box, ↑/↓ move the active option (`aria-activedescendant`),
 * Enter chooses it, and typing narrows by label, value or group.
 *
 * Inside a `Field`, the trigger takes the field's id, so the label names it.
 */
export function FilterSelect({
  options,
  value,
  onChange,
  label,
  filterLabel = "Filter",
  placeholder = "Filter…",
  emptyText = "No match.",
  className,
  ...rest
}: {
  options: FilterSelectOption[];
  value: string;
  onChange: (value: string) => void;
  /** Names the list of options to assistive tech ("Timezone"). */
  label: string;
  filterLabel?: string;
  placeholder?: string;
  emptyText?: string;
  className?: string;
} & Pick<ComponentProps<"button">, "id" | "aria-describedby" | "aria-invalid" | "disabled">) {
  const container = usePortalContainer();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(0);
  const listRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const baseId = useId();
  const listId = `${baseId}-list`;
  const optionId = (i: number) => `${baseId}-opt-${i}`;

  const current = options.find((o) => o.value === value);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return options;
    return options.filter(
      (o) => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q) || (o.group ?? "").toLowerCase().includes(q),
    );
  }, [options, query]);

  const onOpenChange = (next: boolean) => {
    if (next) {
      setQuery("");
      setActive(Math.max(0, options.findIndex((o) => o.value === value)));
    }
    setOpen(next);
  };

  // Keep the active option in view as the keys (or a new filter) move it.
  useEffect(() => {
    if (!open) return;
    listRef.current?.querySelector<HTMLElement>(`#${CSS.escape(optionId(active))}`)?.scrollIntoView?.({ block: "nearest" });
  }, [open, active, filtered]);

  const choose = (v: string) => {
    onChange(v);
    setOpen(false);
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    const last = filtered.length - 1;
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        setActive((i) => (i >= last ? 0 : i + 1));
        break;
      case "ArrowUp":
        e.preventDefault();
        setActive((i) => (i <= 0 ? last : i - 1));
        break;
      case "PageDown":
        e.preventDefault();
        setActive((i) => Math.min(last, i + 10));
        break;
      case "PageUp":
        e.preventDefault();
        setActive((i) => Math.max(0, i - 10));
        break;
      case "Enter":
        e.preventDefault();
        if (filtered[active]) choose(filtered[active].value);
        break;
    }
  };

  const field = useFieldProps(rest);

  return (
    <P.Root open={open} onOpenChange={onOpenChange}>
      <P.Trigger asChild>
        <button
          type="button"
          className={cx("ain-btn ain-selfield__trigger", className)}
          data-open={open || undefined}
          aria-haspopup="listbox"
          {...field}
        >
          <span className="ain-selfield__label">{current?.label ?? value}</span>
          <ChevronDownIcon className="ain-selfield__caret" aria-hidden />
        </button>
      </P.Trigger>
      <P.Portal container={container}>
        <P.Content
          className="ain-menu ain-menu--anchored ain-selfield__menu"
          align="start"
          sideOffset={4}
          collisionPadding={12}
          aria-label={label}
          onOpenAutoFocus={(e) => {
            e.preventDefault();
            inputRef.current?.focus();
          }}
        >
          <div className="ain-selfield__filter">
            <input
              ref={inputRef}
              className="ain-field__input"
              type="text"
              role="combobox"
              aria-expanded
              aria-controls={listId}
              aria-autocomplete="list"
              aria-activedescendant={filtered[active] ? optionId(active) : undefined}
              aria-label={filterLabel}
              value={query}
              placeholder={placeholder}
              onChange={(e) => {
                setQuery(e.target.value);
                setActive(0);
              }}
              onKeyDown={onKeyDown}
            />
          </div>
          {filtered.length === 0 && <p className="ain-selfield__empty">{emptyText}</p>}
          <div ref={listRef} id={listId} role="listbox" aria-label={label}>
            {filtered.map((o, i) => {
              const showGroup = o.group && (i === 0 || filtered[i - 1].group !== o.group);
              const selected = o.value === value;
              return (
                <div key={o.value} role="none">
                  {showGroup && (
                    <div className="ain-selfield__group" role="presentation">
                      {o.group}
                    </div>
                  )}
                  <div
                    id={optionId(i)}
                    className="ain-menu__item ain-menu__item--radio"
                    role="option"
                    aria-selected={selected}
                    data-highlighted={i === active || undefined}
                    // Keep focus in the filter box: the pick happens on click.
                    onPointerDown={(e) => e.preventDefault()}
                    onPointerMove={() => i !== active && setActive(i)}
                    onClick={() => choose(o.value)}
                  >
                    <span className="ain-menu__check" aria-hidden>
                      {selected && <CheckIcon />}
                    </span>
                    {o.label}
                  </div>
                </div>
              );
            })}
          </div>
        </P.Content>
      </P.Portal>
    </P.Root>
  );
}
