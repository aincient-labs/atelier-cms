import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { CheckIcon, ChevronDownIcon, SparkleIcon } from "./icons";

/**
 * One selectable model in the picker. `recommendation` is our curated quality
 * label (recommended | tested | untested | not-recommended); it drives both the
 * ordering and the chip shown beside the model.
 */
export type ModelPickerOption = {
  /** The stored value — a "provider:model" string. */
  value: string;
  /** The model's display label. */
  label: string;
  /** The drupal/ai provider plugin id (for grouping + the mark). */
  providerId: string;
  /** The provider's human label (the group heading). */
  providerLabel: string;
  /** recommended | tested | untested | not-recommended. */
  recommendation: string;
};

/** Sort weight per label — mirrors ModelRecommendations::rank() on the backend. */
const RANK: Record<string, number> = {
  recommended: 0,
  tested: 1,
  untested: 2,
  "not-recommended": 3,
};

const rank = (label: string): number => RANK[label] ?? RANK.untested;

/** The short chip text shown beside a model, or null for the neutral "untested". */
function chipFor(recommendation: string): { text: string; tone: string } | null {
  switch (recommendation) {
    case "recommended":
      return { text: "Recommended", tone: "rec" };
    case "tested":
      return { text: "Tested", tone: "tested" };
    case "not-recommended":
      return { text: "Not recommended", tone: "warn" };
    default:
      return null;
  }
}

type Row =
  | { kind: "group"; key: string; label: string }
  | { kind: "option"; key: string; option: ModelPickerOption }
  | { kind: "none"; key: string; label: string };

/**
 * An accessible model picker — a custom listbox that replaces the native
 * `<select>` so each option can carry a provider mark and a quality chip, with
 * recommended models pinned to the top of their provider group.
 *
 * Options are grouped by provider; groups are ordered by their best model
 * (a group holding a recommended model floats up), and within a group models
 * sort recommended → tested → untested → not-recommended. The value is always a
 * "provider:model" string, so callers stay unchanged from the old `<select>`.
 *
 * Follows the WAI-ARIA listbox pattern: a trigger button (aria-haspopup) opens a
 * `role="listbox"`; arrow keys move `aria-activedescendant`, Enter/Space select,
 * Escape/Tab/outside-click close.
 */
export function ModelPicker({
  value,
  options,
  allowNone = false,
  noneLabel = "Not set",
  ariaLabel,
  onChange,
  renderMark,
}: {
  value: string;
  options: ModelPickerOption[];
  allowNone?: boolean;
  noneLabel?: string;
  ariaLabel?: string;
  onChange: (value: string) => void;
  renderMark?: (providerId: string) => ReactNode;
}) {
  const baseId = useId();
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);

  // Build the display rows: provider groups (best-rank first, then label) each
  // holding its models (rank, then label). An optional "not set" row leads.
  const rows = useMemo<Row[]>(() => {
    const byProvider = new Map<string, ModelPickerOption[]>();
    for (const opt of options) {
      const list = byProvider.get(opt.providerId) ?? [];
      list.push(opt);
      byProvider.set(opt.providerId, list);
    }
    const groups = [...byProvider.entries()].map(([providerId, opts]) => {
      const sorted = [...opts].sort(
        (a, b) => rank(a.recommendation) - rank(b.recommendation) || a.label.localeCompare(b.label),
      );
      const best = sorted.length ? rank(sorted[0].recommendation) : RANK.untested;
      return { providerId, label: sorted[0]?.providerLabel ?? providerId, best, opts: sorted };
    });
    groups.sort((a, b) => a.best - b.best || a.label.localeCompare(b.label));

    const out: Row[] = [];
    if (allowNone) out.push({ kind: "none", key: "__none__", label: noneLabel });
    for (const g of groups) {
      out.push({ kind: "group", key: `g:${g.providerId}`, label: g.label });
      for (const opt of g.opts) out.push({ kind: "option", key: opt.value, option: opt });
    }
    return out;
  }, [options, allowNone, noneLabel]);

  // The indices of navigable rows (selectable options + the "none" row).
  const selectableIndices = useMemo(
    () => rows.map((r, i) => (r.kind === "group" ? -1 : i)).filter((i) => i >= 0),
    [rows],
  );

  const selected = useMemo(() => options.find((o) => o.value === value) ?? null, [options, value]);

  const commit = useCallback(
    (next: string) => {
      onChange(next);
      setOpen(false);
      buttonRef.current?.focus();
    },
    [onChange],
  );

  // On open, place the active row on the current selection (or the first option).
  const openList = useCallback(() => {
    const selIdx = rows.findIndex((r) => r.kind === "option" && r.option.value === value);
    const start = selIdx >= 0 ? selIdx : (selectableIndices[0] ?? 0);
    setActiveIndex(start);
    setOpen(true);
  }, [rows, selectableIndices, value]);

  // Move the active row by a step among the navigable rows only.
  const move = useCallback(
    (dir: 1 | -1 | "home" | "end") => {
      const pos = selectableIndices.indexOf(activeIndex);
      let nextPos: number;
      if (dir === "home") nextPos = 0;
      else if (dir === "end") nextPos = selectableIndices.length - 1;
      else nextPos = Math.min(selectableIndices.length - 1, Math.max(0, pos + dir));
      setActiveIndex(selectableIndices[nextPos] ?? activeIndex);
    },
    [selectableIndices, activeIndex],
  );

  // Focus the list when it opens; keep the active option scrolled into view.
  useEffect(() => {
    if (open) listRef.current?.focus();
  }, [open]);
  useEffect(() => {
    if (!open) return;
    const el = listRef.current?.querySelector<HTMLElement>(`[data-idx="${activeIndex}"]`);
    el?.scrollIntoView({ block: "nearest" });
  }, [open, activeIndex]);

  // Close on outside click.
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  const chooseActive = () => {
    const row = rows[activeIndex];
    if (row?.kind === "none") commit("");
    else if (row?.kind === "option") commit(row.option.value);
  };

  const onListKeyDown = (e: React.KeyboardEvent) => {
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        move(1);
        break;
      case "ArrowUp":
        e.preventDefault();
        move(-1);
        break;
      case "Home":
        e.preventDefault();
        move("home");
        break;
      case "End":
        e.preventDefault();
        move("end");
        break;
      case "Enter":
      case " ":
        e.preventDefault();
        chooseActive();
        break;
      case "Escape":
        e.preventDefault();
        setOpen(false);
        buttonRef.current?.focus();
        break;
      case "Tab":
        setOpen(false);
        break;
    }
  };

  const activeId = `${baseId}-opt-${activeIndex}`;
  const selectedChip = selected ? chipFor(selected.recommendation) : null;

  return (
    <div className="ain-picker" ref={rootRef}>
      <button
        type="button"
        ref={buttonRef}
        className="ain-btn ain-picker__trigger"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={ariaLabel}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={(e) => {
          if (!open && (e.key === "ArrowDown" || e.key === "Enter" || e.key === " ")) {
            e.preventDefault();
            openList();
          }
        }}
      >
        <span className="ain-picker__value">
          {selected ? (
            <>
              {renderMark?.(selected.providerId)}
              <span className="ain-picker__value-label">{selected.label}</span>
              {selectedChip && (
                <span className={`ain-picker__chip ain-picker__chip--${selectedChip.tone}`}>
                  {selectedChip.tone === "rec" && <SparkleIcon className="ain-picker__chip-icon" />}
                  {selectedChip.text}
                </span>
              )}
            </>
          ) : (
            <span className="ain-picker__value-label ain-picker__value-label--empty">{noneLabel}</span>
          )}
        </span>
        <ChevronDownIcon className="ain-picker__caret" />
      </button>

      {open && (
        <ul
          className="ain-picker__list"
          role="listbox"
          ref={listRef}
          tabIndex={-1}
          aria-activedescendant={activeId}
          aria-label={ariaLabel}
          onKeyDown={onListKeyDown}
        >
          {rows.map((row, i) => {
            if (row.kind === "group") {
              return (
                <li key={row.key} role="presentation" className="ain-picker__group">
                  {row.label}
                </li>
              );
            }
            const isActive = i === activeIndex;
            if (row.kind === "none") {
              const isSel = value === "";
              return (
                <li
                  key={row.key}
                  id={`${baseId}-opt-${i}`}
                  data-idx={i}
                  role="option"
                  aria-selected={isSel}
                  className={`ain-picker__option${isActive ? " ain-picker__option--active" : ""}`}
                  onMouseEnter={() => setActiveIndex(i)}
                  onClick={() => commit("")}
                >
                  <span className="ain-picker__option-label ain-picker__value-label--empty">{row.label}</span>
                  {isSel && <CheckIcon className="ain-picker__check" />}
                </li>
              );
            }
            const opt = row.option;
            const isSel = value === opt.value;
            const chip = chipFor(opt.recommendation);
            return (
              <li
                key={row.key}
                id={`${baseId}-opt-${i}`}
                data-idx={i}
                role="option"
                aria-selected={isSel}
                className={`ain-picker__option${isActive ? " ain-picker__option--active" : ""}`}
                onMouseEnter={() => setActiveIndex(i)}
                onClick={() => commit(opt.value)}
              >
                {renderMark?.(opt.providerId)}
                <span className="ain-picker__option-label">{opt.label}</span>
                {chip && (
                  <span className={`ain-picker__chip ain-picker__chip--${chip.tone}`}>
                    {chip.tone === "rec" && <SparkleIcon className="ain-picker__chip-icon" />}
                    {chip.text}
                  </span>
                )}
                {isSel && <CheckIcon className="ain-picker__check" />}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
