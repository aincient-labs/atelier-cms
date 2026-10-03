import { PagePreview } from "@console/sdk";
import { PageStudio } from "./page-studio";
import { PagePreviewToolUI } from "./page-preview-tool";
import "./styles.css";

/**
 * The Content studio's UI entry — the fifth studio MODULE (plans/studio-modules.md
 * Phase E.4, DECISIONS 0435). Its named exports are the `StudioUiModule`
 * contract; the crumb's name and icon are the manifest's `ui:` map,
 * and the build gives this file its own lazy chunk; the generated registry files them under the manifest's frozen
 * `content` id (the URL slug and the permission keep that id; the console SHOWS
 * "Pages" — the site's output surface, the IA's Tier-1 daily peer). This file
 * never registers itself, and imports only `@console/kit`, `@console/sdk`, its
 * own files and react: the studio fence (`studio-fence.test.ts`) refuses
 * anything else.
 *
 * The split-pane: `Studio` is the page composer rail — the section stack with
 * its component picker, the SEO / teaser / blog field groups, translations,
 * the editor lock and the editorial actions (save · publish · review
 * transitions) — and `Preview` is the SHARED page preview, reached through the
 * sdk and NOT a file of this module: Checks renders the very same canvas beside
 * its findings rail (it audits the draft this rail edits), and a studio may
 * never import another studio, so the canvas, the content browser and the
 * Presence cards it composes stay core. `ToolUIs` brings the one chat widget
 * the pages agent emits: the `page_preview` card, which applies the agent's
 * ops to the draft and yanks the console into the page's room.
 *
 * State is the console's page-state store (and the facet, dirty and lock
 * stores), reached through `@console/sdk`: the console itself navigates in and
 * out of page rooms (deep links, the `+` birth form, the close-on-leave
 * machine, the switch guard), so those stores are core seams, not studio code.
 */

/** The editor rail: the page composer. */
export const Studio = PageStudio;
/** The centre canvas: the shared page preview (core's, through the sdk). */
export const Preview = PagePreview;
/** The chat widgets this studio's agent renders (`page_preview`). */
export const ToolUIs = [PagePreviewToolUI];
