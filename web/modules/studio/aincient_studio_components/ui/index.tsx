import { ComponentRail } from "./component-rail";
import { ComponentPreview } from "./component-preview";
import { ComponentsProposalToolUI } from "./proposal-tool";
import "./styles.css";

/**
 * The Components studio's UI entry — the first studio MODULE (plans/studio-modules.md
 * Phase B, DECISIONS 0431). Its named exports are the `StudioUiModule` contract:
 * `Studio` for the rail, `Preview` for the centre canvas. The crumb's name and
 * icon are the manifest's `ui:` map, and the build gives this file its own lazy
 * chunk. The generated registry files it under the manifest's id (`components`)
 * — this file never registers itself. It imports only `@console/kit`,
 * `@console/sdk`, its own files and react: the studio fence
 * (`studio-fence.test.ts`) refuses anything else.
 *
 * The Components governance surface (plans/byo-components.md W1b, DECISIONS
 * 0455) — the site-wide narrowing layer over the discovered component
 * vocabulary, in the standard studio frame: chat (the Components agent), the
 * live preview in the centre, the editor rail on the right. `ToolUIs` is the
 * agent's `components_proposal` card, which stages its proposal in the draft.
 * `Studio` lists every component by role group — on/off, its own variants and
 * tones, where it is used — and the site-wide tones; `Preview` renders the
 * contact sheet of everything the draft still offers, or the selected
 * component in the strip's example/variant/tone/width. Checked = available;
 * unchecking stages a REMOVAL (`aincient_pages.site_constraint`).
 *
 * State is the studio's own store (`components-store.ts`): the staged draft +
 * baseline, the selection and the view strip — nothing here is shared with the
 * chrome/page draft stores. Publish sends the WHOLE staged slice (each key
 * replaces its stored list) and re-seeds from the fresh state the save returns;
 * the server's 422s (the last tone site-wide, a component's last variant or
 * tone) are mirrored by the model's guards and, if one slips through, keep the
 * draft and show the error.
 */

/** The editor rail. */
export const Studio = ComponentRail;
/** The centre canvas: the components preview. */
export const Preview = ComponentPreview;
/** The agent's proposal card (P2). */
export const ToolUIs = [ComponentsProposalToolUI];
