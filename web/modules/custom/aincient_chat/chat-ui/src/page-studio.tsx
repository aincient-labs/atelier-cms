import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { ReactNode } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { offerWrapup, isThreadSealed, subscribeSeals } from "./thread-seal";
import {
  getPageDraft,
  setPageDraft,
  subscribePageDraft,
  subscribePageLoad,
  subscribeSelectedSection,
  setSelectedSection,
  getPageNode,
  setPageNode,
  setPageUrl,
  getPageLang,
  getPageMode,
  getPageTranslations,
  loadPageIntoStudio,
  loadBlockIntoStudio,
  getAuthoringNew,
  setTranslationMode,
  reloadPreview,
  getPageKind,
  getModeration,
  subscribeModeration,
  saveDraft,
  publishDoc,
  runTransition,
  RevisionConflictError,
  LockConflictError,
  EMPTY_PAGE,
  type PageSchema,
  type PageSection,
  type StudioKind,
  type Moderation,
  type Transition,
} from "./page-state";
import { subscribeLock, lockState, acquireLock, type LockHolder } from "./page-lock";
import { consoleNav } from "./console-nav";
import { setPageDirty } from "./page-dirty";
import { useFacet, setFacet, resetFacet } from "./page-facet";
import { SeoMetaGroup } from "./seo-meta-group";
import { TeaserGroup } from "./teaser-group";
import { consoleBase } from "./console-url";
import { pageDeepLink, isStudioAccessible } from "./studios";
import { activeStudioKey } from "./flow";
import { CheckIcon, XIcon, PlusIcon, ChevronDownIcon, GripIcon, MoreHorizontalIcon, ShieldCheckIcon } from "./icons";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { ReferenceField } from "./reference-field";
import { openBlockTab } from "./block-nav";
import { PanelBar } from "./panel-bar";
import { FieldRevert } from "./field-revert";

/** One site language (GET /aincient/page/manifest → translation.languages). */
type Lang = { id: string; label: string; default: boolean };

/** The structural prop names that travel with the slot (shared across languages).
 *  Mirrors PageSchemaCodec::STRUCTURAL_PROPS — a symmetric translation inherits
 *  these from the source, so the studio locks them when editing one. */
const STRUCTURAL_PROPS = new Set(["tone", "variant", "columns"]);

/** One prop of a section, as returned by /aincient/page/manifest. */
type PropDef = {
  name: string;
  meaning: string;
  /** Enumerated values (tone/variant/columns) — render a select. */
  enum?: string[];
  /** A repeatable row shape, e.g. "[{value,label}]" — render a rows editor. */
  shape?: string;
  /** This prop holds an image (a media:<id> token) — render a media picker. */
  image?: boolean;
  /** This prop holds an embed token (entity:<…>) — render an embed picker. */
  embed?: boolean;
  /** This prop holds a global-block id — render a block picker. */
  block?: boolean;
  /** This prop holds long-form text (Markdown source) — render a textarea. */
  multiline?: boolean;
  /** This prop is a typed boolean — render a checkbox, not a text input. */
  boolean?: boolean;
  /** This prop is a nested panels list (accordion) — two levels deep, so it
   *  gets a dedicated panels editor (label + open + per-block component picker
   *  and props sub-form) instead of the flat rows editor. */
  panels?: boolean;
};

/** One placeable section component + its prop schema. */
type SectionDef = { component: string; use: string; props: PropDef[] };

type Manifest = {
  sections: SectionDef[];
  /** Reference placeables (embed / block) — merged into the editable palette. */
  reference?: SectionDef[];
  tones: string[];
  hero_variants: string[];
  prop_vocab: Record<string, string>;
  /** Prop / row-field names that hold an image (render a media picker). */
  image_props: string[];
  /** The bounded child allow-list for accordion panels — the components the
   *  panels editor offers per block (their prop schemas come from `sections`). */
  accordion_blocks?: string[];
  /** Translation bootstrap: site languages + governance flags. */
  translation: { languages: Lang[]; multilingual: boolean; allow_divergence: boolean };
};

const MANIFEST_URL = "/aincient/page/manifest";

/** Transitions handled by dedicated primary buttons / the Save-draft button, so
 *  they're not rendered again as generic secondary transition buttons. */
const PRIMARY_TRANSITIONS = new Set(["publish", "approve", "create_new_draft"]);

/** The transient success line shown after a pure editorial transition. */
const TRANSITION_NOTICE: Record<string, string> = {
  submit_for_review: "Sent for review",
  reject: "Sent back to draft",
  archive: "Archived — taken off the live site",
  restore: "Restored to draft",
};

/** A compact, stable identity for dirty-detection (key order is fixed by us). */
const snapshot = (s: PageSchema | null): string => JSON.stringify(s ?? EMPTY_PAGE);

/** A stable slot id for a studio-added section. Matches PageStore::slotId's
 *  `[a-z0-9]{4,32}` shape, so the server PRESERVES it on save rather than
 *  minting a fresh one — which keeps a section's identity stable across a
 *  Publish/reload. That stable id is what the per-field diff matches draft
 *  sections to their saved baseline by (id, not array position), so the markers
 *  survive add/remove/reorder. */
function slotUid(): string {
  const buf = new Uint8Array(4);
  (globalThis.crypto ?? window.crypto).getRandomValues(buf);
  return Array.from(buf, (b) => b.toString(16).padStart(2, "0")).join("");
}

/** Order-insensitive deep equality for prop values (strings, numbers, repeatable
 *  row arrays). Used by the per-field diff so a row whose keys serialise in a
 *  different order than the saved baseline isn't reported as a phantom change. */
function deepEq(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (typeof a !== "object" || typeof b !== "object" || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a)) {
    const bb = b as unknown[];
    return a.length === bb.length && a.every((x, i) => deepEq(x, bb[i]));
  }
  const ao = a as Record<string, unknown>;
  const bo = b as Record<string, unknown>;
  const ak = Object.keys(ao);
  return ak.length === Object.keys(bo).length && ak.every((k) => k in bo && deepEq(ao[k], bo[k]));
}

/** Per-section dirty verdict relative to the saved baseline, aligned to the
 *  draft's section order: `changed` names the props whose value differs. A
 *  section with no saved counterpart (added this session) diffs against empty,
 *  so each populated prop simply lands in `changed` — the same count-badge +
 *  per-field-marker path a modified section takes (no separate "new" state). */
type SectionDiff = { changed: Set<string> };

/** The field names inside a repeatable shape: "[{icon,title,body}]" → [icon,title,body]. */
function shapeFields(shape: string): string[] {
  const m = /\{([^}]*)\}/.exec(shape);
  return m ? m[1].split(",").map((s) => s.trim()).filter(Boolean) : [];
}

/** Title-case a snake/identifier for a human label ("cta_label" → "Cta label"). */
function humanize(name: string): string {
  const s = name.replace(/_/g, " ");
  return s.charAt(0).toUpperCase() + s.slice(1);
}

/** A type glyph per placeable, so a collapsed section reads at a glance. Falls
 *  back to a neutral block mark for anything not mapped (forward-compatible). */
const SECTION_ICONS: Record<string, string> = {
  hero: "◆", banner: "▬", logos: "▤", stats: "▦", features: "⊞",
  content: "¶", gallery: "▤", testimonials: "❝", team: "☻", pricing: "$",
  faq: "?", newsletter: "✉", cta: "◈", divider: "—", embed: "⧉", block: "▣",
};
const sectionIcon = (component: string): string => SECTION_ICONS[component] ?? "▢";

/** The props worth showing as a one-line summary of a collapsed section, in
 *  priority order — the first non-empty string wins. */
const SUMMARY_PROPS = ["heading", "title", "label", "quote", "name", "eyebrow", "body"];

/** A short, human summary of a section's content for its collapsed header. Uses
 *  the most title-like text prop; failing that, the count of repeatable rows. */
function summarize(section: PageSection, props: PropDef[]): string {
  for (const key of SUMMARY_PROPS) {
    const v = section.props[key];
    if (typeof v === "string" && v.trim()) {
      const t = v.trim();
      return t.length > 52 ? `${t.slice(0, 51)}…` : t;
    }
  }
  // No headline copy yet — fall back to the first repeatable prop's row count
  // (a flat rows shape or a nested panels list).
  const rows = props.find((p) => (p.shape || p.panels) && Array.isArray(section.props[p.name]));
  if (rows) {
    const n = (section.props[rows.name] as unknown[]).length;
    if (n) return `${n} ${humanize(rows.name).toLowerCase()}`;
  }
  return "";
}

/**
 * The page studio rail: a structured section editor over the working draft.
 *
 * The page parallel of BrandStudio. The shared page-state store is the single
 * source of truth — the agent's `preview_page` ops and the controls here both
 * write to it, and the live preview iframe re-renders from it. Everything is a
 * DRAFT: edits only repaint the preview. The one deliberate write is Publish,
 * which POSTs the whole schema to /aincient/page/save (create a new page node,
 * or a new revision of the one already published this session). Discard reverts
 * to the last saved baseline.
 */
export function PageStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  // The runtime is read only to resolve the active thread's backend id when a
  // a Publish/Approve offers to wrap the conversation up (see commitPublish()).
  const runtime = useAssistantRuntime();
  const [manifest, setManifest] = useState<Manifest | null>(null);
  const [draft, setDraftState] = useState<PageSchema>(() => getPageDraft() ?? EMPTY_PAGE);
  // The last-saved schema snapshot the draft is diffed against for "dirty". When
  // an existing page was loaded before mount, that loaded schema IS the saved
  // baseline (so the studio opens clean, not dirty); otherwise the empty page.
  const [baseline, setBaseline] = useState<string>(() => snapshot(getPageDraft() ?? EMPTY_PAGE));
  // The node this session is editing — set by loading an existing page or by the
  // first Publish, so a second Publish revisions it instead of creating a
  // duplicate. Owned by the page-state store (loadPageIntoStudio can set it
  // before this component mounts); mirrored here for rendering.
  const [nodeId, setNodeIdState] = useState<string | null>(() => getPageNode());
  // Whether this session edits a full page or a reusable global block (the block
  // picker can switch the studio into block-authoring). Mirrored from the store.
  const [kind, setKind] = useState<StudioKind>(() => getPageKind());
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ text: string; url?: string } | null>(null);
  // `publishing` doubles as the generic "a write is in flight" flag — it disables
  // every action (save / publish / transition) while one runs.
  const [publishing, setPublishing] = useState(false);
  // The editorial-state envelope for the open doc (state + label + legal
  // transitions + pending-draft flag + base_vid), mirrored from the page-state
  // store. Drives the action set, status badge, read-only mode and legibility.
  const [moderation, setModerationState] = useState<Moderation>(() => getModeration());
  // Whether the active thread is wrapped up (DECISIONS 0091) — a SECOND read-only
  // axis orthogonal to node access: a sealed thread freezes the studio even when
  // the user still holds update access on the node.
  const [sealed, setSealed] = useState(false);
  // A stale-write conflict (HTTP 409): the page advanced since we loaded it, so a
  // save/publish would clobber newer work. Holds the server's current head vid;
  // the only way forward is Reload latest (rebase) — never a blind retry.
  const [conflict, setConflict] = useState<{ currentVid: number | null } | null>(null);
  // The single-writer editor lock (page-lock.ts): who holds the pen for this
  // page. When another session holds it (a second tab or another user), the
  // studio shows a take-over banner and writes are fenced server-side. Mirrored
  // from the lock store so the banner reacts to acquire / lost / release.
  const [lockHeldBy, setLockHeldBy] = useState<LockHolder | null>(() => {
    const s = lockState();
    return s.token === null ? s.holder : null;
  });
  const [takingOver, setTakingOver] = useState(false);
  // Open when a discard would clear the whole page (no published baseline yet),
  // so the user confirms before the agent's/their work disappears.
  const [confirmingDiscard, setConfirmingDiscard] = useState(false);
  // The X steps back to the listing while a doc is open; this guards that step
  // behind a confirm when there are unsaved edits to lose.
  const [confirmingBack, setConfirmingBack] = useState(false);
  // Translation state: the language this draft edits (null = source), its layout
  // mode, the page's existing translations, the language-switcher open flag, and
  // a language awaiting a switch-while-dirty confirmation. Mirrored from the
  // page-state store (which loadPageIntoStudio populates) for rendering.
  const [lang, setLang] = useState<string | null>(() => getPageLang());
  const [mode, setMode] = useState<string | null>(() => getPageMode());
  const [translations, setTranslations] = useState<string[]>(() => getPageTranslations());
  const [switchingLang, setSwitchingLang] = useState(false);
  // A staged language switch awaiting a dirty confirm. `{ to }` wraps the target
  // so the source (to: null) is distinguishable from "nothing pending" (null).
  const [pendingLang, setPendingLang] = useState<{ to: string | null } | null>(null);
  // Which section cards are expanded (by index). The rail opens as a scannable
  // outline — every section collapsed to a one-line summary — and a card opens
  // for editing on click. Add auto-expands the new card; reorder/remove keep the
  // open flag pinned to its content by re-indexing the set alongside the move.
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set());
  // Which insertion point is showing the component picker (the index a new
  // section would land at: 0 = top, n = after section n-1, sections.length =
  // end). null = no picker open. Only one is open at a time.
  const [insertAt, setInsertAt] = useState<number | null>(null);
  const toggleSection = useCallback(
    (index: number) =>
      setExpanded((prev) => {
        const next = new Set(prev);
        next.has(index) ? next.delete(index) : next.add(index);
        return next;
      }),
    [],
  );
  // The rail root — scopes the click-to-focus scroll query to THIS studio's cards.
  const railRef = useRef<HTMLDivElement>(null);

  // Body vs Presence facet (pages only). Swaps this rail's editor AND the centre
  // preview (both read the shared page-facet store).
  const facet = useFacet();

  // Seed the store if it's empty so the preview + adapter context have a draft,
  // then mirror the store into local state (the store is the single source).
  useEffect(() => {
    if (getPageDraft() === null) setPageDraft(EMPTY_PAGE);
    else setDraftState(getPageDraft()!);
    return subscribePageDraft((s) => setDraftState(s ?? EMPTY_PAGE));
  }, []);

  // An existing page was loaded into the studio (deep-link or datatable action)
  // while we're already mounted: adopt it as the clean saved baseline so it opens
  // un-dirty, mirror the node, and clear any stale notice/confirm. (On a cold
  // open the initializers above already pick this up from the store.)
  useEffect(
    () =>
      subscribePageLoad((node) => {
        setNodeIdState(node);
        setKind(getPageKind());
        setBaseline(snapshot(getPageDraft() ?? EMPTY_PAGE));
        // Mirror the loaded translation's language/mode/existing-translations.
        setLang(getPageLang());
        setMode(getPageMode());
        setTranslations(getPageTranslations());
        setNotice(null);
        setError(null);
        setConfirmingDiscard(false);
        // A fresh load lands on a fresh revision → any stale-write conflict clears.
        setConflict(null);
        // A different document's slot ids don't apply — drop any preview selection.
        setSelectedSection(null);
        // A freshly loaded page always opens on its body facet.
        resetFacet();
      }),
    [],
  );

  // Click-to-focus: a click in the live preview selects its section — open that
  // card (add, never toggle, so a repeat click keeps it open) and scroll it into
  // view once the expand has laid out. Matches by stable slot id, so it's robust
  // to reorder. An id we don't hold (a spliced block, a stale frame) is ignored.
  useEffect(
    () =>
      subscribeSelectedSection((id) => {
        if (!id) return;
        const index = (getPageDraft()?.sections ?? []).findIndex((s) => s.id === id);
        if (index < 0) return;
        setExpanded((prev) => (prev.has(index) ? prev : new Set(prev).add(index)));
        requestAnimationFrame(() => {
          railRef.current
            ?.querySelector(`[data-ain-sec-id="${CSS.escape(id)}"]`)
            ?.scrollIntoView({ block: "nearest", behavior: "smooth" });
        });
      }),
    [],
  );

  // Mirror the shared editorial-state envelope (load / New / every write updates
  // it in the store; we re-render the action set + badge from it).
  useEffect(() => subscribeModeration(() => setModerationState(getModeration())), []);
  // Track the editor lock: surface the holder whenever we DON'T hold the token
  // (another session took the pen, or we lost it on a fenced write); clear the
  // banner once we hold it again.
  useEffect(
    () =>
      subscribeLock(() => {
        const s = lockState();
        setLockHeldBy(s.token === null ? s.holder : null);
      }),
    [],
  );

  // Track the active thread's seal state (the second read-only axis). Recomputed
  // whenever any thread's seal flips; the studio goes read-only the instant the
  // current conversation is wrapped up.
  useEffect(() => {
    const sync = () => setSealed(isThreadSealed(runtime.threads.mainItem.getState().remoteId));
    sync();
    return subscribeSeals(sync);
  }, [runtime]);

  // Load the section/prop catalog the editor renders from.
  useEffect(() => {
    let live = true;
    fetch(MANIFEST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: Manifest) => live && setManifest(data))
      .catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, []);

  const sections = draft.sections ?? [];
  const dirty = snapshot(draft) !== baseline;
  // Publish the dirty verdict to the shell so a thread switch can guard against
  // dropping these unsaved edits (the switch fires outside the studio — see
  // page-dirty.ts). Reset to false on unmount so a stale `true` can't block a
  // later, unrelated switch.
  useEffect(() => {
    setPageDirty(dirty);
  }, [dirty]);
  useEffect(() => () => setPageDirty(false), []);
  // Publishable once there's something to render (a bare empty draft isn't).
  const hasContent = draft.type === "blog" || sections.length > 0;
  const isBlock = kind === "block";
  // A never-saved draft (no node yet) — the first Publish/Save creates the node.
  const isNew = nodeId === null;
  // Showing the content browser (nothing open, no deliberate New) — the state the
  // X leaves the studio from; otherwise X steps back to this listing.
  const atListing = isNew && !getAuthoringNew();

  // ── Editorial state (DECISIONS 0094) ──────────────────────────────────────
  // The studio is read-only when the server says we can't update this revision
  // (review / archived / another author's draft / no perm) OR the conversation is
  // wrapped up — two orthogonal axes that both freeze editing. A new draft is
  // always editable (canEdit:true) until it exists.
  // Another session holds the pen (the take-over banner is showing). Freeze the
  // editor here too: without this the form + composer stay live, so a user can
  // pile up edits that are silently discarded the instant they take over (which
  // rebases onto the server's latest). The conflict banner is the explanation;
  // this makes the server-side fence visible before a write, not after a 409.
  const lockHeldElsewhere = lockHeldBy !== null;
  const readOnly = !moderation.canEdit || sealed || lockHeldElsewhere;
  // Friendly "held since" clock for the lock banner, so a take-over is an
  // informed decision (who holds the pen, and how long they've had it) rather
  // than a blind steal. acquired_at is a Unix timestamp in seconds.
  const lockSince = lockHeldBy
    ? new Date(lockHeldBy.acquired_at * 1000).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })
    : null;
  const hasTransition = (id: string) => moderation.transitions.some((t) => t.id === id);
  // The go-live primary: Publish a new page, a draft (the `publish` transition), or
  // re-publish unsaved edits to an already-published page (no transition needed —
  // the state doesn't change, the backend just writes a new published default).
  const canPublish =
    isNew || hasTransition("publish") || (moderation.state === "published" && dirty);
  const approveTransition = moderation.transitions.find((t) => t.id === "approve") ?? null;
  // Remaining lifecycle transitions rendered as plain buttons (submit-for-review /
  // reject / archive / restore). Restore doubles as the unlock from Archived.
  const secondaryTransitions = moderation.transitions.filter((t) => !PRIMARY_TRANSITIONS.has(t.id));
  // A live (published default) page carries a newer, not-yet-live revision — what
  // you're editing isn't what's live. After Save draft the latest revision's own
  // state is `draft`, so this keys off hasPendingDraft (the live default is still
  // published), not the latest state. The load-bearing "not live yet" signal.
  const draftPending = moderation.hasPendingDraft;
  // The state-legibility line: name WHY editing is blocked + the live↔draft delta,
  // never a bare disabled button. Read-only reasons first (most load-bearing), then
  // the pending-draft delta, then a plain draft note.
  // State-first: archived / in-review / sealed always carry their note (the
  // superuser operator can still edit them — canEdit follows Drupal entity access
  // — so the note, not the disabled state, is what explains the situation). A
  // read-only state names its unlock; a live page with a pending draft names the
  // live↔draft delta. Disabled buttons never appear without an explanation.
  const docNoun = isBlock ? "block" : "page";
  const legibility: string | null =
    sealed
      ? "This conversation is wrapped up — start a new thread to make more changes."
      : moderation.state === "archived"
        ? `Archived — not on the live site. Restore it to bring it back.`
        : moderation.state === "needs_review"
          ? moderation.canEdit
            ? "In review — approve to publish, or reject to send it back to draft."
            : "In review — waiting for approval before it goes live."
          : !moderation.canEdit
            ? `You don’t have access to edit this ${docNoun} right now.`
            : draftPending
              ? "Your changes aren’t live yet — Publish to update the live page."
              : moderation.state === "draft" && !isNew
                ? `This is a draft — not live yet. Publish to make it the live ${docNoun}.`
                : null;

  // Translation derived state. `lang === null` means the source/default language
  // (never locked). A non-source SYMMETRIC translation inherits the source layout,
  // so its structural controls are locked — only the copy is editable here.
  const languages: Lang[] = manifest?.translation?.languages ?? [];
  const multilingual = manifest?.translation?.multilingual ?? false;
  const allowDivergence = manifest?.translation?.allow_divergence ?? false;
  const sourceLabel = languages.find((l) => l.default)?.label ?? "source";
  const langLabel = lang ? languages.find((l) => l.id === lang)?.label ?? lang : sourceLabel;
  // The name shown on the document switcher (the panel-bar title). It doubles as
  // wayfinding ("which page am I editing?") and as the trigger for the open/new
  // picker, so a brand-new draft falls back to a neutral placeholder.
  const docName = (draft.title ?? "").trim() || (kind === "block" ? "Untitled block" : "Untitled page");
  const layoutLocked = lang !== null && mode === "symmetric";

  // Every edit writes the whole schema back to the store; the subscription above
  // then flows it into local state and the preview re-renders. One write path.
  const commit = useCallback((next: PageSchema) => {
    setPageDraft(next);
    setNotice(null);
    setError(null);
    // Any edit while a clear-confirm is pending makes it stale — dismiss it.
    setConfirmingDiscard(false);
  }, []);

  // The editable palette = inline sections + the reference placeables (embed /
  // block). A block being authored can't contain another block (one-level
  // expansion), so drop `block` from a block's own palette.
  const palette = useMemo(() => {
    const refs = (manifest?.reference ?? []).filter((s) => !(kind === "block" && s.component === "block"));
    return [...(manifest?.sections ?? []), ...refs];
  }, [manifest, kind]);

  const propDefs = useMemo(() => {
    const by = new Map<string, PropDef[]>();
    for (const s of palette) by.set(s.component, s.props);
    return by;
  }, [palette]);

  // Image-bearing prop / row-field names (manifest-driven) → render a media
  // picker for them, in both top-level props and repeatable rows.
  const imageProps = useMemo(() => new Set(manifest?.image_props ?? []), [manifest]);

  // Accordion panels editor: the bounded child allow-list + a lookup of each
  // child component's prop schema (children are leaf sections, so their defs
  // live in `propDefs`). Threaded into the panels editor's per-block picker.
  const blockComponents = useMemo(() => manifest?.accordion_blocks ?? [], [manifest]);
  const blockDefs = useCallback((component: string) => propDefs.get(component) ?? [], [propDefs]);

  const setMeta = (patch: Partial<PageSchema>) => commit({ ...draft, ...patch });

  const setSectionProp = (index: number, prop: string, value: unknown) => {
    const next = sections.map((s, i) => {
      if (i !== index) return s;
      const props = { ...s.props };
      if (value === "" || value === undefined || value === null) delete props[prop];
      else props[prop] = value;
      return { ...s, props };
    });
    commit({ ...draft, sections: next });
  };

  // Insert a section at `at` (default end), from a searchable insertion-point
  // picker. The new card opens for editing; open flags at/after the insert shift
  // up one so they stay pinned to their content, and the picker closes.
  const addSection = (component: string, at: number = sections.length) => {
    if (layoutLocked) return;
    const index = Math.max(0, Math.min(at, sections.length));
    // Stamp a stable id now (the server preserves it) so the per-field diff can
    // match this section to its saved baseline after a Publish/reload.
    const next = [...sections.slice(0, index), { id: slotUid(), component, props: {} }, ...sections.slice(index)];
    setExpanded((prev) => {
      const set = new Set<number>();
      for (const i of prev) set.add(i >= index ? i + 1 : i);
      set.add(index);
      return set;
    });
    setInsertAt(null);
    commit({ ...draft, sections: next });
  };

  const removeSection = (index: number) => {
    if (layoutLocked) return;
    setInsertAt(null);
    // Drop the removed index and shift every higher open flag down one.
    setExpanded((prev) => {
      const next = new Set<number>();
      for (const i of prev) if (i < index) next.add(i); else if (i > index) next.add(i - 1);
      return next;
    });
    commit({ ...draft, sections: sections.filter((_, i) => i !== index) });
  };

  const moveSection = (index: number, delta: number) => {
    if (layoutLocked) return;
    setInsertAt(null);
    const to = index + delta;
    if (to < 0 || to >= sections.length) return;
    const next = [...sections];
    [next[index], next[to]] = [next[to], next[index]];
    // Swap the two cards' open flags so a card stays open as it moves.
    setExpanded((prev) => {
      const set = new Set(prev);
      const had = set.has(index);
      set.has(to) ? set.add(index) : set.delete(index);
      had ? set.add(to) : set.delete(to);
      return set;
    });
    commit({ ...draft, sections: next });
  };

  // Drag-reorder (study 02, Plate 11): the grip drags, any row is a drop
  // target. The in-flight source index lives in a ref — no re-render needed
  // until the drop commits the splice.
  const dragFrom = useRef<number | null>(null);
  const moveSectionTo = (from: number, to: number) => {
    if (layoutLocked || from === to || from < 0 || from >= sections.length) return;
    if (to < 0 || to >= sections.length) return;
    setInsertAt(null);
    const next = [...sections];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    // Remap the open flags so each card's expansion follows it across the splice.
    setExpanded((prev) => {
      const set = new Set<number>();
      for (const i of prev) {
        if (i === from) {
          set.add(to);
          continue;
        }
        let ni = i > from ? i - 1 : i;
        if (ni >= to) ni += 1;
        set.add(ni);
      }
      return set;
    });
    commit({ ...draft, sections: next });
  };

  // The langcode a write targets — pages carry per-language governance, blocks
  // never do (a block is language-neutral). Centralised so every handler agrees.
  const writeLang = !isBlock ? lang : null;

  // Fold a write's returned envelope into the studio: adopt the saved schema as
  // the clean baseline, remember the (maybe newly-minted) node + url, track a
  // freshly-created translation, and repaint the preview so it stays authoritative.
  const absorbWrite = useCallback(
    (result: Record<string, unknown>) => {
      setBaseline(snapshot(draft));
      if (result?.node_id) {
        const id = String(result.node_id);
        setNodeIdState(id);
        setPageNode(id);
      }
      if (!isBlock) setPageUrl(typeof result?.url === "string" ? (result.url as string) : null);
      if (!isBlock && lang && !translations.includes(lang)) setTranslations((t) => [...t, lang]);
      reloadPreview();
    },
    [draft, isBlock, lang, translations],
  );

  // One catch for every write: a 409 raises the stale-write conflict banner (the
  // only safe path is Reload latest — never a blind retry); anything else is a
  // plain error line with the verb that failed.
  const failWrite = useCallback((verb: string, e: unknown) => {
    if (e instanceof LockConflictError) {
      // Lost the pen mid-write — page-lock already recorded the holder, so the
      // take-over banner surfaces it. No generic error line (the banner is the UI).
      return;
    }
    if (e instanceof RevisionConflictError) {
      setConflict({ currentVid: e.currentVid });
    } else {
      setError(`Couldn’t ${verb}: ${e instanceof Error ? e.message : e}`);
    }
  }, []);

  // Explicitly take over the editor lock (a deliberate, non-silent seize — the
  // only way past a held/lost lock). Force-acquire mints a fresh token (staling
  // the prior holder's), then rebase onto the latest revision so our edits sit on
  // top of whatever they saved. Page kind only (blocks aren't lock-scoped in v1).
  const takeOverLock = useCallback(async () => {
    if (nodeId === null || isBlock) return;
    setTakingOver(true);
    setError(null);
    try {
      await acquireLock(nodeId, writeLang, activeStudioKey(), { force: true });
      await loadPageIntoStudio(nodeId, writeLang);
    } catch (e) {
      setError(`Couldn’t take over: ${e instanceof Error ? e.message : e}`);
    } finally {
      setTakingOver(false);
    }
  }, [nodeId, isBlock, writeLang]);

  // The "View page ↗" ref a wrap-up celebration links to: a page carries its live
  // url + node, a block has no standalone page so it links nowhere.
  const wrapupRef = useCallback(
    (result: Record<string, unknown>): { url?: string; node?: string } =>
      isBlock
        ? {}
        : {
            ...(typeof result?.url === "string" ? { url: result.url as string } : {}),
            ...(result?.node_id ? { node: String(result.node_id) } : nodeId ? { node: nodeId } : {}),
          },
    [isBlock, nodeId],
  );

  // Save draft — the decoupled "Update": persist a forward revision WITHOUT going
  // live. Leaves the conversation running (no wrap-up); a published page keeps its
  // live copy and gains a pending draft.
  const saveDraftAction = useCallback(async () => {
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      const wasNew = nodeId === null;
      const result = await saveDraft(draft, kind, nodeId, writeLang);
      absorbWrite(result);
      // A brand-new page just gained a node identity in place — adopt the node
      // room so the machine + URL leave /content/new for /content/node/<nid>
      // WITHOUT switching the conversation or reloading the doc we already hold
      // (mirrors commitPublish's first-mint adopt).
      if (wasNew && result?.node_id) {
        consoleNav.adoptRoom({ kind: "node", doc: kind, nid: Number(result.node_id), langcode: writeLang });
      }
      setNotice({ text: "Draft saved", url: typeof result?.url === "string" ? (result.url as string) : undefined });
    } catch (e) {
      failWrite("save the draft", e);
    } finally {
      setPublishing(false);
    }
  }, [draft, kind, nodeId, writeLang, absorbWrite, failWrite]);

  // Publish — the one-click save+go-live. A brand-new page is created as a draft
  // first (so /publish has a node id), then published. Publishing is the genuine
  // terminal "done" beat → it offers the wrap-up (DECISIONS 0093, refined by 0094:
  // only publish/approve, not save-draft or submit-for-review).
  const commitPublish = useCallback(async () => {
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      let node = nodeId;
      if (node === null) {
        const created = await saveDraft(draft, kind, null, writeLang);
        node = created?.node_id ? String(created.node_id) : null;
        if (node === null) throw new Error("the page could not be created");
        setNodeIdState(node);
        setPageNode(node);
        // The draft just gained a node identity in place — adopt the node room so
        // the machine + URL catch up WITHOUT switching the conversation we're
        // publishing from or reloading the doc we already hold.
        consoleNav.adoptRoom({ kind: "node", doc: kind, nid: Number(node), langcode: writeLang });
      }
      const result = await publishDoc(draft, kind, node, writeLang);
      absorbWrite(result);
      const what = isBlock ? "Block" : lang ? `${langLabel} translation` : "Page";
      setNotice({ text: `${what} published`, url: typeof result?.url === "string" ? (result.url as string) : undefined });
      offerWrapup(runtime.threads.mainItem.getState().remoteId, wrapupRef(result));
    } catch (e) {
      failWrite("publish", e);
    } finally {
      setPublishing(false);
    }
  }, [draft, kind, nodeId, writeLang, isBlock, lang, langLabel, absorbWrite, failWrite, wrapupRef, runtime]);

  // A pure editorial transition (submit-for-review / approve / reject / archive /
  // restore). Submit-for-review carries the current edits → save first if dirty
  // (so the reviewer sees them). Approve lands on Published, so it wraps up too.
  const commitTransition = useCallback(
    async (t: Transition) => {
      if (nodeId === null) return;
      setPublishing(true);
      setError(null);
      setNotice(null);
      try {
        if (t.id === "submit_for_review" && dirty && moderation.canEdit) {
          absorbWrite(await saveDraft(draft, kind, nodeId, writeLang));
        }
        const result = await runTransition(t.id, kind, nodeId);
        reloadPreview();
        if (t.id === "approve") {
          const what = isBlock ? "Block" : "Page";
          setNotice({ text: `${what} published`, url: typeof result?.url === "string" ? (result.url as string) : undefined });
        } else {
          setNotice({ text: TRANSITION_NOTICE[t.id] ?? t.to_label });
        }
        if (t.to === "published") {
          offerWrapup(runtime.threads.mainItem.getState().remoteId, wrapupRef(result));
        }
      } catch (e) {
        failWrite(t.label.toLowerCase(), e);
      } finally {
        setPublishing(false);
      }
    },
    [nodeId, dirty, moderation.canEdit, draft, kind, writeLang, isBlock, absorbWrite, failWrite, wrapupRef, runtime],
  );

  // Resolve a 409 by reloading the latest revision (rebase): the studio adopts the
  // newer head as its baseline + base_vid. The local edits are dropped — taking
  // the latest is the safe default; "View what changed" is a later refinement.
  const reloadLatest = useCallback(() => {
    if (nodeId === null) {
      setConflict(null);
      return;
    }
    const reload = isBlock ? loadBlockIntoStudio(nodeId) : loadPageIntoStudio(nodeId, writeLang);
    void reload
      .then(() => setConflict(null))
      .catch((e) => setError(`Couldn’t reload: ${e instanceof Error ? e.message : e}`));
  }, [nodeId, isBlock, writeLang]);

  // The X is a two-stage back: with a doc open it steps back to the listing (the
  // content browser); already on the listing, it leaves the studio. A dirty doc
  // confirms first so unsaved edits aren't dropped silently.
  const backOrClose = useCallback(() => {
    if (getPageNode() === null && !getAuthoringNew()) {
      onClose();
      return;
    }
    if (dirty) {
      setConfirmingBack(true);
      return;
    }
    // Node → list is a real room move: the machine switches to the list room's
    // thread and closes the doc (releasing its lock) via commitSwitch.
    consoleNav.enterRoom({ kind: "list" });
  }, [onClose, dirty]);

  // Load a language into the studio (null = the source/default). The default
  // option maps to null so the source is never treated as a locked translation.
  // A langcode change is a DIFFERENT node room (same nid → same homed threads), so
  // we reload the translation ourselves and `adoptRoom` the machine to the new
  // langcode WITHOUT a thread switch (enterRoom would abandon the conversation).
  const loadLang = useCallback(
    (target: string | null) => {
      if (!nodeId) return;
      void loadPageIntoStudio(nodeId, target)
        .then(() =>
          consoleNav.adoptRoom({ kind: "node", doc: isBlock ? "block" : "page", nid: Number(nodeId), langcode: target }),
        )
        .catch((e) => setError(`Couldn’t switch language: ${e instanceof Error ? e.message : e}`));
    },
    [nodeId, isBlock],
  );

  // Switch the editing language. Loading replaces the draft, so while it's dirty
  // we stage the switch behind a confirm; clean, switch straight away.
  const switchLang = useCallback(
    (target: string | null) => {
      setSwitchingLang(false);
      if (target === lang || !nodeId) return;
      if (dirty) {
        setPendingLang({ to: target });
        return;
      }
      loadLang(target);
    },
    [lang, nodeId, dirty, loadLang],
  );

  // Flip the current translation's layout mode (copy-on-write diverge / converge),
  // then it reloads with the new structure + mode.
  const flipMode = useCallback(
    (next: "asymmetric" | "symmetric") => {
      if (!nodeId || !lang) return;
      void setTranslationMode(nodeId, lang, next)
        .then(() => {
          setMode(getPageMode());
          setBaseline(snapshot(getPageDraft() ?? EMPTY_PAGE));
        })
        .catch((e) => setError(`Couldn’t change layout mode: ${e instanceof Error ? e.message : e}`));
    },
    [nodeId, lang],
  );

  const confirmLangSwitch = useCallback(() => {
    const target = pendingLang;
    setPendingLang(null);
    if (target) loadLang(target.to);
  }, [pendingLang, loadLang]);

  // What Discard restores: the last published page, or the empty page if this
  // session never published.
  const baselineSchema = useMemo(() => JSON.parse(baseline) as PageSchema, [baseline]);
  const baselineHasContent =
    baselineSchema.type === "blog" || (baselineSchema.sections?.length ?? 0) > 0;

  // Per-field dirty diff against the saved baseline (the Brand-studio parallel,
  // but over a nested page-schema rather than a flat token map). The page title
  // is one field; every section is matched to its saved counterpart BY ID
  // (stable across reorder/insert), then its props are compared field-by-field.
  // A section with no saved counterpart diffs against empty props, so each
  // populated prop lands in `changed` — the same uniform path. Drives the accent
  // dot + one-field revert on each control and the count badge on each card.
  const titleDirty = (draft.title ?? "") !== (baselineSchema.title ?? "");
  const sectionDiffs = useMemo<SectionDiff[]>(() => {
    const baseById = new Map<string, PageSection>();
    for (const s of baselineSchema.sections ?? []) if (s.id) baseById.set(s.id, s);
    return (draft.sections ?? []).map((s) => {
      const baseProps = (s.id ? baseById.get(s.id) : undefined)?.props ?? {};
      const changed = new Set<string>();
      for (const key of new Set([...Object.keys(baseProps), ...Object.keys(s.props ?? {})])) {
        if (!deepEq(baseProps[key], s.props?.[key])) changed.add(key);
      }
      return { changed };
    });
  }, [draft, baselineSchema]);

  // Revert one field to its saved value (the page parallel of Brand's per-token
  // revert): snap just that prop — or the title — back to the baseline, leaving
  // every other edit intact. setSectionProp deletes on an empty/undefined value,
  // so reverting a prop the baseline never had simply drops it. Plain closures
  // (like setMeta/setSectionProp) so they always see the current draft.
  const revertTitle = () => setMeta({ title: baselineSchema.title ?? "" });
  const revertSectionProp = (index: number, prop: string) => {
    const sec = sections[index];
    const base = sec?.id ? (baselineSchema.sections ?? []).find((b) => b.id === sec.id) : undefined;
    setSectionProp(index, prop, base?.props?.[prop]);
  };

  // The single revert path: restore the baseline and clear the confirm.
  const doDiscard = () => {
    commit(baselineSchema);
    setConfirmingDiscard(false);
    setNotice(null);
    setError(null);
  };

  // Discard reverts to the last published page. When nothing's published the
  // baseline is empty, so a discard would wipe the whole (likely agent-built)
  // preview — confirm first in that case; a plain revert needs no confirmation.
  const discard = () => {
    if (baselineHasContent) doDiscard();
    else setConfirmingDiscard(true);
  };

  // Components offered in the "add section" picker — the merged palette.
  const available = palette;

  return (
    <div className="ain-studio__rail" ref={railRef}>
      {/* Discard / Publish / leave pin to the top bar (reachable when the rail
          collapses to a sheet). The language switcher and "Open…" stay in the
          rail head — their popovers anchor to the rail. */}
      <StudioActionsPortal>
        {dirty && !readOnly && <span className="ain-studio-actions__dirty" title="Unsaved changes">●</span>}
        <button
          className="ain-btn ain-topbtn"
          onClick={discard}
          disabled={!dirty || publishing || readOnly}
          title="Discard draft — revert to the last saved version"
        >
          Discard
        </button>
        {/* Save draft — the decoupled "Update": persist a forward revision without
            going live. Available whenever there are unsaved edits we can write. */}
        <button
          className="ain-btn ain-topbtn"
          onClick={() => void saveDraftAction()}
          disabled={!hasContent || !dirty || publishing || readOnly}
          title="Save your changes as a draft — not live yet"
        >
          {publishing ? "Working…" : "Save draft"}
        </button>
        {/* Lifecycle transitions the user actually holds (submit-for-review /
            reject / archive / restore) — read straight from content_moderation.
            Restore doubles as the unlock control from an Archived (read-only) page. */}
        {secondaryTransitions.map((t) => (
          <button
            key={t.id}
            className="ain-btn ain-topbtn"
            onClick={() => void commitTransition(t)}
            disabled={publishing}
            title={t.label}
          >
            {t.label}
          </button>
        ))}
        {/* Approve (reviewer-gated needs_review → published) — a go-live primary. */}
        {approveTransition && (
          <button
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={() => void commitTransition(approveTransition)}
            disabled={publishing}
            title="Approve and publish this page"
          >
            Approve &amp; publish
          </button>
        )}
        {/* Publish — save+go-live in one click (creates the node first for a new
            page). Hidden once a page is in review/archived (no publish transition). */}
        {canPublish && (
          <button
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={() => void commitPublish()}
            disabled={!hasContent || publishing || (readOnly && !isNew)}
            title={isBlock ? "Publish the block — goes live everywhere it's used" : "Publish — make this the live page"}
          >
            {publishing ? "Publishing…" : "Publish"}
          </button>
        )}
        {/* Hand the finished page over to the Checks studio. Page-only, and gated
            on a clean save so the audit reflects the saved page, not an unsaved
            draft (the audit reads stored node state). Deep-link nav re-seeds Checks
            from ?audit= — robust across the studio/agent switch. Also gated on the
            Checks studio being accessible, so the `checks_enabled` release flag
            (which drops Checks from studioAccess) hides this cross-studio entry
            point too — not just the switcher tab. */}
        {kind === "page" && nodeId && !dirty && isStudioAccessible("checks") && (
          <button
            className="ain-btn ain-topbtn"
            onClick={() => nodeId && window.location.assign(pageDeepLink("checks", nodeId, consoleBase()))}
            title="Run checks on this page in the Checks studio"
          >
            <ShieldCheckIcon /> Run checks
          </button>
        )}
        <button
          className="ain-btn ain-iconbtn ain-topbar__leave"
          onClick={backOrClose}
          aria-label={atListing ? "Close page studio" : "Back to page list"}
          title={atListing ? "Leave page studio" : "Back to the page list"}
        >
          <XIcon />
        </button>
      </StudioActionsPortal>
      <PanelBar
        title={docName}
        actions={
          <>
            {/* Body ｜ Presence facet — pages only, hidden on the listing. Swaps
                this rail + the centre preview via the shared page-facet store. */}
            {kind === "page" && !atListing && (
              <div className="ain-facet" role="group" aria-label="Editor facet">
                <button
                  type="button"
                  className="ain-btn ain-facet__btn"
                  aria-pressed={facet === "body"}
                  onClick={() => setFacet("body")}
                  title="Edit the page itself"
                >
                  Body
                </button>
                <button
                  type="button"
                  className="ain-btn ain-facet__btn"
                  aria-pressed={facet === "presence"}
                  onClick={() => setFacet("presence")}
                  title="Edit how the page appears when referenced — SEO, social, search"
                >
                  Presence
                </button>
              </div>
            )}
            {kind === "page" && multilingual && nodeId && (
              <button
                className="ain-btn ain-topbtn"
                onClick={() => setSwitchingLang((s) => !s)}
                disabled={publishing}
                aria-expanded={switchingLang}
                title="Choose which language you’re editing"
              >
                {langLabel} <ChevronDownIcon />
              </button>
            )}
            <button className="ain-btn ain-iconbtn ain-studio__sheetclose" onClick={closeSheets} aria-label="Hide editor" title="Hide editor">
              <XIcon />
            </button>
          </>
        }
      />

      {/* On the listing (no doc open) the preview shows the content browser, so the
          editor form has no document to bind to — rendering its empty fields would
          fight the listing and read as out-of-sync. Hide the whole editor body and
          point the user at the list instead; picking/New re-renders this with a doc. */}
      {atListing ? (
        <p className="ain-studio__hint">
          Pick a {isBlock ? "block" : "page"} from the list to edit it here — or start a new one.
        </p>
      ) : (
      <>
      {/* Editorial state strip: the status badge + the legibility line (DECISIONS
          0094) — always says what state the doc is in and, when editing is blocked
          or the draft differs from live, why + what to do about it. */}
      <div className="ain-studio__modbar" data-state={moderation.state}>
        <span
          className="ain-studio__statebadge"
          data-state={draftPending ? "published" : moderation.state}
          data-pending={draftPending || undefined}
        >
          {/* "Live" is the owner's word for published (study 02, Plate 5). */}
          {draftPending ? "Live" : moderation.stateLabel === "Published" ? "Live" : moderation.stateLabel}
          {draftPending && <span className="ain-studio__statebadge-sub"> · draft pending</span>}
        </span>
        {legibility && <span className="ain-studio__modnote">{legibility}</span>}
      </div>

      {/* Stale-write conflict (HTTP 409): the page advanced under us. The only safe
          move is to take the latest revision (rebase) — never a blind retry. */}
      {conflict && (
        <div className="ain-studio__conflict" role="alert">
          <span className="ain-studio__conflictmsg">
            This {docNoun} changed since you opened it — saving would overwrite newer work.
          </span>
          <div className="ain-studio__confirmbtns">
            <button className="ain-btn ain-topbtn ain-topbtn--primary" onClick={reloadLatest}>
              Reload latest
            </button>
            <button className="ain-btn ain-topbtn" onClick={() => setConflict(null)}>
              Dismiss
            </button>
          </div>
        </div>
      )}

      {/* Single-writer lock (Plan A / DECISIONS 0099): another session holds the
          pen for this page. Writes are fenced server-side; the only way forward
          is an explicit take-over (never a silent steal). */}
      {lockHeldBy && !isBlock && (
        <div className="ain-studio__conflict" role="alert">
          <span className="ain-studio__conflictmsg">
            {lockHeldBy.mine
              ? `You’re editing this page in another tab${lockSince ? ` (opened ${lockSince})` : ""}. Take over here to make changes — the other tab will lose the lock and any unsaved edits there.`
              : `${lockHeldBy.name} is editing this page${lockHeldBy.studio ? ` in ${lockHeldBy.studio}` : ""}${lockSince ? `, since ${lockSince}` : ""}. Take over to make changes — their unsaved edits will be lost.`}
          </span>
          <div className="ain-studio__confirmbtns">
            <button
              className="ain-btn ain-topbtn ain-topbtn--primary"
              onClick={() => void takeOverLock()}
              disabled={takingOver}
            >
              {takingOver ? "Taking over…" : "Take over"}
            </button>
          </div>
        </div>
      )}

      {switchingLang && (
        <div className="ain-pagepick" role="listbox" aria-label="Choose editing language">
          {languages.map((l) => {
            const target = l.default ? null : l.id;
            const isCurrent = (lang ?? null) === target;
            const exists = l.default || translations.includes(l.id);
            return (
              <button
                key={l.id}
                type="button"
                role="option"
                aria-selected={isCurrent}
                className="ain-btn ain-pagepick__item"
                data-current={isCurrent || undefined}
                onClick={() => switchLang(target)}
                title={l.default ? "Source language" : exists ? `Edit the ${l.label} translation` : `Start a ${l.label} translation`}
              >
                <span className="ain-pagepick__title">
                  {l.label}
                  {l.default && " · source"}
                  {!l.default && !exists && " · new"}
                </span>
                {isCurrent && <CheckIcon />}
              </button>
            );
          })}
        </div>
      )}

      {pendingLang && (
        <div className="ain-studio__confirm" role="alertdialog" aria-label="Confirm switch language">
          <span className="ain-studio__confirmmsg">
            Switch language? Your unsaved changes will be lost.
          </span>
          <div className="ain-studio__confirmbtns">
            <button className="ain-btn ain-topbtn" onClick={() => setPendingLang(null)}>
              Cancel
            </button>
            <button className="ain-btn ain-topbtn ain-topbtn--danger" onClick={confirmLangSwitch}>
              Discard &amp; switch
            </button>
          </div>
        </div>
      )}

      {confirmingBack && (
        <div className="ain-studio__confirm" role="alertdialog" aria-label="Confirm back to list">
          <span className="ain-studio__confirmmsg">
            Back to the {isBlock ? "block" : "page"} list? Your unsaved changes will be lost.
          </span>
          <div className="ain-studio__confirmbtns">
            <button className="ain-btn ain-topbtn" onClick={() => setConfirmingBack(false)}>
              Cancel
            </button>
            <button
              className="ain-btn ain-topbtn ain-topbtn--danger"
              onClick={() => {
                setConfirmingBack(false);
                consoleNav.enterRoom({ kind: "list" });
              }}
            >
              Discard &amp; leave
            </button>
          </div>
        </div>
      )}

      {confirmingDiscard && (
        <div className="ain-studio__confirm" role="alertdialog" aria-label="Confirm discard">
          <span className="ain-studio__confirmmsg">
            Clear the whole page? This can’t be undone.
          </span>
          <div className="ain-studio__confirmbtns">
            <button className="ain-btn ain-topbtn" onClick={() => setConfirmingDiscard(false)}>
              Cancel
            </button>
            <button className="ain-btn ain-topbtn ain-topbtn--danger" onClick={doDiscard}>
              Clear
            </button>
          </div>
        </div>
      )}

      <p className="ain-studio__status" data-dirty={dirty || undefined}>
        {dirty ? (
          <>{sections.length} section{sections.length === 1 ? "" : "s"} · unsaved · preview only</>
        ) : notice ? (
          <>
            <CheckIcon /> {notice.text}
            {notice.url && (
              <>
                {" · "}
                <a className="ain-preview__open" href={notice.url} target="_blank" rel="noreferrer">
                  View ↗
                </a>
              </>
            )}
          </>
        ) : hasContent ? (
          <>Matches the saved page</>
        ) : (
          <>Empty page — add a section or ask the agent</>
        )}
      </p>

      {lang && mode && (
        <div className="ain-studio__langnote" data-mode={mode}>
          {mode === "symmetric" ? (
            <>
              <span className="ain-studio__langnotetxt">
                Layout inherited from <strong>{sourceLabel}</strong> — translate the copy here; layout edits follow the source.
              </span>
              {allowDivergence && (
                <button
                  className="ain-btn ain-topbtn"
                  onClick={() => flipMode("asymmetric")}
                  disabled={publishing || readOnly}
                  title="Give this language its own layout (it will stop following the source)"
                >
                  Make layout independent
                </button>
              )}
            </>
          ) : (
            <>
              <span className="ain-studio__langnotetxt">
                <strong>Independent layout</strong> — diverged from {sourceLabel}; source layout edits no longer apply.
              </span>
              <button
                className="ain-btn ain-topbtn"
                onClick={() => flipMode("symmetric")}
                disabled={publishing || readOnly}
                title="Re-inherit the source layout (keeps your translated copy)"
              >
                Re-inherit source layout
              </button>
            </>
          )}
        </div>
      )}

      {error && <p className="ain-studio__error">{error}</p>}
      {!manifest && !error && <p className="ain-studio__hint">Loading catalog…</p>}

      {/* Presence facet (pages only): the teaser-card + SEO/metadata editors over
          draft.teaser / draft.meta, disabled read-only like the body rail. The
          centre preview swaps to the teaser/social/search cards in tandem
          (page-facet store). */}
      {manifest && facet === "presence" && kind === "page" && (
        <fieldset className="ain-studio__groups" disabled={readOnly}>
          <TeaserGroup baseline={baselineSchema.teaser} />
          <SeoMetaGroup baseline={baselineSchema.meta} />
        </fieldset>
      )}

      {manifest && facet === "body" && (
        // A disabled fieldset makes the whole section editor read-only in one
        // place when access (canEdit) or the thread lock says so — native form
        // disabling cascades to every input/select/button inside.
        <fieldset className="ain-studio__groups" disabled={readOnly}>
          {/* Page-level meta. */}
          <section className="ain-studio__group">
            <h3 className="ain-studio__grouptitle">{kind === "block" ? "Block" : "Page"}</h3>
            {kind === "block" && (
              <p className="ain-studio__groupnote">
                A reusable block — place it on any page with a “Block” section. Editing it here updates every page that uses it.
              </p>
            )}
            <label className="ain-field" data-dirty={titleDirty || undefined}>
              <span className="ain-field__label">
                <span className="ain-field__labeltext">{kind === "block" ? "Block name" : "Title"}</span>
                {titleDirty && <FieldRevert label={kind === "block" ? "block name" : "title"} onRevert={revertTitle} />}
              </span>
              <input
                className="ain-field__input"
                value={String(draft.title ?? "")}
                onChange={(e) => setMeta({ title: e.target.value })}
                placeholder={kind === "block" ? "Untitled block" : "Untitled page"}
                spellCheck={false}
              />
            </label>
          </section>

          {/* Sections. */}
          <section className="ain-studio__group">
            <div className="ain-studio__grouphead">
              <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">
                Sections{sections.length > 0 && <span className="ain-studio__count">{sections.length}</span>}
              </h3>
              {sections.length > 1 && (
                <button
                  type="button"
                  className="ain-btn ain-studio__allbtn"
                  onClick={() =>
                    setExpanded((prev) =>
                      prev.size === sections.length
                        ? new Set()
                        : new Set(sections.map((_, i) => i)),
                    )
                  }
                  title={expanded.size === sections.length ? "Collapse every section" : "Expand every section"}
                >
                  {expanded.size === sections.length ? "Collapse all" : "Expand all"}
                </button>
              )}
            </div>
            {draft.type === "blog" ? (
              <p className="ain-studio__groupnote">
                This page uses the blog recipe — its layout is fixed. Switch the agent to a landing page to compose sections.
              </p>
            ) : (
              <>
                {sections.length === 0 && (
                  <p className="ain-studio__groupnote">
                    {layoutLocked
                      ? "No sections — this translation inherits its layout from the source."
                      : "No sections yet. Add one below, or ask the agent to build the page."}
                  </p>
                )}
                {/* Empty state: one prominent "add first section" trigger. */}
                {!layoutLocked && sections.length === 0 && (
                  <InsertPoint
                    index={0}
                    active={insertAt === 0}
                    prominent
                    available={available}
                    onOpen={setInsertAt}
                    onClose={() => setInsertAt(null)}
                    onPick={addSection}
                  />
                )}
                {/* The section STACK (study 02, Plate 11): rows share one panel,
                    content at rest, controls (grip + ⋯) on hover. Insert pickers
                    appear only where a row's ⋯ menu asked for one. */}
                <div className="ain-secstack">
                  {sections.map((section, index) => (
                    <Fragment key={index}>
                      {!layoutLocked && insertAt === index && (
                        <InsertPoint
                          index={index}
                          active
                          available={available}
                          onOpen={setInsertAt}
                          onClose={() => setInsertAt(null)}
                          onPick={addSection}
                        />
                      )}
                      <SectionCard
                        section={section}
                        index={index}
                        count={sections.length}
                        props={propDefs.get(section.component) ?? []}
                        imageProps={imageProps}
                        blockComponents={blockComponents}
                        blockDefs={blockDefs}
                        open={expanded.has(index)}
                        onToggle={toggleSection}
                        onProp={setSectionProp}
                        onRemove={removeSection}
                        onMove={moveSection}
                        onInsert={setInsertAt}
                        onDragStart={(i) => {
                          dragFrom.current = i;
                        }}
                        onDropOn={(i) => {
                          if (dragFrom.current !== null) moveSectionTo(dragFrom.current, i);
                          dragFrom.current = null;
                        }}
                        locked={layoutLocked}
                        diff={sectionDiffs[index]}
                        onRevertProp={revertSectionProp}
                      />
                    </Fragment>
                  ))}
                </div>
                {/* One quiet append affordance closes the stack (the per-gap "+"
                    wedges are retired — insert-between lives in each row's ⋯). */}
                {!layoutLocked && sections.length > 0 && (
                  insertAt === sections.length ? (
                    <InsertPoint
                      index={sections.length}
                      active
                      available={available}
                      onOpen={setInsertAt}
                      onClose={() => setInsertAt(null)}
                      onPick={addSection}
                    />
                  ) : (
                    <button
                      type="button"
                      className="ain-btn ain-addsec"
                      onClick={() => setInsertAt(sections.length)}
                    >
                      <PlusIcon /> Add section
                    </button>
                  )
                )}
              </>
            )}
          </section>
        </fieldset>
      )}
      </>
      )}
    </div>
  );
}

/* -------------------------------------------------------------- insertion point */

/**
 * An "insert here" affordance between section cards (and at the top / end). It's
 * a thin gap that reveals a "+" on hover; clicking opens the searchable
 * ComponentPicker right there, scoped to insert at this exact index. Replaces
 * the old flat chip-wall (which doesn't scale past a dozen components and could
 * only append to the end). The empty-state / first-section trigger renders in a
 * `prominent` always-visible variant.
 */
function InsertPoint({
  index,
  active,
  prominent = false,
  available,
  onOpen,
  onClose,
  onPick,
}: {
  index: number;
  /** Whether this point's picker is open. */
  active: boolean;
  /** Always-visible labelled variant (empty state / first section). */
  prominent?: boolean;
  available: SectionDef[];
  onOpen: (index: number) => void;
  onClose: () => void;
  onPick: (component: string, at: number) => void;
}) {
  if (active) {
    return (
      <div className="ain-insert ain-insert--active">
        <ComponentPicker
          available={available}
          onClose={onClose}
          onPick={(component) => onPick(component, index)}
        />
      </div>
    );
  }
  return (
    <div className={prominent ? "ain-insert ain-insert--prominent" : "ain-insert"}>
      <button
        type="button"
        className="ain-btn ain-insert__btn"
        onClick={() => onOpen(index)}
        title="Add a section here"
      >
        <PlusIcon />
        {prominent && <span>Add a section</span>}
      </button>
    </div>
  );
}

/** A searchable list of placeable components, opened at an insertion point.
 *  Filters on the component name + its `use` description; Enter picks the first
 *  match, Escape and an outside click close it. */
function ComponentPicker({
  available,
  onPick,
  onClose,
}: {
  available: SectionDef[];
  onPick: (component: string) => void;
  onClose: () => void;
}) {
  const [query, setQuery] = useState("");
  const ref = useRef<HTMLDivElement>(null);

  // Close on a click anywhere outside the picker.
  useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) onClose();
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [onClose]);

  const q = query.trim().toLowerCase();
  const shown = q
    ? available.filter(
        (s) => humanize(s.component).toLowerCase().includes(q) || s.use.toLowerCase().includes(q),
      )
    : available;

  return (
    <div className="ain-cpicker" role="dialog" aria-label="Add a section" ref={ref}>
      <input
        className="ain-field__input ain-cpicker__search"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        placeholder="Search components…"
        spellCheck={false}
        autoFocus
        onKeyDown={(e) => {
          if (e.key === "Escape") {
            e.preventDefault();
            onClose();
          } else if (e.key === "Enter" && shown.length > 0) {
            e.preventDefault();
            onPick(shown[0].component);
          }
        }}
      />
      <div className="ain-cpicker__list" role="listbox" aria-label="Components">
        {shown.length === 0 ? (
          <p className="ain-cpicker__empty">Nothing matches “{query.trim()}”.</p>
        ) : (
          shown.map((s) => (
            <button
              key={s.component}
              type="button"
              role="option"
              aria-selected={false}
              className="ain-btn ain-cpicker__item"
              title={s.use}
              onClick={() => onPick(s.component)}
            >
              <span className="ain-cpicker__ico" aria-hidden="true">{sectionIcon(s.component)}</span>
              <span className="ain-cpicker__text">
                <span className="ain-cpicker__name">{humanize(s.component)}</span>
                <span className="ain-cpicker__use">{s.use}</span>
              </span>
            </button>
          ))
        )}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- row menu */

/**
 * The hover ⋯ menu on a repeated rail row (study 02, Plate 11): reorder,
 * insert, and the destructive action live HERE — never as always-visible
 * per-row buttons (thirty visible controls is machinery, not an outline).
 * Uses the shared .ain-menu anatomy, fixed-positioned off the trigger.
 */
function RowMenu({
  label,
  items,
}: {
  label: string;
  items: { label: string; danger?: boolean; disabled?: boolean; onPick: () => void }[];
}) {
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);
  useEffect(() => {
    if (!pos) return;
    const close = () => setPos(null);
    window.addEventListener("click", close);
    window.addEventListener("resize", close);
    return () => {
      window.removeEventListener("click", close);
      window.removeEventListener("resize", close);
    };
  }, [pos]);
  return (
    <>
      <button
        type="button"
        className="ain-btn ain-iconbtn ain-pagesec__more"
        aria-label={label}
        aria-haspopup="menu"
        aria-expanded={!!pos}
        data-open={pos ? "" : undefined}
        onClick={(e) => {
          e.stopPropagation();
          if (pos) {
            setPos(null);
            return;
          }
          const r = e.currentTarget.getBoundingClientRect();
          setPos({ top: r.bottom + 4, left: r.right });
        }}
      >
        <MoreHorizontalIcon />
      </button>
      {pos && (
        <div className="ain-menu" role="menu" style={{ top: pos.top, left: pos.left }}>
          {items.map((it) => (
            <button
              key={it.label}
              type="button"
              role="menuitem"
              className={`ain-btn ain-menu__item${it.danger ? " ain-menu__item--danger" : ""}`}
              disabled={it.disabled}
              onClick={() => {
                setPos(null);
                it.onPick();
              }}
            >
              {it.label}
            </button>
          ))}
        </div>
      )}
    </>
  );
}

/* ----------------------------------------------------------------- section card */

function SectionCard({
  section,
  index,
  count,
  props,
  imageProps,
  blockComponents,
  blockDefs,
  open,
  onToggle,
  onProp,
  onRemove,
  onMove,
  onInsert,
  onDragStart,
  onDropOn,
  locked,
  diff,
  onRevertProp,
}: {
  section: PageSection;
  index: number;
  count: number;
  props: PropDef[];
  /** Row-field names that hold an image (render a media picker inside rows). */
  imageProps: Set<string>;
  /** Accordion panels editor: allowed child components + their prop-schema lookup. */
  blockComponents: string[];
  blockDefs: (component: string) => PropDef[];
  /** Whether this card is expanded for editing (else a one-line outline row). */
  open: boolean;
  onToggle: (index: number) => void;
  onProp: (index: number, prop: string, value: unknown) => void;
  onRemove: (index: number) => void;
  onMove: (index: number, delta: number) => void;
  /** Open the component picker at an index (the ⋯ menu's insert above/below). */
  onInsert: (at: number) => void;
  /** Grip drag lifecycle — the parent owns the reorder splice. */
  onDragStart: (index: number) => void;
  onDropOn: (index: number) => void;
  /** Inherited-layout (symmetric translation): hide reorder/remove and lock the
   *  structural props (tone/variant/columns); only the copy stays editable. */
  locked: boolean;
  /** This section's dirty verdict vs the saved baseline (per-field markers +
   *  the head badge). Absent for the blog regime, which has no section diff. */
  diff?: SectionDiff;
  /** Revert one prop on this section back to its saved value. */
  onRevertProp: (index: number, prop: string) => void;
}) {
  const bodyId = `ain-pagesec-${index}`;
  const summary = summarize(section, props);
  // The head badge: the count of changed props, so a collapsed card still
  // advertises unsaved edits inside it (the Brand-studio .ain-card__dirty idiom).
  const changed = diff?.changed ?? new Set<string>();
  // Content props lead; the structural appearance knobs (tone/variant/columns)
  // tuck into a hairline-led subgroup so the copy fields read first.
  const content = props.filter((p) => !STRUCTURAL_PROPS.has(p.name));
  const appearance = props.filter((p) => STRUCTURAL_PROPS.has(p.name));
  const renderProp = (prop: PropDef) => (
    <PropControl
      key={prop.name}
      prop={prop}
      value={section.props[prop.name]}
      imageProps={imageProps}
      blockComponents={blockComponents}
      blockDefs={blockDefs}
      onChange={(v) => onProp(index, prop.name, v)}
      // A symmetric translation inherits structural props from the source —
      // lock them here so an edit can't be silently dropped on save.
      disabled={locked && STRUCTURAL_PROPS.has(prop.name)}
      // Per-field dirty marker + revert. On a section with no saved counterpart
      // each populated prop reads as changed; reverting clears it (no baseline).
      dirty={changed.has(prop.name)}
      onRevert={() => onRevertProp(index, prop.name)}
    />
  );

  return (
    <div
      className="ain-pagesec"
      data-open={open || undefined}
      data-ain-sec-id={section.id || undefined}
      // Any row is a drop target while a grip drag is in flight.
      onDragOver={locked ? undefined : (e) => e.preventDefault()}
      onDrop={locked ? undefined : (e) => {
        e.preventDefault();
        onDropOn(index);
      }}
    >
      <div className="ain-pagesec__head">
        {/* Content at rest, controls on hover (Plate 11): the grip surfaces on
            hover and drags; everything else lives behind the ⋯ menu. */}
        {!locked && (
          <span
            className="ain-pagesec__grip"
            draggable
            aria-hidden
            title="Drag to reorder"
            onDragStart={(e) => {
              e.dataTransfer.effectAllowed = "move";
              e.dataTransfer.setData("text/plain", String(index));
              onDragStart(index);
            }}
          >
            <GripIcon />
          </span>
        )}
        <button
          type="button"
          className="ain-btn ain-pagesec__toggle"
          onClick={() => onToggle(index)}
          aria-expanded={open}
          aria-controls={bodyId}
          title={open ? "Collapse section" : "Expand to edit"}
        >
          <span className="ain-pagesec__cell">
            <span className="ain-pagesec__name">{humanize(section.component)}</span>
            {/* The mono fact line: the section's content hint (the numbered
                circles and per-type color icons are retired — an outline needs
                names, not a rainbow legend). */}
            {summary && <span className="ain-pagesec__sum">{summary}</span>}
          </span>
          <ChevronDownIcon className="ain-pagesec__chev" />
        </button>
        {changed.size > 0 && (
          <span
            className="ain-pagesec__dirty"
            title={`${changed.size} unsaved change${changed.size === 1 ? "" : "s"} in this section`}
          >
            {changed.size}
          </span>
        )}
        {!locked && (
          <RowMenu
            label="Section actions"
            items={[
              { label: "Move up", disabled: index === 0, onPick: () => onMove(index, -1) },
              { label: "Move down", disabled: index === count - 1, onPick: () => onMove(index, 1) },
              { label: "Insert section above", onPick: () => onInsert(index) },
              { label: "Insert section below", onPick: () => onInsert(index + 1) },
              { label: "Remove section", danger: true, onPick: () => onRemove(index) },
            ]}
          />
        )}
      </div>
      {open && (
        <div className="ain-pagesec__body" id={bodyId}>
          {content.length === 0 && appearance.length === 0 ? (
            <p className="ain-pagesec__noprops">This section has no editable fields.</p>
          ) : (
            <>
              {content.map(renderProp)}
              {appearance.length > 0 && (
                <div className="ain-studio__subgroup">
                  <h4 className="ain-studio__subtitle">Appearance</h4>
                  {appearance.map(renderProp)}
                </div>
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}

/* --------------------------------------------------------------- prop controls */

function PropControl({
  prop,
  value,
  imageProps,
  blockComponents,
  blockDefs,
  onChange,
  disabled = false,
  dirty = false,
  onRevert,
}: {
  prop: PropDef;
  value: unknown;
  /** Row-field names that hold an image (forwarded into the rows editor). */
  imageProps: Set<string>;
  /** Accordion panels editor: allowed child components + their prop-schema lookup. */
  blockComponents: string[];
  blockDefs: (component: string) => PropDef[];
  onChange: (value: unknown) => void;
  /** Locked (inherited from the source layout) — shown read-only for context. */
  disabled?: boolean;
  /** This prop differs from the saved baseline — show the marker + revert. */
  dirty?: boolean;
  /** Revert just this prop to its saved value. */
  onRevert?: () => void;
}) {
  const fieldLabel = humanize(prop.name);
  // The per-field marker, rendered into whichever control this prop maps to.
  const revert = dirty && onRevert ? <FieldRevert label={fieldLabel.toLowerCase()} onRevert={onRevert} /> : null;
  // A nested panels list (accordion) → the dedicated panels editor (two levels
  // deep, so the flat rows editor can't model it). Content, never locked.
  if (prop.panels) {
    return (
      <PanelsControl
        prop={prop}
        value={Array.isArray(value) ? (value as Panel[]) : []}
        imageProps={imageProps}
        blockComponents={blockComponents}
        blockDefs={blockDefs}
        onChange={onChange}
        dirty={dirty}
        revert={revert}
      />
    );
  }
  // Repeatable rows (items / features) get their own editor. (These are content,
  // never structural, so they're never locked.)
  if (prop.shape) {
    return (
      <RowsControl
        prop={prop}
        value={Array.isArray(value) ? (value as Record<string, unknown>[]) : []}
        imageProps={imageProps}
        onChange={onChange}
        dirty={dirty}
        revert={revert}
      />
    );
  }
  // An image prop (image / avatar / cover) → a media reference (with upload).
  if (prop.image) {
    return (
      <ReferenceField
        label={fieldLabel}
        meaning={prop.meaning}
        value={value}
        onChange={onChange}
        disabled={disabled}
        types={["media"]}
        allowUpload
        dirty={dirty}
        revert={revert}
      />
    );
  }
  // An embed reference (entity:node:<id> token) → a node reference + view mode.
  if (prop.embed) {
    return (
      <ReferenceField
        label={fieldLabel}
        meaning={prop.meaning}
        value={value}
        onChange={onChange}
        disabled={disabled}
        types={["node"]}
        viewModes={[
          { id: "", label: "Default" },
          { id: "teaser", label: "Teaser" },
          { id: "full", label: "Full" },
        ]}
        dirty={dirty}
        revert={revert}
      />
    );
  }
  // A global-block reference (block:<id> token) → a block reference, edited in-studio.
  if (prop.block) {
    return (
      <ReferenceField
        label={fieldLabel}
        meaning={prop.meaning}
        value={value}
        onChange={onChange}
        disabled={disabled}
        types={["block"]}
        // Editing (or creating) a block opens it in its OWN browser tab — a
        // block is global/reusable, so a standalone editor is the right model,
        // and the page you're composing stays intact in this tab.
        onEdit={(token) => {
          const id = token.split(":")[1];
          if (id) openBlockTab({ id });
        }}
        onCreate={() => openBlockTab("new")}
        createLabel="New block"
        dirty={dirty}
        revert={revert}
      />
    );
  }
  // A typed boolean (e.g. accordion `exclusive`) → a checkbox, never a text
  // input (a typed string 500s the SDC's `type: boolean` prop). Stores a real
  // boolean; coerces any legacy string/number value when reflecting `checked`.
  if (prop.boolean) {
    const on = value === true || value === "true" || value === 1 || value === "1";
    return (
      <label
        className="ain-field ain-field--check"
        data-dirty={dirty || undefined}
        title={disabled ? `${prop.meaning} (inherited from the source layout)` : prop.meaning}
      >
        <input
          type="checkbox"
          className="ain-field__checkbox"
          checked={on}
          onChange={(e) => onChange(e.target.checked)}
          disabled={disabled}
        />
        <span className="ain-field__label">
          <span className="ain-field__labeltext">{fieldLabel}</span>
          {revert}
        </span>
      </label>
    );
  }
  return (
    <label
      className="ain-field"
      data-dirty={dirty || undefined}
      title={disabled ? `${prop.meaning} (inherited from the source layout)` : prop.meaning}
    >
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{fieldLabel}</span>
        {revert}
      </span>
      {prop.enum ? (
        <select
          className="ain-field__input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        >
          {/* An empty option lets the renderer fall back to its default. */}
          <option value="">(default)</option>
          {prop.enum.map((opt) => (
            <option key={opt} value={opt}>
              {humanize(opt)}
            </option>
          ))}
        </select>
      ) : prop.multiline ? (
        <textarea
          className="ain-field__input ain-field__textarea"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
          placeholder={prop.meaning}
          spellCheck={false}
          disabled={disabled}
          rows={8}
        />
      ) : (
        <input
          className="ain-field__input"
          value={String(value ?? "")}
          onChange={(e) => onChange(e.target.value)}
          placeholder={prop.meaning}
          spellCheck={false}
          disabled={disabled}
        />
      )}
    </label>
  );
}

/** Editor for a repeatable prop (a list of homogeneous rows). */
function RowsControl({
  prop,
  value,
  imageProps,
  onChange,
  dirty = false,
  revert,
}: {
  prop: PropDef;
  value: Record<string, unknown>[];
  /** Row-field names that hold an image (render a media picker for them). */
  imageProps: Set<string>;
  onChange: (value: unknown) => void;
  /** The whole repeatable differs from the saved baseline. */
  dirty?: boolean;
  /** The pre-built per-field revert marker (reverts the whole list). */
  revert?: ReactNode;
}) {
  const fields = useMemo(() => shapeFields(prop.shape ?? ""), [prop.shape]);
  const setField = (rowIdx: number, field: string, fieldValue: unknown) => {
    const next = value.map((row, i) => (i === rowIdx ? { ...row, [field]: fieldValue } : row));
    onChange(next);
  };
  const addRow = () => onChange([...value, Object.fromEntries(fields.map((f) => [f, ""]))]);
  const removeRow = (rowIdx: number) => onChange(value.filter((_, i) => i !== rowIdx));

  return (
    <div className="ain-field" data-dirty={dirty || undefined}>
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{humanize(prop.name)}</span>
        {revert}
      </span>
      <div className="ain-rows">
        {value.map((row, rowIdx) => (
          <div className="ain-row" key={rowIdx}>
            <div className="ain-row__fields">
              {fields.map((field) =>
                imageProps.has(field) ? (
                  <ReferenceField
                    key={field}
                    label={humanize(field)}
                    value={row[field]}
                    onChange={(v) => setField(rowIdx, field, v)}
                    types={["media"]}
                    allowUpload
                  />
                ) : (
                  <input
                    key={field}
                    className="ain-field__input"
                    value={String(row[field] ?? "")}
                    onChange={(e) => setField(rowIdx, field, e.target.value)}
                    placeholder={humanize(field)}
                    spellCheck={false}
                  />
                ),
              )}
            </div>
            <button
              className="ain-btn ain-iconbtn ain-row__del"
              onClick={() => removeRow(rowIdx)}
              aria-label="Remove row"
              title="Remove row"
            >
              <XIcon />
            </button>
          </div>
        ))}
        <button type="button" className="ain-chip" onClick={addRow}>
          <PlusIcon />
          <span className="ain-chip__label">Add {humanize(prop.name).toLowerCase()} row</span>
        </button>
      </div>
    </div>
  );
}

/** One content block inside an accordion panel — a leaf component + its props. */
type Block = { component: string; props?: Record<string, unknown> };
/** One accordion disclosure panel — a label, initial open state, content blocks. */
type Panel = { label?: string; open?: boolean; blocks?: Block[] };

/**
 * Editor for an accordion's `panels` prop — a NESTED, two-level structure the
 * flat RowsControl can't model. Each panel carries a label, an initial-open
 * flag, and a bounded list of CHILD content blocks; each block is one allow-
 * listed leaf component ({@see Manifest.accordion_blocks}) edited with the SAME
 * PropControl sub-form as a top-level section. One level deep only — a child is
 * never a container, so the sub-form never offers another panels prop.
 */
function PanelsControl({
  prop,
  value,
  imageProps,
  blockComponents,
  blockDefs,
  onChange,
  dirty = false,
  revert,
}: {
  prop: PropDef;
  value: Panel[];
  imageProps: Set<string>;
  /** Allow-listed child components offered in each block's component picker. */
  blockComponents: string[];
  /** Prop-schema lookup for a child component (children are leaf sections). */
  blockDefs: (component: string) => PropDef[];
  onChange: (value: unknown) => void;
  dirty?: boolean;
  revert?: ReactNode;
}) {
  const setPanel = (panelIdx: number, patch: Partial<Panel>) =>
    onChange(value.map((p, i) => (i === panelIdx ? { ...p, ...patch } : p)));
  const addPanel = () => onChange([...value, { label: "", open: false, blocks: [] }]);
  const removePanel = (panelIdx: number) => onChange(value.filter((_, i) => i !== panelIdx));

  // Block mutators operate on one panel's `blocks` list (computed per call so
  // they always see the current panel state).
  const blocksOf = (panel: Panel): Block[] => (Array.isArray(panel.blocks) ? panel.blocks : []);
  const addBlock = (panelIdx: number) =>
    setPanel(panelIdx, { blocks: [...blocksOf(value[panelIdx]), { component: blockComponents[0] ?? "", props: {} }] });
  const removeBlock = (panelIdx: number, blockIdx: number) =>
    setPanel(panelIdx, { blocks: blocksOf(value[panelIdx]).filter((_, i) => i !== blockIdx) });
  // Switching a block's component resets its props — prop schemas differ per
  // component, so a stale value would just be dropped server-side anyway.
  const setBlockComponent = (panelIdx: number, blockIdx: number, component: string) =>
    setPanel(panelIdx, {
      blocks: blocksOf(value[panelIdx]).map((b, i) => (i === blockIdx ? { component, props: {} } : b)),
    });
  const setBlockProp = (panelIdx: number, blockIdx: number, name: string, val: unknown) =>
    setPanel(panelIdx, {
      blocks: blocksOf(value[panelIdx]).map((b, i) => {
        if (i !== blockIdx) return b;
        const props = { ...(b.props ?? {}) };
        if (val === "" || val === undefined || val === null) delete props[name];
        else props[name] = val;
        return { ...b, props };
      }),
    });

  // Only a single allowed child type → the picker is redundant; hide it.
  const showPicker = blockComponents.length > 1;

  return (
    <div className="ain-field" data-dirty={dirty || undefined}>
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{humanize(prop.name)}</span>
        {revert}
      </span>
      <div className="ain-panels">
        {value.map((panel, panelIdx) => {
          const blocks = blocksOf(panel);
          return (
            <div className="ain-panel" key={panelIdx}>
              <div className="ain-panel__head">
                <input
                  className="ain-field__input ain-panel__label"
                  value={String(panel.label ?? "")}
                  onChange={(e) => setPanel(panelIdx, { label: e.target.value })}
                  placeholder={`Panel ${panelIdx + 1} label`}
                  spellCheck
                />
                <label className="ain-panel__open" title="Expanded when the page first loads">
                  <input
                    type="checkbox"
                    className="ain-field__checkbox"
                    checked={panel.open === true}
                    onChange={(e) => setPanel(panelIdx, { open: e.target.checked })}
                  />
                  <span>Open</span>
                </label>
                <button
                  className="ain-btn ain-iconbtn"
                  onClick={() => removePanel(panelIdx)}
                  aria-label="Remove panel"
                  title="Remove panel"
                >
                  <XIcon />
                </button>
              </div>
              <div className="ain-panel__blocks">
                {blocks.map((block, blockIdx) => (
                  <div className="ain-block" key={blockIdx}>
                    <div className="ain-block__head">
                      {showPicker ? (
                        <select
                          className="ain-field__input ain-block__pick"
                          value={block.component}
                          onChange={(e) => setBlockComponent(panelIdx, blockIdx, e.target.value)}
                        >
                          {blockComponents.map((c) => (
                            <option key={c} value={c}>
                              {humanize(c)}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <span className="ain-block__type">{humanize(block.component)}</span>
                      )}
                      <button
                        className="ain-btn ain-iconbtn"
                        onClick={() => removeBlock(panelIdx, blockIdx)}
                        aria-label="Remove block"
                        title="Remove block"
                      >
                        <XIcon />
                      </button>
                    </div>
                    <div className="ain-block__props">
                      {blockDefs(block.component)
                        // `tone` is section-band chrome — children render `bare`,
                        // so a per-block tone is meaningless; drop it from the form.
                        .filter((p) => p.name !== "tone")
                        .map((p) => (
                          <PropControl
                            key={p.name}
                            prop={p}
                            value={block.props?.[p.name]}
                            imageProps={imageProps}
                            blockComponents={blockComponents}
                            blockDefs={blockDefs}
                            onChange={(v) => setBlockProp(panelIdx, blockIdx, p.name, v)}
                          />
                        ))}
                    </div>
                  </div>
                ))}
                <button
                  type="button"
                  className="ain-chip ain-panel__addblock"
                  onClick={() => addBlock(panelIdx)}
                  disabled={blockComponents.length === 0}
                >
                  <PlusIcon />
                  <span className="ain-chip__label">Add block</span>
                </button>
              </div>
            </div>
          );
        })}
        <button type="button" className="ain-chip" onClick={addPanel}>
          <PlusIcon />
          <span className="ain-chip__label">Add panel</span>
        </button>
      </div>
    </div>
  );
}
