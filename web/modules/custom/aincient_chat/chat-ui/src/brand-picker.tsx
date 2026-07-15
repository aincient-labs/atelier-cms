import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { makeSafeAssistantToolUI } from "./error-boundary";
import { setBrandOverride, setPendingFonts } from "./brand-state";
import { ensureStudio } from "./flow";
import { consoleNav } from "./console-nav";
import { XIcon } from "./icons";
import { apiUrl } from "./console-config";

/**
 * Quick brand picker — the branding agent's generative-UI widget.
 *
 * The `aincient_brand:brand_picker` capability emits a
 * `{ "__widget__": "brand_picker", "payload": … }` envelope; the dispatcher
 * harvests it out of the agent's tool results and renders this card inline.
 * It deliberately exposes only a SMALL slice of the full brand studio — the
 * primary + accent colour and a few starting presets.
 *
 * Picking a swatch or a preset does NOT touch the live site. It seeds the
 * shared override store (brand-state.ts) with the chosen token(s) and opens the
 * full brand studio, so the change shows in the live preview the moment it's
 * clicked and the user lands in the studio to review + fine-tune. The one
 * deliberate global write is the studio's Publish button — so a stray pick
 * can't reskin the running site. A preset stages its whole token map; its web
 * fonts (which can't be previewed as a CSS var) ride along as pending fonts and
 * apply on Publish.
 *
 * The Tailwind swatch palette + the live token values come from the same
 * `/atelier/brand/manifest` endpoint the full studio uses, so the envelope
 * itself stays tiny. Defensive: renders only what's present, bails to a plain
 * note if the manifest can't load.
 */

type PresetSummary = {
  id: string;
  label: string;
  blurb: string;
  primary: string;
  accent: string;
  /** The full token map (by token name) a preset sets — staged as a draft. */
  tokens?: Record<string, string>;
  /** Web fonts the preset uses — staged to ride along on Publish. */
  fonts?: string[];
};

export type BrandPickerPayload = {
  current?: { primary?: string | null; accent?: string | null };
  presets?: PresetSummary[];
  manifestUrl?: string;
  /**
   * Set by the adapter on a card replayed from storage. A historical picker is
   * read-only history: picking a swatch/preset applies NOTHING, so re-opening a
   * thread and clicking an old preset can't reskin the current draft.
   */
  __historical?: boolean;
};

type Swatch = { step: string; css_var: string; value: string };
type PaletteGroup = { hue: string; label: string; swatches: Swatch[] };
type TokenDef = { name: string; css_var: string; current: string; default: string };
type Manifest = { tokens: TokenDef[]; palette: PaletteGroup[] };

type Slot = "primary" | "accent";
const SLOT_TOKEN: Record<Slot, string> = { primary: "brand_primary", accent: "brand_accent" };

/** Match `var(--name)` (ignoring any fallback) and return the referenced name. */
function refTarget(value: string): string | null {
  const m = /^var\(\s*--([a-z0-9-]+)\s*(?:,[^)]*)?\)$/i.exec(value.trim());
  return m ? m[1] : null;
}

function BrandPicker(payload: BrandPickerPayload) {
  const manifestUrl = payload.manifestUrl ?? apiUrl("/brand/manifest");
  const presets = payload.presets ?? [];
  // A replayed-from-storage card is read-only history — picks apply nothing.
  const historical = payload.__historical === true;

  const [manifest, setManifest] = useState<Manifest | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Working raw values per slot (e.g. "var(--color-rose-500)" or a hex).
  const [values, setValues] = useState<Record<Slot, string>>({ primary: "", accent: "" });
  const [open, setOpen] = useState<Slot | null>(null);

  // Seed the working values from the live manifest (override-or-default).
  const seedFromManifest = useCallback((data: Manifest) => {
    const byName = new Map(data.tokens.map((t) => [t.name, t]));
    setValues({
      primary: byName.get(SLOT_TOKEN.primary)?.current ?? "",
      accent: byName.get(SLOT_TOKEN.accent)?.current ?? "",
    });
  }, []);

  useEffect(() => {
    let live = true;
    fetch(manifestUrl, { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: Manifest) => {
        if (!live) return;
        setManifest(data);
        seedFromManifest(data);
      })
      .catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, [manifestUrl, seedFromManifest]);

  // Resolve a raw value to a literal colour by walking var() chains through the
  // Tailwind swatches — what lets a swatch render its TRUE colour in the chat,
  // where the --color-* base vars don't exist.
  const resolve = useMemo(() => {
    const swatch = new Map<string, string>();
    for (const g of manifest?.palette ?? []) for (const s of g.swatches) swatch.set(s.css_var, s.value);
    const walk = (value: string, depth = 0): string => {
      if (depth > 16 || !value) return value;
      const ref = refTarget(value);
      if (!ref) return value;
      return swatch.has(ref) ? swatch.get(ref)! : value;
    };
    return walk;
  }, [manifest]);

  // Token name → css-var, from the manifest. brand-state keys overrides by
  // css-var ("--<cssVar>: value" on the preview iframe), but the backend talks
  // token names, so a pick maps name → css-var to stage the right preview key.
  const cssVarByName = useMemo(
    () => new Map((manifest?.tokens ?? []).map((t) => [t.name, t.css_var])),
    [manifest],
  );

  /** Stage one token (by name) into the preview draft, if it's a known token. */
  const stageToken = (name: string, value: string) => {
    const cssVar = cssVarByName.get(name);
    if (cssVar) setBrandOverride(cssVar, value);
  };

  // Picking no longer writes to the live site — it stages a preview-only draft
  // and opens the studio, where the user reviews it and clicks Publish. So a
  // stray swatch click can't reskin the running site (the governance win).
  const pickSwatch = (slot: Slot, cssVar: string) => {
    if (historical) return;
    const value = `var(--${cssVar})`;
    setValues((v) => ({ ...v, [slot]: value }));
    stageToken(SLOT_TOKEN[slot], value);
    setOpen(null);
    ensureStudio("design_system");
    consoleNav.adoptRoom({ kind: "studio", studio: "design_system" });
  };

  const applyPreset = (p: PresetSummary) => {
    if (historical) return;
    // Stage the preset's whole token map so the studio shows + can publish all
    // of it. Fonts can't be previewed as a CSS var, so they ride along as a
    // pending list and apply on Publish.
    const tokens = p.tokens ?? { [SLOT_TOKEN.primary]: p.primary, [SLOT_TOKEN.accent]: p.accent };
    for (const [name, value] of Object.entries(tokens)) stageToken(name, value);
    setPendingFonts(p.fonts ?? null);
    setValues((v) => ({
      ...v,
      primary: tokens[SLOT_TOKEN.primary] ?? v.primary,
      accent: tokens[SLOT_TOKEN.accent] ?? v.accent,
    }));
    ensureStudio("design_system");
    consoleNav.adoptRoom({ kind: "studio", studio: "design_system" });
  };

  if (error && !manifest) {
    return <div className="ain-brandpick ain-brandpick--error">Couldn’t load brand options: {error}</div>;
  }

  return (
    <div className="ain-brandpick">
      <div className="ain-brandpick__head">
        <span className="ain-brandpick__title">Quick brand</span>
        <span className="ain-brandpick__hint">
          {historical ? "From earlier in this conversation" : "Previews in the studio — Publish to apply"}
        </span>
      </div>

      <div className="ain-brandpick__colors">
        {(["primary", "accent"] as Slot[]).map((slot) => (
          <div className="ain-brandpick__color" key={slot}>
            <button
              type="button"
              className="ain-btn ain-brandpick__swatch"
              style={{ background: resolve(values[slot]) || "var(--ain-surface-2)" }}
              onClick={() => setOpen((o) => (o === slot ? null : slot))}
              aria-label={`Choose ${slot} colour`}
              aria-expanded={open === slot}
              disabled={historical}
            />
            <span className="ain-brandpick__colorlabel">{slot === "primary" ? "Primary" : "Accent"}</span>
            {open === slot && manifest && (
              <SwatchPopover
                palette={manifest.palette}
                onPick={(cssVar) => pickSwatch(slot, cssVar)}
                onClose={() => setOpen(null)}
              />
            )}
          </div>
        ))}
      </div>

      {presets.length > 0 && (
        <div className="ain-brandpick__presets">
          <span className="ain-brandpick__presetlabel">Or start from a preset</span>
          <div className="ain-brandpick__presetrow">
            {presets.map((p) => (
              <button
                key={p.id}
                type="button"
                className="ain-btn ain-brandpick__preset"
                onClick={() => applyPreset(p)}
                title={p.blurb}
                disabled={historical}
              >
                <span className="ain-brandpick__presetswatches">
                  <span style={{ background: p.primary }} />
                  <span style={{ background: p.accent }} />
                </span>
                {p.label}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

/** A compact Tailwind swatch grid, grouped by hue, dismissed on outside press. */
function SwatchPopover({
  palette,
  onPick,
  onClose,
}: {
  palette: PaletteGroup[];
  onPick: (cssVar: string) => void;
  onClose: () => void;
}) {
  const wrapRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    const onDown = (e: PointerEvent) => {
      if (!wrapRef.current?.contains(e.target as Node)) onClose();
    };
    window.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onDown, true);
    return () => {
      window.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onDown, true);
    };
  }, [onClose]);

  return (
    <div className="ain-brandpick__pop" role="dialog" aria-label="Colour swatches" ref={wrapRef}>
      <div className="ain-brandpick__pophead">
        <span>Pick a colour</span>
        <button type="button" className="ain-btn ain-brandpick__popclose" onClick={onClose} aria-label="Close">
          <XIcon />
        </button>
      </div>
      <div className="ain-brandpick__grid">
        {palette.map((group) => (
          <div className="ain-brandpick__hue" key={group.hue}>
            {group.swatches.map((s) => (
              <button
                key={s.css_var}
                type="button"
                className="ain-btn ain-brandpick__cell"
                style={{ background: s.value }}
                onClick={() => onPick(s.css_var)}
                title={`${group.label} ${s.step}`}
                aria-label={`${group.label} ${s.step}`}
              />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * Registers the quick brand picker for the `brand_picker` tool. Mount once
 * inside the AssistantRuntimeProvider; `args` is the payload the dispatcher
 * passed through as the tool call's arguments.
 */
export const BrandPickerToolUI = makeSafeAssistantToolUI<BrandPickerPayload, unknown>({
  toolName: "brand_picker",
  render: ({ args }) => <BrandPicker {...args} />,
});
