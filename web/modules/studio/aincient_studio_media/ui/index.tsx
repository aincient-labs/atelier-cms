import { MediaStudio } from "./media-studio";
import { MediaPreview } from "./media-preview";
import { MediaResultToolUI } from "./media-result";
import "./styles.css";

/**
 * The Library studio's UI entry — the second studio MODULE (plans/studio-modules.md
 * Phase E). Its named exports are the `StudioUiModule` contract; the crumb's name and icon are the manifest's `ui:` map,
 * and the build gives this file its own lazy chunk; the generated
 * registry files them under the manifest's `media` id (the family's home — the
 * `library` id in the same manifest has no UI of its own, the shelf IS this
 * studio's empty-state canvas, DECISIONS 0168). This file never registers itself,
 * and imports only `@console/kit`, `@console/sdk`, its own files and react: the
 * studio fence (`studio-fence.test.ts`) refuses anything else.
 *
 * The family in one split-pane: `Preview` is the centre canvas — the Library
 * shelf browser when nothing is open, the image at real size once an item is —
 * and `Studio` is the NON-AI editor rail (name · alt · replace file · delete),
 * the always-available human path (DECISIONS 0144). `ToolUIs` brings the one
 * chat widget the image agent emits: the `media_result` card, which routes a
 * generated image / a suggested alt text / a proposed name INTO that rail for
 * the human to review and Save — AI proposes, you approve.
 *
 * State is the console's media-state store, reached through `@console/sdk`: the
 * console itself navigates in and out of the media room (deep links, the
 * close-on-leave machine), so the store is a core seam, not studio code.
 */

/** The editor rail. */
export const Studio = MediaStudio;
/** The centre canvas: the shelf browser, or the open image. */
export const Preview = MediaPreview;
/** The chat widgets this studio's agent renders (`media_result`). */
export const ToolUIs = [MediaResultToolUI];
