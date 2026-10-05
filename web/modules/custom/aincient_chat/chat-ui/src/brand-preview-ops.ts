/**
 * The Identity studio's typed command set (plans/studio-commands.md P1,
 * DECISIONS 0447) — and applying a brand_preview payload through it.
 *
 * {@link brandCommands} is the one executor over the brand draft
 * (brand-state.ts): `set_tokens`, `step_token`, `set_fonts`, `reset` and the
 * read-only `get_draft`. A batch applies atomically and is ONE undo entry
 * (`brandDraftHistory`). Commands change the draft only — Publish stays the
 * studio's button.
 *
 * {@link applyBrandPreviewOps} is the chat adapter onto it, with two callers,
 * deliberately: the `brand_preview` tool card (the authoritative end-of-turn
 * apply, replayed from storage on reload) and the adapter's `preview` frame
 * handler (the transient mid-turn repaints each specialist emits). They differ
 * in whether a card is rendered and whether anything is persisted — NOT in what
 * applying means. Both translate the payload to `Command[]` and run ONE
 * `execute(batch)`, so a whole preview apply undoes in one step.
 *
 * It lives in core, not the studio's `ui/`: the adapter applies preview frames
 * whether or not the Identity chunk has loaded yet.
 */

import { commandFailure, createCommandSurface, defineCommand } from "./sdk/commands";
import type { CommandOf, DescriptorFor, ExecuteResult, StudioCommandSurface } from "./sdk/commands";
import { brandDraftHistory, getBrandDraft, setBrandDraft } from "./brand-state";
import type { BrandDraft } from "./brand-state";

export type BrandCommand = CommandOf<{
  set_tokens: { tokens: Record<string, string> };
  step_token: { token: string; by: 1 | -1 };
  set_fonts: { fonts: string[] };
  reset: Record<string, never>;
  get_draft: Record<string, never>;
}>;

type Of<V extends BrandCommand["verb"]> = Extract<BrandCommand, { verb: V }>;

/* ------------------------------------------------------- step_token's ramps */

/**
 * What `step_token` steps over: the Tier-0 Tailwind base palette the brand
 * manifest serves (`palette` groups from `build-tokens.php`, each hue's
 * swatches in Tailwind's own step order, `css_var` = `color-<hue>-<step>`), and
 * the SAVED token values (manifest `current`, keyed by css var) for a token the
 * draft does not override. The studio feeds it when its manifest loads; until
 * then `step_token` refuses.
 */
export type BrandRampContext = {
  palette: readonly { hue: string; swatches: readonly { step: string; css_var: string }[] }[];
  saved: Readonly<Record<string, string>>;
};

let rampContext: BrandRampContext | null = null;

/** Provide (or clear, with null) the palette + saved values `step_token` reads. */
export function setBrandRampContext(context: BrandRampContext | null): void {
  rampContext = context;
}

const RAMP_REF = /^var\(\s*--(color-[a-z]+-\d+)\s*\)$/;

/* ---------------------------------------------------------------- the apply */

function apply(draft: BrandDraft, command: BrandCommand): BrandDraft | ReturnType<typeof commandFailure> {
  switch (command.verb) {
    case "set_tokens": {
      const tokens = { ...draft.tokens };
      // "" clears one override, exactly as setBrandOverride does.
      for (const [cssVar, value] of Object.entries(command.args.tokens)) {
        if (value === "") delete tokens[cssVar];
        else tokens[cssVar] = value;
      }
      return { ...draft, tokens };
    }
    case "step_token": {
      const { token, by } = command.args;
      if (!rampContext) return commandFailure("the brand palette is not loaded yet — open the Identity studio");
      const value = (draft.tokens[token] ?? rampContext.saved[token] ?? "").trim();
      const ref = RAMP_REF.exec(value)?.[1];
      const group = ref ? rampContext.palette.find((g) => g.swatches.some((s) => s.css_var === ref)) : undefined;
      if (!ref || !group) return commandFailure(`"${token}" does not point at a palette ramp step (value: ${value || "unset"})`);
      const at = group.swatches.findIndex((s) => s.css_var === ref);
      const next = group.swatches[at + by];
      if (!next) {
        return commandFailure(`"${token}" is already at the ${by > 0 ? "last" : "first"} step of the ${group.hue} ramp (${group.swatches[at].step})`);
      }
      return { ...draft, tokens: { ...draft.tokens, [token]: `var(--${next.css_var})` } };
    }
    case "set_fonts":
      return { ...draft, fonts: command.args.fonts.length ? [...command.args.fonts] : null };
    case "reset":
      return { tokens: {}, fonts: null };
    default:
      return commandFailure(`"${command.verb}" does not change the draft`);
  }
}

/* ------------------------------------------------------------ descriptors */

const EMPTY = { type: "object", additionalProperties: false } as const;

const descriptors: DescriptorFor<BrandCommand>[] = [
  defineCommand<Of<"set_tokens">>({
    verb: "set_tokens",
    description: "Layer token overrides onto the brand draft ({css_var: css_value}; \"\" clears one).",
    inputSchema: {
      type: "object",
      required: ["tokens"],
      properties: { tokens: { type: "object", additionalProperties: { type: "string" } } },
      additionalProperties: false,
    },
    readOnly: false,
    toCommand: (input) => ({ verb: "set_tokens", args: { tokens: input.tokens as Record<string, string> } }),
    summarize: (c) =>
      Object.entries(c.args.tokens)
        .map(([k, v]) => `${k} → ${v === "" ? "(saved)" : v}`)
        .join(", "),
  }),
  defineCommand<Of<"step_token">>({
    verb: "step_token",
    description:
      "Move a colour token one step along the palette ramp it points at (+1 = next Tailwind step, darker; -1 = lighter).",
    inputSchema: {
      type: "object",
      required: ["token", "by"],
      properties: { token: { type: "string", minLength: 1 }, by: { type: "integer", enum: [1, -1] } },
      additionalProperties: false,
    },
    readOnly: false,
    toCommand: (input) => ({ verb: "step_token", args: { token: input.token as string, by: input.by as 1 | -1 } }),
    summarize: (c) => `${c.args.token} ${c.args.by > 0 ? "+1" : "-1"} step`,
  }),
  defineCommand<Of<"set_fonts">>({
    verb: "set_fonts",
    description: "Stage web font families (Google family names) to load in the preview and publish with the draft.",
    inputSchema: {
      type: "object",
      required: ["fonts"],
      properties: { fonts: { type: "array", items: { type: "string", minLength: 1 } } },
      additionalProperties: false,
    },
    readOnly: false,
    toCommand: (input) => ({ verb: "set_fonts", args: { fonts: input.fonts as string[] } }),
    summarize: (c) => `fonts → ${c.args.fonts.join(", ") || "(none)"}`,
  }),
  defineCommand<Of<"reset">>({
    verb: "reset",
    description: "Clear the whole draft back to the saved brand.",
    inputSchema: EMPTY,
    readOnly: false,
    toCommand: () => ({ verb: "reset", args: {} }),
    summarize: () => "reset to the saved brand",
  }),
  defineCommand<Of<"get_draft">>({
    verb: "get_draft",
    description: "Read the brand draft (token overrides + staged fonts).",
    inputSchema: EMPTY,
    readOnly: true,
    toCommand: () => ({ verb: "get_draft", args: {} }),
    summarize: () => "read the draft",
  }),
];

/** The Identity studio's command surface (studio id `design_system`). */
export const brandCommands: StudioCommandSurface<BrandDraft, BrandCommand> = createCommandSurface<BrandDraft, BrandCommand>({
  studio: "design_system",
  descriptors,
  getDraft: getBrandDraft,
  setDraft: setBrandDraft,
  apply,
  read: (draft) => structuredClone(draft),
  history: brandDraftHistory,
});

/* ------------------------------------------------- the chat payload adapter */

export type BrandPreviewPayload = {
  /** The typed form: the batch to run. Wins over the legacy fields below. */
  commands?: BrandCommand[];
  // remove after 0.18 — the legacy fields (stored cards only; the server emits `commands`)
  tokens?: Record<string, string>;
  fonts?: string[];
  reset?: boolean;
  rejected?: string[];
  /** A one-line framing sentence (also sent as the tool summary). */
  summary?: string;
  /** Set by the adapter on cards replayed from storage — read-only, applies nothing. */
  __historical?: boolean;
};

/**
 * A brand_preview payload as a command batch. Order is the contract: reset
 * first, then tokens, then fonts — and fonts ONLY when the op carries some, so
 * a token-only op doesn't wipe fonts a previous op (or the studio) staged.
 */
export function brandPreviewCommands(payload: BrandPreviewPayload): BrandCommand[] {
  if (Array.isArray(payload.commands)) return payload.commands.map(phpEmptyArgs);
  // remove after 0.18 — old {tokens,fonts,reset} payload (stored cards from before the server emitted `commands`)
  const batch: BrandCommand[] = [];
  if (payload.reset) batch.push({ verb: "reset", args: {} });
  const tokens: Record<string, string> = {};
  for (const [cssVar, value] of Object.entries(payload.tokens ?? {})) {
    // Non-string values were skipped one by one before; keep skipping them
    // rather than letting arg validation refuse the whole batch.
    if (typeof value === "string") tokens[cssVar] = value;
  }
  if (Object.keys(tokens).length) batch.push({ verb: "set_tokens", args: { tokens } });
  const fonts = Array.isArray(payload.fonts) ? payload.fonts.filter((f): f is string => typeof f === "string" && f !== "") : [];
  if (fonts.length) batch.push({ verb: "set_fonts", args: { fonts } });
  return batch;
}

/**
 * The server builds the batch in PHP and the dispatcher round-trips it through
 * `json_decode(…, TRUE)`, which turns an empty args OBJECT (`reset`'s `{}`)
 * into an empty ARRAY — and `[]` fails the `{type: object}` arg check, refusing
 * the whole batch. An empty array carries no args either way, so read it as `{}`.
 */
function phpEmptyArgs(command: BrandCommand): BrandCommand {
  const args: unknown = (command as { args?: unknown } | null)?.args;
  return Array.isArray(args) && args.length === 0 ? ({ ...command, args: {} } as BrandCommand) : command;
}

/**
 * Apply one preview payload to the shared draft: one batch, one undo entry.
 * A `__historical` (replayed) payload applies nothing. Idempotent per cssVar.
 */
export function applyBrandPreviewOps(payload: BrandPreviewPayload): ExecuteResult<BrandDraft> | undefined {
  if (payload.__historical) return undefined;
  const batch = brandPreviewCommands(payload);
  if (batch.length === 0) return undefined;
  return brandCommands.execute(batch);
}
