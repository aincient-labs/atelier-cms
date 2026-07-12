import { useEffect, useState } from "react";
import {
  getPageDraft,
  getPageUrl,
  setPageDraft,
  subscribePageDraft,
  subscribePageNode,
  type PageMeta,
} from "./page-state";
import { META_FIELDS, type MetaFieldDef } from "./meta-fields";
import { FieldRevert } from "./field-revert";
import { ReferenceField } from "./reference-field";

/**
 * The Presence-facet SEO/metadata editor — an always-available rail group over
 * the shared draft's `meta` block. Live-bound exactly like the studio's Title /
 * section controls: every edit writes the whole schema back via setPageDraft, so
 * the previews and Save/Publish see it with no separate write path. A blank value
 * clears the override → the page inherits the site default again.
 *
 * `baseline` (the last-saved `meta`) enables per-field dirty markers + revert,
 * consistent with the Title field; without it the fields still edit, just with no
 * per-field revert. Read-only is handled by the wrapping `<fieldset disabled>`.
 */

/** Normalize so `''` and `undefined` compare equal (a blank IS "no override"). */
const norm = (v: string | undefined): string => (v ?? "").trim();

function metaOf(): PageMeta {
  return (getPageDraft()?.meta ?? {}) as PageMeta;
}

/**
 * Write one meta key into the shared draft (delete on blank → inherit default).
 * Stores the RAW value — trim only decides key presence, never mutates what's
 * stored — so the controlled input keeps interior/trailing spaces the user is
 * mid-typing (trimming on keystroke would strip a trailing space on every
 * change, blocking multi-word entry). The server's clampMeta trims on save.
 */
function commitMeta(key: keyof PageMeta, value: string): void {
  const draft = getPageDraft();
  if (!draft) return;
  const meta: PageMeta = { ...(draft.meta ?? {}) };
  if (value.trim()) meta[key] = value;
  else delete meta[key];
  setPageDraft({ ...draft, meta });
}

export function SeoMetaGroup({ baseline }: { baseline?: PageMeta }) {
  // Mirror the draft's meta so agent writes (which flow through setPageDraft) and
  // manual edits both re-render the fields from one source of truth.
  const [meta, setMeta] = useState<PageMeta>(metaOf);
  // Track the page's live URL so the Canonical field can show the concrete alias
  // its blank-inherits-`[node:url]` default resolves to. `subscribePageNode`
  // fires on load / New / first Publish — the moments the URL (dis)appears.
  const [pageUrl, setUrl] = useState<string | null>(getPageUrl);
  useEffect(() => {
    const unMeta = subscribePageDraft(() => setMeta(metaOf()));
    const unNode = subscribePageNode(() => setUrl(getPageUrl()));
    return () => {
      unMeta();
      unNode();
    };
  }, []);

  return (
    <section className="ain-studio__group">
      <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">SEO &amp; metadata</h3>
      <p className="ain-studio__groupnote">
        How this page appears in search results and when its link is shared. Leave a field blank to inherit the site default.
      </p>
      {META_FIELDS.map((f) =>
        f.image ? (
          <ImageMetaField key={f.key} def={f} value={meta[f.key] ?? ""} baseline={baseline?.[f.key]} />
        ) : (
          <MetaField
            key={f.key}
            def={f}
            value={meta[f.key] ?? ""}
            baseline={baseline?.[f.key]}
            // Concretize the Canonical default once the page has a URL: blank
            // canonical inherits `[node:url]`, i.e. this exact alias.
            placeholder={f.key === "canonical_url" && pageUrl ? `Defaults to ${pageUrl}` : undefined}
          />
        ),
      )}
    </section>
  );
}

/**
 * An image meta field (og_image) — the shared media picker (search / upload /
 * paste-a-token), identical to the teaser image. Stores a `media:<id>` token
 * (resolved to an absolute URL at render) or a pasted raw URL.
 */
function ImageMetaField({
  def,
  value,
  baseline,
}: {
  def: MetaFieldDef;
  value: string;
  baseline?: string;
}) {
  const changed = baseline !== undefined && norm(value) !== norm(baseline);
  return (
    <ReferenceField
      label={def.label}
      value={value}
      onChange={(v) => commitMeta(def.key, typeof v === "string" ? v : "")}
      types={["media"]}
      allowUpload
      dirty={changed}
      revert={
        changed ? (
          <FieldRevert label={def.label.toLowerCase()} onRevert={() => commitMeta(def.key, baseline ?? "")} />
        ) : undefined
      }
    />
  );
}

function MetaField({
  def,
  value,
  baseline,
  placeholder,
}: {
  def: MetaFieldDef;
  value: string;
  baseline?: string;
  /** Overrides {@link MetaFieldDef.placeholder} when set (e.g. the Canonical
   *  field showing the page's resolved alias instead of the static hint). */
  placeholder?: string;
}) {
  const changed = baseline !== undefined && norm(value) !== norm(baseline);
  const len = value.trim().length;
  const [min, max] = def.counter ?? [0, 0];
  const inRange = len >= min && len <= max;
  const hint = placeholder ?? def.placeholder;

  return (
    <label className="ain-field" data-dirty={changed || undefined}>
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{def.label}</span>
        {changed && (
          <FieldRevert label={def.label.toLowerCase()} onRevert={() => commitMeta(def.key, baseline ?? "")} />
        )}
      </span>
      {def.multiline ? (
        <textarea
          className="ain-field__input"
          value={value}
          rows={3}
          placeholder={hint}
          onChange={(e) => commitMeta(def.key, e.target.value)}
        />
      ) : (
        <input
          className="ain-field__input"
          type="text"
          value={value}
          placeholder={hint}
          spellCheck={false}
          onChange={(e) => commitMeta(def.key, e.target.value)}
        />
      )}
      {def.counter && (
        <span className="ain-field__count" data-ok={inRange || undefined}>
          {len} / {min}–{max} characters
        </span>
      )}
    </label>
  );
}
