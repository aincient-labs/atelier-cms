import { PagePreview } from "@console/sdk";
import { ChecksStudio } from "./checks-studio";
import "./styles.css";

/**
 * The Checks studio's UI entry — the third studio MODULE (plans/studio-modules.md
 * Phase E, DECISIONS 0433). Its named exports are the `StudioUiModule` contract; the crumb's name and icon are the manifest's `ui:` map,
 * and the build gives this file its own lazy chunk;
 * the generated registry files them under the manifest's frozen `checks` id.
 * This file never registers itself, and imports only `@console/kit`,
 * `@console/sdk`, its own files and react: the studio fence
 * (`studio-fence.test.ts`) refuses anything else.
 *
 * The fix loop in one split-pane: `Preview` is the SHARED page preview — the
 * same centre canvas Content renders, reading the same page-state draft through
 * the sdk, so a fix the agent stages shows live — and `Studio` is the findings
 * rail: the deterministic audit of the open page grouped by check, each
 * finding with "Fix with AI" (the agent stages a `preview_page` / `set_meta` op
 * into the draft) or an inline manual edit. The human Publishes; the audit
 * re-runs against the latest revision and the cleared finding drops off.
 *
 * No `ToolUIs`: the audit agent narrates and stages through the console's own
 * page-preview card (core, because Content's agent uses it too).
 */

/** The findings rail. */
export const Studio = ChecksStudio;
/** The centre canvas: the shared page preview. */
export const Preview = PagePreview;
