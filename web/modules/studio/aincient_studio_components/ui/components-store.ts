import { useSyncExternalStore } from "react";
import { apiUrl } from "@console/sdk";
import {
  cloneConstraintDraft,
  cloneKindDraft,
  countDirty,
  countKindDirty,
  DEFAULT_VIEW,
  reseed,
  seedConstraintDraft,
  seedKindDraft,
  SITE_SCOPE,
  viewForSelection,
  type ConstraintDraft,
  type ConstraintManifest,
  type KindDraft,
  type KindState,
  type PreviewView,
  type ScopeInfo,
  type Staged,
} from "./constraint-model";

/**
 * The Components studio's one store — shared by the rail (`Studio`) and the
 * canvas (`Preview`), which the console mounts as two siblings. Module-level,
 * no library: an immutable snapshot, `subscribe`/`get`/`set`, and hooks over
 * `useSyncExternalStore`.
 *
 * Two halves that never mix: the DRAFT (the four removal keys plus the
 * overrides switched off, staged against the `baseline` the manifest seeded —
 * what Publish sends) and the VIEW (which component is selected and how the
 * preview shows it — example, variant, tone, width, and Pack | Original |
 * Compare for an overridden one). Changing the view never touches the draft,
 * so looking at a variant the site has switched off is free.
 *
 * `setDraft` is the write seam a later agent integration uses (P2 writes the
 * agent's proposals into the same draft the rail edits).
 *
 * SCOPES (P1b): the rail edits one "Applies to" scope at a time — the site
 * (`draft`/`baseline` above) or one kind (`kinds[id]`, loaded on first visit).
 * Each scope keeps its OWN staged draft: switching scopes never drops or
 * merges anything, and Publish/Discard act on the current scope only.
 */

/** One kind scope: its server state and its staged draft. */
export type KindScope = Staged<KindDraft> & { state: KindState };

export type ComponentsState = {
  manifest: ConstraintManifest | null;
  baseline: ConstraintDraft | null;
  draft: ConstraintDraft | null;
  /** The manifest load failed (the rail shows it). */
  loadError: string | null;
  selected: string | null;
  view: PreviewView;
  /** The scope the rail edits: SITE_SCOPE or a kind id. */
  scope: string;
  /** The "Applies to" menu, from the manifest (refreshed by every kind load). */
  scopes: ScopeInfo[];
  /** Loaded kind scopes, by kind id. */
  kinds: Record<string, KindScope>;
  /** A kind load failed (the rail shows it), by kind id. */
  kindErrors: Record<string, string>;
};

let state: ComponentsState = {
  manifest: null,
  baseline: null,
  draft: null,
  loadError: null,
  selected: null,
  view: DEFAULT_VIEW,
  scope: SITE_SCOPE,
  scopes: [],
  kinds: {},
  kindErrors: {},
};
const listeners = new Set<() => void>();

export function getComponentsState(): ComponentsState {
  return state;
}

export function subscribeComponents(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function set(patch: Partial<ComponentsState>): void {
  state = { ...state, ...patch };
  for (const fn of listeners) fn();
}

/**
 * Take a fresh manifest (the load, or the state a save returns). The baseline
 * is always re-seeded; the draft too, unless `keepDirtyDraft` and the draft had
 * staged changes — then those survive a background refresh.
 */
export function setManifest(manifest: ConstraintManifest, keepDirtyDraft = false): void {
  const prev = state.baseline && state.draft ? { baseline: state.baseline, draft: state.draft } : undefined;
  const next = reseed(prev, seedConstraintDraft(manifest), keepDirtyDraft, countDirty, cloneConstraintDraft);
  set({
    manifest,
    ...next,
    scopes: manifest.scopes ?? state.scopes,
    loadError: null,
  });
}

/** Replace the staged draft (the rail's ticks; later, agent proposals). */
export function setDraft(draft: ConstraintDraft): void {
  set({ draft });
}

/** Back to the saved constraint. */
export function discardDraft(): void {
  if (state.baseline) set({ draft: cloneConstraintDraft(state.baseline) });
}

/** Switch the scope the rail edits — a kind's state loads on its first visit. */
export function setScope(scope: string): void {
  if (scope === state.scope) return;
  set({ scope });
  if (scope !== SITE_SCOPE && !state.kinds[scope]) void loadKind(scope);
}

/** Take a kind's fresh state (its load, or what its save returns). */
export function setKindState(kindState: KindState, keepDirtyDraft = false): void {
  const id = kindState.kind.id;
  const dirty = (a: KindDraft, b: KindDraft) => countKindDirty(a, b, componentNames(state));
  const next = reseed(state.kinds[id], seedKindDraft(kindState), keepDirtyDraft, dirty, cloneKindDraft);
  const kindErrors = Object.fromEntries(Object.entries(state.kindErrors).filter(([k]) => k !== id));
  set({
    kinds: { ...state.kinds, [id]: { ...next, state: kindState } },
    kindErrors,
    scopes: kindState.scopes ?? state.scopes,
  });
}

/** Replace one kind's staged draft. */
export function setKindDraft(id: string, draft: KindDraft): void {
  const k = state.kinds[id];
  if (k) set({ kinds: { ...state.kinds, [id]: { ...k, draft } } });
}

/** One kind back to its saved settings. */
export function discardKindDraft(id: string): void {
  const k = state.kinds[id];
  if (k) set({ kinds: { ...state.kinds, [id]: { ...k, draft: cloneKindDraft(k.baseline) } } });
}

/** The current scope's unsaved-change count (the dirty badge, the preview header). */
export function scopeDirty(s: ComponentsState): number {
  if (s.scope === SITE_SCOPE) return s.baseline && s.draft ? countDirty(s.baseline, s.draft) : 0;
  const k = s.kinds[s.scope];
  return k ? countKindDirty(k.baseline, k.draft, componentNames(s)) : 0;
}

/** Every component the rail lists — the kind dirty count's universe. */
function componentNames(s: ComponentsState): string[] {
  return s.manifest?.components.map((c) => c.name) ?? [];
}

const kindInflight = new Map<string, Promise<void>>();

/**
 * (Re)load one kind's state. Concurrent callers share one request; a draft
 * with staged changes survives the refresh.
 */
export function loadKind(id: string): Promise<void> {
  const running = kindInflight.get(id);
  if (running) return running;
  const p = fetch(apiUrl(`/constraint/kind/${encodeURIComponent(id)}`), { credentials: "same-origin" })
    .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
    .then((data: KindState) => setKindState(data, true))
    .catch((e) => set({ kindErrors: { ...state.kindErrors, [id]: String(e instanceof Error ? e.message : e) } }))
    .finally(() => {
      kindInflight.delete(id);
    });
  kindInflight.set(id, p);
  return p;
}

/** Refresh every loaded kind (a site publish changes what they show locked). */
export function reloadKinds(): void {
  for (const id of Object.keys(state.kinds)) void loadKind(id);
}

/**
 * Select a component (or none → the contact sheet). A new selection resets
 * example/variant/tone and the Pack | Original | Compare switch, keeps width.
 */
export function selectComponent(name: string | null): void {
  if (name === state.selected) return;
  set({ selected: name, view: viewForSelection(state.view) });
}

/** Change the view strip — never the draft. */
export function setView(patch: Partial<PreviewView>): void {
  set({ view: { ...state.view, ...patch } });
}

let inflight: Promise<void> | null = null;

/**
 * (Re)load the manifest. Concurrent callers share one request; a draft with
 * staged changes survives the refresh (so switching away and back keeps them).
 */
export function loadManifest(): Promise<void> {
  if (inflight) return inflight;
  inflight = fetch(apiUrl("/constraint/manifest"), { credentials: "same-origin" })
    .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
    .then((data: ConstraintManifest) => setManifest(data, true))
    .catch((e) => set({ loadError: String(e instanceof Error ? e.message : e) }))
    .finally(() => {
      inflight = null;
    });
  return inflight;
}

/** The whole snapshot. */
export function useComponentsState(): ComponentsState {
  return useSyncExternalStore(subscribeComponents, getComponentsState, getComponentsState);
}

/** One slice — re-renders only when that slice's identity changes. */
export function useComponents<T>(pick: (s: ComponentsState) => T): T {
  return useSyncExternalStore(
    subscribeComponents,
    () => pick(state),
    () => pick(state),
  );
}
