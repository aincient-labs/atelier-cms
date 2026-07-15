import { useEffect, useMemo, useState, type ComponentType, type SVGProps } from "react";
import {
  settings,
  type OnboardingConnectResult,
  type OnboardingProvider,
  type OnboardingRole,
  type OnboardingSettings,
} from "./adapter";
import {
  AnthropicIcon,
  Chip,
  CheckIcon,
  GeminiIcon,
  OllamaIcon,
  OpenAiIcon,
  ShieldCheckIcon,
  SpinnerIcon,
  Wordmark,
  XIcon,
} from "./icons";
import { STAGED_ASK_KEY, SUGGESTIONS } from "./App";
import { apiUrl, consoleBase } from "./console-config";

/**
 * First-run onboarding wizard — the product's handshake.
 *
 * A dedicated, full-screen, guided flow shown PROACTIVELY on an unconfigured
 * site (the server sets `window.aincientChat.onboarding.needed`). It lives
 * outside the chat conversation so the first run is a polished, can't-miss-it
 * experience rather than a panel hidden behind a chat message.
 *
 * Steps: welcome (with the optional "What should we call you?") → connect your
 * AI → choose models — then it LANDS in the console with the composer focused
 * and a suggested first ask pre-typed (no "Finish" screen; onboarding ends when
 * the owner has made something).
 *
 * The connect step is MULTI-provider: the operator connects one or more
 * providers (Anthropic for chat, Google Gemini for images, …), each with its own
 * key, validated + stored independently against `/atelier/onboarding/connect-provider`.
 * A "key group" like Google (gemini + nanobanana share one Google key) shows as
 * ONE row and lights up both chat and image at once. The models step then binds
 * each Atelier role to a model from ANY connected provider — so chat can run on
 * Anthropic while image generation runs on Nano Banana. Finishing POSTs the
 * role → provider:model map to `/atelier/onboarding/finalize`, which binds and
 * projects them; on success we reload into the now-configured console.
 *
 * Principle (see the onboarding proposal): the user's experience comes first —
 * smart defaults, zero dead-ends, forgiving errors, free choice always kept.
 */

type Step = "welcome" | "connect" | "models";

/** A connected provider's probed models (provider:model → label) + suggestions. */
type Connected = {
  chat: Record<string, string>;
  image: Record<string, string>;
  suggested: Record<string, string>;
};

/** Where to grab an API key, per known provider (shown as a gentle hint). */
const KEY_HELP: Record<string, { href: string; label: string }> = {
  anthropic: { href: "https://console.anthropic.com/settings/keys", label: "console.anthropic.com" },
  openai: { href: "https://platform.openai.com/api-keys", label: "platform.openai.com" },
  gemini: { href: "https://aistudio.google.com/apikey", label: "aistudio.google.com" },
};

/**
 * Per-provider brand mark for the picker. People recognise a provider by its
 * mark far quicker than its name, so each row leads with its logo on a white
 * badge. Every mark sits in the same neutral ink so the picker reads as one
 * consistent set. Keyed by drupal/ai's provider plugin id (or key-group primary,
 * e.g. `gemini` for the Google group); unknown providers fall back to the
 * Atelier chip, so the picker never shows a blank tile.
 */
// Literal on purpose: provider marks sit on the always-white logo tile
// (.ain-wiz__provider-badge), so their ink must not follow the theme tokens.
const PROVIDER_INK = "#0a0a0a";
const PROVIDER_BRAND: Record<string, { Icon: ComponentType<SVGProps<SVGSVGElement>> }> = {
  anthropic: { Icon: AnthropicIcon },
  openai: { Icon: OpenAiIcon },
  gemini: { Icon: GeminiIcon },
  ollama: { Icon: OllamaIcon },
};

/**
 * Display order for the picker — Ollama first (local-first, no key needed),
 * then Anthropic, OpenAI, and Google Gemini. Providers not listed keep their
 * server order, sorted after the known ones.
 */
const PROVIDER_ORDER = ["ollama", "anthropic", "openai", "gemini"];

function progressFor(step: Step): { index: number; total: number } {
  const order: Step[] = ["welcome", "connect", "models"];
  return { index: Math.max(0, order.indexOf(step)), total: order.length };
}

function ProgressDots({ step }: { step: Step }) {
  const { index, total } = progressFor(step);
  return (
    <div className="ain-wiz__dots" aria-hidden>
      {Array.from({ length: total }).map((_, i) => (
        <span key={i} className={`ain-wiz__dot${i <= index ? " ain-wiz__dot--on" : ""}`} />
      ))}
    </div>
  );
}

/** Small "chat" / "image" capability whispers for a provider row — mono
 *  micro-labels, not bordered tags (study 02, onboarding kit). */
function CapabilityChips({ provider }: { provider: OnboardingProvider }) {
  const caps: string[] = [];
  if (provider.capabilities?.chat) caps.push("chat");
  if (provider.capabilities?.image) caps.push("image");
  if (caps.length === 0) return null;
  return (
    <>
      {caps.map((c) => (
        <span key={c} className="ain-wiz__chip ain-wiz__chip--cap">
          {c}
        </span>
      ))}
    </>
  );
}

/**
 * One connectable provider row: logo, label, capability badges, and either a
 * "Connected" state or an inline key/URL field + Connect button.
 */
function ProviderRow({
  provider,
  connected,
  open,
  credential,
  status,
  error,
  onToggle,
  onCredentialChange,
  onConnect,
}: {
  provider: OnboardingProvider;
  connected: boolean;
  open: boolean;
  credential: string;
  status: "idle" | "connecting";
  error: string | null;
  onToggle: () => void;
  onCredentialChange: (value: string) => void;
  onConnect: () => void;
}) {
  const Icon = PROVIDER_BRAND[provider.id]?.Icon ?? Chip;
  const isHost = provider.auth === "host";
  const keyHelp = isHost ? undefined : KEY_HELP[provider.id];
  return (
    <div
      className={`ain-wiz__provider${open ? " ain-wiz__provider--selected" : ""}${
        connected ? " ain-wiz__provider--recommended" : ""
      }`}
      data-provider={provider.id}
    >
      <button type="button" className="ain-btn ain-wiz__provider-hit" onClick={onToggle} aria-expanded={open}>
        <span className="ain-wiz__provider-badge" style={{ color: PROVIDER_INK }} aria-hidden>
          <Icon className="ain-wiz__provider-mark" />
        </span>
        <span className="ain-wiz__provider-body">
          <span className="ain-wiz__provider-head">
            <span className="ain-wiz__provider-name">{provider.label}</span>
            <CapabilityChips provider={provider} />
            {connected && (
              <span className="ain-wiz__chip ain-wiz__chip--ok">
                <CheckIcon className="ain-wiz__chip-icon" /> Connected
              </span>
            )}
          </span>
          {provider.description && <span className="ain-wiz__provider-desc">{provider.description}</span>}
        </span>
      </button>

      {open && (
        <div className="ain-wiz__connect">
          <label className="ain-wiz__field">
            <span className="ain-wiz__label">{isHost ? "Server URL" : "API key"}</span>
            <div className="ain-wiz__field-row">
              <input
                type={isHost ? "text" : "password"}
                className="ain-wiz__input"
                value={credential}
                onChange={(e) => onCredentialChange(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && onConnect()}
                placeholder={isHost ? "http://localhost:11434" : "sk-…"}
                autoComplete="off"
                spellCheck={false}
                autoFocus
              />
              <button
                type="button"
                className="ain-btn ain-topbtn ain-topbtn--sm"
                onClick={onConnect}
                disabled={status === "connecting"}
              >
                {status === "connecting" ? (
                  <>
                    <SpinnerIcon className="ain-wiz__spin" /> Checking…
                  </>
                ) : connected ? (
                  "Reconnect"
                ) : (
                  "Connect"
                )}
              </button>
            </div>
          </label>
          {error && <p className="ain-wiz__error">{error}</p>}
          {keyHelp && (
            <p className="ain-wiz__foot">
              Get a key at{" "}
              <a href={keyHelp.href} target="_blank" rel="noreferrer">
                {keyHelp.label}
              </a>
              .
            </p>
          )}
        </div>
      )}
    </div>
  );
}

export function OnboardingWizard() {
  const cfg: OnboardingSettings = settings().onboarding ?? {};
  // Present providers in our preferred order (Ollama, Anthropic, OpenAI, Google).
  const providers = useMemo(() => {
    const rank = (id: string) => {
      const i = PROVIDER_ORDER.indexOf(id);
      return i === -1 ? PROVIDER_ORDER.length : i;
    };
    return [...(cfg.providers ?? [])].sort((a, b) => rank(a.id) - rank(b.id));
  }, [cfg.providers]);
  const connectUrl = cfg.connectProviderUrl ?? apiUrl("/onboarding/connect-provider");
  const finalizeUrl = cfg.finalizeUrl ?? apiUrl("/onboarding/finalize");
  const roles: OnboardingRole[] = cfg.roles ?? [];
  const providerLabel = useMemo(() => {
    const map: Record<string, string> = {};
    for (const p of providers) map[p.id] = p.label;
    return map;
  }, [providers]);

  // A re-run on a configured site is closable + pre-filled (Law 14). First-run
  // (forced falsy) keeps the no-escape flow.
  const closable = !!cfg.forced;
  const [step, setStep] = useState<Step>("welcome");
  // The optional "What should we call you?" answer — the one polite place the
  // studio ASKS for a name (study 02: names are earned, never scraped). On a
  // re-run, pre-fill the earned name so re-finishing never wipes it.
  const [name, setName] = useState(settings().viewer?.name ?? "");
  // Which provider row is expanded for key entry, and its in-progress credential.
  const [openId, setOpenId] = useState<string | null>(null);
  const [credential, setCredential] = useState("");
  const [status, setStatus] = useState<"idle" | "connecting" | "saving">("idle");
  const [error, setError] = useState<string | null>(null);
  // Everything the operator has connected so far (provider id → probed models).
  const [connected, setConnected] = useState<Record<string, Connected>>({});
  // The per-role model chosen on the models step (role id → "provider:model").
  // Seeded from the site's CURRENT bindings on a re-run, so a no-op finish re-binds
  // the same models rather than wiping them (Law 14).
  const [roleModels, setRoleModels] = useState<Record<string, string>>(cfg.current ?? {});

  // Merged model pools across every connected provider, keyed by "provider:model".
  // On a re-run, existing role bindings are surfaced as options even before a
  // reconnect, so the models step opens ON them instead of on blank selects.
  const chatPool = useMemo(() => augmentPoolWithCurrent(mergePools(connected, "chat"), roles, cfg.current, "chat"), [connected, roles, cfg.current]);
  const imagePool = useMemo(() => augmentPoolWithCurrent(mergePools(connected, "image"), roles, cfg.current, "image"), [connected, roles, cfg.current]);
  // Continue isn't blocked on a re-run: a usable chat provider (or an existing chat
  // binding surfaced above) counts, even before the operator reconnects anything.
  const hasChat = Object.keys(chatPool).length > 0
    || providers.some((p) => p.usable && p.capabilities?.chat);

  // Return to the console, abandoning a re-run without changes (Law 14). Only the
  // forced/closable path reaches this; first-run has no escape.
  const skip = () => window.location.assign(consoleBase());

  // Honour the configured/saved theme so the wizard matches the console it
  // leads into (App sets this too, but App doesn't run on the wizard path).
  useEffect(() => {
    const theme = localStorage.getItem("aincient-theme") || settings().theme || "dark";
    document.getElementById("aincient-chat-root")?.setAttribute("data-ain-theme", theme);
  }, []);

  // Landing on the models step, seed each role from the connected providers'
  // suggestions (a provider:model), falling back to the first option in the
  // role's pool. Reseeds whenever the models step is (re-)entered.
  useEffect(() => {
    if (step !== "models") return;
    const merged: Record<string, string> = {};
    for (const c of Object.values(connected)) Object.assign(merged, c.suggested);
    // Only FILL roles that are still empty — never overwrite a value already set
    // (a pre-filled `current` binding, or the operator's own pick). This is what
    // stops a re-run from clobbering the earned bindings (Law 14).
    setRoleModels((prev) => {
      const seeded: Record<string, string> = { ...prev };
      for (const role of roles) {
        if (seeded[role.id]) continue;
        const pool = role.pool === "image" ? imagePool : chatPool;
        const keys = Object.keys(pool);
        if (keys.length === 0) continue;
        const pick = merged[role.id];
        seeded[role.id] = pick && pool[pick] ? pick : keys[0];
      }
      return seeded;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);

  // Operators without site-config rights can't connect AI — guide, don't trap.
  if (cfg.canConfigure === false) {
    return (
      <main className="ain-wiz" aria-label="Welcome to Atelier">
        <div className="ain-wiz__card">
          <span className="ain-wiz__hero" aria-hidden>
            <Wordmark className="ain-wiz__hero-mark" />
          </span>
          <h1 className="ain-wiz__title">Almost ready</h1>
          <p className="ain-wiz__lede">
            Atelier needs an AI provider connected before it can run. That’s a site-wide
            setup step — ask a site administrator to finish it, then reload this page.
          </p>
        </div>
      </main>
    );
  }

  // Validate + store ONE provider's credential. On success we merge its probed
  // models into `connected` and collapse the row — the operator can connect more,
  // or move on to choosing models.
  const connectProvider = async (id: string) => {
    if (!credential.trim()) {
      const isHost = providers.find((p) => p.id === id)?.auth === "host";
      setError(isHost ? "Enter your server URL." : "Enter your API key.");
      return;
    }
    setStatus("connecting");
    setError(null);
    try {
      const res = await fetch(connectUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ provider: id, credential: credential.trim() }),
      });
      const data = (await res.json().catch(() => ({}))) as OnboardingConnectResult & {
        ok?: boolean;
        error?: string;
      };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed (HTTP ${res.status})`);
      }
      setConnected((prev) => ({
        ...prev,
        [id]: {
          chat: data.models?.chat ?? {},
          image: data.models?.image ?? {},
          suggested: data.suggested ?? {},
        },
      }));
      setStatus("idle");
      setCredential("");
      setOpenId(null);
    } catch (e) {
      setStatus("idle");
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  // Toggle a provider row open for key entry (only one open at a time).
  const toggleProvider = (id: string) => {
    setError(null);
    setCredential("");
    setOpenId((cur) => (cur === id ? null : id));
  };

  // Leave the welcome step, keeping any offered name. The save is best-effort
  // and non-blocking: a name must never stand between the owner and the
  // studio (the server sanitizes maître-d' style; the account pane can always
  // fix it later).
  const begin = () => {
    const offered = name.trim();
    if (offered) {
      void fetch(apiUrl("/account"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name: offered }),
      }).catch(() => {});
    }
    setStep("connect");
  };

  // Bind each role to its chosen provider:model and finish.
  const finish = async () => {
    setStatus("saving");
    setError(null);
    try {
      // Only send roles that actually have a chosen model.
      const chosen: Record<string, string> = {};
      for (const [role, value] of Object.entries(roleModels)) {
        if (value) chosen[role] = value;
      }
      const res = await fetch(finalizeUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ roles: chosen }),
      });
      const data = (await res.json().catch(() => ({}))) as { ok?: boolean; error?: string };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed (HTTP ${res.status})`);
      }
      // The wizard's last act is not a "Finish" screen — it lands in the
      // studio with the composer focused and one suggested first ask
      // pre-typed (study 02: onboarding ends when the owner has MADE
      // something, not when the form is done). The reload shows the
      // configured console; the staged ask rides sessionStorage.
      try {
        sessionStorage.setItem(STAGED_ASK_KEY, SUGGESTIONS[0]);
      } catch {
        /* private mode — land with an empty composer */
      }
      window.location.assign(consoleBase());
    } catch (e) {
      setStatus("idle");
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  return (
    <main className="ain-wiz" aria-label="Welcome to Atelier">
      <div className="ain-wiz__card">
        {/* A re-run can be dismissed back to the console; first-run cannot. */}
        {closable && (
          <button
            type="button"
            className="ain-btn ain-pop__close ain-wiz__close"
            onClick={skip}
            aria-label="Close"
            title="Close — back to the console"
          >
            <XIcon />
          </button>
        )}
        <ProgressDots step={step} />

        {step === "welcome" && (
          <>
            <span className="ain-wiz__hero" aria-hidden>
              <Wordmark className="ain-wiz__hero-mark" />
            </span>
            {/* Complete without a name — never the machine username, never a
                name mined from the email (study 02, onboarding kit). */}
            <h1 className="ain-wiz__title">Welcome to your studio.</h1>
            <p className="ain-wiz__lede">
              Two steps and you’re making: connect your AI, then pick the models it works
              with. Your first page is a plain-words ask away.
            </p>
            {/* The one polite place to ASK for a name — optional, skippable
                without guilt. */}
            <label className="ain-field">
              <span className="ain-field__label">
                <span className="ain-field__labeltext">What should we call you? · optional</span>
              </span>
              <input
                className="ain-field__input"
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && begin()}
                autoComplete="name"
                maxLength={60}
                autoFocus
              />
              <span className="ain-field__hint">
                Skip it and the studio simply won’t pretend to know you.
              </span>
            </label>
            <div className="ain-wiz__actions">
              {closable && (
                <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={skip}>
                  Skip for now
                </button>
              )}
              <button type="button" className="ain-btn ain-topbtn ain-topbtn--primary" onClick={begin}>
                Set up — two steps
              </button>
            </div>
          </>
        )}

        {step === "connect" && (
          <>
            <h1 className="ain-wiz__title">Connect your AI</h1>
            <p className="ain-wiz__lede ain-wiz__lede--row">
              <ShieldCheckIcon className="ain-wiz__shield" />
              Connect one provider or several — chat on one, images on another. Each key is
              validated before it’s saved, and stored on your own server, never in code or git.
            </p>

            <div className="ain-wiz__providers">
              {providers.length === 0 && (
                <p className="ain-wiz__note">
                  No AI providers are installed. Add a provider module (e.g. Anthropic) to
                  continue.
                </p>
              )}
              {providers.map((p) => (
                <ProviderRow
                  key={p.id}
                  provider={p}
                  connected={!!connected[p.id] || !!p.usable}
                  open={openId === p.id}
                  credential={openId === p.id ? credential : ""}
                  status={status === "connecting" && openId === p.id ? "connecting" : "idle"}
                  error={openId === p.id ? error : null}
                  onToggle={() => toggleProvider(p.id)}
                  onCredentialChange={setCredential}
                  onConnect={() => connectProvider(p.id)}
                />
              ))}
            </div>

            {!hasChat && Object.keys(connected).length > 0 && (
              <p className="ain-wiz__note">
                Connect a chat provider (Anthropic, OpenAI, Google Gemini, or Ollama) — the
                studio runs on chat.
              </p>
            )}

            <div className="ain-wiz__actions">
              <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={() => setStep("welcome")}>
                ← Back
              </button>
              {closable && (
                <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={skip}>
                  Skip for now
                </button>
              )}
              <button
                type="button"
                className="ain-btn ain-topbtn ain-topbtn--primary"
                onClick={() => setStep("models")}
                disabled={!hasChat}
              >
                Continue →
              </button>
            </div>
          </>
        )}

        {step === "models" && (
          <>
            <h1 className="ain-wiz__title">Choose your models</h1>
            <p className="ain-wiz__lede">
              Atelier works in roles — pick a model for each, from any provider you connected.
              We’ve suggested good defaults; change any of them now, or come back anytime via
              <strong> Set up AI providers</strong> in your account menu.
            </p>

            <div className="ain-wiz__roles">
              {roles.map((role) => {
                const pool = role.pool === "image" ? imagePool : chatPool;
                const options = Object.keys(pool);
                if (options.length === 0) {
                  // A role whose pool nobody connected. Only surface the (optional)
                  // image role as a gentle nudge; never block finishing on it.
                  if (role.pool !== "image") return null;
                  return (
                    <div key={role.id} className="ain-wiz__role ain-wiz__role--empty">
                      <span className="ain-wiz__role-head">
                        <span className="ain-wiz__role-name">{role.label}</span>
                      </span>
                      <span className="ain-wiz__role-desc">
                        {role.description} Go back to connect Google Gemini to enable it.
                      </span>
                    </div>
                  );
                }
                return (
                  <label key={role.id} className="ain-wiz__role">
                    <span className="ain-wiz__role-head">
                      <span className="ain-wiz__role-name">{role.label}</span>
                    </span>
                    <span className="ain-wiz__role-desc">{role.description}</span>
                    <select
                      className="ain-wiz__select"
                      value={roleModels[role.id] ?? ""}
                      onChange={(e) => setRoleModels((prev) => ({ ...prev, [role.id]: e.target.value }))}
                    >
                      {role.optional && <option value="">- Not set -</option>}
                      {groupByProvider(pool, providerLabel).map((group) => (
                        <optgroup key={group.label} label={group.label}>
                          {group.options.map(([value, label]) => (
                            <option key={value} value={value}>
                              {label}
                            </option>
                          ))}
                        </optgroup>
                      ))}
                    </select>
                  </label>
                );
              })}
            </div>

            {error && <p className="ain-wiz__error">{error}</p>}

            <div className="ain-wiz__actions">
              <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={() => setStep("connect")}>
                ← Back
              </button>
              {closable && (
                <button type="button" className="ain-btn ain-topbtn ain-topbtn--quiet" onClick={skip}>
                  Skip for now
                </button>
              )}
              <button
                type="button"
                className="ain-btn ain-topbtn ain-topbtn--primary"
                onClick={finish}
                disabled={status === "saving"}
              >
                {status === "saving" ? (
                  <>
                    <SpinnerIcon className="ain-wiz__spin" /> Opening your studio…
                  </>
                ) : (
                  "Open your studio"
                )}
              </button>
            </div>
          </>
        )}
      </div>
    </main>
  );
}

/** Merge every connected provider's models for a pool into one map. */
function mergePools(connected: Record<string, Connected>, pool: "chat" | "image"): Record<string, string> {
  const out: Record<string, string> = {};
  for (const c of Object.values(connected)) Object.assign(out, c[pool]);
  return out;
}

/**
 * Surface the site's CURRENT role bindings as options in their pool, so a re-run
 * opens the models step on the existing selection even before any provider is
 * reconnected (Law 14). Only adds a synthetic entry when the pool doesn't already
 * carry that provider:model (a reconnect's real, labelled model always wins). The
 * label is the model half of the "provider:model" value.
 */
function augmentPoolWithCurrent(
  pool: Record<string, string>,
  roles: OnboardingRole[],
  current: Record<string, string> | undefined,
  which: "chat" | "image",
): Record<string, string> {
  if (!current) return pool;
  const out = { ...pool };
  for (const role of roles) {
    const rolePool = role.pool === "image" ? "image" : "chat";
    if (rolePool !== which) continue;
    const value = current[role.id];
    if (value && !out[value]) out[value] = value.split(":").slice(1).join(":") || value;
  }
  return out;
}

/**
 * Group a "provider:model" → label pool into optgroups by provider label, so the
 * selects read as "Anthropic ▸ Claude…", "Google Gemini ▸ Nano Banana…".
 */
function groupByProvider(
  pool: Record<string, string>,
  providerLabel: Record<string, string>,
): { label: string; options: [string, string][] }[] {
  const groups = new Map<string, [string, string][]>();
  for (const [value, label] of Object.entries(pool)) {
    const providerId = value.split(":", 1)[0];
    const group = providerLabel[providerId] ?? providerId;
    if (!groups.has(group)) groups.set(group, []);
    groups.get(group)!.push([value, label]);
  }
  return [...groups.entries()].map(([label, options]) => ({ label, options }));
}
