/**
 * Shared working draft between the page studio editor, the live preview, and the
 * page agent's preview tool — the page parallel of brand-state.ts.
 *
 * Where the brand draft is a thin token-override DIFF (the saved brand is the
 * baseline, applied live as inline CSS vars), a page draft is the WHOLE
 * page-schema: pages need a full re-render, not a CSS patch, so there's no
 * stable "base page" to overlay on. The browser holds the authoritative draft
 * here; the studio rail and the agent both mutate it:
 *   - the studio's section controls call setPageDraft() on every edit;
 *   - the `page_preview` tool POSTs the agent's ops to /aincient/page/apply and
 *     writes the returned (validated) schema back with setPageDraft().
 * The preview iframe subscribes and re-renders by POSTing the schema to the
 * stateless /aincient/page/preview endpoint.
 *
 * Nothing here is persisted — this is preview only. The one deliberate write is
 * the studio's Publish button (POST /aincient/page/save), exactly as brand's is.
 */

/** One placed section in a landing page-schema. `id` is the stable slot
 *  identity stamped by PageStore::validate() — it survives reordering, so ops
 *  (and the future per-language content overlay) address a slot by id, not by
 *  its shifting array position. */
export type PageSection = { id?: string; component: string; props: Record<string, unknown> };

/** A page's SEO/meta override block — per-page overrides that flow through the
 *  same staged-draft → Publish loop as sections (keys are Metatag plugin ids;
 *  the page <title> lives in `PageSchema.title`, not here). An absent key
 *  inherits the site default; the studio's SEO editor and the repair agent's
 *  `set_meta` op both stage into it. */
export type PageMeta = {
  description?: string;
  canonical_url?: string;
  og_title?: string;
  og_description?: string;
  og_image?: string;
};

/** A page's teaser block — how it presents when referenced as a card
 *  (title/description/image), distinct from both the page body and the SEO/meta
 *  block. `image` is a `media:<id>` token (resolved like every other image in
 *  the schema), not a URL. Flows through the same staged-draft → Publish loop;
 *  the Presence editor and the agent's `set_teaser` op both stage into it. Each
 *  key maps to a dedicated `field_teaser_*` field (see PageStore::TEASER_KEYS). */
export type PageTeaser = {
  title?: string;
  description?: string;
  image?: string;
};

/** A page-schema as the validator (PageStore) returns it. */
export type PageSchema = {
  type: string;
  title: string;
  /** Landing sections (absent/ignored for the locked blog recipe). */
  sections?: PageSection[];
  /** Per-page SEO/meta overrides (absent when the page inherits every default). */
  meta?: PageMeta;
  /** Teaser/presence block (absent when the page carries no teaser). */
  teaser?: PageTeaser;
  /** Blog recipes carry their own fields (lead, body_html, …); pass-through. */
  [key: string]: unknown;
};

/** The empty landing draft a fresh studio session starts from. Matches the
 *  shape PageStore::validate() normalises to, so a blank draft round-trips. */
export const EMPTY_PAGE: PageSchema = {
  type: "landing",
  title: "Untitled page",
  sections: [],
};

/** A fresh global-block draft (a sections-only fragment authored in the studio). */
export const EMPTY_BLOCK: PageSchema = {
  type: "landing",
  title: "Untitled block",
  sections: [],
};

/** What the studio is editing — a full page, or a reusable global block (a
 *  fragment placed on pages via a `block` slot). A block has no blog regime and
 *  no translation governance; otherwise the editor is the same. */
export type StudioKind = "page" | "block";

import { ensureStudio, activeStudioKey } from "./flow";
import type { StudioKey } from "./studios";
import { clearDocEnd } from "./doc-end-state";
import {
  acquireLock,
  releaseLock,
  lockToken,
  markLockLost,
  type LockHolder,
} from "./page-lock";

/**
 * Thrown by the deep-link loaders when the schema endpoint refuses the node, so
 * callers can tell an access denial (403) / missing page (404) — which surface
 * the {@link ThreadEndState} pane — from a transient network error (swallowed,
 * as before, so a blip never strands the console). Carries the HTTP status.
 */
export class DocLoadError extends Error {
  constructor(public readonly status: number, message: string) {
    super(message);
    this.name = "DocLoadError";
  }
}

/** One editorial transition the current user may legally perform from the doc's
 *  current state — the source of truth for which workflow buttons the studio
 *  shows (read straight from content_moderation, never a hand-rolled map). */
export type Transition = { id: string; label: string; to: string; to_label: string };

/**
 * The editorial-state envelope every studio read/write returns (DECISIONS 0094).
 * `baseVid` pins the next write to the revision the studio is editing (optimistic
 * concurrency); `canEdit` is the surfaced result of the server's update-access
 * call on that exact revision (no parallel client policy). The studio renders its
 * action set, status badge, read-only mode and legibility notes from this.
 */
export type Moderation = {
  state: string;
  stateLabel: string;
  hasPendingDraft: boolean;
  canEdit: boolean;
  transitions: Transition[];
  baseVid: number | null;
};

/** A fresh, never-saved draft: editable, no node/revision yet (the first Publish
 *  creates the node), no server transitions until it exists. */
export const NEW_DRAFT: Moderation = {
  state: "draft",
  stateLabel: "Draft",
  hasPendingDraft: false,
  canEdit: true,
  transitions: [],
  baseVid: null,
};

/**
 * Thrown on an HTTP 409 from a save/publish/transition: the doc advanced past the
 * revision the studio loaded, so the write would clobber newer work. Carries the
 * server's current head vid so the studio can offer "Reload latest" (rebase).
 */
export class RevisionConflictError extends Error {
  constructor(public readonly currentVid: number | null) {
    super("This page changed since you opened it.");
    this.name = "RevisionConflictError";
  }
}

/**
 * Thrown on an HTTP 409 `lock_conflict` from a write: another session holds the
 * editor lock (a second tab or another user took the pen), so the fence rejected
 * the write. Distinct from {@link RevisionConflictError} — this isn't a stale
 * revision, it's a lost lock; the studio offers "take over" (re-acquire), not
 * "reload latest". Carries the current holder for the banner copy.
 */
export class LockConflictError extends Error {
  constructor(public readonly holder: LockHolder | null) {
    super("Another session is editing this page.");
    this.name = "LockConflictError";
  }
}

let current: PageSchema | null = null;
/**
 * The aincient_page node this draft edits, once it has one — set either by
 * loading an existing page (loadPageIntoStudio) or by the studio's first
 * Publish. A second Publish revisions this node instead of creating a duplicate.
 * Lives here (not in the studio component) so the deep-link entry point and the
 * datatable row-action can point the studio at an existing page before it mounts.
 */
let currentNode: string | null = null;
/**
 * Whether the studio is currently editing a full page or a reusable global
 * block. Drives which save/load endpoints the studio uses and which chrome it
 * shows. Owned here (not in the component) so the block picker can switch the
 * studio into block-authoring before it re-renders.
 */
let currentKind: StudioKind = "page";
/**
 * The live URL of the page this draft edits, once it has one — set by loading an
 * existing page or by Publish. null for an unsaved/new draft and for blocks
 * (a global block isn't a standalone viewable page). Drives the preview's
 * "Open ↗" link; lives here so the preview (a sibling of the studio) can read it.
 */
let currentUrl: string | null = null;
/**
 * The language this draft is being edited in: null = the source/default language
 * (the canonical page), a langcode = that translation. Drives which translation
 * Publish writes and whether the structural controls are locked (a symmetric
 * translation inherits the source layout, so it may only translate the words).
 */
let currentLang: string | null = null;
/** The loaded translation's layout mode ('symmetric'|'asymmetric'), or null for
 *  the source language / a monolingual page. 'symmetric' ⇒ structure is inherited
 *  (locked here); 'asymmetric' ⇒ this language owns its layout. */
let currentMode: string | null = null;
/** The non-source langcodes this page already has a translation for. */
let currentTranslations: string[] = [];
/**
 * The editorial-state of the doc the studio currently edits. Seeded from
 * {@link loadPageIntoStudio}/{@link loadBlockIntoStudio}, reset to {@link NEW_DRAFT}
 * by New, and replaced by the envelope every save/publish/transition returns.
 * Owned here (not the component) so the deep-link entry point seeds it before the
 * studio mounts and the write helpers below can update it from one place.
 */
let currentModeration: Moderation = { ...NEW_DRAFT };
/**
 * Whether the user is DELIBERATELY authoring a fresh doc (clicked "New page"/
 * "New block") vs just sitting on an idle, nothing-open studio. Both states have
 * `currentNode === null` and an empty draft, so this flag is what lets the
 * Content preview tell them apart: idle (false) → show the content browser to
 * pick/start something; authoring (true) → show the "build it" placeholder so a
 * deliberate New isn't buried under a directory. Set true by startNew*, cleared
 * by loadPage/BlockIntoStudio; flips inside the same calls that already notify
 * the node/load subscribers, so the preview re-evaluates for free.
 */
let authoringNew = false;
/**
 * The section slot-id the user last clicked in the live preview (click-to-focus),
 * or null. It's a transient UI signal, not part of the draft: the preview paints
 * a selection outline on the matching band and the studio expands + scrolls that
 * section's card into view. Cleared whenever the open document changes. Every set
 * re-emits (even to the same id) so a repeat click re-runs the scroll.
 */
let selectedSection: string | null = null;
const subscribers = new Set<(schema: PageSchema | null) => void>();
const selectSubscribers = new Set<(id: string | null) => void>();
const reloadSubscribers = new Set<() => void>();
const loadSubscribers = new Set<(node: string | null) => void>();
const nodeSubscribers = new Set<() => void>();
const moderationSubscribers = new Set<() => void>();

function emit(): void {
  for (const cb of subscribers) cb(current);
}

/**
 * Notify "the open-document IDENTITY changed" — a load, a New, or the first
 * Publish minting a node id. Distinct from {@link loadSubscribers} (which tells
 * the studio to reset its baseline): this one is for url-sync, which reflects
 * the open node into the URL as ?page=/?block=, so it must also fire on Publish
 * (setPageNode), where the baseline reset must NOT.
 */
function emitNode(): void {
  for (const cb of nodeSubscribers) cb();
}

function emitModeration(): void {
  for (const cb of moderationSubscribers) cb();
}

/** The current editorial-state envelope (read by the studio's action set + badge). */
export function getModeration(): Moderation {
  return currentModeration;
}

/** Replace the editorial-state envelope and notify the studio. */
function setModeration(m: Moderation): void {
  currentModeration = m;
  emitModeration();
}

/** Subscribe to editorial-state changes (load / New / save / publish / transition);
 *  returns an unsubscribe fn. */
export function subscribeModeration(cb: () => void): () => void {
  moderationSubscribers.add(cb);
  return () => {
    moderationSubscribers.delete(cb);
  };
}

/** Parse a server envelope into a {@link Moderation} (forgiving defaults so a thin
 *  legacy response still yields an editable draft). */
function readModeration(data: unknown): Moderation {
  const d = (data ?? {}) as Record<string, unknown>;
  return {
    state: typeof d.moderation_state === "string" ? d.moderation_state : "draft",
    stateLabel: typeof d.state_label === "string" ? d.state_label : "Draft",
    hasPendingDraft: d.has_pending_draft === true,
    canEdit: d.can_edit !== false,
    transitions: Array.isArray(d.transitions) ? (d.transitions as Transition[]) : [],
    baseVid: typeof d.base_vid === "number" ? d.base_vid : null,
  };
}

/** Replace the working draft and notify the preview + studio. */
export function setPageDraft(schema: PageSchema | null): void {
  current = schema;
  emit();
}

/** The current working draft (read by the adapter context + the preview tool). */
export function getPageDraft(): PageSchema | null {
  return current;
}

/** Drop the draft entirely (revert the preview to nothing — Discard on a fresh page). */
export function resetPageDraft(): void {
  current = null;
  currentLang = null;
  currentMode = null;
  emit();
}

/** Whether the studio is editing a page or a global block. */
export function getPageKind(): StudioKind {
  return currentKind;
}

/**
 * Start a fresh, empty page in the studio: a blank landing draft with no node
 * identity yet (the first Publish creates the aincient_page node). Switches the
 * console to the Page studio. Used by the "New" action — which opens it in a new
 * browser tab, so each unsaved draft lives in its own independent JS context.
 */
export function startNewPage(): void {
  authoringNew = true;
  currentKind = "page";
  currentNode = null;
  currentUrl = null;
  currentLang = null;
  currentMode = null;
  currentTranslations = [];
  current = { ...EMPTY_PAGE, sections: [] };
  setModeration({ ...NEW_DRAFT });
  for (const cb of loadSubscribers) cb(currentNode);
  emitNode();
  emit();
  ensureStudio("content");
  // A fresh draft holds no node yet, so no lock to fence — release any prior
  // page's lock and clear local state (the first save acquires the new one).
  void releaseLock();
}

/**
 * Start a fresh global block in the studio (block-authoring mode): a blank
 * sections-only fragment with no node identity yet. The first Publish creates
 * the aincient_block node. Switches the console to the (shared) Page studio.
 */
export function startNewBlock(): void {
  authoringNew = true;
  currentKind = "block";
  currentNode = null;
  currentUrl = null;
  currentLang = null;
  currentMode = null;
  currentTranslations = [];
  current = { ...EMPTY_BLOCK, sections: [] };
  setModeration({ ...NEW_DRAFT });
  for (const cb of loadSubscribers) cb(currentNode);
  emitNode();
  emit();
  ensureStudio("content");
  // Release any prior page lock (blocks aren't lock-scoped in v1).
  void releaseLock();
}

/**
 * Close the open document WITHOUT leaving the studio: drop the node identity and
 * draft back to the idle state so the preview shows the content browser (the
 * "back to the listing" step of the studio's X — distinct from {@link startNewPage},
 * which is a deliberate New and keeps the build placeholder). Unlike New it sets
 * {@link authoringNew} FALSE, which is what surfaces the directory. Stays on the
 * current studio (no {@link ensureStudio}); url-sync drops ?page=/?block= when
 * the node clears.
 */
export function closeDocToListing(): void {
  // Nothing open (idle studio, no node, not deliberately authoring) → no-op. The
  // console machine's close-on-leave (commitSwitch) may call this on any switch
  // that isn't a node/audit room; without this guard it would reset state + fire
  // every subscriber + releaseLock on studio→studio hops that never had a doc.
  if (currentNode === null && !authoringNew) return;
  authoringNew = false;
  currentNode = null;
  currentUrl = null;
  currentLang = null;
  currentMode = null;
  currentTranslations = [];
  current = currentKind === "block" ? { ...EMPTY_BLOCK, sections: [] } : { ...EMPTY_PAGE, sections: [] };
  setModeration({ ...NEW_DRAFT });
  for (const cb of loadSubscribers) cb(currentNode);
  emitNode();
  emit();
  // Closing the doc releases its editor lock (clean exit — the pen is free for
  // another session). Fire-and-forget; local lock state clears regardless.
  void releaseLock();
}

/**
 * Open an existing global block in the studio for editing — the parallel of
 * loadPageIntoStudio for the `aincient_block` bundle. Editing + Publishing the
 * block updates every page that references it.
 */
export async function loadBlockIntoStudio(node: string): Promise<void> {
  const res = await fetch(`/aincient/block/${encodeURIComponent(node)}/schema`, {
    credentials: "same-origin",
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.schema) {
    throw new DocLoadError(res.status, data?.error ?? `Couldn’t load block ${node} (HTTP ${res.status}).`);
  }
  // Loaded — any prior deep-link dead-end no longer applies.
  clearDocEnd();
  authoringNew = false;
  currentKind = "block";
  currentNode = String(data.node_id ?? node);
  // A global block has no standalone page to open.
  currentUrl = null;
  current = data.schema as PageSchema;
  currentLang = null;
  currentMode = null;
  currentTranslations = [];
  setModeration(readModeration(data));
  for (const cb of loadSubscribers) cb(currentNode);
  emitNode();
  emit();
  ensureStudio("content");
}

/** The language the draft is edited in (null = source/default). */
export function getPageLang(): string | null {
  return currentLang;
}

/** The loaded translation's layout mode ('symmetric'|'asymmetric'|null=source). */
export function getPageMode(): string | null {
  return currentMode;
}

/** The non-source langcodes this page already has translations for. */
export function getPageTranslations(): string[] {
  return currentTranslations;
}

/** The node this draft edits, or null for an unsaved/new page. */
export function getPageNode(): string | null {
  return currentNode;
}

/** Whether the user deliberately started a fresh doc (vs an idle studio with
 *  nothing open). {@link authoringNew} — the Content preview reads this to choose
 *  the "build it" placeholder over the content browser. */
export function getAuthoringNew(): boolean {
  return authoringNew;
}

/**
 * Remember the node this draft edits (no preview repaint — identity only).
 * Called by the studio after a first Publish so the next Publish revisions it.
 */
export function setPageNode(node: string | null): void {
  if (node === currentNode) return;
  currentNode = node;
  emitNode();
}

/** The live URL of the page this draft edits, or null (unsaved/new, or a block). */
export function getPageUrl(): string | null {
  return currentUrl;
}

/** Remember the live URL of the page this draft edits (the preview's "Open ↗"). */
export function setPageUrl(url: string | null): void {
  currentUrl = url || null;
}

/** Subscribe to "an existing page was loaded" — the studio resets its baseline
 *  to the loaded schema so it opens clean (not dirty). Returns an unsubscribe fn. */
export function subscribePageLoad(cb: (node: string | null) => void): () => void {
  loadSubscribers.add(cb);
  return () => {
    loadSubscribers.delete(cb);
  };
}

/** Subscribe to open-document identity changes (load / New / first Publish) —
 *  url-sync uses this to keep ?page=/?block= in the URL in sync. Returns an
 *  unsubscribe fn. */
export function subscribePageNode(cb: () => void): () => void {
  nodeSubscribers.add(cb);
  return () => {
    nodeSubscribers.delete(cb);
  };
}

/**
 * Open an existing page in the page studio: fetch its stored schema, seed it as
 * the working draft + node identity, and switch to the Page studio. The single
 * "edit this page" entry point — the deep-link (`?page=<nid>`) and the datatable
 * row-action both call this. The studio then drives the agent/preview loop
 * against the loaded page; Publish revisions the same node.
 *
 * Sets store state BEFORE switching studio so a not-yet-mounted PageStudio reads
 * the loaded page in its initializers (opens clean); an already-mounted one is
 * told via the load subscription to reset its baseline.
 *
 * `studio` is which workspace owns the loaded draft — "content" (the editor,
 * default) or "checks" (the fix-loop preview). It drives both the workspace
 * switch and the editor-lock's `studio` provenance, so the shared page-state
 * draft is single-writer-safe across the two studios (Plan A lock).
 */
export async function loadPageIntoStudio(node: string, langcode?: string | null, studio: StudioKey = "content"): Promise<void> {
  const qs = langcode ? `?langcode=${encodeURIComponent(langcode)}` : "";
  const res = await fetch(`/aincient/page/${encodeURIComponent(node)}/schema${qs}`, {
    credentials: "same-origin",
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.schema) {
    throw new DocLoadError(res.status, data?.error ?? `Couldn’t load page ${node} (HTTP ${res.status}).`);
  }
  // Loaded — any prior deep-link dead-end no longer applies.
  clearDocEnd();
  authoringNew = false;
  currentKind = "page";
  currentNode = String(data.node_id ?? node);
  currentUrl = typeof data.url === "string" && data.url ? (data.url as string) : null;
  current = data.schema as PageSchema;
  currentLang = langcode ?? null;
  currentMode = (data.layout_mode as string | null) ?? null;
  currentTranslations = Array.isArray(data.translations) ? (data.translations as string[]) : [];
  setModeration(readModeration(data));
  for (const cb of loadSubscribers) cb(currentNode);
  emitNode();
  emit();
  ensureStudio(studio);
  // Take the single-writer editor lock for this page (dropping any prior page's
  // lock first). A held/denied result doesn't block loading — it drives the
  // studio's take-over banner; read-only users simply hold no token. Page kind
  // only (the lock is aincient_page-scoped for v1).
  await releaseLock();
  await acquireLock(currentNode, currentLang, studio).catch(() => undefined);
}

/**
 * Flip a translation's layout mode (copy-on-write diverge / re-inherit converge),
 * then reload it so the editor reflects the new structure + mode. Returns the
 * resulting mode. The source language has no mode and can't be flipped.
 */
export async function setTranslationMode(
  node: string,
  langcode: string,
  mode: "asymmetric" | "symmetric",
): Promise<void> {
  const verb = mode === "asymmetric" ? "diverge" : "converge";
  const res = await fetch(`/aincient/page/${encodeURIComponent(node)}/${verb}`, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ langcode }),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    throw new Error(data?.error ?? `Couldn’t ${verb} ${langcode} (HTTP ${res.status}).`);
  }
  await loadPageIntoStudio(node, langcode);
}

/* ---------------------------------------------------- editorial writes */

/** The studio's API base for the current kind (page vs reusable global block). */
function apiBase(kind: StudioKind): string {
  return kind === "block" ? "/aincient/block" : "/aincient/page";
}

/**
 * POST a studio write (save / publish / transition) and parse the state envelope.
 * Throws {@link RevisionConflictError} on 409 (stale base revision) so the studio
 * can offer "Reload latest" instead of blind-retrying, and a plain Error on any
 * other failure (the message is the server's where it sent one).
 */
async function writeRequest(path: string, body: Record<string, unknown>): Promise<Record<string, unknown>> {
  const res = await fetch(path, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = (await res.json().catch(() => null)) as Record<string, unknown> | null;
  if (res.status === 409 && data?.lock_conflict === true) {
    // Lost the editor lock (a second tab / another user took over) — record it
    // so the studio drops to a take-over prompt rather than a rebase offer.
    const holder = (data.holder as LockHolder | null) ?? null;
    markLockLost(holder);
    throw new LockConflictError(holder);
  }
  if (res.status === 409) {
    throw new RevisionConflictError(typeof data?.current_vid === "number" ? data.current_vid : null);
  }
  if (!res.ok) {
    throw new Error((data?.error as string) ?? `HTTP ${res.status}`);
  }
  return data ?? {};
}

/** Fold a write's returned envelope back into the shared store: track the (maybe
 *  newly-minted) node id + live url and replace the editorial state. Reflects the
 *  node into the URL only when it actually changed (first publish mints it). */
function applyWriteResult(data: Record<string, unknown>): void {
  const node = data.node_id != null ? String(data.node_id) : null;
  const nodeChanged = node !== null && node !== currentNode;
  if (node !== null) currentNode = node;
  if (typeof data.url === "string") currentUrl = data.url || null;
  setModeration(readModeration(data));
  if (nodeChanged) emitNode();
}

/** The base revision the next write pins to (optimistic concurrency), as a body
 *  fragment — absent for a never-saved draft (the create path skips the check). */
function baseVidArg(): Record<string, number> {
  return currentModeration.baseVid != null ? { base_vid: currentModeration.baseVid } : {};
}

/**
 * The lock + provenance fragment every write carries: the fencing `token` (so
 * the server can confirm we still hold the pen — omitted for a never-locked new
 * draft) and the `studio` this save happens in (stamped as a revision co-author
 * for provenance). The agent + thread co-authors are added by the caller when an
 * agent produced the change.
 */
function writeMeta(): Record<string, unknown> {
  const token = lockToken();
  return {
    ...(token ? { token } : {}),
    studio: activeStudioKey(),
  };
}

/**
 * Save the working schema as a forward DRAFT — the decoupled "Update": create a
 * new draft node (no id yet) or write a new revision of the editable head WITHOUT
 * going live. Returns the post-write envelope.
 */
export async function saveDraft(
  schema: PageSchema,
  kind: StudioKind,
  node: string | null,
  langcode: string | null,
): Promise<Record<string, unknown>> {
  const data = await writeRequest(`${apiBase(kind)}/save`, {
    schema,
    ...(node ? { node_id: node } : {}),
    ...(langcode ? { langcode } : {}),
    ...baseVidArg(),
    ...writeMeta(),
  });
  applyWriteResult(data);
  // A brand-new page just minted its node — acquire its lock so subsequent saves
  // hold the pen (the create path had no lock to fence). Page kind only (the
  // lock + co-authors are aincient_page-scoped for v1).
  if (!node && kind === "page" && typeof data.node_id !== "undefined" && data.node_id !== null) {
    await acquireLock(String(data.node_id), langcode, activeStudioKey()).catch(() => undefined);
  }
  return data;
}

/**
 * Mint a brand-new draft page from the `+` birth form (studio-navigation.md §3.2).
 *
 * A DELIBERATE create: the node is born with a title + type (the composition
 * contract — landing-only for v1, but the `type` is the forward-compatible seam
 * a template resolves from later) so there is no such thing as a stub. Kept
 * isolated from the studio's own {@link saveDraft} on purpose — it sends ONLY the
 * birth fields, never the currently-open page's baseVid/meta (which saveDraft
 * folds in), so opening the form over another page can't bleed that page's state
 * into the new node, and it doesn't mutate the shared draft store. The caller
 * navigates to the new Node room; the load path ({@link loadPageIntoStudio})
 * hydrates page-state and takes the editor lock.
 *
 * @returns the new node's id.
 */
export async function createPage(
  title: string,
  type: string,
  langcode: string | null,
): Promise<string> {
  const data = await writeRequest(`${apiBase("page")}/save`, {
    schema: { type, title, sections: [] },
    ...(langcode ? { langcode } : {}),
  });
  if (data.node_id == null) {
    throw new Error((data.error as string) ?? "The page could not be created.");
  }
  return String(data.node_id);
}

/**
 * Write the latest schema (when given) then PUBLISH — the one-click save+go-live.
 * Requires an existing node id (a brand-new page is saved as a draft first). The
 * server validates the publish/approve transition the user actually holds.
 */
export async function publishDoc(
  schema: PageSchema | null,
  kind: StudioKind,
  node: string,
  langcode: string | null,
): Promise<Record<string, unknown>> {
  const data = await writeRequest(`${apiBase(kind)}/publish`, {
    node_id: node,
    ...(schema ? { schema } : {}),
    ...(langcode ? { langcode } : {}),
    ...baseVidArg(),
    ...writeMeta(),
  });
  applyWriteResult(data);
  return data;
}

/** The transition id → endpoint suffix map (publish is its own save+go-live path;
 *  create_new_draft is the Save-draft button, so neither is a pure transition). */
const TRANSITION_PATHS: Record<string, string> = {
  submit_for_review: "submit-review",
  approve: "approve",
  reject: "reject",
  archive: "archive",
  restore: "restore",
};

/**
 * Apply a pure editorial transition (submit-for-review / approve / reject /
 * archive / restore) — a state change on a new revision, no schema write. Returns
 * the post-transition envelope.
 */
export async function runTransition(
  transitionId: string,
  kind: StudioKind,
  node: string,
): Promise<Record<string, unknown>> {
  const suffix = TRANSITION_PATHS[transitionId];
  if (!suffix) throw new Error(`Unsupported transition “${transitionId}”.`);
  const data = await writeRequest(`${apiBase(kind)}/${suffix}`, {
    node_id: node,
    ...baseVidArg(),
    // Studio provenance (a transition isn't lock-fenced — a reviewer needn't
    // hold the pen — so no token, just the co-author studio).
    studio: activeStudioKey(),
  });
  applyWriteResult(data);
  return data;
}

/* -------------------------------------------------- preview click-to-focus */

/** The section slot-id currently selected in the preview (null = none). */
export function getSelectedSection(): string | null {
  return selectedSection;
}

/**
 * Select a section from the preview (click-to-focus) — or clear it (null). The
 * preview outlines the matching band and the studio opens + scrolls that card.
 * Always emits, even for the same id, so clicking an already-open section
 * re-runs the scroll-into-view rather than being swallowed as a no-op.
 */
export function setSelectedSection(id: string | null): void {
  selectedSection = id;
  for (const cb of selectSubscribers) cb(id);
}

/** Subscribe to preview selection changes; returns an unsubscribe fn. */
export function subscribeSelectedSection(cb: (id: string | null) => void): () => void {
  selectSubscribers.add(cb);
  return () => {
    selectSubscribers.delete(cb);
  };
}

/** Subscribe to draft changes; returns an unsubscribe fn. */
export function subscribePageDraft(cb: (schema: PageSchema | null) => void): () => void {
  subscribers.add(cb);
  return () => {
    subscribers.delete(cb);
  };
}

/**
 * Ask the live preview to re-render the current draft. Fired after a successful
 * Publish: the draft has just become the saved page, so there's no visual change,
 * but re-rendering keeps the preview authoritative (and mirrors brand's reload
 * contract so the two studios behave the same).
 */
export function reloadPreview(): void {
  for (const cb of reloadSubscribers) cb();
}

/** Subscribe to preview-reload requests; returns an unsubscribe fn. */
export function subscribePreviewReload(cb: () => void): () => void {
  reloadSubscribers.add(cb);
  return () => {
    reloadSubscribers.delete(cb);
  };
}
