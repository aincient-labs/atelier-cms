import { describe, expect, it } from "vitest";
import { youtubeId } from "./youtube";
import { carriesPrefill, tourHeader, visibleTourRooms } from "./tour-model";

/** Display names, as the studio registry would answer them. */
const NAMES: Record<string, string> = {
  content: "Pages",
  media: "Library",
  design_system: "Design System",
  globals: "Globals",
};
const nameOf = (key: string) => NAMES[key];
/** Everything in NAMES is a real, enterable room unless a test says otherwise. */
const allAvailable = (key: string) => key in NAMES;

const ALL = [
  { key: "content", status: "No pages yet" },
  { key: "media", status: "The shelf is empty" },
  { key: "design_system", status: "Colours, type…" },
  { key: "globals", status: "Header, footer…" },
];

/**
 * The tour widget renders BOTH a whole-map tour and a one-room handoff from the
 * same payload, so its header must branch on what actually rendered — and the
 * access filter must run before that branch, in the one-room case as much as the
 * four-room one.
 */
describe("studio tour rooms + header", () => {
  it("shows the whole map, unchanged, for a default (all rooms) payload", () => {
    const rooms = visibleTourRooms(ALL, allAvailable);
    expect(rooms.map((r) => r.key)).toEqual(["content", "media", "design_system", "globals"]);
    expect(tourHeader(rooms.map((r) => r.key as string), nameOf)).toEqual({
      title: "Your studio",
      hint: "Pick a room to open it",
    });
  });

  it("turns a one-room payload into a named handoff", () => {
    const rooms = visibleTourRooms([{ key: "content", status: "No pages yet" }], allAvailable);
    expect(rooms).toHaveLength(1);
    expect(tourHeader(["content"], nameOf)).toEqual({
      title: "Pages",
      hint: "This is where landing pages are built — open it to continue",
    });
  });

  it("names each room it hands off to", () => {
    expect(tourHeader(["media"], nameOf).title).toBe("Library");
    expect(tourHeader(["design_system"], nameOf).title).toBe("Design System");
    expect(tourHeader(["globals"], nameOf).title).toBe("Globals");
  });

  it("falls back to a generic line for a room with no handoff copy", () => {
    expect(tourHeader(["checks"], () => "Checks")).toEqual({
      title: "Checks",
      hint: "Open it to continue",
    });
  });

  it("filters rooms the user may not enter — including the one-room case", () => {
    const noPages = (key: string) => allAvailable(key) && key !== "content";
    expect(visibleTourRooms(ALL, noPages).map((r) => r.key)).toEqual([
      "media",
      "design_system",
      "globals",
    ]);
    // A one-room handoff into a room they can't enter renders NOTHING — the
    // widget must not announce "this is where pages are built" over an empty
    // grid, so the header branch reads the filtered list, not the payload.
    expect(visibleTourRooms([{ key: "content" }], noPages)).toEqual([]);
  });

  it("drops malformed and unknown rooms", () => {
    expect(
      visibleTourRooms(
        [{ key: "content" }, {}, { key: "" }, { key: "not_a_room" }] as { key?: string }[],
        allAvailable,
      ).map((r) => r.key),
    ).toEqual(["content"]);
    expect(visibleTourRooms(undefined, allAvailable)).toEqual([]);
  });

  it("carries the user's sentence only when exactly one room rendered", () => {
    // Four cards means the ask was "show me around" — carrying THAT into the
    // Pages composer would hand the user a sentence about the wrong thing.
    expect(carriesPrefill(4)).toBe(false);
    expect(carriesPrefill(0)).toBe(false);
    expect(carriesPrefill(1)).toBe(true);
  });
});

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
