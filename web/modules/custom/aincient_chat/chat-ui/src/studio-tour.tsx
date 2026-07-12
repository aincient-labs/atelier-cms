import { useState } from "react";
import type { MouseEvent } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { STUDIO_REGISTRY, studioAvailable } from "./studio-registry";
import type { StudioKey } from "./studios";
import { sectionRoom } from "./rooms-core";
import { consoleNav } from "./console-nav";
import { opensNewTab, roomToPath } from "./console-url";
import { ArrowRightIcon, PlayIcon } from "./icons";
import { youtubeId } from "./youtube";

/**
 * Studio tour — the onboarding "map of the console" generative-UI widget.
 *
 * The `aincient_onboarding:studio_tour` capability emits a
 * `{ "__widget__": "studio_tour", "payload": … }` envelope: one entry per
 * console area, carrying only the studio KEY plus a server-derived status line
 * (live page/media counts). Everything display-owned resolves client-side —
 * name and icon from {@link STUDIO_REGISTRY}, the deep link from the room
 * codec — so display renames and subdir installs never stale the server.
 *
 * Cards the user may not enter are filtered out (same {@link studioAvailable}
 * predicate as the nav bar, so the tour can never advertise a room the bar
 * wouldn't show). A primary click enters the room in place via
 * {@link consoleNav}; modifier/middle clicks follow the real href natively.
 *
 * An optional intro video (`payload.video`, a YouTube URL from
 * aincient_onboarding.settings) renders as a click-to-load embed: nothing is
 * fetched from YouTube until the user asks to play.
 */

type TourRoom = { key?: string; status?: string };

export type StudioTourPayload = {
  rooms?: TourRoom[];
  video?: { url?: string; title?: string };
};

function TourVideo({ url, title }: { url: string; title?: string }) {
  const [playing, setPlaying] = useState(false);
  const id = youtubeId(url);
  if (!id) return null;

  if (playing) {
    return (
      <div className="ain-tour__video ain-tour__video--playing">
        <iframe
          className="ain-tour__frame"
          src={`https://www.youtube-nocookie.com/embed/${id}?autoplay=1`}
          title={title || "Introduction video"}
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
        />
      </div>
    );
  }

  // Click-to-load: a local poster button, no YouTube request until asked.
  return (
    <button type="button" className="ain-tour__video" onClick={() => setPlaying(true)}>
      <img
        className="ain-tour__poster"
        src={`https://i.ytimg.com/vi/${id}/hqdefault.jpg`}
        alt=""
        loading="lazy"
      />
      <span className="ain-tour__play">
        <PlayIcon />
      </span>
      <span className="ain-tour__videolabel">{title || "Watch the two-minute intro"}</span>
    </button>
  );
}

function TourCard({ room }: { room: TourRoom }) {
  const key = (room.key ?? "") as StudioKey;
  const def = STUDIO_REGISTRY[key];
  if (!def || !studioAvailable(key)) return null;

  const target = sectionRoom(key);
  const href = roomToPath(target);
  const open = (e: MouseEvent<HTMLAnchorElement>) => {
    if (opensNewTab(e)) return;
    e.preventDefault();
    consoleNav.enterRoom(target);
  };

  return (
    <a className="ain-tour__card" href={href} onClick={open}>
      <def.Icon className="ain-tour__icon" />
      <span className="ain-tour__cardbody">
        <span className="ain-tour__name">{def.name}</span>
        {room.status && <span className="ain-tour__status">{room.status}</span>}
      </span>
      <ArrowRightIcon className="ain-tour__go" />
    </a>
  );
}

function StudioTour(payload: StudioTourPayload) {
  const rooms = (payload.rooms ?? []).filter((r) => typeof r?.key === "string");
  if (rooms.length === 0 && !payload.video?.url) return null;

  return (
    <div className="ain-tour">
      <div className="ain-tour__head">
        <span className="ain-tour__title">Your studio</span>
        <span className="ain-tour__hint">Pick a room to open it</span>
      </div>
      {payload.video?.url && <TourVideo url={payload.video.url} title={payload.video.title} />}
      <div className="ain-tour__grid">
        {rooms.map((room, i) => (
          <TourCard key={room.key ?? i} room={room} />
        ))}
      </div>
    </div>
  );
}

/**
 * Registers the tour for the `studio_tour` tool. Mount once inside the
 * AssistantRuntimeProvider; `args` is the payload the dispatcher passed
 * through as the tool call's arguments.
 */
export const StudioTourToolUI = makeSafeAssistantToolUI<StudioTourPayload, unknown>({
  toolName: "studio_tour",
  render: ({ args }) => <StudioTour {...args} />,
});
