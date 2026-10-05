import { useMemo } from "react";
import {
  AlertCircleIcon,
  ComponentIcon,
  Field,
  LoadingState,
  Notice,
  RadioCard,
  Select,
  StudioGroup,
  TextInput,
} from "@console/kit";
import {
  filterEntriesBy,
  groupEntries,
  humanizeName,
  isKindOn,
  isPack,
  isRecipe,
  KIND_AUTOMATIC_REASON,
  kindAllowed,
  kindTicks,
  openerOptions,
  setIncludeNew,
  setKindComponentOn,
  setKindLimit,
  setKindNarrow,
  setKindOpener,
  SITE_SCOPE,
  usageTotal,
  type ComponentEntry,
  type ComponentFilter,
  type ConstraintManifest,
  type KindDraft,
  componentSummary,
} from "./constraint-model";
import { selectComponent, setScope, type KindScope } from "./components-store";
import {
  BlockedGroup,
  ComponentTools,
  OverrideBadge,
  TickList,
  UsedOn,
  type UsageCache,
  type UsageResult,
} from "./rail-parts";

/**
 * The rail body for ONE kind scope (P1b, DECISIONS 0455/0456): the same grouped
 * rows as the site scope, but a tick edits the KIND — its allow-list or its deny
 * list, depending on the "New components from packs" regime — plus what only a
 * kind has: a required opener and a per-page limit per component. Everything the
 * SITE layer turned off is shown ticked-off and locked, with a way back to
 * Everywhere: a kind can only narrow the site's palette further. A recipe kind
 * has a fixed layout and shows only a notice. An override badge shows the
 * SAVED site's choice (switching back to the original is a site-wide setting),
 * and the gate-refused pack components close the list as "Can't be used".
 */

/** Values the saved site constraint removed for one component (locked off here). */
function siteRemovedVariants(manifest: ConstraintManifest, name: string): string[] {
  return manifest.constraint.variants?.[name] ?? [];
}

function siteRemovedTones(manifest: ConstraintManifest, siteOffTones: string[], name: string): string[] {
  return [...siteOffTones, ...(manifest.constraint.component_tones?.[name] ?? [])];
}

function KindRow({ entry, manifest, scope, selected, onDraft, usageCache, onUsage }: {
  entry: ComponentEntry;
  manifest: ConstraintManifest;
  scope: KindScope;
  selected: boolean;
  onDraft: (next: KindDraft) => void;
  usageCache: UsageCache;
  onUsage: (name: string, result: UsageResult | string) => void;
}) {
  const { draft, state } = scope;
  const siteOff = state.site_off.components.includes(entry.name);
  const on = !siteOff && isKindOn(draft, entry.name);
  const label = humanizeName(entry.name);
  const total = usageTotal(entry);
  const panelId = `ain-components-kpanel-${entry.name}`;
  const variantsOff = siteRemovedVariants(manifest, entry.name);
  const tonesOff = siteRemovedTones(manifest, state.site_off.tones, entry.name);
  const limit = draft.limits[entry.name];
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
          disabled={siteOff}
          aria-label={`Offer ${label} here`}
          title={
            siteOff
              ? `${label} is off everywhere — turn it back on under Everywhere`
              : on
                ? `${label} is offered here — untick to stop new placements`
                : `${label} is off here — tick to offer it`
          }
          onChange={(e) => onDraft(setKindComponentOn(draft, entry.name, e.target.checked))}
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
          <OverrideBadge entry={entry} off={Boolean(entry.override_off)} />
          <span
            className="ain-components__count"
            title={`Used on ${total} page${total === 1 ? "" : "s"} or block${total === 1 ? "" : "s"}`}
          >
            {total}
          </span>
        </button>
        {siteOff && (
          <button
            type="button"
            className="ain-components__sitelink"
            title="Turned off for the whole site — open Everywhere to change it"
            onClick={() => setScope(SITE_SCOPE)}
          >
            Off everywhere
          </button>
        )}
      </div>
      {selected && (
        <div className="ain-components__panel" id={panelId}>
          {componentSummary(entry) && <p className="ain-components__use">{componentSummary(entry)}</p>}
          {siteOff ? (
            <p className="ain-components__hint">
              Turned off for the whole site, so no page type can offer it.{" "}
              <button type="button" className="ain-components__sitelink" onClick={() => setScope(SITE_SCOPE)}>
                Change it under Everywhere
              </button>
            </p>
          ) : (
            <>
              <TickList
                title="Variants"
                ticks={kindTicks(draft, entry.name, "variants", entry.variants, variantsOff)}
                onChange={(v, checked) =>
                  onDraft(setKindNarrow(draft, entry.name, "variants", v, checked, kindAllowed(entry.variants, variantsOff)))
                }
              />
              <TickList
                title="Tones"
                ticks={kindTicks(draft, entry.name, "tones", entry.tones, tonesOff)}
                onChange={(t, checked) =>
                  onDraft(setKindNarrow(draft, entry.name, "tones", t, checked, kindAllowed(entry.tones, tonesOff)))
                }
              />
              {draft.include_new && (entry.variants.length > 0 || entry.tones.length > 0) && (
                <p className="ain-components__hint">{KIND_AUTOMATIC_REASON}</p>
              )}
              <Field label="Max per page" hint="Blank = no limit.">
                <TextInput
                  type="number"
                  min={1}
                  step={1}
                  inputMode="numeric"
                  className="ain-components__limit"
                  value={limit ?? ""}
                  onChange={(e) =>
                    onDraft(setKindLimit(draft, entry.name, e.target.value === "" ? null : Number(e.target.value)))
                  }
                />
              </Field>
            </>
          )}
          <UsedOn entry={entry} cache={usageCache} onLoaded={onUsage} />
        </div>
      )}
    </li>
  );
}

export function KindScopeBody({ kindId, scope, error, manifest, selected, query, filter, onQuery, onFilter, onDraft, usageCache, onUsage }: {
  kindId: string;
  scope: KindScope | undefined;
  error: string | undefined;
  manifest: ConstraintManifest;
  selected: string | null;
  query: string;
  filter: ComponentFilter;
  onQuery: (q: string) => void;
  onFilter: (f: ComponentFilter) => void;
  onDraft: (next: KindDraft) => void;
  usageCache: UsageCache;
  onUsage: (name: string, result: UsageResult | string) => void;
}) {
  const entries = manifest.components;
  const names = useMemo(() => entries.map((e) => e.name), [entries]);
  const groups = useMemo(() => {
    if (!scope) return [];
    const siteOff = scope.state.site_off.components;
    const on = (e: ComponentEntry) => !siteOff.includes(e.name) && isKindOn(scope.draft, e.name);
    return groupEntries(filterEntriesBy(entries, on, filter, query));
  }, [entries, scope, filter, query]);

  if (error) return <Notice tone="error" panel>{`Couldn’t load this page type: ${error}`}</Notice>;
  if (!scope) return <LoadingState variant="fields" label={`Loading ${kindId}`} />;

  const { draft, state } = scope;
  const kind = state.kind;
  if (isRecipe(kind)) {
    return (
      <div className="ain-studio__groups">
        <p className="ain-components__notice" data-testid="components-recipe">
          This page type has a fixed layout — there is nothing to place.
        </p>
      </div>
    );
  }
  const warnings = state.effective.warnings ?? [];
  const what = kind.fragment ? "this kind of block" : "this page type";
  const radio = `ain-components-include-new-${kind.id}`;
  return (
    <div className="ain-studio__groups">
      {warnings.length > 0 && (
        <ul className="ain-components__warnings">
          {warnings.map((w, i) => (
            <li key={i} className="ain-components__warning">
              <AlertCircleIcon /> {w}
            </li>
          ))}
        </ul>
      )}

      <StudioGroup
        title="New components from packs"
        note={`Whether a component a pack adds later is offered on ${what} straight away.`}
      >
        <fieldset className="ain-components__regime">
          <legend className="ain-sr-only">New components from packs</legend>
          <RadioCard
            name={radio}
            label="Allowed automatically"
            hint="Everything the site offers, except what you untick here."
            checked={draft.include_new}
            onChange={() => onDraft(setIncludeNew(draft, true, names))}
          />
          <RadioCard
            name={radio}
            label="Off until I allow them"
            hint="Only what you tick here — and you can narrow each one’s variants and tones."
            checked={!draft.include_new}
            onChange={() => onDraft(setIncludeNew(draft, false, names))}
          />
        </fieldset>
      </StudioGroup>

      {!kind.fragment && (
        <StudioGroup title="Opener" note="The section every new page of this type starts with.">
          <Field label="Opener">
            <Select value={draft.opener} onChange={(e) => onDraft(setKindOpener(draft, e.target.value))}>
              <option value="">None</option>
              {openerOptions(entries, draft, state.site_off.components).map((name) => (
                <option key={name} value={name}>{humanizeName(name)}</option>
              ))}
            </Select>
          </Field>
        </StudioGroup>
      )}

      <StudioGroup
        title="Components"
        note={`Which building blocks ${what} offers. Unticking one stops new placements here — sections already placed stay and keep rendering. Rows turned off everywhere are locked.`}
      >
        <ComponentTools query={query} filter={filter} onQuery={onQuery} onFilter={onFilter} />
        {groups.length === 0 && <p className="ain-components__empty">No components match.</p>}
        {groups.map((g) => (
          <section key={g.id} className="ain-components__group" aria-labelledby={`ain-components-kgroup-${g.id}`}>
            <h4 id={`ain-components-kgroup-${g.id}`} className="ain-components__grouptitle">{g.label}</h4>
            <ul className="ain-components__list">
              {g.entries.map((entry) => (
                <KindRow
                  key={entry.name}
                  entry={entry}
                  manifest={manifest}
                  scope={scope}
                  selected={selected === entry.name}
                  onDraft={onDraft}
                  usageCache={usageCache}
                  onUsage={onUsage}
                />
              ))}
            </ul>
          </section>
        ))}
        <BlockedGroup blocked={manifest.blocked} filter={filter} query={query} />
      </StudioGroup>
    </div>
  );
}
