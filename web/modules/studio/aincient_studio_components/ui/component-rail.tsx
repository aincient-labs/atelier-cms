import { useCallback, useEffect, useMemo, useState } from "react";
import {
  AlertCircleIcon,
  Button,
  Checkbox,
  ComponentIcon,
  IconButton,
  LoadingState,
  Notice,
  PanelBar,
  StudioActionsPortal,
  StudioGroup,
  StudioStatus,
  useStudioUI,
  XIcon,
} from "@console/kit";
import {
  apiUrl,
  fieldAnchorId,
  focusStudioField,
  offerWrapup,
  requestedFieldAnchor,
  useConsoleChat,
} from "@console/sdk";
import {
  filterEntries,
  groupEntries,
  humanizeName,
  isEnabled,
  isLastEnabledTone,
  isOn,
  isOverrideOff,
  isPack,
  isRecipe,
  kindStatusLine,
  newImpacts,
  setComponentEnabled,
  setComponentToneEnabled,
  setOverrideOff,
  setToneEnabled,
  setVariantEnabled,
  SITE_SCOPE,
  statusLine,
  toKindSavePayload,
  toneTicks,
  toSavePayload,
  usageTotal,
  variantTicks,
  type CheckResult,
  type ComponentEntry,
  type ComponentFilter,
  type ConstraintDraft,
  type ConstraintManifest,
  type ImpactRow,
  type KindDraft,
  type KindState,
  componentSummary,
} from "./constraint-model";
import {
  discardDraft,
  discardKindDraft,
  loadManifest,
  reloadKinds,
  scopeDirty,
  selectComponent,
  setDraft,
  setKindDraft,
  setKindState,
  setManifest,
  setScope,
  useComponentsState,
} from "./components-store";
import { KindScopeBody } from "./kind-scope";
import {
  BlockedGroup,
  ComponentTools,
  ImpactPanel,
  OverrideBadge,
  ScopeMenu,
  TickList,
  UsedOn,
  type UsageCache,
  type UsageResult,
} from "./rail-parts";

/**
 * The Components studio's editor rail (the `Studio` export) — the site-wide
 * narrowing layer over the discovered component vocabulary
 * (plans/byo-components.md W1b, DECISIONS 0455). Checked = available;
 * unchecking stages a REMOVAL. The rail can only narrow what discovery
 * admitted — never invent or re-admit anything.
 *
 * One row per component, grouped by role. A row's checkbox is the on/off; the
 * rest of the row SELECTS it — the canvas previews it — and expands it in place:
 * what it is for, its own variants and tones, and where it is used. The site-wide
 * tone ticks stay at the foot. State is the studio's store (components-store.ts),
 * shared with the canvas. Publish sends the WHOLE staged slice and re-seeds from
 * the fresh state the save returns; a 422 keeps the draft and shows the error.
 *
 * "Applies to" (P1b): the menu at the top switches the rail between the site
 * scope (this file's body) and one kind (`kind-scope.tsx`); each scope keeps its
 * own staged draft and Publish/Discard act on the current one. Publish dry-runs
 * first (D6): if the change adds impacts on existing pages, the impact panel
 * lists them and asks "Publish anyway".
 *
 * Packs (P3, P4b): a built-in a pack overrides (SDC `replaces:`) carries a
 * badge, and its expanded row stages "Use original instead" into the same
 * draft (`overrides_off`); pack components the admission gate refused close the
 * list as "Can't be used", never tickable.
 */

const SAVE_URL = apiUrl("/constraint/save");
const CHECK_URL = apiUrl("/constraint/check");

/** POST JSON the way every write here does; a non-2xx throws with the server's `error`. */
async function postJson<T>(url: string, body: unknown): Promise<T> {
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
  return data as T;
}

function ComponentRow({ entry, draft, selected, onDraft, usageCache, onUsage }: {
  entry: ComponentEntry;
  draft: ConstraintDraft;
  selected: boolean;
  onDraft: (next: ConstraintDraft) => void;
  usageCache: UsageCache;
  onUsage: (name: string, result: UsageResult | string) => void;
}) {
  const on = isOn(draft, entry.name);
  const overrideOff = isOverrideOff(draft, entry);
  const label = humanizeName(entry.name);
  const total = usageTotal(entry);
  const panelId = `ain-components-panel-${entry.name}`;
  return (
    <li
      className="ain-components__item"
      data-selected={selected || undefined}
      data-off={!on || undefined}
      data-ain-component-row={entry.name}
    >
      <div className="ain-components__row">
        <input
          className="ain-field__checkbox"
          type="checkbox"
          checked={on}
          aria-label={`Offer ${label}`}
          title={on ? `${label} is offered — untick to stop new placements` : `${label} is off — tick to offer it again`}
          onChange={(e) => onDraft(setComponentEnabled(draft, entry.name, e.target.checked))}
        />
        <button
          type="button"
          className="ain-components__select"
          aria-expanded={selected}
          aria-controls={panelId}
          title={componentSummary(entry) || undefined}
          onClick={() => selectComponent(selected ? null : entry.name)}
        >
          <span className="ain-components__icon">
            <ComponentIcon name={entry.name} />
          </span>
          <span className="ain-components__name">{label}</span>
          {isPack(entry) && <span className="ain-components__tag">{entry.provider}</span>}
          <OverrideBadge entry={entry} off={overrideOff} />
          <span
            className="ain-components__count"
            title={`Used on ${total} page${total === 1 ? "" : "s"} or block${total === 1 ? "" : "s"}`}
          >
            {total}
          </span>
        </button>
      </div>
      {selected && (
        <div className="ain-components__panel" id={panelId}>
          {componentSummary(entry) && <p className="ain-components__use">{componentSummary(entry)}</p>}
          {entry.replaced_by && (
            <div className="ain-components__override-switch">
              <Checkbox
                className="ain-components__tick"
                label="Use original instead"
                checked={overrideOff}
                onChange={(e) => onDraft(setOverrideOff(draft, entry, e.target.checked))}
              />
              <p className="ain-components__hint">
                {overrideOff
                  ? `Every page renders the original ${label}; ${entry.replaced_by.label} from ${entry.replaced_by.provider} is switched off.`
                  : `${entry.replaced_by.label} from ${entry.replaced_by.provider} renders in place of the original on every page.`}
              </p>
            </div>
          )}
          <TickList
            title="Variants"
            ticks={variantTicks(draft, entry)}
            onChange={(v, checked) => onDraft(setVariantEnabled(draft, entry.name, v, checked, entry.variants))}
          />
          <TickList
            title="Tones"
            ticks={toneTicks(draft, entry)}
            onChange={(t, checked) => onDraft(setComponentToneEnabled(draft, entry, t, checked))}
          />
          {on && (
            <p className="ain-components__hint">
              Turning it off stops new placements — pages that already use it keep it.
            </p>
          )}
          <UsedOn entry={entry} cache={usageCache} onLoaded={onUsage} />
        </div>
      )}
    </li>
  );
}

export function ComponentRail({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  const runtime = useConsoleChat();
  const componentsState = useComponentsState();
  const { manifest, baseline, draft, loadError, selected, scope, scopes, kinds, kindErrors } = componentsState;
  const [publishing, setPublishing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  /** The impact list Publish found (D6) — shown until Publish anyway / Cancel / an edit. */
  const [impact, setImpact] = useState<ImpactRow[] | null>(null);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<ComponentFilter>("all");
  const [usageCache, setUsageCache] = useState<UsageCache>({});

  const site = scope === SITE_SCOPE;
  const kindScope = site ? undefined : kinds[scope];
  const scopeInfo = site ? null : scopes.find((s) => s.id === scope) ?? kindScope?.state.kind ?? null;

  useEffect(() => {
    void loadManifest();
  }, []);

  // A scope switch drops the outcome lines and any impact list (they belonged to the other scope).
  useEffect(() => {
    setNotice(null);
    setError(null);
    setImpact(null);
  }, [scope]);

  // Land the operator ON a requested field (deep-link `?field=…`).
  const ready = draft !== null;
  useEffect(() => {
    if (!ready) return;
    const key = requestedFieldAnchor();
    if (key) focusStudioField(key);
  }, [ready]);

  // A selection made on the canvas brings its row into view.
  useEffect(() => {
    if (!selected) return;
    const row = document.querySelector(`[data-ain-component-row="${CSS.escape(selected)}"]`);
    row?.scrollIntoView?.({ block: "nearest" });
  }, [selected]);

  const dirty = useMemo(() => scopeDirty(componentsState), [componentsState]);

  const update = useCallback((next: ConstraintDraft) => {
    setNotice(null);
    setImpact(null);
    setDraft(next);
  }, []);

  const updateKind = useCallback(
    (next: KindDraft) => {
      setNotice(null);
      setImpact(null);
      setKindDraft(scope, next);
    },
    [scope],
  );

  const onUsage = useCallback((name: string, result: UsageResult | string) => {
    setUsageCache((c) => ({ ...c, [name]: result }));
  }, []);

  /** Save the current scope's staged draft for real. */
  const save = useCallback(async () => {
    setPublishing(true);
    setError(null);
    try {
      if (site) {
        if (!draft) return;
        // The whole staged slice — each key REPLACES its stored list, so the
        // publish is exactly what the rail shows, nothing merged underneath it.
        setManifest(await postJson<ConstraintManifest>(SAVE_URL, toSavePayload(draft)));
        // What a kind shows locked ("Off everywhere") just changed.
        reloadKinds();
      } else {
        if (!kindScope) return;
        const url = apiUrl(`/constraint/kind/${encodeURIComponent(scope)}/save`);
        setKindState(await postJson<KindState>(url, toKindSavePayload(kindScope.draft)));
      }
      setImpact(null);
      setUsageCache({});
      setNotice(site ? "Component governance published to the live site." : `Published for ${scopeInfo?.label ?? scope}.`);
      offerWrapup(runtime.activeThread().remoteId);
    } catch (e) {
      // A 422 (e.g. every tone removed) keeps the draft so nothing staged is lost.
      setError(`Couldn’t publish: ${e instanceof Error ? e.message : e}`);
    } finally {
      setPublishing(false);
    }
  }, [site, draft, kindScope, scope, scopeInfo, runtime]);

  /**
   * Publish = dry run first (D6): check the staged draft AND the saved state,
   * and list only the impacts this change adds. None → publish straight away;
   * some → the impact panel asks "Publish anyway".
   */
  const publish = useCallback(async () => {
    const body = site
      ? draft && baseline && { staged: toSavePayload(draft), saved: toSavePayload(baseline) }
      : kindScope && { staged: toKindSavePayload(kindScope.draft), saved: toKindSavePayload(kindScope.baseline) };
    if (!body) return;
    setPublishing(true);
    setError(null);
    setNotice(null);
    let added: ImpactRow[];
    try {
      const [after, before] = await Promise.all([
        postJson<CheckResult>(CHECK_URL, { scope, draft: body.staged }),
        postJson<CheckResult>(CHECK_URL, { scope, draft: body.saved }),
      ]);
      added = newImpacts(before.rows ?? [], after.rows ?? []);
    } catch (e) {
      setError(`Couldn’t check which pages this affects: ${e instanceof Error ? e.message : e}`);
      setPublishing(false);
      return;
    }
    if (added.length > 0) {
      setImpact(added);
      setPublishing(false);
      return;
    }
    await save();
  }, [site, draft, baseline, kindScope, scope, save]);

  const discard = useCallback(() => {
    if (site) discardDraft();
    else discardKindDraft(scope);
    setNotice(null);
    setError(null);
    setImpact(null);
  }, [site, scope]);

  const entries = manifest?.components ?? [];
  const groups = useMemo(
    () => (draft ? groupEntries(filterEntries(entries, draft, filter, query)) : []),
    [entries, draft, filter, query],
  );
  const siteTones = manifest?.vocabulary.tones ?? [];
  const warnings = manifest?.effective.warnings ?? [];
  const line = site
    ? draft ? statusLine(entries, draft, dirty) : ""
    : kindScope ? kindStatusLine(entries, kindScope.draft, kindScope.state.site_off.components, dirty) : "";
  const shownError = error ?? (loadError ? `Couldn’t load the components: ${loadError}` : null);
  const recipe = !site && isRecipe(scopeInfo);

  return (
    <div className="ain-studio__rail" data-testid="studio-rail" data-studio="components">
      <StudioActionsPortal>
        {dirty > 0 && (
          <span
            className="ain-studio-actions__dirty"
            title={`${dirty} unsaved change${dirty === 1 ? "" : "s"}`}
          >
            {dirty}
          </span>
        )}
        <Button
          onClick={discard}
          disabled={dirty === 0 || publishing}
          title={site ? "Discard draft — revert to the saved constraint" : "Discard draft — revert this page type to its saved settings"}
        >
          Discard
        </Button>
        <Button
          variant="primary"
          onClick={() => void publish()}
          disabled={dirty === 0 || publishing || impact !== null}
          title="Publish the component governance to the live site"
        >
          {publishing ? "Publishing…" : "Publish"}
        </Button>
        <IconButton
          className="ain-topbar__leave"
          onClick={onClose}
          label="Close components studio"
          title="Leave components studio"
        >
          <XIcon />
        </IconButton>
      </StudioActionsPortal>

      <PanelBar
        title="Components"
        actions={
          <IconButton
            className="ain-studio__sheetclose"
            onClick={closeSheets}
            label="Hide editor"
            title="Hide editor"
          >
            <XIcon />
          </IconButton>
        }
      />

      {scopes.length > 0 && <ScopeMenu scope={scope} scopes={scopes} onChange={setScope} />}

      {!recipe && (
        <StudioStatus
          dirty={dirty > 0 && <>{line} · nothing applied until Publish</>}
          saved={notice}
        >
          {line || "Matches the saved constraint"}
        </StudioStatus>
      )}

      {shownError && <Notice tone="error" panel>{shownError}</Notice>}
      {!manifest && !loadError && <LoadingState variant="fields" label="Loading components" />}

      {impact && (
        <ImpactPanel
          rows={impact}
          busy={publishing}
          onPublish={() => void save()}
          onCancel={() => setImpact(null)}
        />
      )}

      {site && warnings.length > 0 && (
        <ul className="ain-components__warnings">
          {warnings.map((w, i) => (
            <li key={i} className="ain-components__warning">
              <AlertCircleIcon /> {w}
            </li>
          ))}
        </ul>
      )}

      {!site && manifest && (
        <KindScopeBody
          kindId={scope}
          scope={kindScope}
          error={kindErrors[scope]}
          manifest={manifest}
          selected={selected}
          query={query}
          filter={filter}
          onQuery={setQuery}
          onFilter={setFilter}
          onDraft={updateKind}
          usageCache={usageCache}
          onUsage={onUsage}
        />
      )}

      {site && manifest && draft && (
        <div className="ain-studio__groups">
          <StudioGroup
            id={fieldAnchorId("constraint.components")}
            title="Components"
            note="Which building blocks this site uses. Unchecked components can no longer be placed. Sections already on pages stay and keep rendering — Content marks them “No longer offered” — but the agent and the studios stop offering them."
          >
            <ComponentTools query={query} filter={filter} onQuery={setQuery} onFilter={setFilter} />
            {groups.length === 0 && (
              <p className="ain-components__empty">No components match.</p>
            )}
            {groups.map((g) => (
              <section key={g.id} className="ain-components__group" aria-labelledby={`ain-components-group-${g.id}`}>
                <h4 id={`ain-components-group-${g.id}`} className="ain-components__grouptitle">{g.label}</h4>
                <ul className="ain-components__list">
                  {g.entries.map((entry) => (
                    <ComponentRow
                      key={entry.name}
                      entry={entry}
                      draft={draft}
                      selected={selected === entry.name}
                      onDraft={update}
                      usageCache={usageCache}
                      onUsage={onUsage}
                    />
                  ))}
                </ul>
              </section>
            ))}
            <BlockedGroup blocked={manifest.blocked} filter={filter} query={query} />
          </StudioGroup>

          <StudioGroup
            id={fieldAnchorId("constraint.tones")}
            title="Tones everywhere"
            note="The colour moods sections may take, site-wide. A tone removed here is off for every component. At least one tone must remain available."
          >
            <div className="ain-components__tickrow">
              {siteTones.map((tone) => {
                const last = isLastEnabledTone(draft, tone, siteTones);
                return (
                  <Checkbox
                    key={tone}
                    className="ain-components__tick"
                    label={humanizeName(tone)}
                    checked={isEnabled(draft.tones, tone)}
                    disabled={last}
                    title={last ? "The last remaining tone can’t be disabled" : undefined}
                    onChange={(e) => update(setToneEnabled(draft, tone, e.target.checked, siteTones))}
                  />
                );
              })}
            </div>
          </StudioGroup>
        </div>
      )}
    </div>
  );
}
