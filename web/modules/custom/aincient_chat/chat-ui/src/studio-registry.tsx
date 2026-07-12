import type { ComponentType, SVGProps } from "react";
import { PaletteIcon, DocumentIcon, ChatBubbleIcon, LayoutIcon, LibraryIcon, ShieldCheckIcon } from "./icons";
import { BrandStudio } from "./brand-studio";
import { BrandPreview } from "./brand-preview";
import { GlobalsStudio } from "./globals-studio";
import { GlobalsPreview } from "./globals-preview";
import { PageStudio } from "./page-studio";
import { PagePreview } from "./page-preview";
import { MediaStudio } from "./media-studio";
import { MediaPreview } from "./media-preview";
import { ChecksStudio } from "./checks-studio";
import { enabledStudioKeys, isStudioAccessible, type StudioKey } from "./studios";

/**
 * The studio component registry — the front-end half of the studio concept.
 *
 * Each studio's NAME, ICON, and (for specialised studios) editor/preview
 * components live here, keyed by the studio key shared with the backend `Studio`
 * enum and the server's studio catalog ({@see studios.ts}). General has no
 * editor components → it renders as full-width chat; Design System (Foundations
 * tokens), Globals (chrome — Brand identity / Header / Footer), and Content
 * (pages) bring a live-preview split-pane.
 *
 * A studio may be EDITOR-ONLY (Globals; Media when the image role is unbound):
 * it has editor components here but no agent in the server catalog, so it offers
 * no NEW chat. {@see enabledStudios} surfaces it from its editor presence alone;
 * App gates the chat COMPOSER off when the active studio has no agent — the chat
 * column itself still renders wherever the section holds live conversations, so
 * history never becomes unreachable when an agent is dropped.
 *
 * Adding a studio (Menu, Homepage, …) = a new entry here + its components, the
 * matching enum case, and (for an agent-bearing studio) a config row. Nothing
 * else hard-codes the set.
 */

type IconType = ComponentType<SVGProps<SVGSVGElement>>;

export type StudioDef = {
  /** Display name in the breadcrumb (the backend owns its own admin label). */
  name: string;
  /** Inline-SVG glyph for the studio crumb. */
  Icon: IconType;
  /** The editor rail — omitted for chat-only studios (General). */
  Studio?: ComponentType<{ onClose: () => void }>;
  /** The live-preview pane — omitted for chat-only studios. */
  Preview?: ComponentType;
};

export const STUDIO_REGISTRY: Record<StudioKey, StudioDef> = {
  general: { name: "General", Icon: ChatBubbleIcon },
  design_system: { name: "Design System", Icon: PaletteIcon, Studio: BrandStudio, Preview: BrandPreview },
  globals: { name: "Globals", Icon: LayoutIcon, Studio: GlobalsStudio, Preview: GlobalsPreview },
  // The site OUTPUT surface — a visitor-facing page. Display name is "Pages"
  // (the IA's Tier-1 daily peer); the enum key + URL slug stay `content`.
  content: { name: "Pages", Icon: DocumentIcon, Studio: PageStudio, Preview: PagePreview },
  // The reusable-ingredient family — displayed as "Library", its Tier-1 name
  // (DECISIONS 0168). One studio owns the whole family, exactly as `content`
  // owns Pages: the SHELF room (browse media + global blocks — MediaPreview
  // renders the shelf browser when nothing is open) and the item rooms (one
  // image open in the same split-pane). The OPTIONAL Nano Banana chat rail
  // attaches once an image agent is configured; unbound, the family degrades to
  // browse + the non-AI editor rail. The old `library` registry entry is gone —
  // the shelf IS the section's home, not a separate studio.
  media: { name: "Library", Icon: LibraryIcon, Studio: MediaStudio, Preview: MediaPreview },
  // The fix loop: same live preview as Content (it reads the shared page-state
  // draft), with the findings rail as the editor. Its agent stages fixes into
  // that draft via preview_page; the human Publishes. Still surfaces before its
  // agent exists (the editor-only arm of enabledStudios).
  checks: { name: "Checks", Icon: ShieldCheckIcon, Studio: ChecksStudio, Preview: PagePreview },
};

/** The registry entry for a studio key (undefined for an unknown key). */
export function studioDef(key: StudioKey | undefined): StudioDef | undefined {
  return key ? STUDIO_REGISTRY[key] : undefined;
}

/** Whether a studio renders an editor/preview split-pane (vs full-width chat). */
export function studioHasEditor(key: StudioKey | undefined): boolean {
  return !!studioDef(key)?.Studio;
}

/**
 * Whether a studio is OFFERED to this user: it EITHER has a configured agent
 * (present in the server catalog) OR brings its own editor components, AND the
 * user may enter it. The editor arm is what surfaces an EDITOR-ONLY studio
 * (Globals/Library — no chat agent) without a config row; the access arm gates
 * every studio (incl. editor-only ones) by the server's `studioAccess`.
 *
 * The single availability predicate — used by both {@link enabledStudios} (the
 * flat list) and the tiered nav model ({@link ./nav-model}), so the two can
 * never disagree on which studios exist for a user.
 */
export function studioAvailable(key: StudioKey): boolean {
  return (
    (new Set(enabledStudioKeys()).has(key) || studioHasEditor(key)) &&
    isStudioAccessible(key)
  );
}

/**
 * The available studios in REGISTRY (display) order (see {@link studioAvailable}).
 *
 * @return list of {key, def}.
 */
export function enabledStudios(): { key: StudioKey; def: StudioDef }[] {
  return Object.keys(STUDIO_REGISTRY)
    .filter((key) => studioAvailable(key))
    .map((key) => ({ key, def: STUDIO_REGISTRY[key] }));
}
