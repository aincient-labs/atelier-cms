import { useCallback, useEffect, useId, useState } from "react";
import { Button, LockIcon, Notice } from "@console/kit";
import { apiUrl, csrfTokenUrl } from "@console/sdk";

/**
 * Managers (Manager Instances M4: m4-1 + the console half of m4-3) — the
 * Atelier Manager apps paired with this site's experimental engine, and a
 * Revoke for every one but the primary.
 *
 * The list is the engine's own (`pairings.json` in its read-only outbox), so
 * nothing in Drupal can hide a pairing from it. NOTHING here pairs (design
 * E-02): no code is shown and no approve exists — the copy says where pairing
 * happens instead. A revoke writes one request the engine acts on within
 * seconds; the list updates when the engine republishes it.
 *
 * Renders NOTHING when the engine is not enabled on this site (no outbox
 * mount) or the list can't be fetched: a default install never sees it.
 */

export type Pairing = {
  id: string;
  name: string;
  primary: boolean;
  created: number;
  last_used: number;
  via: string;
};
type State = "absent" | "pending" | "ok" | "error";
type ListResponse = { state: State; pairings: Pairing[]; error?: string };
type RevokeResponse = Partial<ListResponse> & { ok: boolean; error?: string; revoked?: { id: string; name: string } };

const LIST_URL = apiUrl("/managers");
const REVOKE_URL = apiUrl("/managers/revoke");
/** How often the list re-reads while a revoke is waiting for the engine. */
const FOLLOW_MS = 3000;
const FOLLOW_TRIES = 10;

function when(seconds: number): string {
  if (!seconds) return "—";
  return new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" }).format(new Date(seconds * 1000));
}

/** "approved by <id>" reads better with the approver's name, when it is still paired. */
export function viaText(via: string, pairings: Pairing[]): string {
  const m = /^approved by ([0-9a-z]{12})$/.exec(via);
  const by = m ? pairings.find((p) => p.id === m[1]) : undefined;
  return by ? `approved on ${by.name}` : via;
}

async function sessionToken(): Promise<string> {
  const url = csrfTokenUrl();
  if (!url) return "";
  try {
    const res = await fetch(url, { credentials: "same-origin" });
    return res.ok ? await res.text() : "";
  } catch {
    return "";
  }
}

export function ManagersSection() {
  const labelId = useId();
  const [data, setData] = useState<ListResponse | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const adopt = useCallback((d: Partial<ListResponse> | null | undefined) => {
    if (d && typeof d.state === "string" && Array.isArray(d.pairings)) {
      setData({ state: d.state, pairings: d.pairings, error: d.error });
    }
  }, []);

  const load = useCallback(async (): Promise<ListResponse | null> => {
    try {
      const r = await fetch(LIST_URL, { credentials: "same-origin" });
      if (!r.ok) return null;
      const d = (await r.json()) as ListResponse;
      adopt(d);
      return d;
    } catch {
      return null;
    }
  }, [adopt]);

  useEffect(() => {
    void load();
  }, [load]);

  // After a revoke: re-read until the engine has dropped the pairing.
  useEffect(() => {
    if (!waiting) return;
    let tries = 0;
    const timer = window.setInterval(() => {
      tries += 1;
      void load().then((d) => {
        if ((d && !d.pairings.some((p) => p.id === waiting)) || tries >= FOLLOW_TRIES) {
          window.clearInterval(timer);
          setWaiting(null);
        }
      });
    }, FOLLOW_MS);
    return () => window.clearInterval(timer);
  }, [waiting, load]);

  const revoke = async (p: Pairing) => {
    if (!window.confirm(`Revoke “${p.name}”? That Manager loses access to this site at once and has to ask to join again.`)) return;
    setBusy(p.id);
    setError(null);
    setNotice(null);
    try {
      const token = await sessionToken();
      const res = await fetch(REVOKE_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", ...(token ? { "X-CSRF-Token": token } : {}) },
        body: JSON.stringify({ id: p.id }),
      });
      const d = (await res.json().catch(() => null)) as RevokeResponse | null;
      adopt(d);
      if (!res.ok || !d?.ok) throw new Error(d?.error ?? `HTTP ${res.status}`);
      setNotice(`Revoke sent. The engine acts on it within a few seconds; “${p.name}” leaves this list when it does.`);
      setWaiting(p.id);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  if (!data || data.state === "absent") return null;

  return (
    <section className="ain-settings-managers" aria-labelledby={`${labelId}-title`} data-testid="managers-section">
      <div className="ain-settings-managers__head">
        <h3 className="ain-settings-managers__title" id={`${labelId}-title`}>
          <LockIcon /> Managers
        </h3>
        <p className="ain-settings-managers__explainer">
          The Atelier Manager apps paired with this site’s engine.
          Pairing never happens here.
        </p>
        <ul className="ain-settings-managers__howto">
          <li>
            The <strong>first</strong> Manager pairs with the install code printed when the engine was enabled.
            Lost it? Make a new one over SSH:{" "}
            <code>docker exec &lt;site&gt;-engine-1 atelier engine admin install-code</code>
          </li>
          <li>
            Every <strong>later</strong> Manager uses <em>Ask to join</em> in the Manager app; you approve it on
            your first Manager.
          </li>
        </ul>
      </div>

      {error && <Notice tone="error">{error}</Notice>}
      {notice && <Notice tone="success">{notice}</Notice>}

      {data.state === "pending" && (
        <p className="ain-settings-managers__empty">The engine hasn’t published its list of Managers yet. It does within a minute of starting.</p>
      )}
      {data.state === "error" && (
        <Notice tone="error">{data.error ?? "The engine’s list of Managers could not be read."}</Notice>
      )}
      {data.state === "ok" && data.pairings.length === 0 && (
        <p className="ain-settings-managers__empty">No Manager is paired with this site yet.</p>
      )}

      {data.state === "ok" && data.pairings.length > 0 && (
        <ul className="ain-settings-managers__list">
          {data.pairings.map((p) => (
            <li
              key={p.id}
              className="ain-settings-managers__row"
              data-busy={busy === p.id || waiting === p.id || undefined}
              data-testid="managers-row"
            >
              <div className="ain-settings-managers__body">
                <span className="ain-settings-managers__name">
                  {p.name}
                  {p.primary && <span className="ain-settings-managers__badge">Primary</span>}
                </span>
                <span className="ain-settings-managers__meta">
                  Paired {when(p.created)}
                  {p.via ? ` · ${viaText(p.via, data.pairings)}` : ""}
                </span>
                <span className="ain-settings-managers__meta">Last seen {when(p.last_used)}</span>
              </div>
              {!p.primary && (
                <Button
                  className="ain-settings-managers__revokebtn"
                  type="button"
                  onClick={() => void revoke(p)}
                  disabled={busy !== null || waiting === p.id}
                  aria-label={`Revoke ${p.name}`}
                  title={`Revoke ${p.name}`}
                >
                  {busy === p.id ? "Revoking…" : waiting === p.id ? "Waiting…" : "Revoke"}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
      {data.state === "ok" && data.pairings.some((p) => p.primary) && (
        <p className="ain-settings-managers__footnote">
          The primary Manager can’t be revoked here — only from the Manager app or over SSH.
        </p>
      )}
    </section>
  );
}
