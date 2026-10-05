import { useEffect, type ReactNode } from "react";
import {
  AlertCircleIcon,
  Button,
  Checkbox,
  Field,
  LoadingState,
  Notice,
  SegmentedControl,
  Select,
  TextInput,
} from "@console/kit";
import { apiUrl, consoleBase, openPageInPlace, opensNewTab, pageDeepLink, roomToPath } from "@console/sdk";
import {
  filterBlocked,
  FILTERS,
  groupImpacts,
  humanizeName,
  impactHeading,
  overrideBadge,
  scopeMenu,
  scopeOptionLabel,
  SITE_SCOPE,
  usageTotal,
  type BlockedComponent,
  type ComponentEntry,
  type ComponentFilter,
  type ImpactRow,
  type ScopeInfo,
  type Tick,
} from "./constraint-model";

/**
 * The pieces both rail scopes share (P1b): the "Applies to" menu, the search +
 * filter strip, a row's Variants/Tones ticks and "Used on" list, links that open
 * a page or block in Content, the impact panel Publish shows first (D6), a
 * row's override badge and the "Can't be used" group (P3). Pure presentation —
 * the scope bodies own the drafts.
 */

const USAGE_LIMIT = 8;
const IMPACT_GROUP_LIMIT = 20;

export type UsageItem = { type: "page" | "block"; id: string | number; title: string };
export type UsageResult = { total: number; items: UsageItem[] };
export type UsageCache = Record<string, UsageResult | string | undefined>;

/** The "Applies to" menu: Everywhere, then the page types, then the reusable fragments. */
export function ScopeMenu({ scope, scopes, onChange }: {
  scope: string;
  scopes: ScopeInfo[];
  onChange: (scope: string) => void;
}) {
  const { pages, reusable } = scopeMenu(scopes);
  return (
    <div className="ain-components__scope">
      <Field label="Applies to">
        <Select value={scope} onChange={(e) => onChange(e.target.value)}>
          <option value={SITE_SCOPE}>Everywhere</option>
          {pages.length > 0 && (
            <optgroup label="Pages">
              {pages.map((s) => (
                <option key={s.id} value={s.id}>{scopeOptionLabel(s)}</option>
              ))}
            </optgroup>
          )}
          {reusable.length > 0 && (
            <optgroup label="Reusable">
              {reusable.map((s) => (
                <option key={s.id} value={s.id}>{scopeOptionLabel(s)}</option>
              ))}
            </optgroup>
          )}
        </Select>
      </Field>
    </div>
  );
}

/** Search + the All / Unused / Off / Packs filter (the kit's segmented control). */
export function ComponentTools({ query, filter, onQuery, onFilter }: {
  query: string;
  filter: ComponentFilter;
  onQuery: (q: string) => void;
  onFilter: (f: ComponentFilter) => void;
}) {
  return (
    <div className="ain-components__tools">
      <TextInput
        type="search"
        value={query}
        placeholder="Search components"
        aria-label="Search components"
        onChange={(e) => onQuery(e.target.value)}
      />
      <SegmentedControl
        className="ain-components__filters"
        label="Show"
        value={filter}
        onChange={onFilter}
        options={FILTERS.map((f) => ({ value: f.id, label: f.label }))}
      />
    </div>
  );
}

/** "Replaced by acme_pack" / "Using original" — a pack overrides this built-in (P3). */
export function OverrideBadge({ entry, off }: { entry: ComponentEntry; off: boolean }) {
  const text = overrideBadge(entry, off);
  if (!text || !entry.replaced_by) return null;
  return (
    <span
      className="ain-components__override"
      data-off={off || undefined}
      title={
        off
          ? `The original ${humanizeName(entry.name)} renders — ${entry.replaced_by.label} from ${entry.replaced_by.provider} is switched off`
          : `${entry.replaced_by.label} from ${entry.replaced_by.provider} renders in place of the original`
      }
    >
      {text}
    </span>
  );
}

/**
 * Pack components the admission gate refused (P3): listed so the owner knows
 * they exist, never tickable. The plain reason shows; the gate's own words are
 * the tooltip, for whoever maintains the pack.
 */
export function BlockedGroup({ blocked, filter, query }: {
  blocked: BlockedComponent[] | undefined;
  filter: ComponentFilter;
  query: string;
}) {
  const rows = filterBlocked(blocked, filter, query);
  if (rows.length === 0) return null;
  return (
    <section className="ain-components__group" aria-labelledby="ain-components-group-blocked" data-testid="components-blocked">
      <h4 id="ain-components-group-blocked" className="ain-components__grouptitle">Can’t be used</h4>
      <ul className="ain-components__list">
        {rows.map((b) => (
          <li key={`${b.provider}:${b.name}`} className="ain-components__item ain-components__blocked" title={b.detail || undefined}>
            <div className="ain-components__blockedhead">
              <span className="ain-components__name">{b.label || humanizeName(b.name)}</span>
              {b.provider && <span className="ain-components__tag">{b.provider}</span>}
            </div>
            <p className="ain-components__hint">{b.reason}</p>
          </li>
        ))}
      </ul>
    </section>
  );
}

/** A component's Variants or Tones ticks. */
export function TickList({ title, ticks, onChange }: {
  title: string;
  ticks: Tick[];
  onChange: (value: string, on: boolean) => void;
}) {
  if (ticks.length === 0) return null;
  return (
    <div className="ain-components__ticks" role="group" aria-label={title}>
      <h5 className="ain-components__subtitle">{title}</h5>
      <div className="ain-components__tickrow">
        {ticks.map((t) => (
          <Checkbox
            key={t.value}
            className="ain-components__tick"
            label={humanizeName(t.value)}
            checked={t.on}
            disabled={t.locked}
            title={t.reason ?? undefined}
            onChange={(e) => onChange(t.value, e.target.checked)}
          />
        ))}
      </div>
    </div>
  );
}

/**
 * A link that opens a page or block in Content. A page opens IN PLACE (the
 * console's room machine; a modifier click opens the durable URL in a new tab);
 * a block has no in-place entry in the sdk, so it follows its room URL.
 */
export function DocLink({ type, id, langcode, children }: {
  type: "page" | "block";
  id: string | number;
  langcode?: string | null;
  children: ReactNode;
}) {
  const node = String(id);
  const href =
    type === "page"
      ? pageDeepLink("content", node, consoleBase(), langcode)
      : roomToPath({ kind: "node", doc: "block", nid: Number(node), langcode: langcode ?? null });
  return (
    <a
      className="ain-components__doclink"
      href={href}
      onClick={(e) => {
        if (type !== "page" || opensNewTab(e)) return;
        e.preventDefault();
        openPageInPlace("content", node, langcode ?? null);
      }}
    >
      {children}
    </a>
  );
}

/** Where a component is used — fetched once per expand, cached for the rail's life. */
export function UsedOn({ entry, cache, onLoaded }: {
  entry: ComponentEntry;
  cache: UsageCache;
  onLoaded: (name: string, result: UsageResult | string) => void;
}) {
  const total = usageTotal(entry);
  const hit = cache[entry.name];
  useEffect(() => {
    if (total === 0 || hit !== undefined) return;
    let live = true;
    fetch(apiUrl(`/constraint/usage?key=${encodeURIComponent(`c:${entry.name}`)}`), { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: UsageResult) => live && onLoaded(entry.name, { total: data.total ?? 0, items: data.items ?? [] }))
      .catch((e) => live && onLoaded(entry.name, `Couldn’t load where it’s used: ${e instanceof Error ? e.message : e}`));
    return () => {
      live = false;
    };
  }, [entry.name, total, hit, onLoaded]);

  if (total === 0) return <p className="ain-components__hint">Not used on any page or block yet.</p>;
  const shown = typeof hit === "object" ? Math.min(hit.items.length, USAGE_LIMIT) : 0;
  const more = typeof hit === "object" ? Math.max(hit.total, hit.items.length) - shown : 0;
  return (
    <div className="ain-components__usedon">
      <h5 className="ain-components__subtitle">Used on {total}</h5>
      {hit === undefined ? (
        <LoadingState variant="fields" rows={2} label={`Finding where ${humanizeName(entry.name)} is used`} />
      ) : typeof hit === "string" ? (
        <Notice tone="error">{hit}</Notice>
      ) : (
        <ul className="ain-components__usedlist">
          {hit.items.slice(0, USAGE_LIMIT).map((item) => (
            <li key={`${item.type}:${item.id}`} className="ain-components__useditem">
              <DocLink type={item.type} id={item.id}>
                <span className="ain-components__usedtitle">{item.title || `Untitled ${item.type}`}</span>
              </DocLink>
              {item.type === "block" && <span className="ain-components__tag">Block</span>}
            </li>
          ))}
          {more > 0 && <li className="ain-components__usedmore">and {more} more</li>}
        </ul>
      )}
    </div>
  );
}

/**
 * The impact list Publish shows first when the staged change touches existing
 * pages (D6): grouped by page, each section's component and the plain impact.
 * Nothing on those pages changes — the sections are kept and keep rendering,
 * they are only no longer offered (D5) — so the choice is "Publish anyway" or
 * "Cancel" (the draft stays staged either way).
 */
export function ImpactPanel({ rows, busy, onPublish, onCancel }: {
  rows: ImpactRow[];
  busy: boolean;
  onPublish: () => void;
  onCancel: () => void;
}) {
  const groups = groupImpacts(rows);
  const more = Math.max(0, groups.length - IMPACT_GROUP_LIMIT);
  // A single-language site never shows (or links) a langcode — the source opens bare.
  const multiLang = new Set(groups.map((g) => g.langcode)).size > 1;
  return (
    <section className="ain-components__impact" aria-labelledby="ain-components-impact-title" data-testid="components-impact">
      <h4 id="ain-components-impact-title" className="ain-components__impacttitle">
        <AlertCircleIcon /> {impactHeading(rows)}
      </h4>
      <p className="ain-components__hint">
        {rows.length === 1
          ? "It keeps rendering as it is — Content marks it “No longer offered”, and it can’t be placed again."
          : "They keep rendering as they are — Content marks them “No longer offered”, and they can’t be placed again."}
      </p>
      <ul className="ain-components__impactlist">
        {groups.slice(0, IMPACT_GROUP_LIMIT).map((g) => (
          <li key={g.key} className="ain-components__impactitem">
            <div className="ain-components__useditem">
              <DocLink type={g.type} id={g.nid} langcode={multiLang ? g.langcode : null}>
                <span className="ain-components__usedtitle">{g.title || `Untitled ${g.type}`}</span>
              </DocLink>
              {g.type === "block" && <span className="ain-components__tag">Block</span>}
              {multiLang && <span className="ain-components__tag">{g.langcode}</span>}
            </div>
            <ul className="ain-components__impactrows">
              {g.rows.map((r, i) => (
                <li key={i} className="ain-components__impactrow" title={r.detail}>
                  {humanizeName(r.component)} — {r.impact}
                </li>
              ))}
            </ul>
          </li>
        ))}
        {more > 0 && <li className="ain-components__usedmore">and {more} more</li>}
      </ul>
      <div className="ain-components__impactactions">
        <Button variant="danger" onClick={onPublish} disabled={busy}>
          {busy ? "Publishing…" : "Publish anyway"}
        </Button>
        <Button onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
      </div>
    </section>
  );
}
