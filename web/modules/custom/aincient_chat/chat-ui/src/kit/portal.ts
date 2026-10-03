import { createContext, useContext } from "react";

/**
 * Where the kit's floating layers (Popover, Menu, Dialog) portal to.
 *
 * NOT `document.body`: every console token is defined on `#aincient-chat-root`
 * (tokens.generated.css), so a layer portalled outside it would render with no
 * colours, no radius and no theme. The default is the console root; a surface
 * mounted somewhere else provides its own element.
 */
export const KitPortalContext = createContext<() => HTMLElement | null>(() =>
  typeof document === "undefined" ? null : document.getElementById("aincient-chat-root"),
);

/** The element floating layers portal into (undefined = Radix's own default). */
export function usePortalContainer(): HTMLElement | undefined {
  return useContext(KitPortalContext)() ?? undefined;
}
