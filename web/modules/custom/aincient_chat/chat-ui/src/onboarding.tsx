import { useState } from "react";
import { useThreadRuntime } from "@assistant-ui/react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { apiUrl } from "./console-config";

/**
 * Onboarding panel — the first-run "connect AI" generative-UI widget.
 *
 * The `aincient_onboarding:onboarding_panel` capability emits a
 * `{ "__widget__": "onboarding", "payload": … }` envelope from a DETERMINISTIC
 * FlowDrop workflow (no AI node — a fresh site has no usable key yet, so the
 * agent can't run). The dispatcher harvests it and renders this card inline.
 *
 * The card collects the credential for one provider (the recommended one, or
 * the first installed) and POSTs it to `/atelier/onboarding/save`, which
 * validates it BEFORE persisting, stores it via the Key module's state provider
 * (the secret lives in Drupal State, never in config/git), and binds a model to
 * each AIncient role automatically. On success the console drops back to its
 * normal AI-driven routing, and the "Set up my brand" hand-off appends a turn
 * that walks the user into the next step of the happy path. (The full-screen
 * wizard is the richer multi-provider + per-role path.)
 */

export type OnboardingPayload = {
  saveUrl?: string;
  /** The provider this card connects (resolved server-side). */
  provider?: string;
  providerLabel?: string;
  /** "api_key" | "host" — drives the field label + placeholder. */
  auth?: "api_key" | "host";
  configured?: boolean;
};

/** Where to grab an API key, per known provider (a gentle hint). */
const KEY_HELP: Record<string, { href: string; label: string }> = {
  anthropic: { href: "https://console.anthropic.com/settings/keys", label: "console.anthropic.com" },
  openai: { href: "https://platform.openai.com/api-keys", label: "platform.openai.com" },
  openrouter: { href: "https://openrouter.ai/keys", label: "openrouter.ai" },
};

function Onboarding(payload: OnboardingPayload) {
  const saveUrl = payload.saveUrl ?? apiUrl("/onboarding/save");
  const provider = payload.provider ?? "";
  const providerLabel = payload.providerLabel ?? "your AI provider";
  const isHost = payload.auth === "host";
  const keyHelp = isHost ? undefined : KEY_HELP[provider];
  const thread = useThreadRuntime();

  const [apiKey, setApiKey] = useState("");
  const [status, setStatus] = useState<"idle" | "saving" | "done">(
    payload.configured ? "done" : "idle",
  );
  const [error, setError] = useState<string | null>(null);

  const connect = async () => {
    const key = apiKey.trim();
    if (!key) {
      setError(isHost ? "Enter your server URL." : `Enter your ${providerLabel} credential.`);
      return;
    }
    setStatus("saving");
    setError(null);
    try {
      const res = await fetch(saveUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ provider, credential: key }),
      });
      const data = (await res.json().catch(() => ({}))) as { ok?: boolean; error?: string };
      if (!res.ok || !data.ok) {
        throw new Error(data.error || `Request failed (HTTP ${res.status})`);
      }
      setStatus("done");
    } catch (e) {
      setStatus("idle");
      setError(e instanceof Error ? e.message : String(e));
    }
  };

  // Hand off to the next step of the happy path: the key now works, so this
  // appends a normal turn the agent answers with the brand picker.
  const toBranding = () => {
    thread.append({ role: "user", content: [{ type: "text", text: "Help me set up my brand" }] });
  };

  if (status === "done") {
    return (
      <div className="ain-onboard ain-onboard--done">
        <div className="ain-onboard__head">
          <span className="ain-onboard__title">AI connected</span>
        </div>
        <p className="ain-onboard__note">
          Your console is live. Next, give your site a look and feel.
        </p>
        <button type="button" className="ain-btn ain-topbtn ain-topbtn--primary" onClick={toBranding}>
          Set up my brand →
        </button>
      </div>
    );
  }

  return (
    <div className="ain-onboard">
      <div className="ain-onboard__head">
        <span className="ain-onboard__title">Connect AI</span>
        <span className="ain-onboard__hint">Validated before anything is saved</span>
      </div>

      <label className="ain-onboard__field">
        <span className="ain-onboard__label">{isHost ? "Server URL" : `${providerLabel} API key`}</span>
        <input
          type={isHost ? "text" : "password"}
          className="ain-onboard__input"
          value={apiKey}
          onChange={(e) => setApiKey(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && connect()}
          placeholder={isHost ? "http://host.docker.internal:11434" : "sk-…"}
          autoComplete="off"
          spellCheck={false}
        />
      </label>

      {error && <p className="ain-onboard__error">{error}</p>}

      <button
        type="button"
        className="ain-btn ain-topbtn ain-topbtn--primary"
        onClick={connect}
        disabled={status === "saving"}
      >
        {status === "saving" ? "Connecting…" : "Connect"}
      </button>

      <p className="ain-onboard__foot">
        {isHost && (
          <>
            Atelier runs in a container, so <code>localhost</code> points at the container itself. Reach a
            server on the host with <code>http://host.docker.internal:11434</code>.{" "}
          </>
        )}
        {keyHelp && (
          <>
            Get a key at{" "}
            <a href={keyHelp.href} target="_blank" rel="noreferrer">
              {keyHelp.label}
            </a>
            .{" "}
          </>
        )}
        It’s stored on your own server — never in code or git.
      </p>
    </div>
  );
}

/**
 * Registers the onboarding panel for the `onboarding` tool. Mount once inside
 * the AssistantRuntimeProvider; `args` is the payload the dispatcher passed
 * through as the tool call's arguments.
 */
export const OnboardingToolUI = makeSafeAssistantToolUI<OnboardingPayload, unknown>({
  toolName: "onboarding",
  render: ({ args }) => <Onboarding {...args} />,
});
