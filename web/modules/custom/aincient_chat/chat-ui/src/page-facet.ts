/**
 * Which facet of the open page the Content studio is editing — its **body**
 * (front-facing sections + rendered preview) or its **presence** (how the page
 * appears where it's referenced: SEO/meta + social/search previews). A page is
 * one node with one draft; the facet only swaps which preview + which rail show.
 *
 * A tiny reactive store (the selected-section pattern), shared so `PagePreview`
 * and `PageStudio` stay in step: the studio's switch sets it, both panes read it.
 * Defaults to "body" and resets there whenever the open document changes, so a
 * page always opens on its body. Presence is a Content-studio, page-only concept;
 * other studios ignore the facet (they never render the switch).
 */
import { useEffect, useState } from "react";

export type PageFacet = "body" | "presence";

let facet: PageFacet = "body";
const subs = new Set<(f: PageFacet) => void>();

export function getFacet(): PageFacet {
  return facet;
}

export function setFacet(next: PageFacet): void {
  if (next === facet) return;
  facet = next;
  for (const cb of subs) cb(facet);
}

/** Snap back to the body facet (on a doc switch / studio change). */
export function resetFacet(): void {
  setFacet("body");
}

/** Subscribe to facet changes; returns an unsubscribe fn. */
export function subscribeFacet(cb: (f: PageFacet) => void): () => void {
  subs.add(cb);
  return () => {
    subs.delete(cb);
  };
}

/** React hook: the live facet, re-rendering the caller on change. */
export function useFacet(): PageFacet {
  const [f, setF] = useState<PageFacet>(getFacet);
  useEffect(() => subscribeFacet(setF), []);
  return f;
}
