import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { offerWrapup } from "./thread-seal";
import { PanelBar } from "./panel-bar";
import { XIcon, CheckIcon } from "./icons";
import { MenuEditor } from "./menu-editor";
import { ReferenceField } from "./reference-field";
import { HandsOnMarker } from "./hands-on-marker";
import { fieldAnchorId, focusStudioField, requestedFieldAnchor } from "./studio-field-anchor";
import {
  setChromeDraft,
  getChromeDraft,
  reloadPreview,
  subscribeChromeDraft,
  subscribeChromeReset,
  type ChromeDraft,
  type ChromeMenuLink,
} from "./globals-state";
import {
  seedChromeDraft,
  cloneChromeDraft,
  titleCase,
  type ChromeManifest,
  type RegistrySetting,
} from "./chrome-shared";
import { apiUrl } from "./console-config";

/**
 * The Navigation & Pages studio (machine id `globals`; DECISIONS 0372).
 *
 * What's left of the old Globals studio after the identity half moved to Identity
 * (design_system) and email/privacy to Settings: the two chrome MENUS (main +
 * footer), the front/404/403 page ROUTING, and the header/footer ARRANGEMENT
 * knobs (sticky, nav alignment, footer layout, show tagline/credit). The logo
 * layout knobs (`logo_position`, `logo_size`) went WITH the logo to Identity, so
 * they're filtered out of the arrangement lists here.
 *
 * One rail, one draft: every edit writes the whole draft into globals-state, which
 * the live preview (a server re-render) subscribes to. Nothing is live until
 * Publish, which sends ONLY this surface's slice to /atelier/chrome/save — the
 * save merges by provided key, so the omitted identity/logo/settings fields the
 * other two surfaces own are never touched.
 */

const MANIFEST_URL = apiUrl("/chrome/manifest");
const SAVE_URL = apiUrl("/chrome/save");

/** The header/footer arrangement keys THIS surface owns (the logo knobs
 *  `logo_position` / `logo_size` moved to Identity). */
const NAV_LAYOUT_KEYS: Record<"header" | "footer", string[]> = {
  header: ["sticky", "nav_alignment"],
  footer: ["layout", "show_tagline", "show_credit"],
};

/** The page-routing slots this surface owns (front/404/403 — NOT `mail`). */
const ROUTING_KEYS = ["front", "page_404", "page_403"] as const;

type Tab = "header" | "footer" | "pages";

const TABS: { id: Tab; label: string }[] = [
  { id: "header", label: "Header" },
  { id: "footer", label: "Footer" },
  { id: "pages", label: "Pages" },
];

/** The number of changed fields vs the saved baseline (drives the dirty badge).
 *  Scoped to THIS surface: arrangement knobs, menus, and the routing slots. */
function countDirty(base: ChromeDraft, draft: ChromeDraft): number {
  let n = 0;
  for (const section of ["header", "footer"] as const) {
    for (const key of NAV_LAYOUT_KEYS[section]) {
      if ((draft.chrome[section]?.[key]) !== (base.chrome[section]?.[key])) n++;
    }
  }
  if (JSON.stringify(draft.menus.main) !== JSON.stringify(base.menus.main)) n++;
  if (JSON.stringify(draft.menus.footer) !== JSON.stringify(base.menus.footer)) n++;
  for (const key of ROUTING_KEYS) {
    if ((draft.identity.site?.[key] ?? "") !== (base.identity.site?.[key] ?? "")) n++;
  }
  return n;
}

/** One header/footer layout setting (an enum select or a boolean checkbox). */
function LayoutControl({
  def,
  value,
  onChange,
}: {
  def: RegistrySetting;
  value: string | boolean | undefined;
  onChange: (v: string | boolean) => void;
}) {
  const id = useId();
  if (def.type === "bool") {
    return (
      <label className="ain-field ain-field--check">
        <input
          className="ain-field__checkbox"
          type="checkbox"
          checked={value === undefined ? !!def.default : !!value}
          onChange={(e) => onChange(e.target.checked)}
        />
        <span className="ain-field__label">{def.label}</span>
      </label>
    );
  }
  return (
    <div className="ain-field">
      <label className="ain-field__label" htmlFor={id}>
        {def.label}
      </label>
      <select
        id={id}
        className="ain-field__input"
        value={String(value ?? def.default)}
        onChange={(e) => onChange(e.target.value)}
      >
        {(def.enum ?? []).map((opt) => (
          <option key={opt} value={opt}>
            {titleCase(opt)}
          </option>
        ))}
      </select>
    </div>
  );
}

export function GlobalsStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  const runtime = useAssistantRuntime();
  const [manifest, setManifest] = useState<ChromeManifest | null>(null);
  const [baseline, setBaseline] = useState<ChromeDraft | null>(null);
  const [draft, setDraft] = useState<ChromeDraft | null>(null);
  const [tab, setTab] = useState<Tab>("header");
  const [publishing, setPublishing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const lastPushed = useRef<ChromeDraft | null>(null);

  const pushChrome = useCallback((next: ChromeDraft) => {
    lastPushed.current = next;
    setChromeDraft(next);
  }, []);

  useEffect(() => {
    let live = true;
    fetch(MANIFEST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ChromeManifest) => {
        if (!live) return;
        const seed = seedChromeDraft(data);
        setManifest(data);
        setBaseline(seed);
        const existing = getChromeDraft();
        if (existing) {
          setDraft(existing);
          lastPushed.current = existing;
        } else {
          const working = cloneChromeDraft(seed);
          setDraft(working);
          pushChrome(working);
        }
      })
      .catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, [pushChrome]);

  // Land the operator ON a requested field (Phase 3 deep-link `?field=menus.main`).
  useEffect(() => {
    if (!draft) return;
    const key = requestedFieldAnchor();
    if (key) focusStudioField(key);
  }, [draft]);

  const dirty = useMemo(
    () => (baseline && draft ? countDirty(baseline, draft) : 0),
    [baseline, draft],
  );

  const update = useCallback(
    (next: ChromeDraft) => {
      setNotice(null);
      setDraft(next);
      pushChrome(next);
    },
    [pushChrome],
  );

  const setLayout = (section: "header" | "footer", key: string, value: string | boolean) => {
    if (!draft) return;
    const next = cloneChromeDraft(draft);
    next.chrome[section] = { ...next.chrome[section], [key]: value };
    update(next);
  };

  const setMenu = (menu: "main" | "footer", links: ChromeMenuLink[]) => {
    if (!draft) return;
    const next = cloneChromeDraft(draft);
    next.menus[menu] = links;
    update(next);
  };

  const setRouting = (key: (typeof ROUTING_KEYS)[number], value: string) => {
    if (!draft) return;
    const next = cloneChromeDraft(draft);
    next.identity.site = { ...next.identity.site, [key]: value };
    update(next);
  };

  const publish = useCallback(async () => {
    if (!draft) return;
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      // Only this surface's slice — arrangement knobs (never the logo knobs),
      // the two menus, and the routing slots (never `mail`). The chrome save
      // merges by provided key, so the identity/logo/settings fields are safe.
      const pick = (section: "header" | "footer") =>
        Object.fromEntries(
          NAV_LAYOUT_KEYS[section]
            .filter((k) => k in (draft.chrome[section] ?? {}))
            .map((k) => [k, draft.chrome[section][k]]),
        );
      const site = Object.fromEntries(
        ROUTING_KEYS.map((k) => [k, draft.identity.site[k]]),
      );
      const body = {
        chrome: { header: pick("header"), footer: pick("footer") },
        identity: { site },
        menus: draft.menus,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok) throw new Error(data?.error ?? `HTTP ${res.status}`);
      const seed = seedChromeDraft(data);
      const working = cloneChromeDraft(seed);
      setBaseline(seed);
      setDraft(working);
      pushChrome(working);
      setNotice("Published to the live site.");
      reloadPreview();
      offerWrapup(runtime.threads.mainItem.getState().remoteId);
    } catch (e) {
      setError(`Couldn’t publish: ${e instanceof Error ? e.message : e}`);
    } finally {
      setPublishing(false);
    }
  }, [draft, pushChrome, runtime]);

  const discard = useCallback(() => {
    if (!baseline) return;
    const seed = cloneChromeDraft(baseline);
    setDraft(seed);
    pushChrome(seed);
    setNotice(null);
    setError(null);
  }, [baseline, pushChrome]);

  useEffect(() => {
    const unsubDraft = subscribeChromeDraft((incoming) => {
      if (incoming && incoming !== lastPushed.current) {
        lastPushed.current = incoming;
        setDraft(incoming);
        setNotice(null);
      }
    });
    const unsubReset = subscribeChromeReset(() => discard());
    return () => {
      unsubDraft();
      unsubReset();
    };
  }, [discard]);

  // The arrangement defs THIS surface renders, in registry order, minus the logo
  // knobs that moved to Identity.
  const layoutDefs = (section: "header" | "footer"): RegistrySetting[] =>
    (manifest?.registry[section] ?? []).filter((d) => NAV_LAYOUT_KEYS[section].includes(d.key));

  return (
    <div className="ain-studio__rail" data-testid="studio-rail" data-studio="globals">
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
          title="Discard draft — revert the preview to the saved chrome"
        >
          Discard
        </button>
        <button
          className="ain-btn ain-topbtn ain-topbtn--primary"
          onClick={() => void publish()}
          disabled={dirty === 0 || publishing}
          title="Publish the draft to the live site"
        >
          {publishing ? "Publishing…" : "Publish"}
        </button>
        <button
          className="ain-btn ain-iconbtn ain-topbar__leave"
          onClick={onClose}
          aria-label="Close navigation & pages studio"
          title="Leave navigation & pages studio"
        >
          <XIcon />
        </button>
      </StudioActionsPortal>

      <PanelBar
        title="Navigation & Pages"
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
            {dirty} unsaved change{dirty === 1 ? "" : "s"} · preview only
          </>
        ) : notice ? (
          <>
            <CheckIcon /> {notice}
          </>
        ) : (
          <>Matches the saved chrome</>
        )}
      </p>

      {error && <p className="ain-studio__error">{error}</p>}
      {!manifest && !error && <p className="ain-studio__hint">Loading…</p>}

      {manifest && draft && (
        <>
          <div className="ain-globals__tabs" role="tablist" aria-label="Navigation & Pages sections">
            {TABS.map((t) => (
              <button
                key={t.id}
                type="button"
                role="tab"
                aria-selected={tab === t.id}
                className={`ain-btn ain-globals__tab${tab === t.id ? " is-active" : ""}`}
                onClick={() => setTab(t.id)}
              >
                {t.label}
              </button>
            ))}
          </div>

          <div className="ain-studio__groups">
            {tab === "header" && (
              <div className="ain-globals__panel">
                {layoutDefs("header").map((def) => (
                  <LayoutControl
                    key={def.key}
                    def={def}
                    value={draft.chrome.header[def.key]}
                    onChange={(v) => setLayout("header", def.key, v)}
                  />
                ))}
                <div className="ain-studio__subgroup" id={fieldAnchorId("menus.main")}>
                  <h4 className="ain-studio__subtitle">
                    <HandsOnMarker reachKey="menus.main" />
                    Navigation
                  </h4>
                  <p className="ain-studio__groupnote">
                    The header menu (Drupal&apos;s <code>main</code> menu).
                  </p>
                  <MenuEditor
                    links={draft.menus.main}
                    onChange={(links) => setMenu("main", links)}
                    addLabel="Add header link"
                    rootLabel="Header menu"
                  />
                </div>
              </div>
            )}

            {tab === "footer" && (
              <div className="ain-globals__panel">
                {layoutDefs("footer").map((def) => (
                  <LayoutControl
                    key={def.key}
                    def={def}
                    value={draft.chrome.footer[def.key]}
                    onChange={(v) => setLayout("footer", def.key, v)}
                  />
                ))}
                <div className="ain-studio__subgroup" id={fieldAnchorId("menus.footer")}>
                  <h4 className="ain-studio__subtitle">
                    <HandsOnMarker reachKey="menus.footer" />
                    Navigation
                  </h4>
                  <p className="ain-studio__groupnote">
                    The footer menu (Drupal&apos;s <code>footer</code> menu).
                  </p>
                  <MenuEditor
                    links={draft.menus.footer}
                    onChange={(links) => setMenu("footer", links)}
                    addLabel="Add footer link"
                    rootLabel="Footer menu"
                  />
                </div>
              </div>
            )}

            {tab === "pages" && (
              <div className="ain-globals__panel">
                <p className="ain-studio__groupnote">
                  Which page answers each special URL. Anything left empty falls back
                  to the shipped default — set only what you want to own.
                </p>
                <div id={fieldAnchorId("site.front")}>
                  <ReferenceField
                    label="Front page"
                    meaning="The page shown at your site’s root URL. Empty = the default front page."
                    value={draft.identity.site.front}
                    onChange={(v) => setRouting("front", typeof v === "string" ? v : "")}
                    types={["node"]}
                    adornment={<HandsOnMarker reachKey="site.front" />}
                  />
                </div>
                <div id={fieldAnchorId("site.page_404")}>
                  <ReferenceField
                    label="Not-found page (404)"
                    meaning="Shown when a visitor hits a URL that doesn’t exist."
                    value={draft.identity.site.page_404}
                    onChange={(v) => setRouting("page_404", typeof v === "string" ? v : "")}
                    types={["node"]}
                    adornment={<HandsOnMarker reachKey="site.page_404" />}
                  />
                </div>
                <div id={fieldAnchorId("site.page_403")}>
                  <ReferenceField
                    label="Access-denied page (403)"
                    meaning="Shown when a visitor isn’t allowed to see a page."
                    value={draft.identity.site.page_403}
                    onChange={(v) => setRouting("page_403", typeof v === "string" ? v : "")}
                    types={["node"]}
                    adornment={<HandsOnMarker reachKey="site.page_403" />}
                  />
                </div>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
