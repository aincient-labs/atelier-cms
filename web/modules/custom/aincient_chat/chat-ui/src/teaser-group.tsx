import { useEffect, useState } from "react";
import { getPageDraft, setPageDraft, subscribePageDraft, type PageTeaser } from "./page-state";
import { FieldRevert } from "./field-revert";
import { ReferenceField } from "./reference-field";

/**
 * The Presence-facet teaser editor — an always-available rail group over the
 * shared draft's `teaser` block (the page's PRESENCE as a referenced card:
 * title, description, image). Live-bound exactly like {@link SeoMetaGroup} and
 * the Title field: every edit writes the whole schema back via setPageDraft, so
 * the teaser-card preview and Save/Publish see it with no separate write path.
 * The image is a `media:<id>` token, edited through the shared media picker
 * (search / upload / paste-a-token) — identical to how section images are set.
 *
 * `baseline` (the last-saved `teaser`) enables per-field dirty markers + revert;
 * read-only is handled by the wrapping `<fieldset disabled>`.
 */

/** Normalize so `''` and `undefined` compare equal (a blank IS "no value"). */
const norm = (v: string | undefined): string => (v ?? "").trim();

function teaserOf(): PageTeaser {
  return (getPageDraft()?.teaser ?? {}) as PageTeaser;
}

/**
 * Write one teaser key into the shared draft (delete on blank). Stores the RAW
 * value — trim only decides key presence, never mutates what's stored — so the
 * controlled input keeps interior/trailing spaces the user is mid-typing
 * (trimming on keystroke would strip a trailing space on every change, blocking
 * multi-word entry). The server's clampTeaser trims on save.
 */
function commitTeaser(key: keyof PageTeaser, value: string): void {
  const draft = getPageDraft();
  if (!draft) return;
  const teaser: PageTeaser = { ...(draft.teaser ?? {}) };
  if (value.trim()) teaser[key] = value;
  else delete teaser[key];
  setPageDraft({ ...draft, teaser });
}

export function TeaserGroup({ baseline }: { baseline?: PageTeaser }) {
  // Mirror the draft's teaser so agent writes (set_teaser, via setPageDraft) and
  // manual edits both re-render the fields from one source of truth.
  const [teaser, setTeaser] = useState<PageTeaser>(teaserOf);
  useEffect(() => subscribePageDraft(() => setTeaser(teaserOf())), []);

  const titleDirty = baseline?.title !== undefined && norm(teaser.title) !== norm(baseline.title);
  const descDirty = baseline?.description !== undefined && norm(teaser.description) !== norm(baseline.description);
  const imgDirty = baseline?.image !== undefined && norm(teaser.image) !== norm(baseline.image);

  return (
    <section className="ain-studio__group">
      <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">Teaser card</h3>
      <p className="ain-studio__groupnote">
        How this page appears when it&rsquo;s referenced elsewhere as a card — in listings and on the front
        page. Leave the title blank to fall back to the page title.
      </p>

      {/* Teaser title. */}
      <label className="ain-field" data-dirty={titleDirty || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Teaser title</span>
          {titleDirty && (
            <FieldRevert label="teaser title" onRevert={() => commitTeaser("title", baseline?.title ?? "")} />
          )}
        </span>
        <input
          className="ain-field__input"
          type="text"
          value={teaser.title ?? ""}
          placeholder="Falls back to the page title when blank"
          spellCheck={false}
          onChange={(e) => commitTeaser("title", e.target.value)}
        />
      </label>

      {/* Teaser description. */}
      <label className="ain-field" data-dirty={descDirty || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Teaser description</span>
          {descDirty && (
            <FieldRevert
              label="teaser description"
              onRevert={() => commitTeaser("description", baseline?.description ?? "")}
            />
          )}
        </span>
        <textarea
          className="ain-field__input"
          value={teaser.description ?? ""}
          rows={3}
          placeholder="A short summary shown on the card"
          onChange={(e) => commitTeaser("description", e.target.value)}
        />
      </label>

      {/* Teaser image — the shared media picker (search / upload / paste token). */}
      <ReferenceField
        label="Teaser image"
        value={teaser.image ?? ""}
        onChange={(v) => commitTeaser("image", typeof v === "string" ? v : "")}
        types={["media"]}
        allowUpload
        dirty={imgDirty}
        revert={
          imgDirty ? (
            <FieldRevert label="teaser image" onRevert={() => commitTeaser("image", baseline?.image ?? "")} />
          ) : undefined
        }
      />
    </section>
  );
}
