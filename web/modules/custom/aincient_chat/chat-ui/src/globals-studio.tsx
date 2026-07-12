import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { offerWrapup } from "./thread-seal";
import { PanelBar } from "./panel-bar";
import { XIcon, CheckIcon, ShieldCheckIcon } from "./icons";
import { MenuEditor } from "./menu-editor";
import { ReferenceField } from "./reference-field";
import {
  setChromeDraft,
  getChromeDraft,
  reloadPreview,
  subscribeChromeDraft,
  subscribeChromeReset,
  type ChromeDraft,
  type ChromeLayout,
  type ChromeMenuLink,
} from "./globals-state";

/**
 * The Globals studio: site-wide chrome, edited as a live, preview-only draft and
 * published deliberately — the chrome parallel of the Brand (Design System) and
 * Page (Content) studios. Three tabs:
 *
 *  - Brand identity — name, tagline, description, tone, logo, footer note
 *    (the brand IDENTITY that moved out of the Design System / token rail).
 *  - Header — logo position / sticky / nav alignment + the `main` menu (inline).
 *  - Footer — layout / show tagline + the `footer` menu (inline).
 *
 * One rail, one draft: every edit writes the whole draft into globals-state, which
 * the live preview (a server re-render — chrome is markup, not CSS vars) subscribes
 * to. Nothing is live until Publish (POST /aincient/chrome/save), exactly like the
 * other studios.
 *
 * The chrome AGENT (step 2b) writes the same draft via the `chrome_preview` widget,
 * so this studio also SUBSCRIBES to external draft writes (to mirror an agent edit
 * into the rail fields + the unsaved-change count) and to reset requests (the
 * agent's `reset` op → revert to the saved baseline). To avoid a feedback loop we
 * ignore notifications for the very draft this rail just pushed.
 */

const MANIFEST_URL = "/aincient/chrome/manifest";
const SAVE_URL = "/aincient/chrome/save";

/** One header/footer layout setting as the manifest describes it for the rail. */
type RegistrySetting = {
  key: string;
  label: string;
  type: "enum" | "bool";
  enum: string[] | null;
  default: string | boolean;
};

/** The chrome state + editing vocabulary the studio renders from. Identity/chrome/
 *  privacy/menus share the shape Publish returns, so one seed builder covers both. */
type ChromeManifest = {
  chrome: ChromeLayout;
  registry: { header: RegistrySetting[]; footer: RegistrySetting[] };
  identity: {
    guidelines: {
      name: string;
      tagline: string;
      description: string;
      tone: string;
      imagery_style: string;
      imagery_avoid: string;
    };
    footer_note: string;
    /** `media:<id>` tokens (or '' for none) — the unified picker's value. */
    logo: string;
    favicon: string;
  };
  privacy: {
    font_delivery: string;
    /** The delivery modes the backend accepts, in display order. */
    options: string[];
    /** Whether the SAVED state shows a consent banner (a cross-check for the rail). */
    banner_active: boolean;
  };
  menus: { main: ChromeMenuLink[]; footer: ChromeMenuLink[] };
};

type Tab = "identity" | "header" | "footer" | "privacy";

const TABS: { id: Tab; label: string }[] = [
  { id: "identity", label: "Brand identity" },
  { id: "header", label: "Header" },
  { id: "footer", label: "Footer" },
  { id: "privacy", label: "Privacy" },
];

/** The two font-delivery modes (must match BrandRepository::DELIVERY_*). */
const DELIVERY_SELFHOST = "selfhost";
const DELIVERY_GOOGLE = "google";

/** Operator-facing copy for each delivery mode (label + the privacy consequence). */
const DELIVERY_COPY: Record<string, { label: string; desc: string }> = {
  [DELIVERY_SELFHOST]: {
    label: "Self-host (private)",
    desc: "Brand fonts are served from your own origin. No third-party request, so no consent banner is shown.",
  },
  [DELIVERY_GOOGLE]: {
    label: "Load from Google Fonts",
    desc: "Visitors are asked for consent first (a banner appears). Until they accept, the system font is shown and nothing is sent to Google.",
  },
};

/** A plain-JSON deep clone — the draft is pure data, so this is safe + cheap. */
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

/** Title-case a raw enum value ("nav_alignment" → "Nav alignment", "left" → "Left"). */
function titleCase(s: string): string {
  const t = s.replace(/[_-]+/g, " ").trim();
  return t.charAt(0).toUpperCase() + t.slice(1);
}

/** Build a working draft from the manifest (or the save response — same shape). */
function seedFrom(data: Pick<ChromeManifest, "chrome" | "identity" | "privacy" | "menus">): ChromeDraft {
  const g = data.identity?.guidelines ?? {};
  return {
    chrome: clone(data.chrome ?? { header: {}, footer: {} }),
    identity: {
      guidelines: {
        name: String(g.name ?? ""),
        tagline: String(g.tagline ?? ""),
        description: String(g.description ?? ""),
        tone: String(g.tone ?? ""),
        imagery_style: String(g.imagery_style ?? ""),
        imagery_avoid: String(g.imagery_avoid ?? ""),
      },
      footer_note: String(data.identity?.footer_note ?? ""),
      logo: String(data.identity?.logo ?? ""),
      favicon: String(data.identity?.favicon ?? ""),
    },
    privacy: {
      font_delivery: String(data.privacy?.font_delivery ?? DELIVERY_GOOGLE),
    },
    menus: {
      main: clone(data.menus?.main ?? []),
      footer: clone(data.menus?.footer ?? []),
    },
  };
}

/** The number of changed fields vs the saved baseline (drives the dirty badge). */
function countDirty(base: ChromeDraft, draft: ChromeDraft): number {
  let n = 0;
  const g = draft.identity.guidelines;
  const gb = base.identity.guidelines;
  for (const k of ["name", "tagline", "description", "tone", "imagery_style", "imagery_avoid"] as const) {
    if ((g[k] ?? "") !== (gb[k] ?? "")) n++;
  }
  if ((draft.identity.footer_note ?? "") !== (base.identity.footer_note ?? "")) n++;
  // Logo/favicon are `media:<id>` tokens now — a changed token (incl. cleared) is
  // one changed field, compared against the saved baseline like the text fields.
  if ((draft.identity.logo ?? "") !== (base.identity.logo ?? "")) n++;
  if ((draft.identity.favicon ?? "") !== (base.identity.favicon ?? "")) n++;
  for (const sec of ["header", "footer"] as const) {
    const a = draft.chrome[sec] ?? {};
    const b = base.chrome[sec] ?? {};
    for (const key of Object.keys(a)) {
      if (a[key] !== b[key]) n++;
    }
  }
  if (JSON.stringify(draft.menus.main) !== JSON.stringify(base.menus.main)) n++;
  if (JSON.stringify(draft.menus.footer) !== JSON.stringify(base.menus.footer)) n++;
  if (draft.privacy.font_delivery !== base.privacy.font_delivery) n++;
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
      <label className="ain-field__label">{def.label}</label>
      <select
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

/** A labelled single-line / multi-line identity text field. */
function TextField({
  label,
  value,
  onChange,
  placeholder,
  multiline,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  multiline?: boolean;
}) {
  return (
    <div className="ain-field">
      <label className="ain-field__label">{label}</label>
      {multiline ? (
        <textarea
          className="ain-field__input ain-field__textarea ain-globals__textarea"
          value={value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <input
          className="ain-field__input"
          type="text"
          value={value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
        />
      )}
    </div>
  );
}

export function GlobalsStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  // Read only to resolve the active thread's backend id when a Publish wraps the
  // conversation up (the celebration offer; see offerWrapup).
  const runtime = useAssistantRuntime();
  const [manifest, setManifest] = useState<ChromeManifest | null>(null);
  const [baseline, setBaseline] = useState<ChromeDraft | null>(null);
  const [draft, setDraft] = useState<ChromeDraft | null>(null);
  const [tab, setTab] = useState<Tab>("identity");
  const [publishing, setPublishing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  // The last draft THIS rail pushed to the shared store — so the draft subscriber
  // (which also fires for the agent's writes) can ignore our own echoes.
  const lastPushed = useRef<ChromeDraft | null>(null);

  // Push a working draft to the shared store (→ live preview + agent context),
  // tracking it so our own subscription doesn't treat the echo as external.
  const pushChrome = useCallback((next: ChromeDraft) => {
    lastPushed.current = next;
    setChromeDraft(next);
  }, []);

  // Fetch the chrome manifest and seed the baseline + working draft. Pushing the
  // seed into globals-state immediately lets the preview render the saved chrome.
  // If the agent already staged a draft before this rail mounted (it calls
  // ensureStudio("globals") + writes the store), ADOPT that as the working draft
  // (baseline stays the saved manifest, so the agent's edits read as unsaved).
  useEffect(() => {
    let live = true;
    fetch(MANIFEST_URL, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: ChromeManifest) => {
        if (!live) return;
        const seed = seedFrom(data);
        setManifest(data);
        setBaseline(seed);
        const existing = getChromeDraft();
        if (existing) {
          setDraft(existing);
          lastPushed.current = existing;
        } else {
          const working = clone(seed);
          setDraft(working);
          pushChrome(working);
        }
      })
      .catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, [pushChrome]);

  const dirty = useMemo(
    () => (baseline && draft ? countDirty(baseline, draft) : 0),
    [baseline, draft],
  );

  // Commit a new draft to both local state and the shared store (→ live preview).
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
    const next = clone(draft);
    next.chrome[section] = { ...next.chrome[section], [key]: value };
    update(next);
  };

  const setGuideline = (key: keyof ChromeDraft["identity"]["guidelines"], value: string) => {
    if (!draft) return;
    const next = clone(draft);
    next.identity.guidelines[key] = value;
    update(next);
  };

  const setFooterNote = (value: string) => {
    if (!draft) return;
    const next = clone(draft);
    next.identity.footer_note = value;
    update(next);
  };

  const setMenu = (menu: "main" | "footer", links: ChromeMenuLink[]) => {
    if (!draft) return;
    const next = clone(draft);
    next.menus[menu] = links;
    update(next);
  };

  const setFontDelivery = (mode: string) => {
    if (!draft) return;
    const next = clone(draft);
    next.privacy.font_delivery = mode;
    update(next);
  };

  // Logo/favicon are `media:<id>` tokens now — set from the unified picker
  // (which handles search / upload itself). An empty string clears.
  const setLogoToken = (token: string) => {
    if (!draft) return;
    const next = clone(draft);
    next.identity.logo = token;
    update(next);
  };

  const setFaviconToken = (token: string) => {
    if (!draft) return;
    const next = clone(draft);
    next.identity.favicon = token;
    update(next);
  };

  const publish = useCallback(async () => {
    if (!draft) return;
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      const body = {
        chrome: draft.chrome,
        identity: {
          guidelines: draft.identity.guidelines,
          footer_note: draft.identity.footer_note,
          logo: draft.identity.logo,
          favicon: draft.identity.favicon,
        },
        privacy: { font_delivery: draft.privacy.font_delivery },
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
      // Re-seed the baseline + draft from the saved state (server-assigned menu
      // ids, resolved logo URL, no staged fid) so the studio opens clean.
      const seed = seedFrom(data);
      const working = clone(seed);
      setBaseline(seed);
      setDraft(working);
      pushChrome(working);
      setNotice("Published to the live site.");
      reloadPreview();
      // A site-wide chrome publish wraps the conversation up too (DECISIONS
      // 0093) — no single page to view, so the celebration just offers a fresh
      // thread. Only when there's a backend thread to lock.
      offerWrapup(runtime.threads.mainItem.getState().remoteId);
    } catch (e) {
      setError(`Couldn’t publish: ${e instanceof Error ? e.message : e}`);
    } finally {
      setPublishing(false);
    }
  }, [draft, pushChrome, runtime]);

  const discard = useCallback(() => {
    if (!baseline) return;
    const seed = clone(baseline);
    setDraft(seed);
    pushChrome(seed);
    setNotice(null);
    setError(null);
  }, [baseline, pushChrome]);

  // Mirror the chrome AGENT's writes into the rail: when the `chrome_preview`
  // widget merges into the shared draft, adopt it here so the editor fields +
  // the unsaved-change count reflect it. Ignore our own pushes (lastPushed).
  // The agent's `reset` op routes through subscribeChromeReset → discard.
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

  return (
    <div className="ain-studio__rail">
      {/* Primary actions live in the top bar so they survive the rail collapsing
          to a sheet on narrow screens (the brand/page studio idiom). */}
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
          aria-label="Close globals studio"
          title="Leave globals studio"
        >
          <XIcon />
        </button>
      </StudioActionsPortal>

      <PanelBar
        title="Globals"
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
          <div className="ain-globals__tabs" role="tablist" aria-label="Globals sections">
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
            {tab === "identity" && (
              <div className="ain-globals__panel">
                <TextField
                  label="Site name"
                  value={draft.identity.guidelines.name}
                  onChange={(v) => setGuideline("name", v)}
                  placeholder="Atelier"
                />
                <TextField
                  label="Tagline"
                  value={draft.identity.guidelines.tagline}
                  onChange={(v) => setGuideline("tagline", v)}
                  placeholder="A short line under the name"
                />
                <TextField
                  label="Description"
                  value={draft.identity.guidelines.description}
                  onChange={(v) => setGuideline("description", v)}
                  placeholder="What the site is about (for meta + the agent)"
                  multiline
                />
                <TextField
                  label="Voice / tone"
                  value={draft.identity.guidelines.tone}
                  onChange={(v) => setGuideline("tone", v)}
                  placeholder="e.g. warm, precise, playful"
                />
                <TextField
                  label="Imagery style"
                  value={draft.identity.guidelines.imagery_style}
                  onChange={(v) => setGuideline("imagery_style", v)}
                  placeholder="Art direction for images — light, palette, mood, subjects (e.g. soft natural light, muted tones, real moments)"
                  multiline
                />
                <TextField
                  label="Imagery to avoid"
                  value={draft.identity.guidelines.imagery_avoid}
                  onChange={(v) => setGuideline("imagery_avoid", v)}
                  placeholder="Clichés to steer clear of (e.g. generic stock photos, glowing-brain AI tropes)"
                  multiline
                />

                <ReferenceField
                  label="Logo"
                  meaning="Pick from the Library or upload a new image."
                  value={draft.identity.logo}
                  onChange={(v) => setLogoToken(typeof v === "string" ? v : "")}
                  types={["media"]}
                  allowUpload
                />

                <ReferenceField
                  label="Favicon"
                  meaning="The little icon in the browser tab — a square PNG works best."
                  value={draft.identity.favicon}
                  onChange={(v) => setFaviconToken(typeof v === "string" ? v : "")}
                  types={["media"]}
                  allowUpload
                />

                <TextField
                  label="Footer note"
                  value={draft.identity.footer_note}
                  onChange={setFooterNote}
                  placeholder="© 2026 Atelier (defaults to © year + name)"
                />
              </div>
            )}

            {tab === "header" && (
              <div className="ain-globals__panel">
                {manifest.registry.header.map((def) => (
                  <LayoutControl
                    key={def.key}
                    def={def}
                    value={draft.chrome.header[def.key]}
                    onChange={(v) => setLayout("header", def.key, v)}
                  />
                ))}
                <div className="ain-studio__subgroup">
                  <h4 className="ain-studio__subtitle">Navigation</h4>
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
                {manifest.registry.footer.map((def) => (
                  <LayoutControl
                    key={def.key}
                    def={def}
                    value={draft.chrome.footer[def.key]}
                    onChange={(v) => setLayout("footer", def.key, v)}
                  />
                ))}
                <div className="ain-studio__subgroup">
                  <h4 className="ain-studio__subtitle">Navigation</h4>
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

            {tab === "privacy" && (
              <div className="ain-globals__panel">
                <p className="ain-studio__groupnote">
                  How brand fonts reach your public pages — the one setting that decides
                  whether visitors see a GDPR consent banner.
                </p>
                <div className="ain-field">
                  <label className="ain-field__label">Font delivery</label>
                  {manifest.privacy.options.map((mode) => {
                    const copy = DELIVERY_COPY[mode] ?? { label: mode, desc: "" };
                    return (
                      <label key={mode} className="ain-field ain-field--radio">
                        <input
                          className="ain-field__radio"
                          type="radio"
                          name="font_delivery"
                          checked={draft.privacy.font_delivery === mode}
                          onChange={() => setFontDelivery(mode)}
                        />
                        <span className="ain-field__radiobody">
                          <span className="ain-field__label">{copy.label}</span>
                          <span className="ain-field__hint">{copy.desc}</span>
                        </span>
                      </label>
                    );
                  })}
                </div>
                <p
                  className="ain-globals__consent-status"
                  data-active={draft.privacy.font_delivery === DELIVERY_GOOGLE || undefined}
                >
                  <ShieldCheckIcon />{" "}
                  {draft.privacy.font_delivery === DELIVERY_GOOGLE
                    ? "Consent banner shown — visitors are asked before any font loads from Google."
                    : "No consent banner — nothing third-party loads from your public pages."}
                </p>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
