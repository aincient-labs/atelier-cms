import { useSyncExternalStore } from "react";
import {
  editorialMarker,
  editorialStateVersion,
  subscribeEditorialStates,
  threadEditorialState,
  type EditorialState,
} from "./thread-editorial-state";

/**
 * The sidebar row's editorial marker — Draft / In review / Live — for the
 * page a thread works on, in the thread's language (content-workflow.md Phase 4).
 *
 * It wears the same whisper chip as the Content browser's state column
 * (`.ain-studio__statebadge[data-state]`), so one state reads one way across the
 * console; `.ain-tli__state` only fits it to the row's 11px meta line. The full
 * server label ("Draft · published copy live") rides in the tooltip and the
 * accessible name. Renders nothing for an unhomed thread or a state without an
 * agreed marker.
 */
export function ThreadStateMarker({ state }: { state: EditorialState | null | undefined }) {
  const marker = editorialMarker(state);
  if (!marker) return null;
  return (
    <span
      className="ain-studio__statebadge ain-tli__state"
      data-state={marker.state}
      title={marker.detail}
      data-testid="thread-state-marker"
    >
      {marker.text}
    </span>
  );
}

/** The marker for one listed thread, re-rendering when a newer listing lands. */
export function ThreadRowStateMarker({ remoteId }: { remoteId: string | undefined }) {
  useSyncExternalStore(subscribeEditorialStates, editorialStateVersion);
  return <ThreadStateMarker state={threadEditorialState(remoteId)} />;
}
