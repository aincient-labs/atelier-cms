import { describe, expect, it } from "vitest";
import { youtubeId } from "./youtube";

/**
 * The tour video accepts whatever YouTube URL a site owner pastes into
 * settings — watch, share, embed, shorts — and must never build an embed from
 * a non-YouTube URL (the id feeds an iframe src).
 */
describe("youtubeId", () => {
  it("parses the common YouTube URL shapes", () => {
    expect(youtubeId("https://www.youtube.com/watch?v=dQw4w9WgXcQ")).toBe("dQw4w9WgXcQ");
    expect(youtubeId("https://youtu.be/dQw4w9WgXcQ?t=10")).toBe("dQw4w9WgXcQ");
    expect(youtubeId("https://www.youtube.com/embed/dQw4w9WgXcQ")).toBe("dQw4w9WgXcQ");
    expect(youtubeId("https://youtube.com/shorts/dQw4w9WgXcQ")).toBe("dQw4w9WgXcQ");
    expect(youtubeId("https://m.youtube.com/watch?v=dQw4w9WgXcQ")).toBe("dQw4w9WgXcQ");
  });

  it("rejects everything that is not a YouTube video URL", () => {
    expect(youtubeId("https://example.com/watch?v=dQw4w9WgXcQ")).toBeNull();
    expect(youtubeId("https://www.youtube.com/")).toBeNull();
    expect(youtubeId("https://www.youtube.com/watch")).toBeNull();
    expect(youtubeId("not a url")).toBeNull();
    // An id-shaped value with unsafe characters must not reach the iframe.
    expect(youtubeId('https://youtu.be/"<script>')).toBeNull();
  });
});
