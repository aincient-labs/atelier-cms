import { useCallback, useEffect, useId, useState } from "react";
import type { SVGProps } from "react";
import { CheckIcon, EyeIcon, TrashIcon } from "./icons";
import { apiUrl } from "./console-config";

/**
 * Freeze & Live (DECISIONS 0416) — the Settings studio's Snapshots section.
 *
 * The appliance can serve anonymous visitors a FROZEN export of the published
 * site instead of live Drupal; owners always see the live site while logged in.
 * This section is the only operator surface for it: freeze a new snapshot,
 * choose what visitors are served (live or a snapshot), keep / delete snapshots,
 * and preview any snapshot's files. It owns its own state and talks to the
 * `/snapshots…` JSON API directly — it shares nothing with the chrome draft +
 * Publish logic of the studio it is mounted in.
 *
 * Every mutating response returns the fresh `{ serving, snapshots }` pair, and
 * the section re-reads BOTH from every response rather than patching local
 * state, so what it shows is always what the backend last said.
 */

export type Snapshot = {
  id: string;
  label: string | null;
  frozen_at: string | null;
  frozen_by: string | null;
  atelier_version: string | null;
  pages: number;
  assets: number;
  keep: boolean;
};

type ListResponse = { serving: string; snapshots: Snapshot[] };

type FreezeResponse = ListResponse & {
  ok: boolean;
  snapshot: Snapshot;
  served: boolean;
  problems: string[];
};

type MutateResponse = Partial<ListResponse> & { ok: boolean; error?: string };

export const LIVE = "live";

const LIST_URL = apiUrl("/snapshots");
const FREEZE_URL = apiUrl("/snapshots/freeze");
const USE_URL = apiUrl("/snapshots/use");
const KEEP_URL = apiUrl("/snapshots/keep");
const DELETE_URL = apiUrl("/snapshots/delete");

/** The snapshot's home page — the exported files, served as visitors would see them. */
export function snapshotPreviewUrl(id: string): string {
  return apiUrl(`/snapshots/${encodeURIComponent(id)}/`);
}

/** "10 Sep 2026, 11:02" in the operator's locale + timezone; the raw value when unparsable. */
export function formatFrozenAt(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const date = d.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
  const time = d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });
  return `${date}, ${time}`;
}

export function snapshotName(s: Snapshot): string {
  return s.label && s.label.trim() ? s.label : "Snapshot";
}

/** POST a JSON body; resolves the parsed JSON, rejects with the server's `error` or the HTTP status. */
async function post<T extends { ok?: boolean; error?: string }>(url: string, body: unknown): Promise<T> {
  const res = await fetch(url, {
    method: "POST",
    credentials: "same-origin",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  const data = (await res.json().catch(() => null)) as T | null;
  if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
  if (!data) throw new Error("Empty response");
  return data;
}

// ---- icons local to this section (same grammar as ./icons: 24 viewBox, stroke currentColor, 2px)

type IconProps = SVGProps<SVGSVGElement>;

function Svg({ children, ...props }: IconProps) {
  return (
    <svg
      width="1em"
      height="1em"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      {...props}
    >
      {children}
    </svg>
  );
}

/** Snowflake — "freeze". */
const SnowflakeIcon = (p: IconProps) => (
  <Svg {...p}>
    <line x1="12" y1="2" x2="12" y2="22" />
    <line x1="3.34" y1="7" x2="20.66" y2="17" />
    <line x1="3.34" y1="17" x2="20.66" y2="7" />
    <path d="M9 4l3 3 3-3" /><path d="M9 20l3-3 3 3" />
    <path d="M4.3 9.5l4.1 1.1 1.1-4.1" /><path d="M19.7 14.5l-4.1-1.1-1.1 4.1" />
    <path d="M4.3 14.5l4.1-1.1 1.1 4.1" /><path d="M19.7 9.5l-4.1 1.1-1.1-4.1" />
  </Svg>
);

/** Bookmark — "keep". Filled when kept. */
const BookmarkIcon = ({ filled, ...p }: IconProps & { filled?: boolean }) => (
  <Svg {...p} fill={filled ? "currentColor" : "none"}>
    <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
  </Svg>
);

/** Radio-style serving marker: a ring, filled with a dot when serving. */
const ServingMarker = ({ on }: { on: boolean }) => (
  <span className="ain-snapshots__marker" data-on={on || undefined} aria-hidden="true">
    <Svg>
      <circle cx="12" cy="12" r="9" />
      {on && <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />}
    </Svg>
  </span>
);

// ---- the section

type Busy = { kind: "freeze" } | { kind: "row"; id: string } | null;

export function SnapshotsSection() {
  const labelId = useId();
  const [serving, setServing] = useState<string>(LIVE);
  const [snapshots, setSnapshots] = useState<Snapshot[] | null>(null);
  const [label, setLabel] = useState("");
  const [busy, setBusy] = useState<Busy>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  /** The last freeze that came back `ok:false` — its problems, and whether it still needs serving. */
  const [failed, setFailed] = useState<{ id: string; name: string; problems: string[]; served: boolean } | null>(null);

  const adopt = useCallback((data: Partial<ListResponse> | null | undefined) => {
    if (!data) return;
    if (typeof data.serving === "string") setServing(data.serving);
    if (Array.isArray(data.snapshots)) setSnapshots(data.snapshots);
  }, []);

  useEffect(() => {
    let live = true;
    fetch(LIST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ListResponse) => {
        if (!live) return;
        adopt(data);
        if (!Array.isArray(data.snapshots)) setSnapshots([]);
      })
      .catch((e) => {
        if (!live) return;
        setSnapshots([]);
        setError(`Couldn’t load snapshots: ${e instanceof Error ? e.message : e}`);
      });
    return () => {
      live = false;
    };
  }, [adopt]);

  /** Run one mutating call: clears messages, marks busy, adopts the response, surfaces errors inline. */
  const run = useCallback(
    async <T extends MutateResponse>(mark: Busy, url: string, body: unknown, after?: (data: T) => void) => {
      setBusy(mark);
      setError(null);
      setNotice(null);
      try {
        const data = await post<T>(url, body);
        adopt(data);
        after?.(data);
      } catch (e) {
        setError(e instanceof Error ? e.message : String(e));
      } finally {
        setBusy(null);
      }
    },
    [adopt],
  );

  const freeze = () =>
    run<FreezeResponse>({ kind: "freeze" }, FREEZE_URL, label.trim() ? { label: label.trim() } : {}, (data) => {
      const name = data.snapshot ? snapshotName(data.snapshot) : "Snapshot";
      if (data.ok) {
        setFailed(null);
        setLabel("");
        setNotice(data.served ? `${name} frozen and now served to visitors.` : `${name} frozen.`);
      } else {
        setFailed({
          id: data.snapshot?.id ?? "",
          name,
          problems: Array.isArray(data.problems) ? data.problems : [],
          served: data.served === true,
        });
      }
    });

  const serve = (id: string) =>
    run({ kind: "row", id }, USE_URL, { id }, () => {
      if (failed && failed.id === id) setFailed({ ...failed, served: true });
      setNotice(id === LIVE ? "Visitors now see the live site." : "Visitors now see this snapshot.");
    });

  const toggleKeep = (s: Snapshot) => run({ kind: "row", id: s.id }, KEEP_URL, { id: s.id, keep: !s.keep });

  const remove = (s: Snapshot) => {
    if (!window.confirm(`Delete ${snapshotName(s)}? Its exported files are removed. This can’t be undone.`)) return;
    run({ kind: "row", id: s.id }, DELETE_URL, { id: s.id }, () => {
      if (failed && failed.id === s.id) setFailed(null);
    });
  };

  const freezing = busy?.kind === "freeze";
  const locked = busy !== null;
  const isLive = serving === LIVE;
  const servingSnapshot = snapshots?.find((s) => s.id === serving) ?? null;
  const servingName = servingSnapshot ? snapshotName(servingSnapshot) : serving;

  return (
    <section className="ain-snapshots" aria-labelledby={`${labelId}-title`} data-testid="snapshots-section">
      <div className="ain-snapshots__head">
        <h3 className="ain-snapshots__title" id={`${labelId}-title`}>
          <SnowflakeIcon /> Snapshots
        </h3>
        <p className="ain-snapshots__explainer">
          Freeze the published site so visitors see a fixed version while you keep editing. You always
          see the live site while logged in.
        </p>
      </div>

      <p className="ain-snapshots__serving" data-live={isLive || undefined} role="status">
        {isLive ? (
          <>Visitors see the live site.</>
        ) : (
          <>
            Visitors see snapshot <strong>{servingName}</strong>. You see the live site.
          </>
        )}
      </p>

      <div className="ain-snapshots__freeze">
        <label className="ain-field__label" htmlFor={labelId}>
          <span className="ain-field__labeltext">Label for the new snapshot (optional)</span>
        </label>
        <div className="ain-field__inputrow">
          <input
            id={labelId}
            className="ain-field__input"
            type="text"
            value={label}
            placeholder="e.g. Before the autumn redesign"
            disabled={locked}
            onChange={(e) => setLabel(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !locked) {
                e.preventDefault();
                void freeze();
              }
            }}
          />
          <button
            type="button"
            className="ain-btn ain-topbtn ain-topbtn--primary"
            onClick={() => void freeze()}
            disabled={locked || snapshots === null}
            title="Render every published page into a new snapshot"
          >
            <SnowflakeIcon /> {freezing ? "Freezing…" : "Freeze now"}
          </button>
        </div>
        {freezing && (
          <p className="ain-snapshots__progress" role="status">
            Freezing… rendering every published page. This can take a minute.
          </p>
        )}
      </div>

      {error && <p className="ain-snapshots__error" role="alert">{error}</p>}
      {notice && !error && (
        <p className="ain-snapshots__notice" role="status">
          <CheckIcon /> {notice}
        </p>
      )}

      {failed && (
        <div className="ain-snapshots__problems" role="alert">
          <p className="ain-snapshots__problems-title">
            {failed.name} was frozen, but the export had {failed.problems.length}{" "}
            problem{failed.problems.length === 1 ? "" : "s"}
            {failed.served ? "." : " — it is not being served."}
          </p>
          {failed.problems.length > 0 && (
            <ul className="ain-snapshots__problemlist">
              {failed.problems.map((p, i) => (
                <li key={i}>{p}</li>
              ))}
            </ul>
          )}
          <div className="ain-snapshots__problemactions">
            {!failed.served && failed.id && (
              <button
                type="button"
                className="ain-btn ain-topbtn ain-topbtn--sm"
                onClick={() => void serve(failed.id)}
                disabled={locked}
              >
                Serve it anyway
              </button>
            )}
            <button
              type="button"
              className="ain-btn ain-topbtn ain-topbtn--sm ain-topbtn--quiet"
              onClick={() => setFailed(null)}
              disabled={locked}
            >
              Dismiss
            </button>
          </div>
        </div>
      )}

      {snapshots === null ? (
        <p className="ain-snapshots__loading">Loading snapshots…</p>
      ) : (
        <ul className="ain-snapshots__list" aria-busy={locked || undefined}>
          <li className="ain-snapshots__row ain-snapshots__row--live" data-serving={isLive || undefined}>
            <ServingMarker on={isLive} />
            <div className="ain-snapshots__body">
              <div className="ain-snapshots__name">
                Live
                {isLive && <span className="ain-snapshots__chip ain-snapshots__chip--serving">serving</span>}
              </div>
              <p className="ain-snapshots__meta">
                Drupal renders every visit. Changes reach visitors as you publish them.
              </p>
            </div>
            <div className="ain-snapshots__actions">
              {!isLive && (
                <button
                  type="button"
                  className="ain-btn ain-topbtn ain-topbtn--sm"
                  onClick={() => void serve(LIVE)}
                  disabled={locked}
                  title="Serve the live site to visitors"
                >
                  Serve live
                </button>
              )}
            </div>
          </li>

          {snapshots.map((s) => {
            const on = serving === s.id;
            const rowBusy = busy?.kind === "row" && busy.id === s.id;
            const name = snapshotName(s);
            const frozen = formatFrozenAt(s.frozen_at);
            return (
              <li key={s.id} className="ain-snapshots__row" data-serving={on || undefined} data-busy={rowBusy || undefined}>
                <ServingMarker on={on} />
                <div className="ain-snapshots__body">
                  <div className="ain-snapshots__name">
                    {name}
                    {on && <span className="ain-snapshots__chip ain-snapshots__chip--serving">serving</span>}
                    {s.keep && <span className="ain-snapshots__chip ain-snapshots__chip--kept">kept</span>}
                  </div>
                  <p className="ain-snapshots__meta">
                    {frozen && <>Frozen {frozen}</>}
                    {frozen && s.frozen_by && <> by {s.frozen_by}</>}
                    {!frozen && s.frozen_by && <>Frozen by {s.frozen_by}</>}
                    {s.atelier_version && (
                      <>
                        {(frozen || s.frozen_by) && <span className="ain-snapshots__dot">·</span>}
                        Atelier {s.atelier_version}
                      </>
                    )}
                  </p>
                  <p className="ain-snapshots__counts">
                    {s.pages} page{s.pages === 1 ? "" : "s"}
                    <span className="ain-snapshots__dot">·</span>
                    {s.assets} asset{s.assets === 1 ? "" : "s"}
                  </p>
                </div>
                <div className="ain-snapshots__actions">
                  <a
                    className="ain-btn ain-topbtn ain-topbtn--sm ain-topbtn--quiet"
                    href={snapshotPreviewUrl(s.id)}
                    target="_blank"
                    rel="noopener"
                    title={`Open ${name} in a new tab`}
                  >
                    <EyeIcon /> View
                  </a>
                  {!on && (
                    <button
                      type="button"
                      className="ain-btn ain-topbtn ain-topbtn--sm"
                      onClick={() => void serve(s.id)}
                      disabled={locked}
                      title={`Serve ${name} to visitors`}
                    >
                      Serve this
                    </button>
                  )}
                  <button
                    type="button"
                    className="ain-btn ain-topbtn ain-topbtn--sm ain-topbtn--quiet"
                    onClick={() => void toggleKeep(s)}
                    disabled={locked}
                    aria-pressed={s.keep}
                    title={s.keep ? `Stop keeping ${name}` : `Keep ${name} from being pruned`}
                  >
                    <BookmarkIcon filled={s.keep} /> {s.keep ? "Unkeep" : "Keep"}
                  </button>
                  {!on && (
                    <button
                      type="button"
                      className="ain-btn ain-topbtn ain-topbtn--sm ain-topbtn--quiet ain-snapshots__delete"
                      onClick={() => remove(s)}
                      disabled={locked}
                      aria-label={`Delete ${name}`}
                      title={`Delete ${name}`}
                    >
                      <TrashIcon />
                    </button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
