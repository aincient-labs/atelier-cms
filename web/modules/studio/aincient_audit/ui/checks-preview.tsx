import { useEffect, useState, type ReactNode } from "react";
import {
  PagePreview,
  SearchResultCard,
  ShareCard,
  getPageBaseline,
  getPageDraft,
  getPageUrl,
  setSelectedSection,
  subscribePageBaseline,
  subscribePageDraft,
  type PageSchema,
  type PreviewLens,
} from "@console/sdk";
import { SEVERITY_LABEL, type Rail, type Row } from "./checks-rows";
import {
  SEARCH_LENS,
  SHARE_LENS,
  pinsFor,
  rowForSection,
  rowsForLens,
  type ChecksLens,
} from "./checks-lenses";
import { requestRow, setCanvasLens, useCanvas } from "./checks-canvas";

/**
 * The Checks canvas: the SHARED page preview (the same one Content renders)
 * with what Checks adds through the sdk's lens seam (DECISIONS 0453, S3):
 *
 *  - two lenses — Search result and Share card — each the saved page beside
 *    the draft, the same cards the Presence canvas draws, with the lens's
 *    findings listed under them;
 *  - pins over every section with a failing, fixed or changed finding, and
 *    over every section a staged change touched. A pin opens its row.
 *
 * The rail drives it through `checks-canvas.ts`: picking a row switches to the
 * lens that shows its field, or scrolls the page to its section.
 */
export function ChecksPreview() {
  const { rail, lens, focus } = useCanvas();
  // Pins read the draft and baseline: follow both.
  const [, setTick] = useState(0);
  useEffect(() => {
    const bump = () => setTick((t) => t + 1);
    const offDraft = subscribePageDraft(bump);
    const offBase = subscribePageBaseline(bump);
    return () => {
      offDraft();
      offBase();
    };
  }, []);

  if (!rail) return <PagePreview />;

  const draft = getPageDraft();
  const baseline = getPageBaseline();
  const lenses: PreviewLens[] = [
    lensFor(rail, SEARCH_LENS, "Search result", (s) => <SearchResultCard schema={s} url={getPageUrl()} />),
    lensFor(rail, SHARE_LENS, "Share card", (s) => <ShareCard schema={s} url={getPageUrl()} />),
  ];
  return (
    <PagePreview
      lenses={lenses}
      lens={lens}
      onLensChange={(id) => setCanvasLens(id as ChecksLens)}
      pins={pinsFor(rail, draft, baseline)}
      onPin={(section) => {
        setSelectedSection(section);
        const row = rowForSection(rail, section);
        if (row) requestRow(row.finding.id);
      }}
      focus={focus}
    />
  );
}

/** Failing or warning rows count on the lens switch; fixed ones don't. */
const open = (r: Row) => r.status === "fail" || r.status === "warn";

function lensFor(rail: Rail, id: ChecksLens, label: string, card: (s: PageSchema | null) => ReactNode): PreviewLens {
  const rows = rowsForLens(rail, id);
  return {
    id,
    label,
    count: rows.filter(open).length,
    render: (draft, base) => <LensCompare draft={draft} base={base} rows={rows} card={card} />,
  };
}

/**
 * One lens: the saved page and the draft side by side — or the draft alone
 * when nothing it shows has changed — then the findings it covers, each
 * opening its row in the rail.
 */
function LensCompare({
  draft,
  base,
  rows,
  card,
}: {
  draft: PageSchema | null;
  base: PageSchema | null;
  rows: Row[];
  card: (s: PageSchema | null) => ReactNode;
}) {
  const changed = rows.some((r) => r.value !== r.baseValue || r.status === "fixed");
  return (
    <div className="ain-checks-lens">
      <div className="ain-checks-lens__pair" data-compare={changed || undefined}>
        {changed && (
          <figure className="ain-checks-lens__side">
            <figcaption className="ain-checks-lens__cap">Last saved</figcaption>
            {card(base)}
          </figure>
        )}
        <figure className="ain-checks-lens__side">
          <figcaption className="ain-checks-lens__cap">{changed ? "Your draft" : "Your draft · unchanged since your last save"}</figcaption>
          {card(draft)}
        </figure>
      </div>
      {rows.length > 0 && (
        <ul className="ain-checks-lens__rows">
          {rows.map((r) => (
            <li key={r.finding.id}>
              <button type="button" className="ain-checks-lens__row" onClick={() => requestRow(r.finding.id)}>
                <span className="ain-checks-audit__badge" data-severity={r.status}>
                  {r.status === "fixed" ? "Fixed in draft" : SEVERITY_LABEL[r.status]}
                </span>
                {r.finding.title}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
