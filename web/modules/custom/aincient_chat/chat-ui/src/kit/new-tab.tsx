import { ExternalIcon } from "./icons";

/**
 * Marks a link that opens in a new tab (audit N4): the external icon for the
 * eye, and words for a screen reader, which would otherwise read nothing (or,
 * with the old "↗" glyph, "north east arrow").
 */
export function NewTabMark() {
  return (
    <>
      <ExternalIcon className="ain-newtab" />
      <span className="ain-sr-only"> (opens in a new tab)</span>
    </>
  );
}
