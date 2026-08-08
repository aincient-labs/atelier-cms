import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import { useAssistantRuntime } from "@assistant-ui/react";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { offerWrapup } from "./thread-seal";
import { PanelBar } from "./panel-bar";
import { XIcon, CheckIcon, ShieldCheckIcon } from "./icons";
import { HandsOnMarker } from "./hands-on-marker";
import { apiUrl } from "./console-config";
import { fieldAnchorId, focusStudioField, requestedFieldAnchor } from "./studio-field-anchor";
import {
  setChromeDraft,
  getChromeDraft,
  reloadPreview,
  subscribeChromeDraft,
  subscribeChromeReset,
  type ChromeDraft,
} from "./globals-state";
import { seedChromeDraft, cloneChromeDraft, type ChromeManifest } from "./chrome-shared";

/**
 * The Settings studio (DECISIONS 0372): the operator settings that belong to
 * neither Identity nor Navigation & Pages — the site email and the privacy /
 * consent lever (font delivery). Split out of the old Globals studio.
 *
 * It shares the chrome draft store + the live chrome preview with the other two
 * chrome surfaces (only one studio is mounted at a time; each re-seeds from the
 * manifest), but RENDERS, COUNTS and PUBLISHES only its own slice — `mail` and
 * `font_delivery`. The Publish therefore sends a partial `{identity:{site:{mail}},
 * privacy:{font_delivery}}` to /atelier/chrome/save, which merges by provided
 * key, so it can never clobber the menus / routing / logo / identity the other
 * surfaces own.
 */

const MANIFEST_URL = apiUrl("/chrome/manifest");
const SAVE_URL = apiUrl("/chrome/save");

const DELIVERY_GOOGLE = "google";

/** Operator-facing copy for each delivery mode (label + the privacy consequence). */
const DELIVERY_COPY: Record<string, { label: string; desc: string }> = {
  selfhost: {
    label: "Self-host (private)",
    desc: "Brand fonts are served from your own origin. No third-party request, so no consent banner is shown.",
  },
  google: {
    label: "Load from Google Fonts",
    desc: "Visitors are asked for consent first (a banner appears). Until they accept, the system font is shown and nothing is sent to Google.",
  },
};

/** The changed-field count for this surface (mail + font_delivery). */
function countDirty(base: ChromeDraft, draft: ChromeDraft): number {
  let n = 0;
  if ((draft.identity.site?.mail ?? "") !== (base.identity.site?.mail ?? "")) n++;
  if (draft.privacy.font_delivery !== base.privacy.font_delivery) n++;
  return n;
}

export function SettingsStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  const runtime = useAssistantRuntime();
  const mailId = useId();
  const [manifest, setManifest] = useState<ChromeManifest | null>(null);
  const [baseline, setBaseline] = useState<ChromeDraft | null>(null);
  const [draft, setDraft] = useState<ChromeDraft | null>(null);
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

  // Land the operator ON a requested field (Phase 3 deep-link `?field=site.mail`).
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

  const setMail = (value: string) => {
    if (!draft) return;
    const next = cloneChromeDraft(draft);
    next.identity.site = { ...next.identity.site, mail: value };
    update(next);
  };

  const setFontDelivery = (mode: string) => {
    if (!draft) return;
    const next = cloneChromeDraft(draft);
    next.privacy.font_delivery = mode;
    update(next);
  };

  const publish = useCallback(async () => {
    if (!draft) return;
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      // Only this surface's slice — mail + font_delivery. The chrome save merges
      // by provided key, so omitting everything else leaves it untouched.
      const body = {
        identity: { site: { mail: draft.identity.site.mail } },
        privacy: { font_delivery: draft.privacy.font_delivery },
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
      setNotice("Settings published to the live site.");
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

  // Mirror an agent's chrome-draft writes + reset op (parity with the other rails).
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
    <div className="ain-studio__rail" data-testid="studio-rail" data-studio="settings">
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
          title="Discard draft — revert to the saved settings"
        >
          Discard
        </button>
        <button
          className="ain-btn ain-topbtn ain-topbtn--primary"
          onClick={() => void publish()}
          disabled={dirty === 0 || publishing}
          title="Publish the settings to the live site"
        >
          {publishing ? "Publishing…" : "Publish"}
        </button>
        <button
          className="ain-btn ain-iconbtn ain-topbar__leave"
          onClick={onClose}
          aria-label="Close settings studio"
          title="Leave settings studio"
        >
          <XIcon />
        </button>
      </StudioActionsPortal>

      <PanelBar
        title="Settings"
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
          <>Matches the saved settings</>
        )}
      </p>

      {error && <p className="ain-studio__error">{error}</p>}
      {!manifest && !error && <p className="ain-studio__hint">Loading…</p>}

      {manifest && draft && (
        <div className="ain-studio__groups">
          <div className="ain-globals__panel">
            <div className="ain-field" id={fieldAnchorId("site.mail")}>
              <label className="ain-field__label" htmlFor={mailId}>
                <HandsOnMarker reachKey="site.mail" />
                <span className="ain-field__labeltext">Site email</span>
              </label>
              <input
                id={mailId}
                className="ain-field__input"
                type="text"
                value={draft.identity.site.mail}
                placeholder="Where system mail (password resets, notifications) comes from"
                onChange={(e) => setMail(e.target.value)}
              />
            </div>

            <div
              className="ain-field"
              role="radiogroup"
              aria-labelledby="ain-font-delivery"
              id={fieldAnchorId("privacy.font_delivery")}
            >
              <span className="ain-field__label" id="ain-font-delivery">
                <HandsOnMarker reachKey="privacy.font_delivery" />
                <span className="ain-field__labeltext">Font delivery</span>
              </span>
              <p className="ain-studio__groupnote">
                How brand fonts reach your public pages — the one setting that decides
                whether visitors see a GDPR consent banner.
              </p>
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
          </div>
        </div>
      )}
    </div>
  );
}
