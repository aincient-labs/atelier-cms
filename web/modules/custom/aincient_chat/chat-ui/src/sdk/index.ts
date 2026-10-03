/**
 * `@console/sdk` — the console hooks a studio legitimately needs (DECISIONS 0430;
 * plans/studio-modules.md, "Front end: source-only split").
 *
 * RE-EXPORTS ONLY. Nothing here moved or was wrapped: each name below is the real
 * implementation in its console file, listed so a studio has ONE place to import
 * it from. That listing is the point — a console-internal rename (or a deletion)
 * then breaks THIS file at build time, where without it the same rename would
 * break a client's studio at runtime, long after the console shipped.
 *
 * GROWN ON DEMAND: the sdk is whatever the studios built so far actually reach
 * for, not a designed surface. The next studio that needs another hook adds one
 * line here — and that line is the decision to make it public API. Keep it that
 * way: widening it is a commitment, not a convenience.
 *
 * Chat: the studio-facing hooks come from `../aui` (the one facade over the chat
 * runtime) so the vendor's names never reach a studio. Studios may also import
 * `@console/aui` directly; the chat names are repeated here only so the
 * "send a turn" path is discoverable from the sdk.
 */

export { useStudioUI, StudioActionsPortal } from "../kit/studio-ui";
export type { StudioUiModule, StudioDef, IconType } from "../studio-module";

// Chat: read the open thread / send a turn.
export { useConsoleChat, useConsoleThread, sendTurn } from "../aui";

// After a publish: offer to wrap the thread up (the seal pane). Added by the
// Components studio (Phase B) — the first studio that publishes.
export { offerWrapup } from "../thread-seal";

// The injected API base: a studio's own JSON routes live under it. Also added
// by the Components studio; a studio never hardcodes `/atelier`.
export { apiUrl, apiBase, consoleBase } from "../console-config";

// Deep-link `?field=…`: anchor ids, the requested field, and landing on it.
// Also Components (Phase B); every rail with addressable fields wants these.
export { fieldAnchorId, focusStudioField, requestedFieldAnchor, REVEAL_FIELD_EVENT } from "../studio-field-anchor";

// Deep links: build and read the reflected URL axes.
export { consoleHref, roomToUrl, roomToPath, parseUrl } from "../console-url";
export { openPageInPlace } from "../url-sync";

// Editor lock: reads, plus `acquireLock` for the rail's explicit "Take over"
// (Content, Phase E.4) — releasing stays the console's job (close-on-leave).
export { subscribeLock, lockState, holdsLock, lockToken, acquireLock } from "../page-lock";
export type { LockHolder } from "../page-lock";

// Page-state seam: the shared draft and the open node. The Checks studio (Phase
// E) widened it to the whole fix loop: load a page INTO a studio, stage and
// save the draft, publish, reload the preview, and the three typed failures a
// load / save can end in. Content (Phase E.4) widened it to the whole composer:
// the selected section, the page URL / mode / kind / translations, the block
// twin, translation mode, the editorial transitions and the empty draft. The
// page family's store is a CORE store — the console's room machine, deep links,
// the close-on-leave path and the `+` birth form read it — so BOTH page studios
// (Content, Checks) read and write it through here.
export {
  getPageDraft,
  setPageDraft,
  subscribePageDraft,
  subscribePageLoad,
  getPageNode,
  setPageNode,
  subscribePageNode,
  getPageUrl,
  setPageUrl,
  getPageLang,
  getPageMode,
  getPageKind,
  getPageTranslations,
  isComposingDraft,
  setTranslationMode,
  getSelectedSection,
  setSelectedSection,
  subscribeSelectedSection,
  getModeration,
  subscribeModeration,
  loadPageIntoStudio,
  loadBlockIntoStudio,
  saveDraft,
  publishDoc,
  runTransition,
  reloadPreview,
  EMPTY_PAGE,
  DocLoadError,
  RevisionConflictError,
  LockConflictError,
} from "../page-state";
export type { PageMeta, PageSchema, PageSection, PageTeaser, PageBlog, StudioKind, Moderation, Transition } from "../page-state";

// The shared page preview — the centre canvas BOTH page studios render: Content
// beside its composer rail, Checks beside its findings (it audits the SAME draft
// Content edits). It stays core (with the content browser and the Presence
// cards it composes) because a studio may never import another studio, and it
// reads only core stores. Content (Phase E.4) reaches it through here too.
export { PagePreview } from "../page-preview";

// The page facet (Content ↔ Presence) the preview and the composer rail share
// — a core store because the preview is. Content (Phase E.4).
export { useFacet, setFacet, resetFacet } from "../page-facet";
export type { PageFacet } from "../page-facet";

// The dirty flag the console's switch guard reads before stranding a draft; the
// composer rail is the one writer. Content (Phase E.4).
export { setPageDirty } from "../page-dirty";

// The thread the rail edits in: sealed → the composer is read-only. Content (Phase E.4).
export { useActiveThreadSealed } from "../thread-seal-hooks";

// The component catalog's presentation helpers (the section glyph, grouping by
// pack provider) — core because the `+` birth form reads the same catalog.
export { sectionIcon, groupByProvider } from "../catalog-meta";

// Studio deep links + access: the Checks hand-off link on the rail, and whether
// this user may enter the studio it points at. Content (Phase E.4).
export { pageDeepLink, isStudioAccessible } from "../studios";

// Which studio the console is in (the preview card decides whether to yank).
export { activeStudioKey } from "../flow";

// The room the stores currently describe (the preview card lands there).
export { deriveRoomFromStores } from "../console-nav";

// "Something was made" — the name-invite nudge's trigger. Content (Phase E.4).
export { markSomethingMade } from "../name-invite-state";

// Audit-state seam: the node under audit. A CORE store (the console's room
// machine, deep links and the close-on-leave path read it), so the Checks
// studio reads and writes it through here. Checks (Phase E).
export { getAuditLang, getAuditNode, setAuditNode, subscribeAuditNode } from "../audit-state";

// The thread end-state pane: a studio reports a document that went away or
// was denied, and clears it when a load succeeds. Checks (Phase E).
export { clearDocEnd, setDocEnd } from "../doc-end-state";

// Rooms: read the current room, react to room changes, enter / adopt one.
// Added by the Library studio (Phase E) — its rail and canvas are room-reactive
// (shelf ↔ item keeps the same studio, no remount).
export { consoleNav, roomVersion, subscribeRoom } from "../console-nav";

// The server catalog: which agents a studio has, which capabilities this
// install can run (`capabilityAvailable("draw")` gates the "ask the chat for an
// image" invitation). Also Library (Phase E).
export { agentsForStudio } from "../studios";
export { capabilityAvailable } from "../capabilities";

// Open a global block's editor (the Library shelf's block rows). Library (Phase E).
export { openBlock } from "../block-nav";

// Media-state seam: the open image item. A CORE store, not studio code — the
// console itself navigates in and out of the media room (deep links, the
// close-on-leave machine, the adapter's historical-card stamp), so the studio
// reads and writes it through here. Library (Phase E).
export {
  clearPendingAlt,
  clearPendingName,
  deleteMedia,
  getEditSource,
  getMediaDetail,
  getPendingAlt,
  getPendingName,
  loadMediaIntoStudio,
  mediaVersion,
  replaceMediaFile,
  replaceOriginalWithCurrent,
  saveMediaMetadata,
  setEditSource,
  stageAltProposal,
  stageNameProposal,
  subscribeMedia,
} from "../media-state";
export type { MediaDetail } from "../media-state";

// Chrome-draft seam: the working copy of the site chrome (menus, routing,
// header/footer arrangement, identity, mail, font delivery). A CORE store —
// Identity (design_system) stages its own slice into it, the adapter stamps
// historical cards from it, the Presence preview re-renders on it — so the
// two Site studios (Navigation & Pages, Settings) read and write it through
// here. The reload pair is renamed on the way out: `reloadPreview` is already
// the page draft's. Site studios (Phase E.3).
export {
  getChromeDraft,
  setChromeDraft,
  resetChromeDraft,
  subscribeChromeDraft,
  reloadPreview as reloadChromePreview,
  subscribePreviewReload as subscribeChromePreviewReload,
  requestChromeReset,
  subscribeChromeReset,
} from "../globals-state";
export type { ChromeDraft, ChromeIdentity, ChromeLayout, ChromeMenuLink, ChromePrivacy } from "../globals-state";

// The chrome manifest shape and the seed/clone helpers the three chrome
// surfaces share (DECISIONS 0372) — core, because Identity uses them too.
export { DELIVERY_GOOGLE, SITE_KEYS, cloneChromeDraft, seedChromeDraft, titleCase } from "../chrome-shared";
export type { ChromeManifest, RegistrySetting } from "../chrome-shared";

// The "set directly" marker beside a field the chat cannot reach. Core (Identity
// renders it too); data-driven from the injected chat-reachable set.
export { HandsOnMarker } from "../hands-on-marker";

// Preview-iframe hygiene for a studio that renders its own canvas: cancel
// navigation, paint the console scrollbar, strip inner tab stops (a11y, 0199).
export { injectPreviewScrollbar, interceptPreviewLinks, neutralizePreviewTabbing } from "../preview-nav";

// Switch the console to a studio (a chat card yanking to the room it edits).
export { ensureStudio } from "../flow";

// Brand-draft seam: the working token / font overrides the Identity rail stages
// and the token showcase renders. A CORE store — the adapter stamps historical
// brand cards from it and replays the agent's preview ops into it
// (`applyBrandPreviewOps`), so the Identity studio reads and writes it through
// here. The reload pair is renamed on the way out, as the chrome pair was:
// `reloadPreview` is the page draft's. Identity (Phase E.5).
export {
  setBrandOverride,
  resetBrandOverrides,
  getBrandOverrides,
  subscribeBrandOverrides,
  setPendingFonts,
  getPendingFonts,
  reloadPreview as reloadBrandPreview,
  subscribePreviewReload as subscribeBrandPreviewReload,
} from "../brand-state";
export type { BrandOverrides } from "../brand-state";
export { applyBrandPreviewOps } from "../brand-preview-ops";
export type { BrandPreviewPayload } from "../brand-preview-ops";

// Sealing a thread from a rail's own Publish (Identity's compound publish seals
// and resets the brand conversation itself rather than offering the wrap-up
// pane): the remembered seal state and the two adapter calls behind it.
// Identity (Phase E.5).
export { rememberThreadSeal } from "../thread-seal";
export { resetThreadMemory, sealThread } from "../adapter";
