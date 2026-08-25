import { useCallback, useEffect, useMemo, useState } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { offerWrapup } from "./thread-seal";
import { PanelBar } from "./panel-bar";
import { XIcon, CheckIcon, AlertCircleIcon } from "./icons";
import { apiUrl } from "./console-config";
import { fieldAnchorId, focusStudioField, requestedFieldAnchor } from "./studio-field-anchor";
import {
  cloneConstraintDraft,
  countDirty,
  isEnabled,
  isLastEnabledTone,
  isLastEnabledVariant,
  seedConstraintDraft,
  setComponentEnabled,
  setToneEnabled,
  setVariantEnabled,
  toSavePayload,
  type ConstraintDraft,
  type ConstraintManifest,
  type VocabularyComponent,
} from "./constraint-model";

/**
 * The Components governance pane (plans/byo-components.md W1b) — the site-wide
 * narrowing layer over the discovered component vocabulary. Checked = available;
 * unchecking stages a REMOVAL (`aincient_pages.site_constraint`). The pane can
 * only narrow what discovery admitted — never invent or re-admit anything.
 *
 * A PANEL-ONLY studio (like Checks): no live preview, its rail is the centre
 * canvas. State is plain local staged-draft + baseline — nothing here is shared
 * with the chrome/page draft stores. Publish sends the WHOLE staged slice
 * (each key replaces its stored list) and re-seeds from the fresh state the
 * save returns; the server's one hard refusal (removing every tone → 422) is
 * mirrored by the last-tone guard, and the per-component analogue by the
 * last-variant guard.
 */

const MANIFEST_URL = apiUrl("/constraint/manifest");
const SAVE_URL = apiUrl("/constraint/save");

/** Tier display order + operator-facing copy. */
const TIERS: { id: string; label: string; note: string }[] = [
  { id: "section", label: "Sections", note: "The page-level building blocks the agent and the studio can place." },
  { id: "layout", label: "Layout", note: "Structural components that arrange other content." },
  { id: "reference", label: "References", note: "Components that pull in other pages or reusable items." },
];

function ComponentRow({
  def,
  enabled,
  onChange,
}: {
  def: VocabularyComponent;
  enabled: boolean;
  onChange: (enabled: boolean) => void;
}) {
  return (
    <label className="ain-field ain-field--check ain-constraint__row" title={def.use}>
      <input
        className="ain-field__checkbox"
        type="checkbox"
        checked={enabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      {def.icon && (
        <span className="ain-constraint__glyph" aria-hidden="true">
          {def.icon}
        </span>
      )}
      <span className="ain-field__label">{def.name}</span>
      {def.use && <span className="ain-constraint__use">{def.use}</span>}
    </label>
  );
}

export function ComponentsStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  const runtime = useAssistantRuntime();
  const [manifest, setManifest] = useState<ConstraintManifest | null>(null);
  const [effective, setEffective] = useState<ConstraintManifest["effective"] | null>(null);
  const [baseline, setBaseline] = useState<ConstraintDraft | null>(null);
  const [draft, setDraft] = useState<ConstraintDraft | null>(null);
  const [publishing, setPublishing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    fetch(MANIFEST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ConstraintManifest) => {
        if (!live) return;
        const seed = seedConstraintDraft(data);
        setManifest(data);
        setEffective(data.effective);
        setBaseline(seed);
        setDraft(cloneConstraintDraft(seed));
      })
      .catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, []);

  // Land the operator ON a requested field (deep-link `?field=…`).
  useEffect(() => {
    if (!draft) return;
    const key = requestedFieldAnchor();
    if (key) focusStudioField(key);
  }, [draft]);

  const dirty = useMemo(
    () => (baseline && draft ? countDirty(baseline, draft) : 0),
    [baseline, draft],
  );

  const update = useCallback((next: ConstraintDraft) => {
    setNotice(null);
    setDraft(next);
  }, []);

  const publish = useCallback(async () => {
    if (!draft) return;
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      // The whole staged slice — each key REPLACES its stored list, so the
      // publish is exactly what the pane shows, nothing merged underneath it.
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(toSavePayload(draft)),
      });
      const data = await res.json().catch(() => null);
      // A 422 (e.g. every tone removed) keeps the draft so nothing staged is lost.
      if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
      const fresh = data as ConstraintManifest;
      const seed = seedConstraintDraft(fresh);
      setManifest(fresh);
      setEffective(fresh.effective);
      setBaseline(seed);
      setDraft(cloneConstraintDraft(seed));
      setNotice("Component governance published to the live site.");
      offerWrapup(runtime.threads.mainItem.getState().remoteId);
    } catch (e) {
      setError(`Couldn’t publish: ${e instanceof Error ? e.message : e}`);
    } finally {
      setPublishing(false);
    }
  }, [draft, runtime]);

  const discard = useCallback(() => {
    if (!baseline) return;
    setDraft(cloneConstraintDraft(baseline));
    setNotice(null);
    setError(null);
  }, [baseline]);

  const vocabTones = manifest?.vocabulary.tones ?? [];
  const availableCount = draft && manifest
    ? manifest.vocabulary.components.filter((c) => isEnabled(draft.components, c.name)).length
    : 0;
  const totalCount = manifest?.vocabulary.components.length ?? 0;

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
        <button
          className="ain-btn ain-topbtn"
          onClick={discard}
          disabled={dirty === 0 || publishing}
          title="Discard draft — revert to the saved constraint"
        >
          Discard
        </button>
        <button
          className="ain-btn ain-topbtn ain-topbtn--primary"
          onClick={() => void publish()}
          disabled={dirty === 0 || publishing}
          title="Publish the component governance to the live site"
        >
          {publishing ? "Publishing…" : "Publish"}
        </button>
        <button
          className="ain-btn ain-iconbtn ain-topbar__leave"
          onClick={onClose}
          aria-label="Close components studio"
          title="Leave components studio"
        >
          <XIcon />
        </button>
      </StudioActionsPortal>

      <PanelBar
        title="Components"
        actions={
          <button
            className="ain-btn ain-iconbtn ain-studio__sheetclose"
            onClick={closeSheets}
            aria-label="Hide editor"
            title="Hide editor"
          >
            <XIcon />
          </button>
        }
      />

      <p className="ain-studio__status" data-dirty={dirty > 0 || undefined}>
        {dirty > 0 ? (
          <>
            {dirty} unsaved change{dirty === 1 ? "" : "s"} · nothing applied until Publish
          </>
        ) : notice ? (
          <>
            <CheckIcon /> {notice}
          </>
        ) : manifest ? (
          <>
            {availableCount} of {totalCount} components available
          </>
        ) : (
          <>Matches the saved constraint</>
        )}
      </p>

      {error && <p className="ain-studio__error">{error}</p>}
      {!manifest && !error && <p className="ain-studio__hint">Loading…</p>}

      {effective && effective.warnings.length > 0 && (
        <ul className="ain-constraint__warnings">
          {effective.warnings.map((w, i) => (
            <li key={i} className="ain-constraint__warning">
              <AlertCircleIcon /> {w}
            </li>
          ))}
        </ul>
      )}

      {manifest && draft && (
        <div className="ain-studio__groups">
          <section className="ain-studio__group" id={fieldAnchorId("constraint.components")}>
            <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">Components</h3>
            <p className="ain-studio__groupnote">
              Which building blocks this site uses. Unchecked components can no longer be
              placed — existing uses stay, but the agent and the studios stop offering them.
            </p>
            {TIERS.map((tier) => {
              const defs = manifest.vocabulary.components.filter((c) => c.tier === tier.id);
              if (defs.length === 0) return null;
              return (
                <div key={tier.id} className="ain-constraint__tier">
                  <h4 className="ain-constraint__tiertitle" title={tier.note}>
                    {tier.label}
                  </h4>
                  {defs.map((def) => (
                    <ComponentRow
                      key={def.name}
                      def={def}
                      enabled={isEnabled(draft.components, def.name)}
                      onChange={(enabled) => update(setComponentEnabled(draft, def.name, enabled))}
                    />
                  ))}
                </div>
              );
            })}
          </section>

          <section className="ain-studio__group" id={fieldAnchorId("constraint.tones")}>
            <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">Tones</h3>
            <p className="ain-studio__groupnote">
              The colour moods sections may take. At least one tone must remain available.
            </p>
            {vocabTones.map((tone) => {
              const last = isLastEnabledTone(draft, tone, vocabTones);
              return (
                <label
                  key={tone}
                  className="ain-field ain-field--check ain-constraint__row"
                  title={last ? "The last remaining tone can’t be disabled" : undefined}
                >
                  <input
                    className="ain-field__checkbox"
                    type="checkbox"
                    checked={isEnabled(draft.tones, tone)}
                    disabled={last}
                    onChange={(e) => update(setToneEnabled(draft, tone, e.target.checked, vocabTones))}
                  />
                  <span className="ain-field__label">{tone}</span>
                </label>
              );
            })}
          </section>

          <section className="ain-studio__group" id={fieldAnchorId("constraint.variants")}>
            <h3 className="ain-studio__grouptitle ain-studio__grouptitle--static">Variants</h3>
            <p className="ain-studio__groupnote">
              The shapes each component may take. Each component keeps at least one variant.
            </p>
            {Object.entries(manifest.vocabulary.variants).map(([component, values]) => (
              <div key={component} className="ain-constraint__tier">
                <h4 className="ain-constraint__tiertitle">{component}</h4>
                {values.map((value) => {
                  const removed = draft.variants[component] ?? [];
                  const last = isLastEnabledVariant(draft, component, value, values);
                  return (
                    <label
                      key={value}
                      className="ain-field ain-field--check ain-constraint__row"
                      title={last ? "The last remaining variant can’t be disabled" : undefined}
                    >
                      <input
                        className="ain-field__checkbox"
                        type="checkbox"
                        checked={isEnabled(removed, value)}
                        disabled={last}
                        onChange={(e) =>
                          update(setVariantEnabled(draft, component, value, e.target.checked, values))
                        }
                      />
                      <span className="ain-field__label">{value}</span>
                    </label>
                  );
                })}
              </div>
            ))}
          </section>
        </div>
      )}
    </div>
  );
}
