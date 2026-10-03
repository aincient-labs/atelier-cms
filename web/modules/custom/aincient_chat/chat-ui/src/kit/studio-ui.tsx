import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";

/**
 * Transient studio layout state shared across the shell.
 *
 * When an editor studio (Brand/Page) is open the workspace is a three-column
 * row — chat · preview · edit — on wide screens. As the viewport narrows the
 * panes collapse and two of them become summoned surfaces:
 *
 *  - **tablet (≤1099px):** chat + preview stay; the editor rail becomes a
 *    right-edge slide-over, revealed by the top-bar "Edit" toggle (`editOpen`).
 *  - **phone (≤767px):** the preview is the full canvas; the chat input is
 *    docked to the bottom and the conversation lifts over it on demand
 *    (`convoOpen`); the editor rail rises as a bottom sheet (`editOpen`).
 *
 * These two booleans drive `data-studio-edit` / `data-studio-convo` on the
 * shell. The CSS that reacts to them is scoped to the narrow breakpoints, so a
 * value left `true` while resizing back to desktop is simply inert. The two
 * sheets are mutually exclusive (opening one closes the other) — you never edit
 * the form and read the conversation at the same time on a small screen.
 *
 * Consumers: the top-bar Edit toggle, the composer's conversation chevron, and
 * each studio's sheet-dismiss (✕) control. Provided inline by `App`, which owns
 * the state so it can also stamp the data-attributes on the shell.
 */
export type StudioUI = {
  /** Editor rail revealed as a sheet (tablet slide-over / phone bottom sheet). */
  editOpen: boolean;
  /** Conversation lifted over the preview canvas (phone only). */
  convoOpen: boolean;
  /** Reveal/hide the editor rail; closes the conversation sheet. */
  toggleEdit: () => void;
  /** Lift/drop the conversation; closes the editor sheet. */
  toggleConvo: () => void;
  /** Force both sheets closed (sheet ✕, scrim tap, leaving the studio). */
  closeSheets: () => void;
};

const noop = () => {};

export const StudioUIContext = createContext<StudioUI>({
  editOpen: false,
  convoOpen: false,
  toggleEdit: noop,
  toggleConvo: noop,
  closeSheets: noop,
});

/** Read the shared studio layout state (see {@link StudioUIContext}). */
export function useStudioUI(): StudioUI {
  return useContext(StudioUIContext);
}

/**
 * Render a studio's primary actions (Discard / Publish / leave) into the top-bar
 * slot, so they stay reachable when the editor rail is collapsed to a sheet.
 *
 * The slot (`#ain-studio-actions`) is rendered unconditionally by the top bar,
 * so it exists by the time a studio mounts; we grab it once. Children keep the
 * studio's own handlers and disabled state — this only relocates where they paint.
 */
export function StudioActionsPortal({ children }: { children: ReactNode }) {
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  useEffect(() => setSlot(document.getElementById("ain-studio-actions")), []);
  return slot ? createPortal(children, slot) : null;
}
