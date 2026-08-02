import { useEffect, useMemo, useState, type ComponentType, type SVGProps } from "react";
import {
  settings,
  type OnboardingConnectResult,
  type OnboardingPresets,
  type OnboardingProfile,
  type OnboardingProvider,
  type OnboardingRecommendationsMeta,
  type OnboardingRole,
  type OnboardingSettings,
} from "./adapter";
import {
  AnthropicIcon,
  CheckIcon,
  GeminiIcon,
  MistralIcon,
  OllamaIcon,
  OpenAiIcon,
  ShieldCheckIcon,
  SparkleIcon,
  SpinnerIcon,
  TrashIcon,
  Wordmark,
  XIcon,
} from "./icons";
import { ModelPicker, type ModelPickerOption } from "./model-picker";
import { apiUrl, consoleBase } from "./console-config";

/**
 * First-run onboarding wizard — the product's handshake.
 *
 * A dedicated, full-screen, guided flow shown PROACTIVELY on an unconfigured
 * site (the server sets `window.aincientChat.onboarding.needed`). It lives
 * outside the chat conversation so the first run is a polished, can't-miss-it
 * experience rather than a panel hidden behind a chat message.
 *
 * TWO steps: connect your AI → set the pace. Then it LANDS on the console's own
 * welcome — heading, lede, and three one-click sample asks — with NOTHING typed
 * in the composer.
 *
 * That landing is deliberate and it replaced two earlier ideas. There was a
 * separate "welcome" step in front, whose only work was an optional name field;
 * asking who you are before the product has done anything for you makes the
 * first thing Atelier ever shows a form, so the step is gone and the name is now
 * asked AFTER the first page exists (see NameInvite in App.tsx). And the wizard
 * used to stage a sample ask into the composer on the way out; a pre-filled
 * composer turns a choice into a chore — you must read someone else's sentence
 * and clear it — and it is strictly slower than the console's chips, which send
 * themselves. Onboarding still ends when the owner has MADE something; the chips
 * get them there in one click instead of two.
 *
 * The pace step leads with ONE question — Best value / Balanced / Best quality
 * — because asking a beginner to make five independent vendor-model decisions
 * before they have ever used the product is the wrong first impression. The
 * profiles come from a curated document we publish (and can refresh on demand,
 * since the honest answer changes weekly); each resolves to a concrete model per
 * role against what the operator actually connected. The five per-role pickers
 * are unchanged and one disclosure away, for the people they were built for.
 *
 * In auto mode the resolved models are not shown AT ALL — not even as a summary.
 * Someone who answered "decide for me" has said they don't want to evaluate
 * model names, and printing five of them under the answer re-poses the question
 * they just declined. The disclosure is there for anyone who does want to look.
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

type Step = "connect" | "models";

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
  mistral: { href: "https://console.mistral.ai/api-keys", label: "console.mistral.ai" },
};

/**
 * Per-provider brand mark for the picker. People recognise a provider by its
 * mark far quicker than its name, so each row leads with its logo on a white
 * badge. Every mark sits in the same neutral ink so the picker reads as one
 * consistent set. Keyed by drupal/ai's provider plugin id (or key-group primary,
 * e.g. `gemini` for the Google group).
 *
 * A provider we have no mark for renders an EMPTY badge — never the Atelier chip.
 * The fallback used to be our own mark, which put the Atelier logo beside a third
 * party's name (a LiteLLM proxy, a local vLLM endpoint, whatever someone installs)
 * and read as a claim about who made it. An empty logo slot says the honest thing:
 * we have no mark for this one. The slot itself stays, so rows keep their column.
 */
// Literal on purpose: provider marks sit on the always-white logo tile
// (.ain-wiz__provider-badge), so their ink must not follow the theme tokens.
const PROVIDER_INK = "#0a0a0a";
const PROVIDER_BRAND: Record<string, { Icon: ComponentType<SVGProps<SVGSVGElement>> }> = {
  anthropic: { Icon: AnthropicIcon },
  openai: { Icon: OpenAiIcon },
  gemini: { Icon: GeminiIcon },
  // Nano Banana is Google's Gemini 2.5 Flash image model — wears the Gemini mark
  // rather than falling through to an empty badge.
  nanobanana: { Icon: GeminiIcon },
  ollama: { Icon: OllamaIcon },
  mistral: { Icon: MistralIcon },
};

/**
 * Display order for the picker — Mistral and Anthropic lead (both recommended),
 * then Ollama (local-first, no key needed), OpenAI, and Google Gemini. Providers
 * not listed keep their server order, sorted after the known ones.
 */
const PROVIDER_ORDER = ["mistral", "anthropic", "ollama", "openai", "gemini"];

function progressFor(step: Step): { index: number; total: number } {
  const order: Step[] = ["connect", "models"];
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
/** The curated provider label shown as a small chip (or null when neutral). */
function providerChip(recommendation?: string): { text: string; tone: string } | null {
  switch (recommendation) {
    case "recommended":
      return { text: "Recommended", tone: "rec" };
    case "tested":
      return { text: "Tested", tone: "tested" };
    case "not-recommended":
      return { text: "Not recommended", tone: "warn" };
    default:
      return null;
  }
}

function ProviderRow({
  provider,
  connected,
  modelCount,
  open,
  credential,
  endpoint,
  status,
  disconnecting,
  error,
  onToggle,
  onCredentialChange,
  onEndpointChange,
  onConnect,
  onDisconnect,
}: {
  provider: OnboardingProvider;
  connected: boolean;
  /**
   * How many models this key just turned out to reach, or 0 when we don't know.
   *
   * Only a probe THIS session yields a number — the server hands back the models
   * it enumerated when it validated the key. A row that is connected because a
   * key was already stored (a re-run, a headless install) has no fresh probe, so
   * it says "Connected" and stops rather than inventing or back-deriving a
   * count. Never guess a number at the one moment we're claiming competence.
   */
  modelCount: number;
  open: boolean;
  credential: string;
  /** The base URL, for the two-field `api_key_endpoint` shape only. */
  endpoint: string;
  status: "idle" | "connecting";
  disconnecting: boolean;
  error: string | null;
  onToggle: () => void;
  onCredentialChange: (value: string) => void;
  onEndpointChange: (value: string) => void;
  onConnect: () => void;
  onDisconnect: () => void;
}) {
  // No mark for this provider ⇒ an empty badge, never ours (see PROVIDER_BRAND).
  const Icon = PROVIDER_BRAND[provider.id]?.Icon;
  const isHost = provider.auth === "host";
  // A key AND the base URL to send it to — two fields, both required. This row
  // is why the shape was hidden from the picker at all: offering a provider one
  // field can't connect is the dead end the whole step is built to avoid.
  const needsEndpoint = provider.auth === "api_key_endpoint";
  const keyHelp = isHost ? undefined : KEY_HELP[provider.id];
  const chip = providerChip(provider.recommendation);
  return (
    <div
      className={`ain-wiz__provider${open ? " ain-wiz__provider--selected" : ""}${
        connected ? " ain-wiz__provider--recommended" : ""
      }`}
      data-provider={provider.id}
    >
      <div className="ain-wiz__provider-top">
        <button type="button" className="ain-btn ain-wiz__provider-hit" onClick={onToggle} aria-expanded={open}>
          <span
            className={`ain-wiz__provider-badge${Icon ? "" : " ain-wiz__provider-badge--empty"}`}
            style={{ color: PROVIDER_INK }}
            aria-hidden
          >
            {Icon && <Icon className="ain-wiz__provider-mark" />}
          </span>
          <span className="ain-wiz__provider-body">
            <span className="ain-wiz__provider-head">
              <span className="ain-wiz__provider-name">{provider.label}</span>
              {chip && (
                <span className={`ain-wiz__chip ain-wiz__chip--${chip.tone}`}>
                  {chip.tone === "rec" && <SparkleIcon className="ain-wiz__chip-icon" />}
                  {chip.text}
                </span>
              )}
              <CapabilityChips provider={provider} />
              {connected && (
                <span className="ain-wiz__chip ain-wiz__chip--ok">
                  <CheckIcon className="ain-wiz__chip-icon" /> Connected
                  {modelCount > 0 && (
                    // The quiet proof that the key WORKS: we didn't just store
                    // it, we reached the provider and counted what came back. A
                    // whisper on the chip (Law 02), not a banner — and no
                    // exclamation (Law 05).
                    <span className="ain-wiz__chip-count">
                      · {modelCount} {modelCount === 1 ? "model" : "models"} ready
                    </span>
                  )}
                </span>
              )}
            </span>
            {provider.description && <span className="ain-wiz__provider-desc">{provider.description}</span>}
          </span>
        </button>

        {connected && (
          <button
            type="button"
            className="ain-btn ain-wiz__provider-disconnect"
            onClick={onDisconnect}
            disabled={disconnecting}
            aria-label={`Disconnect ${provider.label} — removes the stored key`}
            title={`Disconnect ${provider.label} — removes the stored key`}
          >
            {disconnecting ? (
              <SpinnerIcon className="ain-wiz__spin" />
            ) : (
              <TrashIcon className="ain-wiz__provider-disconnect-icon" />
            )}
            <span className="ain-wiz__provider-disconnect-label">Disconnect</span>
          </button>
        )}
      </div>

      {open && (
        <div className="ain-wiz__connect">
          {/* The base URL leads: it says WHICH service this is, and the key is
              meaningless without it. Its own row, no button — the Connect press
              belongs to the last field you fill. */}
          {needsEndpoint && (
            <label className="ain-wiz__field">
              <span className="ain-wiz__label">Base URL</span>
              <div className="ain-wiz__field-row">
                <input
                  type="text"
                  className="ain-wiz__input"
                  value={endpoint}
                  onChange={(e) => onEndpointChange(e.target.value)}
                  onKeyDown={(e) => e.key === "Enter" && onConnect()}
                  placeholder="https://api.deepseek.com"
                  autoComplete="off"
                  spellCheck={false}
                  autoFocus
                />
              </div>
            </label>
          )}
          <label className="ain-wiz__field">
            <span className="ain-wiz__label">{isHost ? "Server URL" : "API key"}</span>
            <div className="ain-wiz__field-row">
              <input
                type={isHost ? "text" : "password"}
                className="ain-wiz__input"
                value={credential}
                onChange={(e) => onCredentialChange(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && onConnect()}
                placeholder={isHost ? "http://host.docker.internal:11434" : "sk-…"}
                autoComplete="off"
                spellCheck={false}
                autoFocus={!needsEndpoint}
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
          {needsEndpoint && (
            <p className="ain-wiz__foot">
              The address of any service that speaks the OpenAI API — a hosted one like{" "}
              <code>https://api.deepseek.com</code>, or your own proxy or local server. Atelier appends{" "}
              <code>/v1</code> itself, so leave it off.
            </p>
          )}
          {/* Both URL-taking shapes hit the same wall: `localhost` inside a
              container is the container. Said once, for whichever field is on
              screen — the port is the only part that differs. */}
          {(isHost || needsEndpoint) && (
            <p className="ain-wiz__foot">
              Atelier runs in a container, so <code>localhost</code> points at the container itself — not the
              machine where your server runs. Reach a server on the host with{" "}
              <code>http://host.docker.internal:{isHost ? "11434" : "8000"}</code>.
            </p>
          )}
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
  const disconnectUrl = cfg.disconnectUrl ?? apiUrl("/onboarding/disconnect-provider");
  const refreshUrl = cfg.refreshRecommendationsUrl ?? apiUrl("/onboarding/refresh-recommendations");
  // Curated quality label per available "provider:model" (absent ⇒ "untested").
  const modelLabels = cfg.modelLabels ?? {};
  const roles: OnboardingRole[] = cfg.roles ?? [];
  const providerLabel = useMemo(() => {
    const map: Record<string, string> = {};
    for (const p of providers) map[p.id] = p.label;
    return map;
  }, [providers]);

  // A re-run on a configured site is closable + pre-filled (Law 14). First-run
  // (forced falsy) keeps the no-escape flow.
  const closable = !!cfg.forced;
  const [step, setStep] = useState<Step>("connect");
  // Which provider row is expanded for key entry, and its in-progress credential.
  const [openId, setOpenId] = useState<string | null>(null);
  const [credential, setCredential] = useState("");
  // The open row's base URL, for the `api_key_endpoint` shape only. Kept apart
  // from `credential` because the two are stored apart — one is a secret, the
  // other is an address — and conflating them is what made this shape wait.
  const [endpoint, setEndpoint] = useState("");
  const [status, setStatus] = useState<"idle" | "connecting" | "saving">("idle");
  const [error, setError] = useState<string | null>(null);
  // The provider row currently being disconnected (its button shows a spinner).
  const [disconnectingId, setDisconnectingId] = useState<string | null>(null);
  // Everything the operator has connected so far (provider id → probed models).
  const [connected, setConnected] = useState<Record<string, Connected>>({});
  // The per-role model chosen on the models step (role id → "provider:model").
  // Seeded from the site's CURRENT bindings on a re-run, so a no-op finish re-binds
  // the same models rather than wiping them (Law 14).
  const [roleModels, setRoleModels] = useState<Record<string, string>>(cfg.current ?? {});

  // ---- the models step's simple mode ---------------------------------------
  // The curated profiles, their resolved per-role picks, and where they came
  // from. All three are seeded from the server and REPLACED by a refresh, so the
  // "Check for updates" click is felt immediately rather than after a reload.
  const [profiles, setProfiles] = useState<OnboardingProfile[]>(cfg.profiles ?? []);
  const [presets, setPresets] = useState<OnboardingPresets>(cfg.presets ?? {});
  const [meta, setMeta] = useState<OnboardingRecommendationsMeta>(cfg.recommendationsMeta ?? {});
  const [refreshing, setRefreshing] = useState(false);
  const [refreshNote, setRefreshNote] = useState<string | null>(null);
  // The chosen profile, or NULL for "Custom" — the state you land in the moment
  // you touch a per-role picker. On a re-run this is READ, not inferred: the
  // server stores which tier is in force, so a site set up with Balanced reopens
  // on Balanced. It used to be derived from "are there bindings?", which reported
  // Custom for every configured site and buried the tier the operator actually
  // chose. Bindings are still never silently overwritten (Law 14) — reopening on
  // a tier re-applies the same models that tier already resolved to.
  const hasEarnedBindings = Object.keys(cfg.current ?? {}).length > 0;
  const activeProfile = cfg.activeProfile ?? "";
  const [profile, setProfile] = useState<string | null>(
    activeProfile !== ""
      ? activeProfile
      : hasEarnedBindings
        ? null
        : (cfg.defaultProfile ?? cfg.profiles?.[0]?.id ?? null),
  );
  // Whether the per-role pickers are revealed. Only advanced operators care which
  // concrete models are in play, so auto mode keeps them collapsed: open from the
  // start only when there is no simple mode to offer, or when the site is genuinely
  // Custom (earned bindings with no tier in force).
  const [advanced, setAdvanced] = useState(
    (hasEarnedBindings && activeProfile === "") || (cfg.profiles ?? []).length === 0,
  );

  // Merged model pools across every connected provider, keyed by "provider:model".
  // The base is the server-enumerated `catalog` (every provider with a STORED
  // key — so the step lists all connected providers on load, even a re-run or a
  // headlessly-set key), with this session's fresh connects layered on top and
  // existing role bindings surfaced as options. This is what decouples the models
  // step from in-session connects.
  const chatPool = useMemo(
    () => augmentPoolWithCurrent({ ...(cfg.catalog?.chat ?? {}), ...mergePools(connected, "chat") }, roles, cfg.current, "chat"),
    [connected, roles, cfg.current, cfg.catalog],
  );
  const imagePool = useMemo(
    () => augmentPoolWithCurrent({ ...(cfg.catalog?.image ?? {}), ...mergePools(connected, "image") }, roles, cfg.current, "image"),
    [connected, roles, cfg.current, cfg.catalog],
  );
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

  // Fill every role from a profile's resolved picks. Unlike the seeding below
  // this REPLACES the whole selection — choosing a profile is precisely the act
  // of saying "decide all of these for me". A pick the pool doesn't carry (a
  // stale entry, or a provider connected since) degrades to the pool's first
  // option so no role is left blank.
  const applyProfile = (id: string, source: OnboardingPresets = presets) => {
    const preset = source[id] ?? {};
    setRoleModels(() => {
      const next: Record<string, string> = {};
      for (const role of roles) {
        const pool = role.pool === "image" ? imagePool : chatPool;
        const keys = Object.keys(pool);
        if (keys.length === 0) continue;
        const pick = preset[role.id];
        next[role.id] = pick && pool[pick] ? pick : keys[0];
      }
      return next;
    });
  };

  // A per-role pick is by definition no longer a profile — drop to Custom rather
  // than leaving a profile highlighted that no longer describes the selection.
  const chooseRoleModel = (roleId: string, value: string) => {
    setProfile(null);
    setRoleModels((prev) => ({ ...prev, [roleId]: value }));
  };

  // Landing on the models step: apply the chosen profile, or — in Custom mode —
  // seed each still-empty role from the connected providers' suggestions,
  // falling back to the first option in the role's pool.
  useEffect(() => {
    if (step !== "models") return;
    if (profile) {
      applyProfile(profile);
      return;
    }
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
  }, [step, profile]);

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
    const auth = providers.find((p) => p.id === id)?.auth;
    if (!credential.trim()) {
      setError(auth === "host" ? "Enter your server URL." : "Enter your API key.");
      return;
    }
    // Refuse locally rather than round-tripping to learn what the field labels
    // already say. The server checks the same thing — this is the faster of two
    // identical answers, not the only one.
    if (auth === "api_key_endpoint" && !endpoint.trim()) {
      setError("Enter the base URL to call.");
      return;
    }
    setStatus("connecting");
    setError(null);
    try {
      const res = await fetch(connectUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          provider: id,
          credential: credential.trim(),
          ...(auth === "api_key_endpoint" ? { endpoint: endpoint.trim() } : {}),
        }),
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
      // The server recomputes every profile across ALL connected providers, so a
      // second provider can legitimately move a role onto it. Take the new map;
      // the models step applies it when entered.
      if (data.presets) setPresets(data.presets);
      setStatus("idle");
      setCredential("");
      setEndpoint("");
      setOpenId(null);
    } catch (e) {
      setStatus("idle");
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  // Fetch the latest published recommendations. Explicit, one click, never
  // automatic — this is the only time Atelier talks to aincient-labs.com, and it
  // happens because someone asked it to. A failure changes nothing: the
  // suggestions already in force keep working, and we say so.
  const refreshRecommendations = async () => {
    setRefreshing(true);
    setRefreshNote(null);
    try {
      const res = await fetch(refreshUrl, { method: "POST", credentials: "same-origin" });
      const data = (await res.json().catch(() => ({}))) as {
        ok?: boolean;
        error?: string;
        meta?: OnboardingRecommendationsMeta;
        profiles?: OnboardingProfile[];
        presets?: OnboardingPresets;
      };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Couldn’t check for updates (HTTP ${res.status}).`);
      }
      if (data.profiles?.length) setProfiles(data.profiles);
      if (data.meta) setMeta(data.meta);
      if (data.presets) {
        setPresets(data.presets);
        // Re-resolve what's on screen against the new document, or the click
        // would look like it did nothing.
        if (profile) applyProfile(profile, data.presets);
      }
      setRefreshNote("Suggestions updated.");
    } catch (e) {
      setRefreshNote(e instanceof Error ? e.message : String(e));
    } finally {
      setRefreshing(false);
    }
  };

  // Remove a provider's stored credential. On success we reload the wizard so the
  // catalogue, connected badges, and role pre-fills are all re-derived from the
  // server — correct by construction (connected keys are already persisted, so a
  // reload never loses this session's connects), and simpler than pruning the
  // pools, bindings, and badges by hand.
  const disconnectProvider = async (id: string) => {
    setDisconnectingId(id);
    setError(null);
    try {
      const res = await fetch(disconnectUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ provider: id }),
      });
      const data = (await res.json().catch(() => ({}))) as { ok?: boolean; error?: string };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed (HTTP ${res.status})`);
      }
      window.location.reload();
    } catch (e) {
      setDisconnectingId(null);
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  // Toggle a provider row open for key entry (only one open at a time).
  const toggleProvider = (id: string) => {
    setError(null);
    setCredential("");
    setEndpoint("");
    setOpenId((cur) => (cur === id ? null : id));
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
        // The tier travels with the bindings. Sending only the resolved models
        // would throw away the intent behind them: the site could no longer say
        // which tier is active, and a later recommendations update would have
        // nothing to honour. `null` (Custom) is sent as '' — an explicit "these
        // are mine", which no refresh will overwrite.
        body: JSON.stringify({ roles: chosen, profile: profile ?? "" }),
      });
      const data = (await res.json().catch(() => ({}))) as { ok?: boolean; error?: string };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed (HTTP ${res.status})`);
      }
      // The wizard's last act is not a "Finish" screen — it lands on the
      // console's own welcome, which already asks the only question that
      // matters ("What would you like to create?") and offers three asks that
      // send themselves. We deliberately type NOTHING into the composer: a
      // pre-filled ask reads as an assignment, has to be cleared before you can
      // write your own, and still needs a press — the chips are both friendlier
      // and fewer clicks. Onboarding ends when the owner has made something
      // (study 02); the chips are the shortest path to that.
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

        {step === "connect" && (
          <>
            {/* The wordmark carries the welcome now that the greeting step is
                gone — one brand gesture at display size (Law 03), then straight
                to the only thing standing between the owner and the studio. */}
            <span className="ain-wiz__hero" aria-hidden>
              <Wordmark className="ain-wiz__hero-mark" />
            </span>
            <h1 className="ain-wiz__title">Welcome to your studio.</h1>
            <p className="ain-wiz__lede">
              One thing to set up: the AI that does the making. Connect a provider below and
              your first page is a plain-words ask away.
            </p>
            <p className="ain-wiz__lede ain-wiz__lede--row">
              <ShieldCheckIcon className="ain-wiz__shield" />
              Connect one or several — chat on one, images on another. Each key is validated
              before it’s saved, and stored on your own server, never in code or git.
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
                  modelCount={countProbedModels(connected[p.id])}
                  open={openId === p.id}
                  credential={openId === p.id ? credential : ""}
                  endpoint={openId === p.id ? endpoint : ""}
                  status={status === "connecting" && openId === p.id ? "connecting" : "idle"}
                  disconnecting={disconnectingId === p.id}
                  error={openId === p.id ? error : null}
                  onToggle={() => toggleProvider(p.id)}
                  onCredentialChange={setCredential}
                  onEndpointChange={setEndpoint}
                  onConnect={() => connectProvider(p.id)}
                  onDisconnect={() => disconnectProvider(p.id)}
                />
              ))}
            </div>

            {/* No enumeration here: the rows above ARE the list, and a hardcoded
                one goes stale the moment an adapter is added. */}
            {!hasChat && Object.keys(connected).length > 0 && (
              <p className="ain-wiz__note">
                Connect one of the chat providers above — the studio runs on chat.
              </p>
            )}

            {/* No Back: connect is the first door now. One primary, one quiet
                escape on a re-run — two raised shapes at most (Laws 07, 08). */}
            <div className="ain-wiz__actions">
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
            {/* The headline used to read "Choose your models" — on the screen
                built so that nobody has to. It now names the question actually
                being asked; the old title only survives where there is no
                curated tier to offer and the pickers really are the step. */}
            <h1 className="ain-wiz__title">
              {profiles.length > 0 ? "Set the pace" : "Choose your models"}
            </h1>
            <p className="ain-wiz__lede">
              {profiles.length > 0
                ? "Tell us what matters most and Atelier picks the right AI for each job. Change it whenever you like, via "
                : "Atelier works in roles — pick a model for each, from any provider you connected. We’ve suggested good defaults; change any of them now, or come back anytime via "}
              <strong>Set up AI providers</strong> in your account menu.
            </p>

            {profiles.length > 0 && (
              <div className="ain-wiz__profiles">
                <span className="ain-wiz__label" id="ain-wiz-profile-label">
                  What matters most?
                </span>
                <div className="ain-wiz__segmented" role="radiogroup" aria-labelledby="ain-wiz-profile-label">
                  {profiles.map((p) => (
                    <button
                      key={p.id}
                      type="button"
                      role="radio"
                      aria-checked={profile === p.id}
                      className={`ain-wiz__segment${profile === p.id ? " ain-wiz__segment--on" : ""}`}
                      // Choosing a tier collapses the pickers. A Custom site opens
                      // this step with them showing; leaving five model dropdowns
                      // on screen after the operator has just said "you decide"
                      // would contradict the answer in the act of accepting it.
                      // Re-openable from the disclosure — this hides, never locks.
                      onClick={() => {
                        setProfile(p.id);
                        setAdvanced(false);
                      }}
                    >
                      {p.label}
                    </button>
                  ))}
                </div>
                <p className="ain-wiz__profile-desc">
                  {profile
                    ? (profiles.find((p) => p.id === profile)?.description ?? "")
                    : "Your own selection — each role set by hand below."}
                </p>
              </div>
            )}

            {/* Auto mode shows NOTHING here. This used to print the five roles
                and the model each resolved to — which re-posed, in technical
                vocabulary, the exact question the operator had just answered
                with "you decide". The models are one disclosure away for anyone
                who wants them; the one thing worth surfacing unprompted is a
                role that CAN'T be filled, below. */}
            {profiles.length > 0 && !advanced && <UnfillableRoles roles={roles} imagePool={imagePool} />}

            {advanced && (
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
                    <div key={role.id} className="ain-wiz__role">
                      <span className="ain-wiz__role-head">
                        <span className="ain-wiz__role-name">{role.label}</span>
                      </span>
                      <span className="ain-wiz__role-desc">{role.description}</span>
                      <ModelPicker
                        ariaLabel={role.label}
                        value={roleModels[role.id] ?? ""}
                        options={poolToOptions(pool, providerLabel, modelLabels)}
                        allowNone={role.optional}
                        noneLabel="Not set"
                        onChange={(value) => chooseRoleModel(role.id, value)}
                        renderMark={(id) => <ProviderMark id={id} />}
                      />
                    </div>
                  );
                })}
              </div>
            )}

            {profiles.length > 0 && (
              <button
                type="button"
                className="ain-btn ain-wiz__disclosure"
                aria-expanded={advanced}
                onClick={() => setAdvanced((v) => !v)}
              >
                {advanced ? "Hide per-role choices" : "Choose per role"} {advanced ? "↑" : "→"}
              </button>
            )}

            {cfg.preferencesDeclared && <SitePreferencesNote />}

            <p className="ain-wiz__foot ain-wiz__provenance">
              <RecommendationsNote meta={meta} />{" "}
              <button
                type="button"
                className="ain-btn ain-wiz__linkbtn"
                onClick={refreshRecommendations}
                disabled={refreshing}
                title={`Fetches ${meta.url ?? "the latest suggestions"} — nothing is sent from this site.`}
              >
                {refreshing ? "Checking…" : "Check for updates"}
              </button>
              {refreshNote && <span className="ain-wiz__provenance-note"> {refreshNote}</span>}
            </p>

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

/**
 * The one thing auto mode still has to say out loud: a role nothing can fill.
 *
 * Hiding the resolved models is right — the operator asked us to decide. Hiding
 * a *capability gap* would not be: "images are off because no image provider is
 * connected" is not a model choice, it's a fact about what the studio can do,
 * and the operator can only learn it here. Same nudge the pickers show, minus
 * the picker.
 *
 * Only image roles reach this: every other role draws on the chat pool, and the
 * connect step already refuses to advance without one.
 */
function UnfillableRoles({
  roles,
  imagePool,
}: {
  roles: OnboardingRole[];
  imagePool: Record<string, string>;
}) {
  const unfillable = roles.filter((r) => r.pool === "image" && Object.keys(imagePool).length === 0);
  if (unfillable.length === 0) return null;
  return (
    <div className="ain-wiz__gaps">
      {unfillable.map((role) => (
        <p key={role.id} className="ain-wiz__gap">
          <span className="ain-wiz__gap-role">{role.label} is off.</span> {role.description} Go back
          and connect Google Gemini to enable it.
        </p>
      ))}
    </div>
  );
}

/**
 * Where the suggestions came from, in one honest sentence.
 *
 * "Bundled" is not an apology — the shipped snapshot is what makes an offline or
 * air-gapped install work — so it reads as a fact with a date, not a warning.
 */
/**
 * Says that this site narrows what the tiers may pick.
 *
 * Without it a tier is a lie of omission: the operator picks "Balanced", gets a
 * model our own guidance doesn't name, and has nothing to attribute it to. The
 * line does not attempt to render the rules — it names where they live, which is
 * the one thing someone surprised by a pick actually needs.
 */
function SitePreferencesNote() {
  return (
    <p className="ain-wiz__foot ain-wiz__sitepref">
      This site narrows which models may be chosen, so a tier here can differ from
      our published suggestions. The rules live in <code>aincient_core.model_preferences</code>.
    </p>
  );
}

function RecommendationsNote({ meta }: { meta: OnboardingRecommendationsMeta }) {
  const dated = meta.updated ? ` ${meta.updated}` : "";
  if (meta.source === "remote") {
    return <>Suggestions updated{dated} from aincient-labs.com.</>;
  }
  return <>Suggestions bundled with this release{dated}.</>;
}

/**
 * How many distinct models a just-connected provider turned out to reach.
 *
 * Counts the union of its chat and image pools — both are keyed
 * "provider:model", so a model offering both capabilities is counted once, and a
 * key group (Google: gemini + nanobanana) correctly counts across both members
 * because the server returns them under the one row that owns the key.
 *
 * Returns 0 for a provider with no entry, which is the "we didn't probe this
 * session" case, not "zero models" — the caller renders nothing rather than
 * claiming a count it never measured.
 */
function countProbedModels(entry: Connected | undefined): number {
  if (!entry) return 0;
  return new Set([...Object.keys(entry.chat ?? {}), ...Object.keys(entry.image ?? {})]).size;
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
 * Turn a "provider:model" → label pool into the picker's option list, stamping
 * each with its provider (for grouping + the mark) and our curated quality label
 * (from `modelLabels`; anything absent is "untested"). The picker handles the
 * ordering — recommended pinned to the top of its provider group.
 */
function poolToOptions(
  pool: Record<string, string>,
  providerLabel: Record<string, string>,
  modelLabels: Record<string, string>,
): ModelPickerOption[] {
  return Object.entries(pool).map(([value, label]) => {
    const providerId = value.split(":", 1)[0];
    return {
      value,
      label,
      providerId,
      providerLabel: providerLabel[providerId] ?? providerId,
      recommendation: modelLabels[value] ?? "untested",
    };
  });
}

/**
 * A provider's brand mark on the always-white badge, for the picker rows.
 *
 * Empty for a provider we have no mark for — the model rows are labelled with the
 * model id and grouped under the provider's name, so the slot can be blank without
 * anything becoming ambiguous. Borrowing our own mark would imply the model is ours.
 */
function ProviderMark({ id }: { id: string }) {
  const Icon = PROVIDER_BRAND[id]?.Icon;
  return (
    <span
      className={`ain-picker__mark${Icon ? "" : " ain-picker__mark--empty"}`}
      style={{ color: PROVIDER_INK }}
      aria-hidden
    >
      {Icon && <Icon className="ain-picker__mark-svg" />}
    </span>
  );
}
