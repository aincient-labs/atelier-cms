import { useEffect, useState } from "react";
import { PAGE_LENS } from "@console/sdk";
import type { Rail } from "./checks-rows";
import type { ChecksLens } from "./checks-lenses";

/**
 * What the Checks rail tells its canvas (DECISIONS 0453, S3) — a tiny store
 * private to this studio, because the console mounts the rail and the preview
 * as two separate components:
 *
 *  - rail → canvas: the rail it rendered (for pins and lens counts), the lens
 *    to show and the section to scroll to when a row is picked;
 *  - canvas → rail: a pin or a lens list item asking the rail to open a row.
 *
 * Only the studio's own files import it (the studio fence).
 */

type CanvasState = {
  rail: Rail | null;
  lens: ChecksLens;
  focus: { section: string; seq: number } | null;
};

let state: CanvasState = { rail: null, lens: PAGE_LENS, focus: null };
const subs = new Set<() => void>();
const emit = () => {
  for (const cb of subs) cb();
};

export const getCanvas = (): CanvasState => state;

export function subscribeCanvas(cb: () => void): () => void {
  subs.add(cb);
  return () => {
    subs.delete(cb);
  };
}

/** The rail it just rendered — null when there is no report. */
export function publishRail(rail: Rail | null): void {
  if (rail === state.rail) return;
  state = { ...state, rail };
  emit();
}

export function setCanvasLens(lens: ChecksLens): void {
  if (lens === state.lens) return;
  state = { ...state, lens };
  emit();
}

/** Scroll the page to a section (and show the page). */
export function focusSection(section: string): void {
  state = { ...state, lens: PAGE_LENS, focus: { section, seq: (state.focus?.seq ?? 0) + 1 } };
  emit();
}

/** Back to the page lens, no focus — a new page or a closed one. */
export function resetCanvas(): void {
  state = { rail: state.rail, lens: PAGE_LENS, focus: null };
  emit();
}

/** React hook: the canvas state, re-rendering on change. */
export function useCanvas(): CanvasState {
  const [s, setS] = useState(getCanvas);
  useEffect(() => subscribeCanvas(() => setS(getCanvas())), []);
  return s;
}

const rowRequests = new Set<(findingId: string) => void>();

/** The canvas asks the rail to open a row (a pin, a lens list item). */
export function requestRow(findingId: string): void {
  for (const cb of rowRequests) cb(findingId);
}

/** The rail listens for those asks. */
export function onRowRequest(cb: (findingId: string) => void): () => void {
  rowRequests.add(cb);
  return () => {
    rowRequests.delete(cb);
  };
}
