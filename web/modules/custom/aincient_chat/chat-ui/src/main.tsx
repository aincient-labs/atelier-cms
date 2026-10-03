import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { settings } from "./adapter";
import { OnboardingWizard } from "./onboarding-wizard";
import { ErrorBoundary } from "./kit/error-boundary";
import { parseUrl } from "./console-url";
import { roomStudio } from "./rooms-core";
import { loadStudio, preloadStudios } from "./studio-loader";
import "./styles.css";

/**
 * The whole-console safety net. If anything throws during render with no nearer
 * boundary to catch it, this stands in for the blank white screen: a styled,
 * self-contained card ("Try again" re-renders in place so a transient hiccup
 * doesn't lose unsaved studio work; "Reload" is the hard reset). Styles are
 * inline on purpose — a crash may implicate the stylesheet, so the fallback
 * must not depend on it.
 */
function ConsoleCrash({ retry }: { retry: () => void }) {
  // Literal Atelier values (gesso ground, bistre ink, one cinnabar primary) —
  // tokens live in the stylesheet this screen must survive without.
  return (
    <div
      role="alert"
      style={{
        display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center",
        gap: "16px", minHeight: "100vh", padding: "24px", textAlign: "center",
        font: "15px/1.5 system-ui, sans-serif", color: "#201D18", background: "#EBE9E2",
      }}
    >
      <div style={{ maxWidth: "420px" }}>
        <h1
          style={{
            fontSize: "20px", margin: "0 0 8px", fontWeight: 600,
            fontFamily: '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif',
          }}
        >
          The console hit a snag
        </h1>
        <p style={{ margin: 0, color: "#5C564A" }}>
          Something went wrong rendering this view. Your work hasn’t been lost — try again, or
          reload if it keeps happening.
        </p>
      </div>
      <div style={{ display: "flex", gap: "10px" }}>
        <button
          type="button"
          onClick={retry}
          style={{
            padding: "8px 16px", border: "1px solid #C2452A", borderRadius: "7px",
            background: "#C2452A", color: "#FBFAF7", fontWeight: 600, cursor: "pointer",
            boxShadow: "0 1px 2px rgba(32,29,24,0.15)",
          }}
        >
          Try again
        </button>
        <button
          type="button"
          onClick={() => window.location.reload()}
          style={{
            padding: "8px 16px", border: "1px solid #D6D2C8", borderRadius: "7px",
            background: "#FCFBF8", color: "#201D18", fontWeight: 600, cursor: "pointer",
            boxShadow: "0 1px 2px rgba(32,29,24,0.06)",
          }}
        >
          Reload
        </button>
      </div>
    </div>
  );
}

function mount(): void {
  const el = document.getElementById("aincient-chat-root");
  if (!el) return;
  // First-run: an unconfigured site shows the dedicated onboarding wizard
  // instead of the console. Decided here so the chat runtime/stack never
  // initialises before AI is connected (the server sets `onboarding.needed`).
  const root = settings().onboarding?.needed ? <OnboardingWizard /> : <App />;
  createRoot(el).render(
    <StrictMode>
      <ErrorBoundary label="console-root" fallback={(retry) => <ConsoleCrash retry={retry} />}>
        {root}
      </ErrorBoundary>
    </StrictMode>,
  );
}

/**
 * Boot. The console is split into chunks (vite.chunks.ts): this entry, the
 * console, and one per studio module. The studio the URL opens is fetched
 * BEFORE the first render so that room paints with its rail rather than a
 * skeleton — the page is blank until then anyway, exactly as it was while one
 * bundle parsed. Every other studio is fetched at idle afterwards, so the chat
 * cards their agents emit render in old threads too (studio-loader.ts). A
 * failed fetch still mounts: the rail then shows its retry, and the chat works.
 */
function boot(): void {
  // The kit gallery (/atelier/dev/kit) is its own chunk and its own surface:
  // no chat runtime, no studios, no onboarding check.
  if (settings().view === "kit") {
    void import("./kit-gallery/gallery").then(({ KitGallery }) => {
      const el = document.getElementById("aincient-chat-root");
      if (!el) return;
      createRoot(el).render(
        <StrictMode>
          <ErrorBoundary label="kit-gallery" fallback={(retry) => <ConsoleCrash retry={retry} />}>
            <KitGallery />
          </ErrorBoundary>
        </StrictMode>,
      );
    });
    return;
  }
  if (settings().onboarding?.needed) {
    mount();
    return;
  }
  let initial: string | undefined;
  try {
    initial = roomStudio(parseUrl().room);
  } catch {
    initial = undefined;
  }
  void loadStudio(initial).finally(() => {
    mount();
    preloadStudios();
  });
}

// The shell may load the script in <head> (Drupal library placement), so wait
// for the mount point to exist.
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", boot);
} else {
  boot();
}
