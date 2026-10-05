import { useEffect, useState } from "react";
import {
  getPageDraft,
  getPageUrl,
  subscribePageDraft,
  subscribePreviewReload,
  subscribePageLoad,
  type PageMeta,
  type PageSchema,
  type PageTeaser,
} from "./page-state";
import { PanelBar } from "./kit/panel-bar";
import { apiUrl } from "./console-config";

/**
 * The Presence facet's centre canvas: how the page shows up everywhere it's
 * referenced, rendered as the cards people actually see — the in-site teaser
 * card, a social-share unfurl and a search-result snippet — off the live draft
 * (title + `teaser` + `meta`). This is the "metadata is visual" surface; it
 * updates as the user (or the agent) edits the Presence rail. Pure presentation:
 * the only network call resolves the teaser image token → a thumbnail URL.
 */

type Presence = {
  title: string;
  metaDescription: string;
  ogTitle: string;
  ogDescription: string;
  ogImage: string;
  teaserTitle: string;
  teaserDescription: string;
  teaserImageToken: string;
  host: string;
  path: string;
  url: string | null;
};

const PLACEHOLDER_HOST = "your-site.example";

function slug(title: string): string {
  return (
    title
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/(^-|-$)/g, "")
      .slice(0, 40) || "page"
  );
}

/** The presence a schema projects — fallbacks applied as the page renders them
 *  (og_title → title, og_description → description). Pure. */
export function presenceOf(draft: PageSchema | null, url: string | null): Presence {
  const meta = (draft?.meta ?? {}) as PageMeta;
  const teaser = (draft?.teaser ?? {}) as PageTeaser;
  const title = (draft?.title ?? "").trim() || "Untitled page";
  let host = PLACEHOLDER_HOST;
  let path = "/" + slug(title);
  if (url) {
    try {
      const u = new URL(url);
      host = u.host;
      path = u.pathname;
    } catch {
      // Non-absolute stored URL: treat it as a path on the placeholder host.
      path = url.startsWith("/") ? url : "/" + url;
    }
  }
  return {
    title,
    metaDescription: (meta.description ?? "").trim(),
    ogTitle: (meta.og_title ?? "").trim() || title,
    ogDescription: (meta.og_description ?? "").trim() || (meta.description ?? "").trim(),
    ogImage: (meta.og_image ?? "").trim(),
    teaserTitle: (teaser.title ?? "").trim() || title,
    teaserDescription: (teaser.description ?? "").trim(),
    teaserImageToken: (teaser.image ?? "").trim(),
    host,
    path,
    url,
  };
}

const readPresence = (): Presence => presenceOf(getPageDraft(), getPageUrl());

/**
 * An image value as a display URL: a `media:<id>` / `entity:` token resolves
 * through a display-sized crop (these cards render at real card size, so the
 * small picker `thumb` would look blurry); a raw URL (og_image may be one) is
 * used as-is. "" while resolving, for an empty value, or when it can't resolve.
 */
function useImageUrl(value: string, style: string): [string, () => void] {
  const [url, setUrl] = useState("");
  useEffect(() => {
    if (!value) {
      setUrl("");
      return;
    }
    if (!/^(media|entity):/.test(value)) {
      setUrl(value);
      return;
    }
    let live = true;
    fetch(apiUrl(`/media/url?token=${encodeURIComponent(value)}&style=${style}`), { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (live) setUrl(typeof d?.url === "string" ? d.url : "");
      })
      .catch(() => {
        if (live) setUrl("");
      });
    return () => {
      live = false;
    };
  }, [value, style]);
  return [url, () => setUrl("")];
}

const DASH = "—";

/**
 * A page as a search-result snippet (title, URL, meta description). Exported
 * through the sdk so a studio's preview lens (Checks, DECISIONS 0453) draws the
 * SAME card the Presence canvas does.
 */
export function SearchResultCard({ schema, url }: { schema: PageSchema | null; url: string | null }) {
  const p = presenceOf(schema, url);
  return (
    <div className="ain-serp">
      <div className="ain-serp__site">
        <span className="ain-serp__fav" aria-hidden="true" />
        <span className="ain-serp__id">
          <b>{p.host}</b>
          <span className="ain-serp__url">
            {p.host}
            {p.path}
          </span>
        </span>
      </div>
      <p className="ain-serp__title">{p.title}</p>
      <p className="ain-serp__desc">{p.metaDescription || DASH}</p>
    </div>
  );
}

/** A page as a social-share unfurl (Open Graph), the share image resolved to
 *  the 2:1 crop (~1.91:1 unfurl). Exported through the sdk like {@link SearchResultCard}. */
export function ShareCard({ schema, url }: { schema: PageSchema | null; url: string | null }) {
  const p = presenceOf(schema, url);
  const [img, clearImg] = useImageUrl(p.ogImage, "960w480h");
  return (
    <div className="ain-social">
      <div className="ain-social__img">
        {img ? <img src={img} alt="" onError={clearImg} /> : <span className="ain-social__imgnote">share image</span>}
      </div>
      <div className="ain-social__body">
        <span className="ain-social__domain">{p.host}</span>
        <p className="ain-social__title">{p.ogTitle}</p>
        <p className="ain-social__desc">{p.ogDescription || DASH}</p>
      </div>
    </div>
  );
}

export function PresencePreview() {
  const [p, setP] = useState<Presence>(readPresence);
  useEffect(() => {
    const refresh = () => setP(readPresence());
    refresh();
    const unsubDraft = subscribePageDraft(refresh);
    const unsubReload = subscribePreviewReload(refresh);
    const unsubLoad = subscribePageLoad(refresh);
    return () => {
      unsubDraft();
      unsubReload();
      unsubLoad();
    };
  }, []);

  // The teaser card renders at 16:9 card size (see useImageUrl).
  const [teaserImg, clearTeaserImg] = useImageUrl(p.teaserImageToken, "960w540h");
  const schema = getPageDraft();

  return (
    <div className="ain-preview">
      <PanelBar
        title="Presence · how this page appears elsewhere"
        actions={
          p.url ? (
            <a className="ain-preview__open" href={p.url} target="_blank" rel="noreferrer">
              Open ↗
            </a>
          ) : undefined
        }
      />
      <div className="ain-presence">
        {/* In-site teaser card (how the page shows up when referenced). */}
        <section className="ain-presence__block">
          <p className="ain-presence__label">
            Teaser card <span className="ain-presence__tag">In-site listings &amp; front page</span>
          </p>
          <div className="ain-teasercard">
            <div className="ain-teasercard__img">
              {teaserImg ? (
                <img src={teaserImg} alt="" onError={clearTeaserImg} />
              ) : (
                <span className="ain-teasercard__imgnote">teaser image</span>
              )}
            </div>
            <div className="ain-teasercard__body">
              <p className="ain-teasercard__title">{p.teaserTitle}</p>
              <p className="ain-teasercard__desc">{p.teaserDescription || DASH}</p>
              <span className="ain-teasercard__more">Read more &rarr;</span>
            </div>
          </div>
        </section>

        {/* Social share unfurl (Open Graph). */}
        <section className="ain-presence__block">
          <p className="ain-presence__label">
            Social share <span className="ain-presence__tag">Open Graph · LinkedIn / Slack / X</span>
          </p>
          <ShareCard schema={schema} url={p.url} />
        </section>

        {/* Search result snippet. */}
        <section className="ain-presence__block">
          <p className="ain-presence__label">
            Search result <span className="ain-presence__tag">Google snippet</span>
          </p>
          <SearchResultCard schema={schema} url={p.url} />
        </section>
      </div>
    </div>
  );
}
