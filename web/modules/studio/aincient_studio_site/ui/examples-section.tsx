import { useCallback, useEffect, useId, useState } from "react";
import { TrashIcon, Button, Notice } from "@console/kit";
import { apiUrl } from "@console/sdk";

/**
 * Example content (plans/studio-modules.md "Demo content", DECISIONS 0441) —
 * the Settings studio's "Clear examples".
 *
 * A studio seeds a few example pages or images the first time it is switched
 * on, so the room is not empty. This section lists what is still present, per
 * studio, and removes it — one studio or all. Edited examples go too: the tag
 * means "came from us". Cleared examples do not come back when the studio is
 * switched off and on; a developer re-seeds with `drush atelier:demo-import`.
 *
 * Renders NOTHING when no example content is present: most sites never see
 * this section, and an empty "Examples" heading would only raise a question.
 * Owns its own state and talks to `/examples…` directly, like the Snapshots
 * section beside it; every mutating response carries the fresh list.
 */

export type ExampleStudio = { id: string; label: string; count: number; ships: boolean };
type ListResponse = { studios: ExampleStudio[]; total: number };
type ClearResponse = Partial<ListResponse> & { ok: boolean; deleted?: number; error?: string };

const LIST_URL = apiUrl("/examples");
const CLEAR_URL = apiUrl("/examples/clear");

function plural(n: number, one: string, many: string): string {
  return `${n} ${n === 1 ? one : many}`;
}

export function ExamplesSection() {
  const labelId = useId();
  const [studios, setStudios] = useState<ExampleStudio[] | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const adopt = useCallback((data: Partial<ListResponse> | null | undefined) => {
    if (data && Array.isArray(data.studios)) setStudios(data.studios);
  }, []);

  useEffect(() => {
    let live = true;
    fetch(LIST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ListResponse) => {
        if (!live) return;
        adopt(data);
        if (!Array.isArray(data.studios)) setStudios([]);
      })
      .catch(() => {
        // A list that cannot load is not worth a red line in Settings: the
        // section simply stays hidden, and drush still clears.
        if (live) setStudios([]);
      });
    return () => {
      live = false;
    };
  }, [adopt]);

  const clear = async (studio: ExampleStudio | null) => {
    const what = studio ? `the ${studio.label} examples (${plural(studio.count, "item", "items")})` : "every example";
    if (!window.confirm(`Remove ${what}? Edited examples are removed too. This can’t be undone.`)) return;
    setBusy(studio?.id ?? "*");
    setError(null);
    setNotice(null);
    try {
      const res = await fetch(CLEAR_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(studio ? { studio: studio.id } : {}),
      });
      const data = (await res.json().catch(() => null)) as ClearResponse | null;
      if (!res.ok || !data?.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
      adopt(data);
      setNotice(`Removed ${plural(data.deleted ?? 0, "example", "examples")}.`);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  // Nothing seeded, nothing to say — but keep the notice of the clear that
  // just emptied the list, so the operator sees it land.
  if (!studios || (studios.length === 0 && !notice)) return null;

  return (
    <section className="ain-settings-examples" aria-labelledby={`${labelId}-title`} data-testid="examples-section">
      <div className="ain-settings-examples__head">
        <h3 className="ain-settings-examples__title" id={`${labelId}-title`}>
          <TrashIcon /> Example content
        </h3>
        <p className="ain-settings-examples__explainer">
          Pages and images a studio added as examples when it was first switched on. Remove them once
          you have your own — edited examples are removed too, and they don’t come back.
        </p>
      </div>

      {error && (
        <Notice tone="error">{error}</Notice>
      )}
      {notice && (
        <Notice tone="success">{notice}</Notice>
      )}

      {studios.length > 0 && (
        <ul className="ain-settings-examples__list">
          {studios.map((s) => (
            <li key={s.id} className="ain-settings-examples__row" data-busy={busy === s.id || undefined}>
              <div className="ain-settings-examples__body">
                <span className="ain-settings-examples__name">{s.label}</span>
                <span className="ain-settings-examples__count">{plural(s.count, "example", "examples")}</span>
              </div>
              <Button
                className="ain-settings-examples__clearbtn"
                type="button"
                onClick={() => void clear(s)}
                disabled={busy !== null}
                aria-label={`Remove the ${s.label} examples`}
                title={`Remove the ${s.label} examples`}
              >
                {busy === s.id ? "Removing…" : "Remove"}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {studios.length > 1 && (
        <div className="ain-settings-examples__actions">
          <Button
            type="button"
            onClick={() => void clear(null)}
            disabled={busy !== null}
          >
            <TrashIcon /> {busy === "*" ? "Removing…" : "Remove all examples"}
          </Button>
        </div>
      )}
    </section>
  );
}
