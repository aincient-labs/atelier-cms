import { useEffect, useState } from "react";
import {
  getPageDraft,
  setPageDraft,
  subscribePageDraft,
  type PageBlog,
  type PageSchema,
} from "./page-state";
import { FieldRevert } from "./field-revert";
import { ReferenceField } from "./reference-field";

/**
 * The body-facet BLOG editor — the rail group shown instead of the Sections
 * stack when the page is a blog post (a LOCKED recipe: fixed article layout, the
 * flat body fields ARE the content). Live-bound exactly like {@link TeaserGroup}
 * and the Title field: every edit writes the whole schema back via setPageDraft,
 * so the centre preview (which renders the post through the branded article
 * layout) and Save/Publish see it with no separate write path.
 *
 * The body is authored as MARKDOWN (`body_md`) — compiled to sanitised HTML and
 * rendered through the `prose` component, so the author writes content, never
 * HTML. The cover is a `media:<id>` token, edited through the shared media picker
 * (search / upload / paste-a-token) — identical to a section or teaser image.
 *
 * `baseline` (the last-saved schema) enables per-field dirty markers + revert;
 * read-only is handled by the wrapping `<fieldset disabled>`.
 */

/** Normalize so `''` and `undefined` compare equal (a blank IS "no value"). */
const norm = (v: unknown): string => (typeof v === "string" ? v : "").trim();

/** The blog body fields live FLAT on the schema, so read them straight off it. */
function blogOf(): PageBlog {
  return (getPageDraft() ?? {}) as PageBlog;
}

/**
 * Write one blog field into the shared draft (delete on blank). Stores the RAW
 * value — trim only decides key presence, never mutates what's stored — so the
 * controlled input keeps interior/trailing spaces (and body_md keeps its
 * newlines) while the user is mid-typing. The server's validate() normalises on
 * save; body_md is stored verbatim (it is Markdown source).
 */
function commitBlog(key: keyof PageBlog, value: string): void {
  const draft = getPageDraft();
  if (!draft) return;
  const next: PageSchema = { ...draft };
  if (value.trim()) next[key] = value;
  else delete next[key];
  setPageDraft(next);
}

export function BlogGroup({ baseline }: { baseline?: PageSchema }) {
  // Mirror the draft's blog fields so agent writes (set_content, via setPageDraft)
  // and manual edits both re-render from one source of truth.
  const [blog, setBlog] = useState<PageBlog>(blogOf);
  useEffect(() => subscribePageDraft(() => setBlog(blogOf())), []);

  // Blog fields live flat on the schema (index signature → unknown); norm() coerces.
  const dirty = (key: keyof PageBlog): boolean =>
    baseline?.[key] !== undefined && norm(blog[key]) !== norm(baseline[key]);
  const revert = (key: keyof PageBlog, label: string) =>
    dirty(key) ? (
      <FieldRevert label={label} onRevert={() => commitBlog(key, (baseline?.[key] as string) ?? "")} />
    ) : undefined;

  return (
    <section className="ain-studio__group">
      <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">Blog post</h3>
      <p className="ain-studio__groupnote">
        A blog post uses a fixed article layout — header, body, and byline. Write the body in Markdown;
        the reading-optimised styling is applied for you.
      </p>

      {/* Category / eyebrow. */}
      <label className="ain-field" data-dirty={dirty("category") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Category</span>
          {revert("category", "category")}
        </span>
        <input
          className="ain-field__input"
          type="text"
          value={blog.category ?? ""}
          placeholder="e.g. Engineering"
          spellCheck={false}
          onChange={(e) => commitBlog("category", e.target.value)}
        />
      </label>

      {/* Lead / standfirst. */}
      <label className="ain-field" data-dirty={dirty("lead") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Lead</span>
          {revert("lead", "lead")}
        </span>
        <textarea
          className="ain-field__input"
          value={blog.lead ?? ""}
          rows={2}
          placeholder="A one- or two-sentence summary under the title"
          onChange={(e) => commitBlog("lead", e.target.value)}
        />
      </label>

      {/* Cover image — the shared media picker (search / upload / paste token). */}
      <ReferenceField
        label="Cover image"
        value={blog.cover ?? ""}
        onChange={(v) => commitBlog("cover", typeof v === "string" ? v : "")}
        types={["media"]}
        allowUpload
        dirty={dirty("cover")}
        revert={revert("cover", "cover image")}
      />

      {/* The body — Markdown source. */}
      <label className="ain-field" data-dirty={dirty("body_md") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Body (Markdown)</span>
          {revert("body_md", "body")}
        </span>
        <textarea
          className="ain-field__input ain-field__input--mono"
          value={blog.body_md ?? ""}
          rows={18}
          placeholder={"## A heading\n\nWrite your post in **Markdown** — headings, lists, > quotes, `code`, and [links](https://example.com)."}
          spellCheck
          onChange={(e) => commitBlog("body_md", e.target.value)}
        />
      </label>

      {/* Author + byline. */}
      <label className="ain-field" data-dirty={dirty("author") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Author</span>
          {revert("author", "author")}
        </span>
        <input
          className="ain-field__input"
          type="text"
          value={blog.author ?? ""}
          placeholder="The post's author"
          spellCheck={false}
          onChange={(e) => commitBlog("author", e.target.value)}
        />
      </label>

      <label className="ain-field" data-dirty={dirty("author_bio") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Author bio</span>
          {revert("author_bio", "author bio")}
        </span>
        <textarea
          className="ain-field__input"
          value={blog.author_bio ?? ""}
          rows={2}
          placeholder="A short bio shown in the byline card"
          onChange={(e) => commitBlog("author_bio", e.target.value)}
        />
      </label>

      {/* Publish date — a freeform display string (e.g. "May 31, 2026"). */}
      <label className="ain-field" data-dirty={dirty("date") || undefined}>
        <span className="ain-field__label">
          <span className="ain-field__labeltext">Date</span>
          {revert("date", "date")}
        </span>
        <input
          className="ain-field__input"
          type="text"
          value={blog.date ?? ""}
          placeholder="e.g. May 31, 2026"
          spellCheck={false}
          onChange={(e) => commitBlog("date", e.target.value)}
        />
      </label>
    </section>
  );
}
