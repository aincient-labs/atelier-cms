import { useEffect, useState } from "react";
import { getPageDraft, getPageUrl, subscribePageDraft, subscribePreviewReload, subscribePageLoad, type PageMeta, type PageTeaser } from "./page-state";
import { PanelBar } from "./panel-bar";

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

function readPresence(): Presence {
  const draft = getPageDraft();
  const meta = (draft?.meta ?? {}) as PageMeta;
  const teaser = (draft?.teaser ?? {}) as PageTeaser;
  const title = (draft?.title ?? "").trim() || "Untitled page";
  const url = getPageUrl();
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

  // The teaser image is a media:<id> token. These cards render the image at real
  // card size, so resolve it through a display-sized crop (16:9 for the teaser,
  // 2:1 for the social unfurl) rather than the small picker `thumb` — a thumbnail
  // upscaled into these cards looks blurry. Re-runs whenever the token changes; a
  // cleared/unresolvable token shows the placeholder.
  const [teaserImg, setTeaserImg] = useState<string>("");
  useEffect(() => {
    const token = p.teaserImageToken;
    if (!token) {
      setTeaserImg("");
      return;
    }
    let live = true;
    fetch(`/aincient/media/url?token=${encodeURIComponent(token)}&style=960w540h`, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (live) setTeaserImg(typeof d?.url === "string" ? d.url : "");
      })
      .catch(() => {
        if (live) setTeaserImg("");
      });
    return () => {
      live = false;
    };
  }, [p.teaserImageToken]);

  // og_image may be a media:<id> token (picked in the Presence editor, resolved
  // to an absolute URL at render) or a raw URL pasted directly. A token resolves
  // through the 2:1 crop for the share-card mock (matching the ~1.91:1 unfurl); a
  // raw URL is used as-is. Mirrors the teaser resolution above.
  const [ogImg, setOgImg] = useState<string>("");
  useEffect(() => {
    const v = p.ogImage;
    if (!v) {
      setOgImg("");
      return;
    }
    if (!/^(media|entity):/.test(v)) {
      setOgImg(v);
      return;
    }
    let live = true;
    fetch(`/aincient/media/url?token=${encodeURIComponent(v)}&style=960w480h`, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (live) setOgImg(typeof d?.url === "string" ? d.url : "");
      })
      .catch(() => {
        if (live) setOgImg("");
      });
    return () => {
      live = false;
    };
  }, [p.ogImage]);

  const dash = "—";
  const socialDesc = p.ogDescription || p.metaDescription;

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
                <img src={teaserImg} alt="" onError={() => setTeaserImg("")} />
              ) : (
                <span className="ain-teasercard__imgnote">teaser image</span>
              )}
            </div>
            <div className="ain-teasercard__body">
              <p className="ain-teasercard__title">{p.teaserTitle}</p>
              <p className="ain-teasercard__desc">{p.teaserDescription || dash}</p>
              <span className="ain-teasercard__more">Read more &rarr;</span>
            </div>
          </div>
        </section>

        {/* Social share unfurl (Open Graph). */}
        <section className="ain-presence__block">
          <p className="ain-presence__label">
            Social share <span className="ain-presence__tag">Open Graph · LinkedIn / Slack / X</span>
          </p>
          <div className="ain-social">
            <div className="ain-social__img">
              {ogImg ? (
                <img src={ogImg} alt="" onError={() => setOgImg("")} />
              ) : (
                <span className="ain-social__imgnote">share image</span>
              )}
            </div>
            <div className="ain-social__body">
              <span className="ain-social__domain">{p.host}</span>
              <p className="ain-social__title">{p.ogTitle}</p>
              <p className="ain-social__desc">{socialDesc || dash}</p>
            </div>
          </div>
        </section>

        {/* Search result snippet. */}
        <section className="ain-presence__block">
          <p className="ain-presence__label">
            Search result <span className="ain-presence__tag">Google snippet</span>
          </p>
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
            <p className="ain-serp__desc">{p.metaDescription || dash}</p>
          </div>
        </section>
      </div>
    </div>
  );
}
