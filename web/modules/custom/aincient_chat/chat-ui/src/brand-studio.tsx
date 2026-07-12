import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import type { CSSProperties, ReactNode, RefObject } from "react";
import { createPortal } from "react-dom";
import { useAssistantRuntime } from "@assistant-ui/react";
import { rememberThreadSeal } from "./thread-seal";
import { sealThread } from "./adapter";
import {
  setBrandOverride,
  resetBrandOverrides,
  getBrandOverrides,
  subscribeBrandOverrides,
  setPendingFonts,
  reloadPreview,
} from "./brand-state";
import { CheckIcon, XIcon, ChevronDownIcon, LockIcon, LockOpenIcon } from "./icons";
import { emitBrandStatus, subscribeBrandStatus } from "./brand-status-state";
import { StudioActionsPortal, useStudioUI } from "./studio-ui";
import { PanelBar } from "./panel-bar";
import { FieldRevert } from "./field-revert";

/** One token, as returned by /aincient/brand/manifest. */
type TokenDef = {
  name: string;
  css_var: string;
  type: string;
  tier: string;
  category: string;
  component: string | null;
  /** For a surface colour, the token name of its paired on-colour (else null). */
  on: string | null;
  label: string;
  description: string;
  enum: string[] | null;
  /** Human-facing names for `enum`, parallel by index (drives the select). */
  enum_labels: string[] | null;
  default: string;
  current: string;
  /** Tracked + published but never rendered as a control (driven by a preset). */
  internal?: boolean;
  /** Belongs under an Advanced fold rather than the card's lead controls. */
  advanced?: boolean;
  /** A colour token that may take a raw tint below the palette tier (e.g. the
   *  shadow colour) — unlocks the raw hex/OKLCH picker for a semantic colour. */
  raw_color?: boolean;
};

/** A Tier 0 Tailwind swatch + its hue group (the immutable base palette). */
type Swatch = { step: string; css_var: string; value: string };
type PaletteGroup = { hue: string; label: string; swatches: Swatch[] };

/** One curated display+body font pairing offered as a one-click typography preset. */
type FontPairing = {
  id: string;
  label: string;
  blurb: string;
  /** CSS font-family stack for the display token (leading web font + fallbacks). */
  display: string;
  /** CSS font-family stack for the base/body token. */
  base: string;
  /** Google family names to load (the non-generic primaries of each stack). */
  fonts: string[];
};

/** One option in a preset group — a named bundle that writes several tokens. */
type PresetOption = {
  id: string;
  label: string;
  blurb: string;
  /** Token NAME → CSS value this option writes (keyed by name, not css_var). */
  tokens: Record<string, string>;
  /** Web font families this option stages (only the pairing group; else []). */
  fonts: string[];
};

/** A preset group — the high-level dial the rail leads with (pairing, roundness…). */
type PresetGroup = {
  id: string;
  label: string;
  description: string;
  options: PresetOption[];
};

type Manifest = {
  tokens: TokenDef[];
  palette: PaletteGroup[];
  /** Which tiers each tier may reference (down only); "tailwind" = Tier 0. */
  referable: Record<string, string[]>;
  /** Curated popular font pairings for the typography quick-picker. */
  font_pairings: FontPairing[];
  /** High-level preset groups (font pairing, roundness, depth, …) the rail
   *  leads with — each option expands to a coherent token bundle. */
  presets: PresetGroup[];
  /** The currently-saved web fonts (the baseline for font-dirty detection). */
  fonts: string[];
  /** The saved design-intent status the studio control + badge seed from. */
  status: BrandStatus;
};

/** The durable, shared design-intent status both the studio and the brand agent
 *  read (distinct from the editor write-lock). `stage` widens the agent's
 *  freedom; `locked` pins the brand to minimal, single-axis changes. Persisted
 *  IMMEDIATELY via POST /aincient/brand/status — it does NOT ride Publish. */
type BrandStatus = { stage: string; locked: boolean };

/** The three stages in rail order, each with the plain-language intent it sets
 *  (mirrors BrandRepository::STAGES + the agent's status directive). */
const STAGE_ORDER = ["ideating", "guided", "polish"] as const;
const STAGE_META: Record<string, { label: string; blurb: string }> = {
  ideating: { label: "Ideating", blurb: "Explore freely — the agent may reshape the whole brand." },
  guided: { label: "Guided", blurb: "Honour the inputs you supply; don’t invent new directions." },
  polish: { label: "Polish", blurb: "Minimal, surgical changes — no cross-axis drift." },
};

const STATUS_URL = "/aincient/brand/status";

/** One prefilled font offered next to a font-family input. */
type FontOption = { family: string; stack: string; blurb: string };

/** The two palette font-family tokens a pairing stages together. */
const FONT_DISPLAY_TOKEN = "font_family_display";
const FONT_BASE_TOKEN = "font_family_base";

/** Generic CSS font keywords that are never loaded as web fonts. */
const GENERIC_FAMILIES = new Set([
  "serif", "sans-serif", "monospace", "cursive", "fantasy", "system-ui",
  "ui-sans-serif", "ui-serif", "ui-monospace", "ui-rounded", "math", "emoji",
  "fangsong", "-apple-system", "blinkmacsystemfont",
]);

/** The leading web font of a font-family stack, or null if it's a system stack.
 *  Mirrors BrandRepository::isFontName so the staged name is a legal write. */
function firstFamily(stack: string): string | null {
  const first = (stack ?? "").split(",")[0]?.trim() ?? "";
  const name = first.replace(/^["']|["']$/g, "").trim();
  if (!name || GENERIC_FAMILIES.has(name.toLowerCase())) return null;
  return /^[A-Za-z0-9 ]{1,50}$/.test(name) ? name : null;
}

/** Order-independent equality of two web-font lists. */
function sameFonts(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false;
  const sb = [...b].sort();
  return [...a].sort().every((x, i) => x === sb[i]);
}

/**
 * The four INTENT cards the rail is organised by — what the user wants to
 * change, not the palette/semantic/component cascade. A token's card is derived
 * by {@see cardOf}: components by tier, then colour/typography by category, the
 * rest (radius/shadow/space/border) to "shape". Each card leads with high-level
 * preset chips + key controls; the raw per-token knobs tuck under an Advanced
 * fold inside the card. Colour & Typography lead open; Shape & Components start
 * collapsed (most brands rarely touch them).
 */
type CardId = "color" | "typography" | "shape" | "components";

const CARD_ORDER: CardId[] = ["color", "typography", "shape", "components"];

const CARD_META: Record<CardId, { label: string; sublabel: string; icon: string; open: boolean }> = {
  color: { label: "Color", sublabel: "Brand, accent & page surfaces", icon: "◑", open: true },
  typography: { label: "Typography", sublabel: "Pick a pairing, then tune", icon: "Aa", open: true },
  shape: { label: "Shape & depth", sublabel: "Corners, shadows, density", icon: "▢", open: false },
  components: { label: "Components", sublabel: "Override per element", icon: "⊞", open: false },
};

/** Which intent card a token belongs to. */
function cardOf(t: TokenDef): CardId {
  if (t.tier === "component") return "components";
  if (t.category === "color") return "color";
  if (t.category === "typography") return "typography";
  return "shape";
}

/** The Colour card's lead controls — the few brand decisions that matter, each
 *  a surface + an on/ink colour rendered as one contrast-checked row. The rest
 *  of the colour tokens drop under the card's Advanced fold. */
const COLOR_LEAD_PAIRS: { surface: string; on: string; label: string }[] = [
  { surface: "brand_primary", on: "brand_primary_foreground", label: "Brand color" },
  { surface: "brand_accent", on: "brand_accent_foreground", label: "Accent" },
  { surface: "neutral_surface", on: "neutral_ink", label: "Page background & ink" },
];

/** A component key as a sub-heading: "site-header" → "Site header". */
function componentLabel(key: string | null): string {
  if (!key) return "Other";
  const spaced = key.replace(/[-_]/g, " ");
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/** Partition component-tier tokens by their `component`, first-seen order kept. */
function byComponent(items: TokenDef[]): { component: string | null; items: TokenDef[] }[] {
  const order: (string | null)[] = [];
  const map = new Map<string | null, TokenDef[]>();
  for (const t of items) {
    if (!map.has(t.component)) {
      map.set(t.component, []);
      order.push(t.component);
    }
    map.get(t.component)!.push(t);
  }
  return order.map((component) => ({ component, items: map.get(component)! }));
}

/** Match `var(--name)` (ignoring any fallback) and return the referenced name. */
function refTarget(value: string): string | null {
  const m = /^var\(\s*--([a-z0-9-]+)\s*(?:,[^)]*)?\)$/i.exec(value.trim());
  return m ? m[1] : null;
}

/** WCAG AA for normal body text — the threshold a surface/on pair must clear. */
const AA_NORMAL = 4.5;

const srgbToLinear = (c: number): number =>
  c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;

/** OKLCH → linear sRGB (Björn Ottosson's matrices), clamped to 0..1. */
function oklchToLinear(L: number, C: number, h: number): [number, number, number] {
  const hr = (h * Math.PI) / 180;
  const a = C * Math.cos(hr);
  const b = C * Math.sin(hr);
  const l_ = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
  const m_ = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
  const s_ = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
  const clamp = (x: number) => Math.max(0, Math.min(1, x));
  return [
    clamp(4.0767416621 * l_ - 3.3077115913 * m_ + 0.2309699292 * s_),
    clamp(-1.2684380046 * l_ + 2.6097574011 * m_ - 0.3413193965 * s_),
    clamp(-0.0041960863 * l_ - 0.7034186147 * m_ + 1.707614701 * s_),
  ];
}

/** Parse a concrete colour literal (#hex, rgb(), oklch()) to linear sRGB, or null.
 *  Mirrors src/ColorContrast.php so the studio badge matches the server check. */
function colorToLinear(value: string): [number, number, number] | null {
  const v = value.trim();
  let m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
  if (m) {
    let hex = m[1];
    if (hex.length === 3) hex = hex.split("").map((c) => c + c).join("");
    return [0, 2, 4].map((i) => srgbToLinear(parseInt(hex.slice(i, i + 2), 16) / 255)) as [number, number, number];
  }
  m = /^rgba?\(([^)]+)\)$/i.exec(v);
  if (m) {
    const parts = m[1].trim().split(/[\s,/]+/).slice(0, 3);
    if (parts.length < 3) return null;
    const chan = (p: string) => {
      const n = parseFloat(p);
      return Number.isNaN(n) ? null : p.endsWith("%") ? n / 100 : n / 255;
    };
    const rgb = parts.map(chan);
    return rgb.some((c) => c === null) ? null : (rgb.map((c) => srgbToLinear(c as number)) as [number, number, number]);
  }
  m = /^oklch\(([^)]+)\)$/i.exec(v);
  if (m) {
    const parts = m[1].replace(/\/.*$/, "").trim().split(/[\s,]+/).slice(0, 3);
    if (parts.length < 3) return null;
    const L = parts[0].endsWith("%") ? parseFloat(parts[0]) / 100 : parseFloat(parts[0]);
    const C = parseFloat(parts[1]);
    const h = parseFloat(parts[2]);
    if ([L, C, h].some((n) => Number.isNaN(n))) return null;
    return oklchToLinear(L, C, h);
  }
  return null;
}

const luminance = ([r, g, b]: [number, number, number]): number =>
  0.2126 * r + 0.7152 * g + 0.0722 * b;

/** WCAG contrast ratio between two colour LITERALS, or null if either won't parse. */
function contrastRatio(aLit: string, bLit: string): number | null {
  const a = colorToLinear(aLit);
  const b = colorToLinear(bLit);
  if (!a || !b) return null;
  const la = luminance(a);
  const lb = luminance(b);
  const hi = Math.max(la, lb);
  const lo = Math.min(la, lb);
  return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
}

/** The WCAG conformance a ratio earns for normal body text — the plain-language
 *  verdict shown beside the pair preview (so "8.9:1" reads as "AAA", not a number
 *  the user has to grade themselves). 3:1 only clears AA for *large* text. */
function wcagLevel(ratio: number): { tag: string; passes: boolean } {
  if (ratio >= 7) return { tag: "AAA", passes: true };
  if (ratio >= AA_NORMAL) return { tag: "AA", passes: true };
  if (ratio >= 3) return { tag: "Large", passes: false };
  return { tag: "Fail", passes: false };
}

/** Render the save endpoint's `rejected` token-name list as readable labels. */
function labelList(rejected: unknown, tokens: TokenDef[] | null): string {
  if (!Array.isArray(rejected) || rejected.length === 0) return "";
  const byName = new Map((tokens ?? []).map((t) => [t.name, t.label]));
  return rejected.map((n) => byName.get(String(n)) ?? String(n)).join(", ");
}

/**
 * The brand editor rail: fetches the token manifest and renders one control per
 * token, grouped by tier. Every control is a *reference picker* scoped to the
 * tokens its tier may legally target (semantic → palette, component → palette +
 * semantic, palette → the Tailwind base). Editing pushes the value to the shared
 * store so the preview iframe reskins live.
 *
 * Everything here is a DRAFT: edits (and anything the quick picker staged before
 * opening the studio) only repaint the preview. The single deliberate global
 * write is Publish, which POSTs the changed tokens (+ any pending web fonts) to
 * /aincient/brand/save — the same persist path the no-AI form uses, so it
 * records an attributed, reversible revision. It is the ONLY way brand changes
 * reach the live site: the agent only ever previews (there is no server-side
 * "set brand" tool). Discard drops the draft and reverts to the saved brand.
 */
const SAVE_URL = "/aincient/brand/save";

export function BrandStudio({ onClose }: { onClose: () => void }) {
  const { closeSheets } = useStudioUI();
  // Read only to resolve the active thread's backend id when a Publish seals the
  // conversation (auto-archives it; see the publish handler).
  const runtime = useAssistantRuntime();
  const [manifest, setManifest] = useState<Manifest | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Working value per css-var (the draft). Seeded from the saved baseline with
  // any picker-staged overrides layered on, so staged picks are publishable.
  const [values, setValues] = useState<Record<string, string>>({});
  // The saved value per css-var — what the draft is diffed against for dirty.
  const [baseline, setBaseline] = useState<Record<string, string>>({});
  const [publishing, setPublishing] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  // The saved design-intent status (stage + lock). Seeded from the manifest and
  // written IMMEDIATELY on toggle (out-of-band from the token draft/Publish
  // cycle) so the persisted config stays the single source of truth the agent
  // reads next turn. `statusSaving` gates the control while a write is in flight.
  const [status, setStatus] = useState<BrandStatus>({ stage: "ideating", locked: false });
  const [statusSaving, setStatusSaving] = useState(false);
  // Cards the user has collapsed. Seeded from CARD_META.open so Shape &
  // Components start folded (most brands rarely touch them); tracked by card id.
  const [collapsed, setCollapsed] = useState<Set<string>>(
    () => new Set(CARD_ORDER.filter((id) => !CARD_META[id].open)),
  );
  const toggleCard = useCallback(
    (id: string) =>
      setCollapsed((prev) => {
        const next = new Set(prev);
        next.has(id) ? next.delete(id) : next.add(id);
        return next;
      }),
    [],
  );

  // Fetch the manifest and reset the baseline to its saved values. The working
  // draft is the baseline with the current preview overrides layered on (so a
  // colour the quick picker staged before the studio opened shows up here too).
  const load = useCallback(() => {
    return fetch("/aincient/brand/manifest", { credentials: "same-origin" })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: Manifest) => {
        const saved = Object.fromEntries(data.tokens.map((t) => [t.css_var, t.current]));
        setManifest(data);
        setBaseline(saved);
        setValues({ ...saved, ...getBrandOverrides() });
        // Status persists out-of-band from tokens, so re-seed it from the saved
        // manifest on every (re)load — it's always the live value. Broadcast so
        // the agent's proposal card (a separate tree) shares the baseline.
        if (data.status) {
          setStatus(data.status);
          emitBrandStatus(data.status);
        }
        return saved;
      });
  }, []);

  useEffect(() => {
    let live = true;
    load().catch((e) => live && setError(String(e)));
    return () => {
      live = false;
    };
  }, [load]);

  // A pick made in the quick picker while the studio is open arrives as an
  // override; fold it into the draft so the rail + dirty count track it.
  useEffect(
    () => subscribeBrandOverrides((ov) => setValues((v) => ({ ...v, ...ov }))),
    [],
  );

  // Adopt a status change applied elsewhere (the agent's HITL proposal card) so
  // the rail control + badge reflect it live without a reload.
  useEffect(() => subscribeBrandStatus((s) => setStatus(s)), []);

  const tokens = manifest?.tokens ?? null;
  const palette = manifest?.palette ?? [];
  const referable = manifest?.referable ?? {};

  // The draft diff: tokens whose working value differs from the saved baseline.
  const changed = useMemo(
    () => (tokens ?? []).filter((t) => (values[t.css_var] ?? "") !== (baseline[t.css_var] ?? "")),
    [tokens, values, baseline],
  );
  // The two palette font-family tokens (by css_var) a pairing drives, if present.
  const fontTokens = useMemo(() => {
    const cssVar = (name: string) => (tokens ?? []).find((t) => t.name === name)?.css_var ?? null;
    const display = cssVar(FONT_DISPLAY_TOKEN);
    const base = cssVar(FONT_BASE_TOKEN);
    return display && base ? { display, base } : null;
  }, [tokens]);

  // A flat, deduped list of prefilled fonts derived from the curated pairings:
  // every display + body stack becomes a one-click option offered next to the
  // font-family inputs (so a user picks a known-good font instead of typing a
  // stack by hand). Custom stacks stay typeable in the input alongside it.
  const fontOptions = useMemo<FontOption[]>(() => {
    const seen = new Map<string, FontOption>();
    for (const p of manifest?.font_pairings ?? []) {
      for (const stack of [p.display, p.base]) {
        if (!seen.has(stack)) {
          seen.set(stack, { family: firstFamily(stack) ?? stack, stack, blurb: p.blurb });
        }
      }
    }
    return [...seen.values()].sort((a, b) => a.family.localeCompare(b.family));
  }, [manifest]);

  // The web fonts the current draft wants loaded: the leading family of each
  // font-family stack (custom typed fonts ride this path too, not just presets).
  const desiredFonts = useMemo(() => {
    if (!fontTokens) return [] as string[];
    const fams = [values[fontTokens.display], values[fontTokens.base]]
      .map((v) => firstFamily(v ?? ""))
      .filter((f): f is string => f !== null);
    return [...new Set(fams)];
  }, [fontTokens, values]);

  // Fonts are dirty only when a font-family token actually changed AND the
  // resulting web fonts differ from the saved baseline (so a baseline mismatch
  // can't show a phantom unsaved change on open).
  const fontTokenChanged = changed.some((t) => t.name === FONT_DISPLAY_TOKEN || t.name === FONT_BASE_TOKEN);
  const fontsDirty = fontTokenChanged && !sameFonts(desiredFonts, manifest?.fonts ?? []);
  const dirty = changed.length;
  // The css-vars whose draft value differs from the saved baseline — drives the
  // per-field "changed" markers + revert affordances (the aggregate `changed`
  // array surfaced down to each control).
  const dirtyVars = useMemo(() => new Set(changed.map((t) => t.css_var)), [changed]);

  // Push the draft's desired web fonts into the shared store so the preview
  // iframe loads the real typeface live; clear them when fonts match the saved
  // brand (or on discard) so no stray stylesheet lingers.
  useEffect(() => {
    if (!manifest) return;
    setPendingFonts(fontsDirty ? desiredFonts : null);
  }, [manifest, fontsDirty, desiredFonts]);

  const publish = useCallback(async () => {
    setPublishing(true);
    setError(null);
    setNotice(null);
    try {
      const body = {
        tokens: Object.fromEntries(
          changed.map((t) => [t.name, values[t.css_var] ?? ""]),
        ),
        ...(fontsDirty ? { fonts: desiredFonts } : {}),
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const result = await res.json().catch(() => null);
      if (!res.ok) {
        // 422 = every change was rejected (nothing valid to publish). Name the
        // offending tokens rather than a bare status code.
        const bad = labelList(result?.rejected, tokens);
        throw new Error(bad ? `invalid values for ${bad}` : (result?.error ?? `HTTP ${res.status}`));
      }
      // Valid tokens (and fonts) are now saved → that becomes the new baseline.
      // Clear pending fonts and refetch the saved values.
      setPendingFonts(null);
      const saved = await load();
      // Drop every preview override that now equals the saved brand (it just
      // published) so the draft — and the per-turn context the brand agent
      // receives — no longer reports a published value as an unsaved change
      // (the stale-draft loop). Any token the server REJECTED still differs from
      // saved, so its override is kept and stays dirty rather than vanishing.
      for (const [cssVar, value] of Object.entries(getBrandOverrides())) {
        if ((saved[cssVar] ?? "") === value) setBrandOverride(cssVar, "");
      }
      // Reload the preview iframe so it re-pulls the freshly-saved brand from
      // the server instead of showing the old saved brand under a stale overlay.
      reloadPreview();
      const rejected = labelList(result?.rejected, tokens);
      // Advisory: the server returns any saved surface/on pair below AA. We
      // publish regardless (pairing is enforced by name, not blocked), but flag
      // it so the user knows a pairing reads poorly.
      const lowContrast = Array.isArray(result?.contrast_warnings) ? result.contrast_warnings.length : 0;
      const contrastNote = lowContrast
        ? ` · ⚠ ${lowContrast} low-contrast pair${lowContrast === 1 ? "" : "s"}`
        : "";
      if (rejected) {
        // Partial publish — the rest is live, but say what was refused (and why
        // it's still dirty) instead of silently dropping it.
        setError(`Published the rest — couldn’t apply ${rejected} (invalid value). Fix or discard.`);
      } else {
        setNotice(`Brand published${contrastNote}`);
        // A clean, site-wide brand publish is the conversation's "done" beat
        // (DECISIONS 0093), so we SEAL the thread right away — read-only +
        // auto-archived (server lock() flips both flags). This stops the finished
        // thread from taking more turns and, crucially, from being re-opened live
        // via its ?thr= deep link. Sealing in place (not consoleNav.seal) keeps
        // the thread active but read-only: the flip is picked up by the composer's
        // seal store, which swaps in the celebration end-state ("Start a new
        // thread"); there's no single page to view (site-wide), so no View link.
        // Skipped on a partial publish (rejected tokens) — we ask the user to fix
        // or discard first.
        const sealedId = runtime.threads.mainItem.getState().remoteId;
        if (sealedId) {
          try {
            await sealThread(sealedId, true);
            rememberThreadSeal(sealedId, true);
          } catch {
            // Publish already landed; a failed seal just leaves the thread live.
            // Not worth surfacing as a publish error.
          }
        }
      }
    } catch (e) {
      setError(`Couldn’t publish: ${e instanceof Error ? e.message : e}`);
    } finally {
      setPublishing(false);
    }
  }, [changed, values, fontsDirty, desiredFonts, load, tokens, runtime]);

  // Resolve any token value to a literal (oklch/hex/…) by walking var() chains
  // through the working values + the Tier 0 palette. This is what lets swatches
  // render their TRUE colour here in the console, where the --color-* base vars
  // and the brand :root block don't exist — no more grey oklch fallbacks.
  const resolve = useMemo(() => {
    const byVar = new Map<string, TokenDef>();
    for (const t of tokens ?? []) byVar.set(t.css_var, t);
    const swatch = new Map<string, string>();
    for (const g of palette) for (const s of g.swatches) swatch.set(s.css_var, s.value);
    const walk = (value: string, depth = 0): string => {
      if (depth > 16 || !value) return value;
      const ref = refTarget(value);
      if (!ref) return value;
      if (swatch.has(ref)) return swatch.get(ref)!;
      const t = byVar.get(ref);
      if (t) return walk(values[t.css_var] ?? t.current, depth + 1);
      return value;
    };
    return walk;
  }, [tokens, palette, values]);

  const change = useCallback((cssVar: string, value: string) => {
    setValues((v) => ({ ...v, [cssVar]: value }));
    setBrandOverride(cssVar, value);
    setNotice(null);
    // A fresh edit clears any stale reject/publish message so it doesn't linger.
    setError(null);
  }, []);

  // Revert ONE field to the saved brand: snap its working value back to the
  // baseline and drop its preview override (clearing the override lets the
  // preview fall back to the saved value — exactly what discard does, scoped to
  // a single token). The fonts effect re-derives from `values`, so reverting a
  // font-family token clears any now-unneeded pending web font automatically.
  const revert = useCallback((cssVar: string) => {
    setValues((v) => ({ ...v, [cssVar]: baseline[cssVar] ?? "" }));
    setBrandOverride(cssVar, "");
    setNotice(null);
    setError(null);
  }, [baseline]);

  // Drop the whole draft: clear preview overrides + pending fonts and snap the
  // working values back to the saved baseline.
  const discard = () => {
    resetBrandOverrides();
    setValues({ ...baseline });
    setNotice(null);
    setError(null);
  };

  // Toggle the design-intent status. Writes IMMEDIATELY (a MODE, not draft
  // content — never rides Publish); optimistic with a revert on failure. A
  // partial POST ({stage} or {locked}) keeps the untouched field server-side.
  const updateStatus = useCallback(
    async (patch: Partial<BrandStatus>) => {
      const prev = status;
      setStatus((s) => ({ ...s, ...patch }));
      setStatusSaving(true);
      setError(null);
      try {
        const res = await fetch(STATUS_URL, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(patch),
        });
        const result = await res.json().catch(() => null);
        if (!res.ok) throw new Error(result?.error ?? `HTTP ${res.status}`);
        // Trust the server's echoed status (it coerces an unknown stage), and
        // broadcast so a proposal card in the transcript reflects the change.
        if (result?.status) {
          setStatus(result.status);
          emitBrandStatus(result.status);
        }
      } catch (e) {
        setStatus(prev);
        setError(`Couldn’t update brand status: ${e instanceof Error ? e.message : e}`);
      } finally {
        setStatusSaving(false);
      }
    },
    [status],
  );

  // Every token bucketed into its intent card (color / typography / shape /
  // components) — the rail's top-level structure, replacing the old tier groups.
  const cardTokens = useMemo(() => {
    const by: Record<CardId, TokenDef[]> = { color: [], typography: [], shape: [], components: [] };
    // `internal` tokens (e.g. the shadow direction multipliers) are still tracked
    // and published via the full `tokens` list, but never get a standalone
    // control — they are driven by a preset (the direction chips).
    for (const t of tokens ?? []) if (!t.internal) by[cardOf(t)].push(t);
    return by;
  }, [tokens]);

  // token name → css_var (paired on-colour lookups) and → TokenDef (lead pairs).
  const nameToVar = useMemo(
    () => new Map((tokens ?? []).map((t) => [t.name, t.css_var])),
    [tokens],
  );
  const tokenByName = useMemo(
    () => new Map((tokens ?? []).map((t) => [t.name, t])),
    [tokens],
  );

  // The preset groups, by id, for the chips/gallery the cards lead with.
  const presetGroups = manifest?.presets ?? [];
  const groupById = useCallback(
    (id: string) => presetGroups.find((g) => g.id === id),
    [presetGroups],
  );

  // Which option in a group is active = the one whose every token write matches
  // the current draft (else null → "Custom", no chip lit).
  const activeOption = useCallback(
    (group: PresetGroup | undefined): string | null => {
      if (!group) return null;
      for (const o of group.options) {
        const hit = Object.entries(o.tokens).every(
          ([name, v]) => (values[nameToVar.get(name) ?? name] ?? "") === v,
        );
        if (hit) return o.id;
      }
      return null;
    },
    [values, nameToVar],
  );

  // Apply a preset: write each of its tokens through the same draft path a
  // manual edit uses (the fonts effect picks up any new families automatically).
  const applyOption = useCallback(
    (o: PresetOption) => {
      for (const [name, v] of Object.entries(o.tokens)) change(nameToVar.get(name) ?? name, v);
    },
    [change, nameToVar],
  );

  // Load every pairing's web fonts into the CONSOLE document (once), so the
  // font-pairing gallery previews each option in its real typeface rather than a
  // system fallback. Mirrors the preview iframe's font link; same-origin CSP
  // already allows Google Fonts (styles.css imports them).
  useEffect(() => {
    const pairing = manifest?.presets?.find((g) => g.id === "pairing");
    if (!pairing) return;
    const families = [...new Set(pairing.options.flatMap((o) => o.fonts))];
    if (families.length === 0) return;
    const href = "https://fonts.googleapis.com/css2?"
      + families.map((f) => `family=${f.replace(/ /g, "+")}:wght@400;600;700`).join("&")
      + "&display=swap";
    let link = document.head.querySelector<HTMLLinkElement>('link[data-ain-pairing-fonts]');
    if (!link) {
      link = document.createElement("link");
      link.rel = "stylesheet";
      link.setAttribute("data-ain-pairing-fonts", "");
      document.head.appendChild(link);
    }
    if (link.href !== href) link.href = href;
  }, [manifest]);

  // Live WCAG contrast for a surface token against its declared on-colour,
  // resolving both var() chains to literals first (so it tracks the draft and
  // matches the server's ColorContrast). null when a value can't be parsed.
  const pairContrast = useCallback(
    (t: TokenDef): { ratio: number; passes: boolean } | null => {
      if (!t.on) return null;
      const onVar = nameToVar.get(t.on);
      if (!onVar) return null;
      const ratio = contrastRatio(resolve(values[t.css_var] ?? ""), resolve(values[onVar] ?? ""));
      return ratio === null ? null : { ratio, passes: ratio >= AA_NORMAL };
    },
    [nameToVar, resolve, values],
  );

  return (
    <div className="ain-studio__rail">
      {/* Primary actions live in the top bar (so they survive the rail
          collapsing to a sheet on narrow screens). The rail head keeps the
          title and a sheet-dismiss ✕ that's only shown when the rail is a
          sheet — CSS gates it; on desktop the rail is always in view. */}
      <StudioActionsPortal>
        {dirty > 0 && <span className="ain-studio-actions__dirty" title={`${dirty} unsaved change${dirty === 1 ? "" : "s"}`}>{dirty}</span>}
        <button
          className="ain-btn ain-topbtn"
          onClick={discard}
          disabled={dirty === 0 || publishing}
          title="Discard draft — revert the preview to the saved brand"
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
        <button className="ain-btn ain-iconbtn ain-topbar__leave" onClick={onClose} aria-label="Close brand studio" title="Leave brand studio">
          <XIcon />
        </button>
      </StudioActionsPortal>
      <PanelBar
        title="Brand studio"
        actions={
          <button className="ain-btn ain-iconbtn ain-studio__sheetclose" onClick={closeSheets} aria-label="Hide editor" title="Hide editor">
            <XIcon />
          </button>
        }
      />

      {/* Design-intent status (stage + lock) — persists immediately, out-of-band
          from the token draft. Sets how much freedom the brand agent has. */}
      {manifest && <StatusControl status={status} saving={statusSaving} onChange={updateStatus} />}

      {/* The draft state: nothing is live until Publish. */}
      <p className="ain-studio__status" data-dirty={dirty > 0 || undefined}>
        {dirty > 0 ? (
          <>
            {dirty} unsaved change{dirty === 1 ? "" : "s"} · preview only
            {fontsDirty && <> · fonts apply on publish</>}
            {/* fonts now render in the preview too; they still only persist on publish */}
          </>
        ) : notice ? (
          <><CheckIcon /> {notice}</>
        ) : (
          <>Matches the saved brand</>
        )}
      </p>

      {error && <p className="ain-studio__error">{error}</p>}
      {!manifest && !error && <p className="ain-studio__hint">Loading tokens…</p>}

      <div className="ain-studio__groups">
        {(() => {
          // Render props every control shares.
          const shared: SharedProps = { tokens: tokens ?? [], palette, referable, resolve, fontOptions, onChange: change, dirtyVars, onRevert: revert };

          // A flat list of token controls: a surface + its declared on-colour fold
          // into one paired row; everything else is a lone field. `components`
          // sub-groups the list per component (Button, Card, …).
          const renderTokens = (items: TokenDef[], opts: { components?: boolean } = {}) => {
            const onNames = new Set(items.filter((t) => t.on).map((t) => t.on));
            const visible = items.filter((t) => !onNames.has(t.name));
            const one = (t: TokenDef) => {
              const onTok = t.category === "color" && t.on ? items.find((x) => x.name === t.on) : undefined;
              if (onTok) {
                return (
                  <PairRow
                    key={t.name}
                    surface={t}
                    on={onTok}
                    surfaceValue={values[t.css_var] ?? ""}
                    onValue={values[onTok.css_var] ?? ""}
                    pair={pairContrast(t)}
                    shared={shared}
                  />
                );
              }
              return (
                <div className="ain-studio__tokenwrap" key={t.name}>
                  <TokenControl token={t} value={values[t.css_var] ?? ""} {...shared} />
                </div>
              );
            };
            if (opts.components) {
              return byComponent(visible).map(({ component, items: subItems }) => (
                <div className="ain-studio__subgroup" key={component ?? "other"}>
                  <h4 className="ain-studio__subtitle">{componentLabel(component)}</h4>
                  {subItems.map(one)}
                </div>
              ));
            }
            return visible.map(one);
          };

          // A lead colour row: surface + on/ink with a live contrast verdict, even
          // when the two aren't a declared `on:` pair (e.g. surface + ink).
          const leadPair = (surfaceName: string, onName: string, label: string) => {
            const surface = tokenByName.get(surfaceName);
            const on = tokenByName.get(onName);
            if (!surface || !on) return null;
            const sV = values[surface.css_var] ?? "";
            const oV = values[on.css_var] ?? "";
            const ratio = contrastRatio(resolve(sV || surface.default), resolve(oV || on.default));
            const pair = ratio === null ? null : { ratio, passes: ratio >= AA_NORMAL };
            return (
              <PairRow
                key={surfaceName}
                surface={surface}
                on={on}
                surfaceValue={sV}
                onValue={oV}
                pair={pair}
                shared={shared}
                surfaceLabel={label}
              />
            );
          };

          const colorLeadNames = new Set(COLOR_LEAD_PAIRS.flatMap((p) => [p.surface, p.on]));
          // Count of changed tokens inside a list — drives the "n changed"
          // badge on an Advanced fold so edits hidden behind the (default-closed)
          // fold are discoverable without expanding it.
          const dirtyCount = (items: TokenDef[]) => items.filter((t) => dirtyVars.has(t.css_var)).length;

          // The body for one intent card.
          const cardBody = (id: CardId) => {
            if (id === "color") {
              const advColors = cardTokens.color.filter((t) => !colorLeadNames.has(t.name));
              return (
                <>
                  {COLOR_LEAD_PAIRS.map((p) => leadPair(p.surface, p.on, p.label))}
                  <AdvancedFold label="Advanced colors" dirtyCount={dirtyCount(advColors)}>
                    {renderTokens(advColors)}
                  </AdvancedFold>
                </>
              );
            }
            if (id === "typography") {
              return (
                <>
                  <FontPairingGallery
                    group={groupById("pairing")}
                    active={activeOption(groupById("pairing"))}
                    onPick={applyOption}
                  />
                  <PresetChips group={groupById("text_size")} active={activeOption(groupById("text_size"))} onPick={applyOption} />
                  <PresetChips group={groupById("heading_weight")} active={activeOption(groupById("heading_weight"))} onPick={applyOption} />
                  <AdvancedFold label="Advanced · custom stacks & scales" dirtyCount={dirtyCount(cardTokens.typography)}>
                    {renderTokens(cardTokens.typography)}
                  </AdvancedFold>
                </>
              );
            }
            if (id === "shape") {
              // Shadow is dialled by its decoupled AXES, not a "style" preset:
              // distance / blur / strength / colour shown directly (the lead
              // controls), with `direction` the one enum on top. The per-step
              // radius scale + the advanced shadow spread tuck under the fold.
              const shadowAxes = cardTokens.shape.filter((t) => t.category === "shadow" && !t.advanced);
              const shapeAdvanced = cardTokens.shape.filter((t) => !(t.category === "shadow" && !t.advanced));
              return (
                <>
                  <PresetChips group={groupById("roundness")} active={activeOption(groupById("roundness"))} onPick={applyOption} />
                  <PresetChips group={groupById("density")} active={activeOption(groupById("density"))} onPick={applyOption} />
                  <div className="ain-studio__subgroup">
                    <h4 className="ain-studio__subtitle">Shadow</h4>
                    <DirectionPad group={groupById("direction")} active={activeOption(groupById("direction"))} onPick={applyOption} />
                    {renderTokens(shadowAxes)}
                  </div>
                  <AdvancedFold label="Advanced · per-step scales" dirtyCount={dirtyCount(shapeAdvanced)}>
                    {renderTokens(shapeAdvanced)}
                  </AdvancedFold>
                </>
              );
            }
            // components — a fine-tune card: per-component sub-groups, no presets.
            return (
              <>
                <p className="ain-studio__groupnote">Each inherits from the choices above. Override only what needs to differ.</p>
                {renderTokens(cardTokens.components, { components: true })}
              </>
            );
          };

          return CARD_ORDER.map((id) => {
            const meta = CARD_META[id];
            const isCollapsed = collapsed.has(id);
            const bodyId = `ain-studio-card-${id}`;
            // How many of this card's tokens differ from the saved brand — shown
            // as a badge on the head so a collapsed card still advertises pending
            // edits (the per-field markers live inside, revealed on expand).
            const cardDirty = cardTokens[id].filter((t) => dirtyVars.has(t.css_var)).length;
            return (
              <section className="ain-card" key={id}>
                <button
                  type="button"
                  className="ain-btn ain-card__head"
                  onClick={() => toggleCard(id)}
                  aria-expanded={!isCollapsed}
                  aria-controls={bodyId}
                >
                  <ChevronDownIcon className="ain-card__chev" />
                  <span className="ain-card__ico" aria-hidden="true">{meta.icon}</span>
                  <span className="ain-card__ct">
                    <span className="ain-card__h">{meta.label}</span>
                    <span className="ain-card__sub">{meta.sublabel}</span>
                  </span>
                  {cardDirty > 0 && (
                    <span className="ain-card__dirty" title={`${cardDirty} unsaved change${cardDirty === 1 ? "" : "s"} in this section`}>
                      {cardDirty}
                    </span>
                  )}
                </button>
                {!isCollapsed && (
                  <div className="ain-card__body" id={bodyId}>
                    {cardBody(id)}
                  </div>
                )}
              </section>
            );
          });
        })()}
      </div>
    </div>
  );
}

/* ─────────────────────────────────────────────────── brand status control */

/**
 * The design-intent status control: a flat neobrutalist badge (current stage +
 * a lock glyph when locked), the three stages as a segmented radiogroup, and a
 * lock toggle. Each toggle writes IMMEDIATELY via {@see onChange} (POST
 * /aincient/brand/status) — this is a MODE, not draft content, so it never rides
 * the token Publish cycle. The badge is the shared `.ain-studio__statebadge`
 * idiom keyed by `data-state={stage}` (+ `data-locked` when locked).
 */
function StatusControl({
  status,
  saving,
  onChange,
}: {
  status: BrandStatus;
  saving: boolean;
  onChange: (patch: Partial<BrandStatus>) => void;
}) {
  const stage = STAGE_META[status.stage] ? status.stage : "ideating";
  const meta = STAGE_META[stage];
  return (
    <div className="ain-brandstatus">
      <div className="ain-brandstatus__head">
        {/* A mode is stated ONCE — by the segmented control below (study 02,
            Plate 12); the stage echo-badge is retired. Locked is a distinct
            state (not an echo), so it alone keeps a whisper chip. */}
        {status.locked && (
          <span
            className="ain-studio__statebadge ain-brandstatus__badge"
            data-locked=""
            title="Brand locked — minimal, single-axis changes only"
          >
            <LockIcon />
            Locked
          </span>
        )}
        <span className="ain-brandstatus__hint">{status.locked ? "The brand is settled — the agent makes minimal, single-axis changes only." : meta.blurb}</span>
      </div>
      <div className="ain-seg" role="radiogroup" aria-label="Brand stage">
        {STAGE_ORDER.map((s) => (
          <button
            key={s}
            type="button"
            className="ain-btn ain-seg__btn"
            aria-pressed={stage === s}
            disabled={saving}
            title={STAGE_META[s].blurb}
            onClick={() => onChange({ stage: s })}
          >
            {STAGE_META[s].label}
          </button>
        ))}
      </div>
      <label className="ain-field ain-field--radio ain-brandstatus__lock">
        <input
          className="ain-field__radio"
          type="checkbox"
          checked={status.locked}
          disabled={saving}
          onChange={(e) => onChange({ locked: e.target.checked })}
        />
        <span className="ain-field__radiobody">
          <span className="ain-field__label ain-brandstatus__locklabel">
            {status.locked ? <LockIcon /> : <LockOpenIcon />}
            Lock the brand
          </span>
          <span className="ain-field__hint">
            Pins the brand — the agent won’t make sweeping or cross-axis changes, whatever the stage.
          </span>
        </span>
      </label>
    </div>
  );
}

/* ───────────────────────────────────────────── preset chips & gallery */

/** A segmented control for one scale-preset group (roundness, depth, density,
 *  body size, heading weight). Each option writes a coherent token bundle; the
 *  active option (or none → the user has hand-tuned to a "Custom" state) is lit.
 *  Renders nothing if the group is missing (manifest still loading). */
function PresetChips({
  group,
  active,
  onPick,
}: {
  group: PresetGroup | undefined;
  active: string | null;
  onPick: (o: PresetOption) => void;
}) {
  if (!group) return null;
  return (
    <div className="ain-preset">
      <span className="ain-preset__lbl">{group.label}</span>
      <div className="ain-seg" role="group" aria-label={group.label}>
        {group.options.map((o) => (
          <button
            key={o.id}
            type="button"
            className="ain-btn ain-seg__btn"
            aria-pressed={active === o.id}
            title={o.blurb}
            onClick={() => onPick(o)}
          >
            {o.label}
          </button>
        ))}
      </div>
    </div>
  );
}

/** Spatial layout of the 9-way `direction` group as a 3×3 radio pad: each cell
 *  sits where its shadow falls — the eight compass directions around a centre
 *  "glow" — so the control reads as a picture of the effect, not a word list.
 *  Cells are placed by id (not option order), so a missing option just blanks. */
const DIRECTION_PAD: { id: string; glyph: string }[] = [
  { id: "top_left", glyph: "↖" }, { id: "top", glyph: "↑" }, { id: "top_right", glyph: "↗" },
  { id: "left", glyph: "←" }, { id: "center", glyph: "◎" }, { id: "right", glyph: "→" },
  { id: "bottom_left", glyph: "↙" }, { id: "bottom", glyph: "↓" }, { id: "bottom_right", glyph: "↘" },
];

/** Shadow direction as a true radiogroup laid out around a square: one
 *  <input type="radio"> per compass cell, each writing the same dir-multiplier
 *  bundle PresetChips would. Renders nothing while the manifest is loading. */
function DirectionPad({
  group,
  active,
  onPick,
}: {
  group: PresetGroup | undefined;
  active: string | null;
  onPick: (o: PresetOption) => void;
}) {
  if (!group) return null;
  const byId = new Map(group.options.map((o) => [o.id, o]));
  return (
    <div className="ain-preset">
      <span className="ain-preset__lbl">{group.label}</span>
      <div className="ain-dirpad" role="radiogroup" aria-label={group.label}>
        {DIRECTION_PAD.map((cell) => {
          const o = byId.get(cell.id);
          if (!o) return <span key={cell.id} className="ain-dirpad__cell" aria-hidden="true" />;
          return (
            <label key={o.id} className="ain-dirpad__cell" title={`${o.label} — ${o.blurb}`}>
              <input
                type="radio"
                className="ain-dirpad__radio"
                name={`ain-dir-${group.id}`}
                checked={active === o.id}
                onChange={() => onPick(o)}
              />
              <span className="ain-dirpad__btn" aria-hidden="true">{cell.glyph}</span>
              <span className="ain-sr-only">{o.label}</span>
            </label>
          );
        })}
      </div>
    </div>
  );
}

/** The typography lead: the curated font pairings as a gallery of cards, each
 *  previewed in its own display + body typefaces (the console loads the pairing
 *  fonts so these render true). Picking one stages both font-family tokens + its
 *  web fonts. Custom stacks live under the card's Advanced fold. */
function FontPairingGallery({
  group,
  active,
  onPick,
}: {
  group: PresetGroup | undefined;
  active: string | null;
  onPick: (o: PresetOption) => void;
}) {
  if (!group) return null;
  return (
    <div className="ain-preset">
      <span className="ain-preset__lbl">{group.label}</span>
      <div className="ain-gallery">
        {group.options.map((o) => {
          const display = o.tokens[FONT_DISPLAY_TOKEN];
          const base = o.tokens[FONT_BASE_TOKEN];
          return (
            <button
              key={o.id}
              type="button"
              className="ain-fp"
              aria-pressed={active === o.id}
              title={o.blurb}
              onClick={() => onPick(o)}
            >
              <span className="ain-fp__ag">
                <span style={{ fontFamily: display }}>Ag</span>
                <span className="ain-fp__b" style={{ fontFamily: base }}>ag</span>
              </span>
              <span className="ain-fp__nm">{o.label}</span>
              <span className="ain-fp__bl">{o.blurb}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

/** A disclosure that tucks a card's raw per-token controls out of the way until
 *  the user wants fine control. Closed by default. A `dirtyCount` badge flags
 *  unsaved edits hiding inside so they're discoverable without expanding. */
function AdvancedFold({ label, children, dirtyCount = 0 }: { label: string; children: ReactNode; dirtyCount?: number }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="ain-adv">
      <button
        type="button"
        className="ain-btn ain-adv__toggle"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
      >
        <ChevronDownIcon className="ain-adv__chev" />
        {label}
        {dirtyCount > 0 && (
          <span className="ain-adv__dirty" title={`${dirtyCount} unsaved change${dirtyCount === 1 ? "" : "s"} in here`}>
            {dirtyCount}
          </span>
        )}
      </button>
      {open && <div className="ain-adv__body">{children}</div>}
    </div>
  );
}

/** The render props shared by every control (everything but the token itself). */
type SharedProps = {
  tokens: TokenDef[];
  palette: PaletteGroup[];
  referable: Record<string, string[]>;
  resolve: (v: string) => string;
  /** Prefilled fonts offered next to a font-family input (empty for others). */
  fontOptions: FontOption[];
  onChange: (cssVar: string, value: string) => void;
  /** The css-vars whose draft value differs from the saved baseline. */
  dirtyVars: Set<string>;
  /** Revert one css-var to its saved value (drops the field's draft edit). */
  onRevert: (cssVar: string) => void;
};

type ControlProps = SharedProps & {
  token: TokenDef;
  value: string;
  /** Which edge the popover anchors to — "right" for the rail's right column. */
  popAlign?: "left" | "right";
  /** Stack the swatch over its value label (used in the paired-colour row). */
  stacked?: boolean;
};

/** Routes a token to the right picker by category, then renders its label. */
function TokenControl(props: ControlProps) {
  const { token, dirtyVars, onRevert } = props;
  // Colour-ness is by TYPE, not category — so the shadow tint (category shadow,
  // type color) still renders as a colour picker rather than a text field.
  const isColor = token.type === "color";
  const dirty = dirtyVars.has(token.css_var);
  return (
    <label className="ain-field" title={token.description || token.name} data-dirty={dirty || undefined}>
      <span className="ain-field__label">
        <span className="ain-field__labeltext">{token.label}</span>
        {dirty && <FieldRevert label={token.label} onRevert={() => onRevert(token.css_var)} />}
      </span>
      {isColor ? <ColorControl {...props} /> : <ScaleControl {...props} />}
    </label>
  );
}

/**
 * A surface colour and its on-colour as one row: [surface] [text] [preview].
 * The first two cells are full colour controls (each labelled); the third is a
 * live sample of the text colour set on the surface, with the WCAG verdict
 * directly beneath it — so the contrast score reads against the thing it grades
 * instead of floating under a lone swatch.
 */
function PairRow({
  surface,
  on,
  surfaceValue,
  onValue,
  pair,
  shared,
  surfaceLabel,
}: {
  surface: TokenDef;
  on: TokenDef;
  surfaceValue: string;
  onValue: string;
  pair: { ratio: number; passes: boolean } | null;
  shared: SharedProps;
  /** Overrides the surface column's heading (lead rows name the intent, e.g.
   *  "Page background & ink", rather than the raw token label). */
  surfaceLabel?: string;
}) {
  const bg = shared.resolve(surfaceValue || surface.default);
  const fg = shared.resolve(onValue || on.default);
  const level = pair ? wcagLevel(pair.ratio) : null;
  const surfaceDirty = shared.dirtyVars.has(surface.css_var);
  const onDirty = shared.dirtyVars.has(on.css_var);
  return (
    <div className="ain-pair">
      <div className="ain-pair__col">
        <span className="ain-field__label" title={surface.description || surface.label}>
          <span className="ain-field__labeltext">{surfaceLabel ?? surface.label}</span>
          {surfaceDirty && <FieldRevert label={surface.label} onRevert={() => shared.onRevert(surface.css_var)} />}
        </span>
        <ColorControl token={surface} value={surfaceValue} stacked {...shared} />
      </div>
      <div className="ain-pair__col">
        <span className="ain-field__label" title={`${on.label} — ${on.description || "text/icons on this surface"}`}>
          <span className="ain-field__labeltext">Text</span>
          {onDirty && <FieldRevert label={on.label} onRevert={() => shared.onRevert(on.css_var)} />}
        </span>
        <ColorControl token={on} value={onValue} stacked popAlign="right" {...shared} />
      </div>
      <div className="ain-pair__col ain-pair__col--preview">
        {/* No label here, but a spacer keeps this box on the same row as the
            two swatches; the verdict sits below it like the other values. */}
        <span className="ain-field__label" aria-hidden="true">{" "}</span>
        <div className="ain-pair__tile" style={{ background: bg }} aria-hidden="true">
          <span className="ain-pair__ag" style={{ color: fg }}>Ag</span>
        </div>
        {pair && level && (
          <span
            className="ain-pair-badge"
            data-pass={pair.passes || undefined}
            title={`${surface.label} with its text: contrast ${pair.ratio.toFixed(2)}:1 — WCAG ${level.tag === "Large" ? "AA for large text only" : level.tag}. Normal text needs AA (${AA_NORMAL}:1).`}
          >
            {/* Whisper chip, tag-first — Plate 12's ratified `.aa-new` cut: no icon,
                no border, mono tint. The pass/fail hue + the title carry the verdict. */}
            {level.tag} {pair.ratio.toFixed(1)}
          </span>
        )}
      </div>
    </div>
  );
}

/** The legal reference targets for a token: same-category tokens in the tiers
 *  its own tier may reference (semantic → palette, component → palette+semantic). */
function candidateTokens(token: TokenDef, tokens: TokenDef[], referable: Record<string, string[]>): TokenDef[] {
  const allowed = referable[token.tier] ?? [];
  // Same "family" = same colour-ness (by TYPE) for colours, else same category
  // AND same type. The type guard stops the typography bucket (which lumps
  // weights, letter-spacing, sizes and line-heights together) from offering a
  // type-incompatible chip — e.g. a `length` line-height token offering a
  // `number` font-weight as a reference, which is nonsensical CSS.
  const sameFamily = (t: TokenDef) =>
    token.type === "color"
      ? t.type === "color"
      : t.category === token.category && t.type === token.type;
  return tokens.filter(
    (t) => t.css_var !== token.css_var && sameFamily(t) && allowed.includes(t.tier),
  );
}

const allowsTailwind = (token: TokenDef, referable: Record<string, string[]>) =>
  (referable[token.tier] ?? []).includes("tailwind");

// Raw values are allowed only at Tier 1 for colour; non-colour scalars may be raw anywhere.
// Keyed on TYPE so a semantic colour stays reference-only — UNLESS it opts in via
// `raw_color` (e.g. the shadow tint), which the registry validator also exempts.
const allowsRaw = (token: TokenDef) =>
  token.type !== "color" || token.tier === "palette" || !!token.raw_color;

/* ───────────────────────────────────────────────── popover anchoring */

/** Width of a `.ain-pop` popover (kept in sync with styles.css). */
const POP_WIDTH = 264;

/**
 * Where portalled popovers mount. NOT `document.body`: the chat UI scopes its
 * `--ain-*` design tokens and box-sizing reset under `#aincient-chat-root`, so
 * a popover rendered outside that subtree loses its background, border and
 * fonts. The scoped root is `position: static` with no transform, so a `fixed`
 * child mounted here is still positioned against the viewport and escapes the
 * `.ain-studio__groups` scroll clip — the best of both.
 */
const popoverHost = (): HTMLElement =>
  document.getElementById("aincient-chat-root") ?? document.body;

/**
 * Anchors a fixed-position popover to a trigger element and renders it through
 * a portal on `document.body`. The token rail (`.ain-studio__groups`) scrolls,
 * so an absolutely-positioned popover nested inside it gets clipped at the
 * viewport edges — most visibly the right-aligned "Text" picker in a pair row.
 * Positioning the popover `fixed` against the trigger's box and portalling it
 * out of the scroll container sidesteps the clip entirely. Re-places on scroll
 * and resize so it tracks the trigger while open.
 */
function useAnchoredPopover(
  open: boolean,
  triggerRef: RefObject<HTMLElement | null>,
  align: "left" | "right",
) {
  const popRef = useRef<HTMLDivElement>(null);
  const [style, setStyle] = useState<CSSProperties>({});

  useLayoutEffect(() => {
    if (!open) return;
    const place = () => {
      const t = triggerRef.current;
      if (!t) return;
      const r = t.getBoundingClientRect();
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const gap = 6;
      const margin = 8;
      // Horizontal: align to the requested edge, then clamp into the viewport.
      let left = align === "right" ? r.right - POP_WIDTH : r.left;
      left = Math.max(margin, Math.min(left, vw - POP_WIDTH - margin));
      // Vertical: open below; flip above if it would spill off the bottom.
      const popH = popRef.current?.offsetHeight ?? 0;
      let top = r.bottom + gap;
      if (popH && top + popH > vh - margin) {
        const above = r.top - gap - popH;
        top = above >= margin ? above : Math.max(margin, vh - popH - margin);
      }
      setStyle({ position: "fixed", left, top, width: POP_WIDTH });
    };
    place();
    window.addEventListener("scroll", place, true);
    window.addEventListener("resize", place);
    return () => {
      window.removeEventListener("scroll", place, true);
      window.removeEventListener("resize", place);
    };
  }, [open, align, triggerRef]);

  return { popRef, style };
}

/* ─────────────────────────────────────────────────────────── colour control */

function ColorControl({ token, value, tokens, palette, referable, resolve, popAlign, stacked, onChange }: ControlProps) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLSpanElement>(null);
  const { popRef, style: popStyle } = useAnchoredPopover(open, wrapRef, popAlign ?? "left");
  const candidates = candidateTokens(token, tokens, referable);
  const tailwind = allowsTailwind(token, referable);
  // Show the value exactly as authored — oklch()/rgb()/#hex stays itself, a
  // reference stays var(--…). `current` is what it resolves to (for the swatch
  // and, when it's a reference, the colour revealed on hover).
  const authored = value || token.default;
  const current = resolve(authored);
  const isRef = current !== authored;
  const targetVar = refTarget(value);

  const pick = (cssVar: string) => {
    onChange(token.css_var, `var(--${cssVar})`);
    setOpen(false);
  };

  // Dismiss without an action while the popover is open: Escape, or a pointer
  // press anywhere outside this control. A document-level pointerdown listener
  // is used rather than a full-screen scrim div — the scrim's synthetic onClick
  // proved unreliable across the studio's stacking contexts. The trigger lives
  // in wrapRef and the popover is portalled to <body> (popRef), so a press on
  // either is excluded from the outside-press dismissal.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    const onDown = (e: PointerEvent) => {
      const node = e.target as Node;
      if (!wrapRef.current?.contains(node) && !popRef.current?.contains(node)) setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onDown, true);
    return () => {
      window.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onDown, true);
    };
  }, [open]);

  return (
    <span className={"ain-field__control" + (stacked ? " ain-field__control--stack" : "")} ref={wrapRef}>
      <button
        type="button"
        className={"ain-swatchbtn" + (open ? " ain-swatchbtn--open" : "")}
        style={{ background: current }}
        onClick={() => setOpen((o) => !o)}
        aria-label={open ? `Close ${token.label} picker` : `Edit ${token.label}`}
        aria-expanded={open}
      />
      {/* Human words first (Plate 12): a reference reads as its token's LABEL,
          a literal as its hex — the raw authored value stays in the tooltip
          and the picker's Custom field ("Advanced"). */}
      <span className="ain-field__name" title={isRef ? `${authored} → ${current}` : authored}>
        {targetVar
          ? candidates.find((c) => c.css_var === targetVar)?.label ?? authored
          : toHexDisplay(current) ?? authored}
      </span>

      {open && createPortal(
        <div className="ain-pop" role="dialog" aria-label={`${token.label} colour`} ref={popRef} style={popStyle}>
            <div className="ain-pop__head">
              <span className="ain-pop__title">{token.label}</span>
              <button
                type="button"
                className="ain-btn ain-pop__close"
                onClick={() => setOpen(false)}
                aria-label="Close picker"
                title="Close (Esc)"
              >
                <XIcon />
              </button>
            </div>
            {/* The value in use, pinned + highlighted so it's obvious what the
                swatches below are being chosen against. */}
            <div className="ain-pop__current">
              <span className="ain-pop__currentsw" style={{ background: current }} aria-hidden="true" />
              <span className="ain-pop__currenttext">
                <code className="ain-pop__currentname">{authored}</code>
                {isRef && <code className="ain-pop__currentval">→ {current}</code>}
              </span>
            </div>
            {candidates.length > 0 && (
              <div className="ain-pop__section">
                <div className="ain-pop__heading">From the {token.tier === "component" ? "palette / semantic" : "palette"}</div>
                <div className="ain-pop__row">
                  {candidates.map((c) => (
                    <button
                      key={c.css_var}
                      type="button"
                      title={c.label}
                      className={"ain-sw" + (targetVar === c.css_var ? " ain-sw--on" : "")}
                      style={{ background: resolve(`var(--${c.css_var})`) }}
                      onClick={() => pick(c.css_var)}
                    />
                  ))}
                </div>
              </div>
            )}

            {tailwind &&
              palette.map((g) => (
                <div className="ain-pop__section" key={g.hue}>
                  <div className="ain-pop__heading">{g.label}</div>
                  <div className="ain-pop__row">
                    {g.swatches.map((s) => (
                      <button
                        key={s.css_var}
                        type="button"
                        title={`${g.label} ${s.step}`}
                        className={"ain-sw" + (targetVar === s.css_var ? " ain-sw--on" : "")}
                        style={{ background: s.value }}
                        onClick={() => pick(s.css_var)}
                      />
                    ))}
                  </div>
                </div>
              ))}

            {allowsRaw(token) && (
              <div className="ain-pop__section">
                <div className="ain-pop__heading">Custom</div>
                <div className="ain-pop__custom">
                  <input
                    type="color"
                    className="ain-field__swatch"
                    value={hexish(current)}
                    onChange={(e) => onChange(token.css_var, e.target.value)}
                  />
                  <input
                    type="text"
                    className="ain-field__input"
                    value={targetVar ? "" : value}
                    placeholder="oklch(…) / #hex"
                    spellCheck={false}
                    onChange={(e) => onChange(token.css_var, e.target.value)}
                  />
                </div>
              </div>
            )}
        </div>,
        popoverHost(),
      )}
    </span>
  );
}

/* ───────────────────────────────────────────── scale control (non-colour) */

function ScaleControl({ token, value, tokens, referable, resolve, fontOptions, onChange }: ControlProps) {
  // An enum is a CLOSED set — render one compact select that shows the current
  // choice, not the chip-row + raw-input combo (no incompatible references can
  // sneak in, and the rail stays quiet). The picked value is written verbatim.
  if (token.enum && token.enum.length > 0) {
    const opts = token.enum;
    const labels = token.enum_labels ?? [];
    // Keep the live value selectable even if it's off-list (e.g. set by hand or
    // the agent) so the control never silently drops it.
    const known = opts.includes(value);
    return (
      <span className="ain-field__control ain-field__control--col">
        <select
          className="ain-field__input"
          value={known ? value : ""}
          onChange={(e) => onChange(token.css_var, e.target.value)}
        >
          {!known && <option value="">{value || "Custom…"}</option>}
          {opts.map((opt, i) => (
            <option key={opt} value={opt}>
              {labels[i] ?? opt}
            </option>
          ))}
        </select>
      </span>
    );
  }

  const candidates = candidateTokens(token, tokens, referable);
  const raw = allowsRaw(token);
  const targetVar = refTarget(value);
  const isFont = token.type === "font-family";

  return (
    <span className="ain-field__control ain-field__control--col">
      {candidates.length > 0 && (
        <div className="ain-chiprow">
          {candidates.map((c) => (
            <button
              key={c.css_var}
              type="button"
              title={`${c.label} · ${c.default}`}
              className={"ain-chip" + (targetVar === c.css_var ? " ain-chip--on" : "")}
              onClick={() => onChange(token.css_var, `var(--${c.css_var})`)}
            >
              <ScalePreview token={token} value={resolve(`var(--${c.css_var})`)} />
              <span className="ain-chip__label">{chipLabel(c)}</span>
            </button>
          ))}
        </div>
      )}
      {raw && (
        <span className="ain-field__inputrow">
          <input
            type="text"
            className="ain-field__input"
            style={isFont ? { fontFamily: value || token.default } : undefined}
            value={targetVar ? "" : value}
            placeholder={token.default}
            spellCheck={false}
            onChange={(e) => onChange(token.css_var, e.target.value)}
          />
          {isFont && fontOptions.length > 0 && (
            <FontPicker
              label={token.label}
              value={value}
              options={fontOptions}
              onPick={(stack) => onChange(token.css_var, stack)}
            />
          )}
        </span>
      )}
    </span>
  );
}

/* ─────────────────────────────────────────────────────────── font picker */

/** A helper button next to a font-family input: opens a popover of prefilled
 *  fonts, each previewed in its own typeface. Picking one writes the full
 *  font-family stack to the token (the fonts effect then loads the web font in
 *  the preview). Typing a custom stack in the input stays available alongside. */
function FontPicker({
  label,
  value,
  options,
  onPick,
}: {
  label: string;
  value: string;
  options: FontOption[];
  onPick: (stack: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLSpanElement>(null);
  const { popRef, style: popStyle } = useAnchoredPopover(open, wrapRef, "right");

  // Same dismiss contract as the colour popover: Esc, an outside pointer press,
  // or picking a value. The trigger lives in wrapRef and the popover is
  // portalled to <body> (popRef), so a press on either is excluded.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    const onDown = (e: PointerEvent) => {
      const node = e.target as Node;
      if (!wrapRef.current?.contains(node) && !popRef.current?.contains(node)) setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    document.addEventListener("pointerdown", onDown, true);
    return () => {
      window.removeEventListener("keydown", onKey);
      document.removeEventListener("pointerdown", onDown, true);
    };
  }, [open]);

  const pick = (stack: string) => {
    onPick(stack);
    setOpen(false);
  };

  return (
    <span className="ain-fontpick" ref={wrapRef}>
      <button
        type="button"
        className={"ain-fontpick__btn" + (open ? " ain-fontpick__btn--open" : "")}
        onClick={() => setOpen((o) => !o)}
        aria-label={`Pick a font for ${label}`}
        aria-expanded={open}
        title="Pick a prefilled font"
      >
        Aa
      </button>
      {open && createPortal(
        <div className="ain-pop" role="dialog" aria-label={`${label} font`} ref={popRef} style={popStyle}>
          <div className="ain-pop__head">
            <span className="ain-pop__title">{label}</span>
            <button
              type="button"
              className="ain-btn ain-pop__close"
              onClick={() => setOpen(false)}
              aria-label="Close picker"
              title="Close (Esc)"
            >
              <XIcon />
            </button>
          </div>
          <div className="ain-fontlist">
            {options.map((o) => (
              <button
                key={o.stack}
                type="button"
                className={"ain-fontopt" + (value === o.stack ? " ain-fontopt--on" : "")}
                onClick={() => pick(o.stack)}
                title={o.blurb}
              >
                <span className="ain-fontopt__name" style={{ fontFamily: o.stack }}>
                  {o.family}
                </span>
                <span className="ain-fontopt__sample" style={{ fontFamily: o.stack }}>
                  Ag
                </span>
              </button>
            ))}
          </div>
        </div>,
        popoverHost(),
      )}
    </span>
  );
}

/** A tiny visual for a scale candidate: rounded corner / shadow box, else text. */
function ScalePreview({ token, value }: { token: TokenDef; value: string }) {
  if (token.category === "radius") {
    return <span className="ain-prev ain-prev--radius" style={{ borderRadius: clampLen(value) }} />;
  }
  if (token.category === "shadow") {
    return <span className="ain-prev ain-prev--shadow" style={{ boxShadow: value === "none" ? "none" : value }} />;
  }
  return null;
}

/** Cap a preview corner radius so a 9999px pill still reads in a ~22px chip. */
function clampLen(v: string): string {
  return /^\d{3,}/.test(v) || v.includes("9999") ? "11px" : v;
}

function chipLabel(c: TokenDef): string {
  // Strip the category prefix for a compact chip ("Radius sm" → "sm").
  return c.label.replace(/^(Radius|Shadow|Size|Weight|Leading|Tracking)\s+/i, "");
}

/** Native <input type=color> needs #rrggbb; fall back to a neutral grey. */
function hexish(value: string): string {
  return /^#[0-9a-fA-F]{6}$/.test(value.trim()) ? value.trim() : "#888888";
}

/**
 * A colour literal as an UPPERCASE hex for display, or null if it won't parse.
 * The rail speaks human first (study 02, Plate 12): "#C21543", never
 * "oklch(0.52 0.22 15)" — the machine syntax stays in the picker's Custom
 * field and the tooltip.
 */
function toHexDisplay(value: string): string | null {
  const v = value.trim();
  if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toUpperCase();
  const linear = colorToLinear(v);
  if (!linear) return null;
  const gamma = (c: number) => (c <= 0.0031308 ? c * 12.92 : 1.055 * c ** (1 / 2.4) - 0.055);
  return (
    "#" +
    linear
      .map((c) => Math.round(Math.max(0, Math.min(1, gamma(c))) * 255).toString(16).padStart(2, "0"))
      .join("")
      .toUpperCase()
  );
}
