import { RotateCcwIcon } from "./icons";

/**
 * A per-field "changed since last save" marker that doubles as a revert
 * control — shared by the brand and page studios. Rendered only when the field
 * differs from the saved baseline: it shows an accent dot at rest and swaps to
 * an undo glyph on hover/focus; activating it snaps just that field back to the
 * saved value. A <span> (not a <button>) so it never becomes the labelable
 * target a wrapping <label> would forward clicks to.
 */
export function FieldRevert({ label, onRevert }: { label: string; onRevert: () => void }) {
  const fire = (e: { preventDefault: () => void; stopPropagation: () => void }) => {
    e.preventDefault();
    e.stopPropagation();
    onRevert();
  };
  return (
    <span
      className="ain-revert"
      role="button"
      tabIndex={0}
      title={`Changed — click to revert ${label} to the saved value`}
      aria-label={`Revert ${label} to the saved value`}
      onClick={fire}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") fire(e);
      }}
    >
      <span className="ain-revert__dot" aria-hidden="true" />
      <RotateCcwIcon className="ain-revert__ico" />
    </span>
  );
}
