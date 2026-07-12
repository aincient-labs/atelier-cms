import { useCallback, useEffect, useRef, useState } from "react";
import type { ReactNode } from "react";
import { CheckIcon, DocumentIcon, ImageIcon, PlusIcon, SpinnerIcon, UploadIcon, XIcon } from "./icons";

/**
 * The studio's ONE reference control — media, embeddable nodes, global blocks.
 *
 * Every page-schema reference is a plain text TOKEN (`media:<id>`,
 * `entity:node:<id>[@vm]`, `block:<id>`) resolved at render. This field unifies
 * the three former pickers into the three decoupled seams the reference layer is
 * built on:
 *
 *   1. a PICKER that searches the unified catalog (GET /aincient/reference/search)
 *      and writes a token via onChange;
 *   2. MANUAL ENTRY — a text input bound to the same value, so typing/pasting a
 *      token behaves identically to picking one;
 *   3. a PREVIEW derived on the fly from the token (GET /aincient/reference/resolve)
 *      — a uniform descriptor card (thumb / label / description / status + edit
 *      link), never stored, always live.
 *
 * Per-type behaviour is layered through props: `allowUpload` (media),
 * `viewModes` (embed → composes a `@vm` suffix), and `onEdit`/`onCreate` (blocks,
 * which edit in-studio rather than at a URL).
 */

const SEARCH_URL = "/aincient/reference/search";
const RESOLVE_URL = "/aincient/reference/resolve";
const UPLOAD_URL = "/aincient/media/upload";

/** The uniform shape every reference resolves to (mirrors ReferenceDescriptor.php). */
export type ReferenceDescriptor = {
  token: string;
  type: string;
  label: string;
  description: string;
  thumb: string | null;
  status: "published" | "unpublished" | null;
  edit_url: string | null;
  meta: Record<string, unknown>;
};

export type ViewMode = { id: string; label: string };

/** Split a token into its base (`entity:node:<id>`/`media:<id>`) + `@<vm>`. */
function parseToken(token: string): { base: string; viewMode: string } | null {
  const m = /^(entity:[a-z][a-z0-9_]*:\d+|media:\d+)(?:@([a-z0-9_]+))?$/.exec(token.trim());
  return m ? { base: m[1], viewMode: m[2] ?? "" } : null;
}

/** Compose a base token + view mode into the stored token string. */
const compose = (base: string, viewMode: string): string => (viewMode ? `${base}@${viewMode}` : base);

export function ReferenceField({
  label,
  meaning,
  value,
  onChange,
  disabled = false,
  types,
  allowUpload = false,
  viewModes,
  onEdit,
  onCreate,
  createLabel = "New",
  dirty = false,
  revert,
}: {
  label: string;
  meaning?: string;
  value: unknown;
  onChange: (value: unknown) => void;
  disabled?: boolean;
  /** The reference types this prop accepts, e.g. ["media"] or ["node"]. */
  types: string[];
  /** Media only: offer an upload button in the picker. */
  allowUpload?: boolean;
  /** Embed only: view-mode options; selecting one composes a `@vm` suffix. */
  viewModes?: ViewMode[];
  /** In-studio edit (blocks have no edit URL); receives the current token. */
  onEdit?: (token: string) => void;
  /** Create action (e.g. "New block"); shown in the picker bar. */
  onCreate?: () => void;
  createLabel?: string;
  /** This field differs from the saved baseline (page studio per-field dirty). */
  dirty?: boolean;
  /** The pre-built per-field revert marker, shown beside the label when dirty. */
  revert?: ReactNode;
}) {
  const current = typeof value === "string" ? value.trim() : "";
  const parsed = parseToken(current);
  const selectedToken = parsed ? parsed.base : current;
  const [open, setOpen] = useState(false);
  // The live preview descriptor for the current token (NULL = empty/unresolved).
  const [preview, setPreview] = useState<ReferenceDescriptor | null>(null);
  // A valid token collapses to a copyable chip (Law 13); "Edit token" swaps the
  // raw <input> back for manual correction / paste.
  const [editingToken, setEditingToken] = useState(false);
  const [copied, setCopied] = useState(false);

  const copyToken = useCallback((token: string) => {
    navigator.clipboard
      ?.writeText(token)
      .then(() => {
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1200);
      })
      .catch(() => {});
  }, []);

  // Resolve the current token → descriptor (the in-field preview), debounced so
  // manual typing doesn't hammer the endpoint.
  useEffect(() => {
    if (current === "") {
      setPreview(null);
      return;
    }
    let live = true;
    const t = window.setTimeout(() => {
      fetch(`${RESOLVE_URL}?token=${encodeURIComponent(current)}`, { credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((data) => live && setPreview((data?.item as ReferenceDescriptor | null) ?? null))
        .catch(() => live && setPreview(null));
    }, 200);
    return () => {
      live = false;
      window.clearTimeout(t);
    };
  }, [current]);

  // A picked / uploaded token: compose the current view mode if the field has one.
  const pickToken = useCallback(
    (token: string) => {
      onChange(viewModes ? compose(token, parsed?.viewMode ?? "") : token);
      setOpen(false);
    },
    [onChange, viewModes, parsed],
  );

  const setViewMode = useCallback(
    (vm: string) => {
      if (parsed) onChange(compose(parsed.base, vm));
    },
    [onChange, parsed],
  );

  const clear = useCallback(() => onChange(""), [onChange]);

  const editUrl = preview?.edit_url ?? null;
  const Icon = types.length === 1 && types[0] === "media" ? ImageIcon : DocumentIcon;

  return (
    <div className="ain-field ain-media" data-dirty={dirty || undefined} title={meaning}>
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{label}</span>
        {revert}
      </span>
      <div className="ain-media__control">
        <button
          type="button"
          className="ain-btn ain-media__thumb"
          onClick={() => !disabled && setOpen((o) => !o)}
          disabled={disabled}
          aria-expanded={open}
          title={disabled ? "Inherited from the source layout" : current ? "Change…" : "Choose…"}
        >
          {preview?.thumb ? <img src={preview.thumb} alt="" /> : <Icon className="ain-media__thumbicon" />}
        </button>
        <div className="ain-media__meta">
          <span className="ain-media__name">
            {preview ? preview.label : current ? "Reference not found" : "Nothing selected"}
            {preview?.status === "unpublished" && <span className="ain-ref__status">unpublished</span>}
          </span>
          {preview?.description && <span className="ain-ref__desc">{preview.description}</span>}
          <div className="ain-media__btns">
            {parsed && viewModes && (
              <select
                className="ain-field__input ain-embed__vm"
                value={parsed.viewMode}
                onChange={(e) => setViewMode(e.target.value)}
                disabled={disabled}
                title="View mode"
              >
                {viewModes.map((vm) => (
                  <option key={vm.id} value={vm.id}>
                    {vm.label}
                  </option>
                ))}
              </select>
            )}
            {editUrl ? (
              <a className="ain-btn ain-topbtn ain-topbtn--sm" href={editUrl} target="_blank" rel="noreferrer" title="Edit this resource">
                Edit ↗
              </a>
            ) : (
              onEdit &&
              current !== "" && (
                <button
                  type="button"
                  className="ain-btn ain-topbtn ain-topbtn--sm"
                  onClick={() => onEdit(current)}
                  disabled={disabled}
                  title="Edit this block's content (opens it in a new tab)"
                >
                  Edit ↗
                </button>
              )
            )}
            <button
              type="button"
              className="ain-btn ain-topbtn ain-topbtn--sm"
              onClick={() => !disabled && setOpen((o) => !o)}
              disabled={disabled}
            >
              {current ? "Change…" : "Choose…"}
            </button>
            {current !== "" && (
              <button
                type="button"
                className="ain-btn ain-iconbtn"
                onClick={clear}
                disabled={disabled}
                aria-label="Remove reference"
                title="Remove"
              >
                <XIcon />
              </button>
            )}
          </div>
        </div>
      </div>
      {/* Manual entry — typing/pasting a token behaves exactly like picking one.
          Once the token is valid it collapses into a copyable mono chip (Law 13:
          machine text wears the chip, never bare input text); "Edit token" swaps
          the raw input back. An empty/invalid value keeps the input as-is so
          paste keeps behaving like picking. */}
      {parsed && !editingToken ? (
        <div className="ain-ref__tokenrow">
          <span className="ain-ref__chip" title={current}>
            {current}
          </span>
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--sm ain-ref__copy"
            onClick={() => copyToken(current)}
            title={`Copy the reference token (${current})`}
          >
            {copied ? <CheckIcon /> : null}
            <span>{copied ? "Copied" : "Copy token"}</span>
          </button>
          {!disabled && (
            <button
              type="button"
              className="ain-btn ain-topbtn ain-topbtn--sm ain-ref__edittoken"
              onClick={() => setEditingToken(true)}
              title="Edit the raw token"
            >
              Edit token
            </button>
          )}
        </div>
      ) : (
        <input
          className="ain-field__input ain-ref__token"
          value={current}
          onChange={(e) => onChange(e.target.value)}
          onBlur={() => setEditingToken(false)}
          disabled={disabled}
          spellCheck={false}
          placeholder="or paste a token — media:42 · entity:node:15 · block:7"
        />
      )}
      {open && !disabled && (
        <ReferencePicker
          types={types}
          selected={selectedToken}
          allowUpload={allowUpload}
          onCreate={onCreate}
          createLabel={createLabel}
          onPick={pickToken}
        />
      )}
    </div>
  );
}

/* --------------------------------------------------------------- picker panel */

function ReferencePicker({
  types,
  selected,
  allowUpload,
  onCreate,
  createLabel,
  onPick,
}: {
  types: string[];
  selected: string | null;
  allowUpload: boolean;
  onCreate?: () => void;
  createLabel: string;
  onPick: (token: string) => void;
}) {
  const [items, setItems] = useState<ReferenceDescriptor[] | null>(null);
  const [query, setQuery] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const debounce = useRef<number | undefined>(undefined);
  // A pure media field shows the thumbnail grid; anything else, a titled list.
  const grid = types.length === 1 && types[0] === "media";

  useEffect(() => {
    window.clearTimeout(debounce.current);
    debounce.current = window.setTimeout(() => {
      const params = new URLSearchParams({ types: types.join(",") });
      if (query.trim()) params.set("q", query.trim());
      fetch(`${SEARCH_URL}?${params}`, { credentials: "same-origin" })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((data) => setItems(Array.isArray(data?.items) ? data.items : []))
        .catch((e) => setError(String(e)));
    }, 180);
    return () => window.clearTimeout(debounce.current);
  }, [query, types]);

  const upload = useCallback(
    async (file: File) => {
      setUploading(true);
      setError(null);
      try {
        const body = new FormData();
        body.append("file", file);
        const res = await fetch(UPLOAD_URL, { method: "POST", credentials: "same-origin", body });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.item?.token) throw new Error(data?.error ?? `HTTP ${res.status}`);
        onPick(data.item.token as string);
      } catch (e) {
        setError(`Upload failed: ${e instanceof Error ? e.message : e}`);
      } finally {
        setUploading(false);
      }
    },
    [onPick],
  );

  const onFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) void upload(file);
    e.target.value = "";
  };

  return (
    <div className="ain-mediapick">
      <div className="ain-mediapick__bar">
        <input
          className="ain-field__input"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search…"
          spellCheck={false}
        />
        {allowUpload && (
          <button
            type="button"
            className="ain-btn ain-topbtn"
            onClick={() => fileInput.current?.click()}
            disabled={uploading}
            title="Upload a new image"
          >
            {uploading ? <SpinnerIcon className="ain-spin" /> : <UploadIcon />}
            <span>Upload</span>
          </button>
        )}
        {onCreate && (
          <button type="button" className="ain-btn ain-topbtn" onClick={onCreate} title={createLabel}>
            <PlusIcon />
            <span>{createLabel}</span>
          </button>
        )}
        {allowUpload && <input ref={fileInput} type="file" accept="image/*" hidden onChange={onFile} />}
      </div>

      {error && <p className="ain-mediapick__error">{error}</p>}

      {items === null ? (
        <p className="ain-mediapick__empty">Loading…</p>
      ) : items.length === 0 ? (
        <p className="ain-mediapick__empty">{query.trim() ? "Nothing matches." : "Nothing here yet."}</p>
      ) : grid ? (
        <div className="ain-mediapick__grid" role="listbox" aria-label="Reference library">
          {items.map((item) => (
            <button
              key={item.token}
              type="button"
              role="option"
              aria-selected={item.token === selected}
              className="ain-btn ain-mediapick__cell"
              data-current={item.token === selected || undefined}
              onClick={() => onPick(item.token)}
              title={item.label}
            >
              {item.thumb ? <img src={item.thumb} alt="" loading="lazy" /> : <ImageIcon />}
              {item.token === selected && (
                <span className="ain-mediapick__check">
                  <CheckIcon />
                </span>
              )}
            </button>
          ))}
        </div>
      ) : (
        <div className="ain-pagepick" role="listbox" aria-label="References">
          {items.map((item) => (
            <button
              key={item.token}
              type="button"
              role="option"
              aria-selected={item.token === selected}
              className="ain-btn ain-pagepick__item"
              data-current={item.token === selected || undefined}
              onClick={() => onPick(item.token)}
              title={item.label}
            >
              <span className="ain-pagepick__title">
                {item.label || "Untitled"}
                {item.description && <span className="ain-ref__desc"> — {item.description}</span>}
                {item.status === "unpublished" && <span className="ain-ref__status">unpublished</span>}
              </span>
              {item.token === selected && <CheckIcon />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
