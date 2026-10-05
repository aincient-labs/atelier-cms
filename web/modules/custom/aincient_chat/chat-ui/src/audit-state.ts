import { apiUrl } from "./console-config";
/**
 * The Checks studio's "which node is being audited" — the audit parallel of
 * page-state's open-document identity, kept here (not in the ChecksStudio
 * component) so it can be read and driven from OUTSIDE the component:
 *   - url-sync reads it to reflect ?audit=<nid> into the URL, and writes it on
 *     back/forward so the report follows history;
 *   - ChecksStudio mirrors its own selection here and adopts external changes.
 *
 * Audit-only and ephemeral — like page-state, nothing here is persisted; it's
 * just the in-context node. null = no page picked (the URL then carries no
 * ?audit, which is itself the "nothing in context" signal).
 */

let currentNode: string | null = null;
/** The TRANSLATION being audited (null = the source language). Part of the
 *  audited-document identity: the report endpoint takes `?langcode=`, so EN and
 *  DE are two different audits of one node. */
let currentLang: string | null = null;
const subscribers = new Set<() => void>();

/** The node currently being audited, or null when none is picked. */
export function getAuditNode(): string | null {
  return currentNode;
}

/** The langcode of the audited translation, or null for the source language. */
export function getAuditLang(): string | null {
  return currentLang;
}

/** Set the audited node (+ translation) and notify listeners. No-op (no notify)
 *  when unchanged, so the ChecksStudio mirror ⇄ url-sync reflection loop
 *  converges in one pass. Clearing the node clears the langcode with it — they
 *  are one identity. */
export function setAuditNode(node: string | null, langcode: string | null = null): void {
  const lang = node === null ? null : langcode;
  if (node === currentNode && lang === currentLang) return;
  currentNode = node;
  currentLang = lang;
  for (const cb of subscribers) cb();
}

/** Subscribe to audited-node changes; returns an unsubscribe fn. */
export function subscribeAuditNode(cb: () => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/** A page's audit verdict in counts — what the Content rail's Checks row shows. */
export type AuditSummary = { fail: number; warn: number; pass: number };

/**
 * Grade a page's latest saved draft (the translation `langcode`, null = the
 * source) and return only the counts — the Content rail's Checks row
 * (DECISIONS 0454). The same report endpoint the Checks studio reads, so the
 * row and the studio never disagree. Null on any failure (Checks off, no
 * access, a blip): the row then just offers "Open Checks".
 */
export async function fetchAuditSummary(node: string, langcode: string | null): Promise<AuditSummary | null> {
  const url = apiUrl(
    `/audit/${encodeURIComponent(node)}/report?revision=draft` +
      (langcode ? `&langcode=${encodeURIComponent(langcode)}` : ""),
  );
  try {
    const res = await fetch(url, { credentials: "same-origin" });
    if (!res.ok) return null;
    const data = (await res.json()) as { summary?: Partial<AuditSummary> };
    const s = data?.summary;
    if (!s || typeof s.fail !== "number" || typeof s.warn !== "number" || typeof s.pass !== "number") return null;
    return { fail: s.fail, warn: s.warn, pass: s.pass };
  } catch {
    return null;
  }
}
