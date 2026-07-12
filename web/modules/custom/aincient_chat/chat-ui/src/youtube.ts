/**
 * The YouTube video id in a watch/short/embed/share URL, or null.
 *
 * The tour video accepts whatever URL a site owner pastes into settings; the
 * id feeds an iframe src, so anything not strictly id-shaped is rejected.
 * Pure (no DOM), so it stays unit-testable outside the widget tree.
 */
export function youtubeId(url: string): string | null {
  try {
    const u = new URL(url);
    const host = u.hostname.replace(/^www\.|^m\./, "");
    let id = "";
    if (host === "youtu.be") {
      id = u.pathname.split("/").filter(Boolean)[0] ?? "";
    } else if (host === "youtube.com" || host === "youtube-nocookie.com") {
      if (u.pathname === "/watch") id = u.searchParams.get("v") ?? "";
      else {
        const m = u.pathname.match(/^\/(?:embed|shorts|live)\/([^/]+)/);
        id = m ? m[1] : "";
      }
    }
    return /^[A-Za-z0-9_-]{6,}$/.test(id) ? id : null;
  } catch {
    return null;
  }
}
