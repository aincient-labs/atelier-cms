import { IdentityStudio } from "./brand-studio";
import { BrandPreview } from "./brand-preview";
import { BrandPickerToolUI } from "./brand-picker";
import { BrandPreviewToolUI } from "./brand-preview-tool";
import { BrandStatusProposalToolUI } from "./brand-status-proposal";
import { DesignTokenAdmissionToolUI } from "./design-token-admission";
import { LogoHandoffToolUI } from "./logo-handoff";
import "./styles.css";

/**
 * The Identity studio's UI entry — the sixth and last studio MODULE
 * (plans/studio-modules.md Phase E.5, DECISIONS 0436). Its named exports are
 * the `StudioUiModule` contract — the LAZY half: the crumb's name and icon are the
 * manifest's `ui:` map, and the build gives this file its own lazy chunk —; the generated registry files them under the
 * manifest's frozen `design_system` id (the permission, the agents map and the
 * stored deep links keep that id — DECISIONS 0372 renamed the ROOM to Identity,
 * never the key). This file never registers itself, and imports only
 * `@console/kit`, `@console/sdk`, its own files and react: the studio fence
 * (`studio-fence.test.ts`) refuses anything else.
 *
 * The split-pane: `Studio` is the brand rail — the whole brand: design tokens
 * (colour, typography, shape) + name / tagline / voice / imagery + the WHOLE
 * logo (image · size · position) + favicon + footer note, with a compound
 * Publish (brand/save tokens + chrome/save identity slice) — and `Preview` is
 * the token showcase, the brand demo page re-rendered live from the staged
 * overrides. `ToolUIs` brings the five chat cards the brand agent emits: the
 * quick picker, the live preview applier, the status proposal, the token
 * admission and the logo hand-off — AI proposes, you Publish.
 *
 * State is the console's brand-state store (and the chrome draft for the
 * identity slice), reached through `@console/sdk`: the adapter replays the
 * agent's preview ops into it and stamps historical cards from it, so it is a
 * core seam, not studio code.
 */

/** The editor rail: the brand studio. */
export const Studio = IdentityStudio;
/** The centre canvas: the live token showcase. */
export const Preview = BrandPreview;
/** The chat widgets this studio's agent renders. */
export const ToolUIs = [BrandPickerToolUI, BrandPreviewToolUI, BrandStatusProposalToolUI, DesignTokenAdmissionToolUI, LogoHandoffToolUI];
