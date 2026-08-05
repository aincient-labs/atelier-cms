import { useState } from "react";
import type { MouseEvent } from "react";
import { useThreadRuntime } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { stageComposerPrefill } from "./composer-prefill";
import { carriesPrefill, tourHeader, visibleTourRooms, type TourRoom } from "./tour-model";
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
 * ONE CARD IS A HANDOFF, NOT A TOUR. The capability takes an optional `rooms`
 * argument, so "where do I build a page?" arrives here as a single room. The
 * header says so — "Pages / This is where landing pages are built" instead of
 * "Your studio / Pick a room to open it" — and the card carries the user's own
 * last sentence into the target room's composer (see {@link roomPrefill} and
 * `composer-prefill.ts`; it is never auto-sent). Deliberately the SAME widget:
 * one payload contract, and access filtering stays in exactly one place.
 *
 * The header branches on the cards that actually RENDER, not on what the server
 * sent — a room the user may not enter is filtered out, and a "one room" payload
 * they lack access to must not be announced as a handoff into it.
 *
 * An optional intro video (`payload.video`, a YouTube URL from
 * aincient_onboarding.settings) renders as a click-to-load embed: nothing is
 * fetched from YouTube until the user asks to play.
 */

export type StudioTourPayload = {
  rooms?: TourRoom[];
  video?: { url?: string; title?: string };
};

/** Whether a payload room can be drawn: known to the registry AND enterable. */
function renderable(key: StudioKey): boolean {
  return !!STUDIO_REGISTRY[key] && studioAvailable(key);
}

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

function TourCard({ room, prefill }: { room: TourRoom; prefill?: string }) {
  const key = (room.key ?? "") as StudioKey;
  const def = STUDIO_REGISTRY[key];
  if (!def) return null;

  const target = sectionRoom(key);
  const href = roomToPath(target);
  const open = (e: MouseEvent<HTMLAnchorElement>) => {
    // A modifier/middle click follows the href into a NEW tab — a fresh app with
    // its own module state, so there is nothing to stage and nothing to clear.
    if (opensNewTab(e)) return;
    e.preventDefault();
    // Staged BEFORE the hop: `enterRoom` switches to a fresh thread, and it is
    // that thread's composer we're filling. Never sent — the user presses.
    if (prefill) stageComposerPrefill(key, prefill);
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

/**
 * The user's own most recent sentence in this conversation — what a one-room
 * handoff carries into the target room's composer. Empty when the thread store
 * raced a switch (a hop with no prefill is the graceful degradation).
 */
function useLastUserText(): string {
  const thread = useThreadRuntime();
  try {
    const messages = thread.getState().messages;
    for (let i = messages.length - 1; i >= 0; i -= 1) {
      const message = messages[i];
      if (message?.role !== "user") continue;
      const text = (message.content ?? [])
        .map((part) => (part.type === "text" ? part.text : ""))
        .join(" ")
        .trim();
      if (text) return text;
    }
  } catch {
    /* the thread store emptied mid-render — no sentence to carry */
  }
  return "";
}

function StudioTour(payload: StudioTourPayload) {
  const lastAsk = useLastUserText();
  const rooms = visibleTourRooms(payload.rooms, renderable);
  if (rooms.length === 0 && !payload.video?.url) return null;

  const keys = rooms.map((room) => room.key as string);
  const { title, hint } = tourHeader(keys, (key) => STUDIO_REGISTRY[key]?.name);
  // One card = the model resolved the ask to one room, so the ask travels.
  const prefill = carriesPrefill(rooms.length) ? lastAsk : "";

  return (
    <div className="ain-tour">
      <div className="ain-tour__head">
        <span className="ain-tour__title">{title}</span>
        <span className="ain-tour__hint">{hint}</span>
      </div>
      {payload.video?.url && <TourVideo url={payload.video.url} title={payload.video.title} />}
      <div className="ain-tour__grid">
        {rooms.map((room, i) => (
          <TourCard key={room.key ?? i} room={room} {...(prefill ? { prefill } : {})} />
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
