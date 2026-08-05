import type { StudioKey } from "./studios";

/**
 * The studio-tour widget's decision layer — which cards render, and what the
 * header says. Pure and dependency-free (no React, no studio registry) so it is
 * unit-testable in the node environment; `studio-tour.tsx` injects the two
 * lookups it needs (availability, display name).
 *
 * The header BRANCHES on the rooms that actually render, not on what the server
 * sent: the same payload shape carries a whole-map tour and a one-room handoff,
 * and a room the user may not enter is filtered out before the branch. Announcing
 * "This is where pages are built" over an empty grid would be a lie.
 */

/** One card as the capability sends it: a studio key + a server status line. */
export type TourRoom = { key?: string; status?: string };

/** The header's two lines. */
export type TourHeader = { title: string; hint: string };

/**
 * What a single-room handoff says under the room's name. Display copy — it lives
 * with the names and icons on the client, not in the payload. An unlisted room
 * falls back to the generic line.
 */
const HANDOFF_HINT: Record<string, string> = {
  content: "This is where landing pages are built — open it to continue",
  media: "Images and uploads live here — open it to continue",
  design_system: "Colours, type and mood live here — open it to continue",
  globals: "The header, footer, logo and site name live here — open it to continue",
};

/**
 * The rooms the widget will render: a real studio key the user may enter.
 *
 * @param rooms
 *   The payload's rooms (anything malformed is dropped).
 * @param renderable
 *   Whether a studio key is both known to the registry and enterable by this
 *   user — the single availability predicate, passed in.
 */
export function visibleTourRooms(
  rooms: TourRoom[] | undefined,
  renderable: (key: StudioKey) => boolean,
): TourRoom[] {
  return (rooms ?? []).filter(
    (room) => typeof room?.key === "string" && room.key !== "" && renderable(room.key),
  );
}

/**
 * The header for a set of rendered rooms: a handoff into the one room, or the
 * map of many (the original copy, unchanged).
 *
 * @param keys
 *   The rendered rooms' keys.
 * @param nameOf
 *   Display-name lookup (the studio registry, injected).
 */
export function tourHeader(
  keys: string[],
  nameOf: (key: StudioKey) => string | undefined,
): TourHeader {
  if (keys.length === 1) {
    const key = keys[0] as StudioKey;
    return {
      title: nameOf(key) ?? "Your studio",
      hint: HANDOFF_HINT[key] ?? "Open it to continue",
    };
  }
  return { title: "Your studio", hint: "Pick a room to open it" };
}

/**
 * Whether a handoff should carry the user's sentence into the target room.
 *
 * Only for a SINGLE rendered room. A whole-map tour was asked for with something
 * like "show me around" — carrying that into the Pages composer would hand the
 * user a sentence that makes no sense in the room they landed in, which is the
 * "someone else's sentence" failure the wizard's docblock warns about. One card
 * means the model already resolved the ask to that room.
 */
export function carriesPrefill(visibleCount: number): boolean {
  return visibleCount === 1;
}
