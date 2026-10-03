import { SettingsStudio } from "./settings-studio";
import { GlobalsPreview } from "./globals-preview";
import "./styles.css";

/**
 * The Settings studio's UI entry (machine id `settings`) — the second studio in
 * the Site module (plans/studio-modules.md Phase E.3; DECISIONS 0372). A
 * SECOND entry file beside `index.tsx`: the manifest's `ui:` map points each
 * id at its own file (and names its crumb and icon), the generated registry
 * lazy-imports both, the build puts both in this MODULE's one chunk, and the
 * stylesheet import is shared (Vite emits it once).
 *
 * EDITOR-ONLY: no agent, no `ToolUIs`. `Studio` is the rail — site email, the
 * privacy / consent lever (font delivery) and the Freeze & Live snapshots
 * section — and `Preview` is the same live chrome the Navigation & Pages
 * studio renders, because the mail and font-delivery fields are chrome-draft
 * slices saved through the same merge.
 */

/** The editor rail. */
export const Studio = SettingsStudio;
/** The centre canvas: the shared live chrome preview. */
export const Preview = GlobalsPreview;
