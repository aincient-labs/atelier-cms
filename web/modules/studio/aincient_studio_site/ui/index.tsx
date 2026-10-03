import { GlobalsStudio } from "./globals-studio";
import { GlobalsPreview } from "./globals-preview";
import { ChromePreviewToolUI } from "./chrome-preview-tool";
import "./styles.css";

/**
 * The Navigation & Pages studio's UI entry (machine id `globals`) — one of the
 * two studios in the Site module, the fourth studio MODULE
 * (plans/studio-modules.md Phase E.3). Its named exports are the
 * `StudioUiModule` contract; the crumb's name and icon are the manifest's `ui:` map,
 * and the build gives this file its own lazy chunk; the generated registry files them under the
 * manifest's frozen `globals` id. The Settings studio's entry is `settings.tsx`
 * beside this file — one module, two UIs, one stylesheet. This file never
 * registers itself, and imports only `@console/kit`, `@console/sdk`, its own
 * files and react: the studio fence (`studio-fence.test.ts`) refuses anything
 * else.
 *
 * The surface in one split-pane: `Preview` is the live chrome — the server
 * renders the header and footer around a placeholder body from the shared
 * chrome draft — and `Studio` is the rail: the main and footer menus, the
 * front / 404 / 403 routing and the header/footer arrangement knobs. `ToolUIs`
 * brings the one chat widget the chrome agent emits: the `chrome_preview` card,
 * which merges the agent's partial into the SAME unsaved draft the rail edits.
 * Nothing is live until Publish.
 *
 * State is the console's chrome-draft store, reached through `@console/sdk`:
 * Identity (design_system, still core) stages its own slice into the same
 * store, and the adapter stamps historical cards from it, so the store is a
 * core seam, not studio code.
 */

/** The editor rail. */
export const Studio = GlobalsStudio;
/** The centre canvas: the live chrome preview. */
export const Preview = GlobalsPreview;
/** The chat widgets this studio's agent renders (`chrome_preview`). */
export const ToolUIs = [ChromePreviewToolUI];
