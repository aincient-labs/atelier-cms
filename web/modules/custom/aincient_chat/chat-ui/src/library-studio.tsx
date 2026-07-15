import { useCallback, useEffect, useRef, useState } from "react";
import type { ChangeEvent } from "react";
import type { ReferenceDescriptor } from "./reference-field";
import { openBlock } from "./block-nav";
import { consoleNav } from "./console-nav";
import { CheckIcon, DocumentIcon, ImageIcon, PlusIcon, SpinnerIcon, UploadIcon } from "./icons";
import { apiUrl } from "./console-config";

/**
 * The Library shelf browser — the media family's browse canvas (DECISIONS 0168).
 *
 * The centre pane of the SHELF room (`{ kind: "shelf" }`), rendered by
 * MediaPreview when nothing is open — exactly as ContentBrowser is the Content
 * list room's canvas. One catalog over every reusable ingredient a page composes
 * FROM: media (images) and global blocks, one substrate (a `block` media bundle,
 * DECISIONS 0138–0139). It reuses the SAME backend the inline picker does —
 * `GET /atelier/reference/search` over {@see ReferenceCatalog} — so the shelf
 * and the in-field picker are two views of one catalog through a keyhole.
 *
 * Plate 10 ledger anatomy: ONE toolbar row (recessed search · segmented type
 * facet · the actions), hairline rows in one panel — thumb, title over a mono
 * fact line, hover-revealed quiet actions. The whole row is the click target:
 * an image opens in place (its media room — the studio rail + chat follow); a
 * block opens its editor in a new tab (no in-console block studio yet).
 * Generating a NEW image is the chat rail's verb now — the retired "New image"
 * button's job — so the toolbar's actions are Upload (the always-available
 * non-AI way in) and the quiet New block.
 */

const SEARCH_URL = apiUrl("/reference/search");
const UPLOAD_URL = apiUrl("/media/upload");

/** The shelf's type facets → the `types` CSV the search endpoint takes. */
type Filter = "all" | "media" | "block";
const FILTER_TYPES: Record<Filter, string> = {
  all: "media,block",
  media: "media",
  block: "block",
};
const FILTERS: { key: Filter; label: string }[] = [
  { key: "all", label: "All" },
  { key: "media", label: "Images" },
  { key: "block", label: "Blocks" },
];

/** The id inside a `<type>:<id>` reference token (media room / block deep link). */
function idOf(token: string): string {
  return token.slice(token.indexOf(":") + 1);
}

export function LibraryBrowse() {
  const [items, setItems] = useState<ReferenceDescriptor[] | null>(null);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<Filter>("all");
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const [copied, setCopied] = useState<string | null>(null);
  // Bumped after an upload to force the search effect to re-run (fresh shelf).
  const [reloadKey, setReloadKey] = useState(0);
  const fileInput = useRef<HTMLInputElement>(null);
  const debounce = useRef<number | undefined>(undefined);

  // Browse the catalog, debounced against typing / filter changes.
  useEffect(() => {
    window.clearTimeout(debounce.current);
    debounce.current = window.setTimeout(() => {
      const params = new URLSearchParams({ types: FILTER_TYPES[filter] });
      if (query.trim()) params.set("q", query.trim());
      setError(null);
      fetch(`${SEARCH_URL}?${params}`, { credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((data) => setItems(Array.isArray(data?.items) ? data.items : []))
        .catch((e) => {
          setItems([]);
          setError(String(e));
        });
    }, 180);
    return () => window.clearTimeout(debounce.current);
  }, [query, filter, reloadKey]);

  const upload = useCallback(async (file: File) => {
    setUploading(true);
    setError(null);
    try {
      const body = new FormData();
      body.append("file", file);
      const res = await fetch(UPLOAD_URL, { method: "POST", credentials: "same-origin", body });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.item?.token) throw new Error(data?.error ?? `HTTP ${res.status}`);
      setReloadKey((k) => k + 1);
    } catch (e) {
      setError(`Upload failed: ${e instanceof Error ? e.message : e}`);
    } finally {
      setUploading(false);
    }
  }, []);

  const onFile = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) void upload(file);
    e.target.value = "";
  };

  const copyToken = useCallback((token: string) => {
    navigator.clipboard
      ?.writeText(token)
      .then(() => {
        setCopied(token);
        window.setTimeout(() => setCopied((c) => (c === token ? null : c)), 1200);
      })
      .catch(() => {});
  }, []);

  // The whole row opens the item: an image enters its media room in place; a
  // block enters its editor in place (it owns the lock + moderation machinery).
  const open = (item: ReferenceDescriptor) => {
    if (item.type === "block") openBlock({ id: idOf(item.token) });
    else consoleNav.enterRoom({ kind: "media", id: Number(idOf(item.token)) });
  };

  return (
    <div className="ain-browser ain-shelf" aria-label="Library">
      <div className="ain-browser__bar">
        <input
          className="ain-field__input ain-browser__search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search the library…"
          spellCheck={false}
          aria-label="Search the library"
        />
        <div className="ain-seg" role="tablist" aria-label="Filter by type">
          {FILTERS.map((f) => (
            <button
              key={f.key}
              type="button"
              role="tab"
              aria-selected={filter === f.key}
              className="ain-btn ain-seg__btn"
              onClick={() => setFilter(f.key)}
            >
              {f.label}
            </button>
          ))}
        </div>
        <button
          type="button"
          className="ain-btn ain-topbtn ain-topbtn--primary ain-browser__new"
          onClick={() => fileInput.current?.click()}
          disabled={uploading}
          title="Upload a new image to the library"
        >
          {uploading ? <SpinnerIcon className="ain-spin" /> : <UploadIcon />}
          <span>Upload</span>
        </button>
        <button
          type="button"
          className="ain-btn ain-topbtn"
          onClick={() => openBlock("new")}
          title="Create a new global block"
        >
          <PlusIcon />
          <span>New block</span>
        </button>
      </div>

      {error && <p className="ain-studio__error">{error}</p>}

      {items === null ? (
        <p className="ain-browser__empty">
          <SpinnerIcon /> Loading the shelf…
        </p>
      ) : items.length === 0 ? (
        <div className="ain-browser__empty">
          <ImageIcon />
          <p>
            {query.trim()
              ? `Nothing matches “${query.trim()}” — clear the search or add it fresh.`
              : "Nothing here yet — upload an image, ask the chat for one, or create a block, and it lands on this shelf."}
          </p>
        </div>
      ) : (
        <ul className="ain-browser__list" role="listbox" aria-label="Open an item">
          {items.map((item) => (
            <li key={item.token} className="ain-shelf__item">
              <button
                type="button"
                role="option"
                aria-selected={false}
                className="ain-browser__row"
                onClick={() => open(item)}
                title={
                  item.type === "block"
                    ? `Edit “${item.label || "Untitled"}” (opens in a new tab)`
                    : `Open “${item.label || "Untitled"}”`
                }
              >
                <span className="ain-shelf__thumb" data-type={item.type}>
                  {item.thumb ? (
                    <img src={item.thumb} alt="" loading="lazy" />
                  ) : item.type === "block" ? (
                    <DocumentIcon />
                  ) : (
                    <ImageIcon />
                  )}
                </span>
                <span className="ain-browser__cell">
                  <span className="ain-browser__title">{item.label || "Untitled"}</span>
                  <span className="ain-browser__facts">
                    {item.type === "block" ? "block" : "image"}
                    {/* The description often IS the label (an image named by its
                        prompt) — echoing it as the fact line says nothing. */}
                    {item.description && item.description !== item.label
                      ? ` · ${item.description}`
                      : ""}
                  </span>
                </span>
                {item.status === "unpublished" && <span className="ain-ref__status">unpublished</span>}
              </button>
              {/* Hover-revealed quiet row actions (Plate 10) — siblings of the row
                  trigger, never nested buttons. */}
              <span className="ain-shelf__actions">
                <button
                  type="button"
                  className="ain-btn ain-topbtn ain-topbtn--sm"
                  onClick={() => copyToken(item.token)}
                  title={`Copy the reference token (${item.token})`}
                >
                  {copied === item.token ? <CheckIcon /> : null}
                  <span>{copied === item.token ? "Copied" : "Copy token"}</span>
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}

      <input ref={fileInput} type="file" accept="image/*" hidden onChange={onFile} />
    </div>
  );
}
