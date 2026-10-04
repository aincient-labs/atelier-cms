import { beforeEach, describe, expect, it } from "vitest";
import { applyBrandPreviewOps, brandCommands, brandPreviewCommands, setBrandRampContext } from "./brand-preview-ops";
import type { BrandCommand } from "./brand-preview-ops";
import { clearBrandDraftHistory, getBrandDraft, getBrandOverrides, getPendingFonts, setBrandDraft, setBrandOverride, setPendingFonts } from "./brand-state";

/**
 * The Identity studio's command surface (plans/studio-commands.md P1): the
 * brand_preview payload runs as ONE batch over brand-state, old and typed
 * payloads land the same draft, and a batch undoes in one step.
 */

/** A known-empty draft and an empty undo stack (both are module-global). */
beforeEach(() => {
  while (brandCommands.undo().ok) {
    /* drain */
  }
  setBrandDraft({ tokens: {}, fonts: null });
  setBrandRampContext(null);
});

const RAMPS = {
  palette: [
    { hue: "base", swatches: [{ step: "white", css_var: "color-white" }] },
    {
      hue: "blue",
      swatches: [
        { step: "50", css_var: "color-blue-50" },
        { step: "100", css_var: "color-blue-100" },
        { step: "200", css_var: "color-blue-200" },
      ],
    },
  ],
  saved: { "brand-primary": "var(--color-blue-100)", "brand-accent": "#ff0000" },
};

describe("brand command surface", () => {
  it("undoes a multi-command batch in one step", () => {
    setBrandOverride("brand-primary", "red");
    const before = getBrandDraft();

    const result = brandCommands.execute([
      { verb: "set_tokens", args: { tokens: { "brand-primary": "blue", "brand-accent": "green" } } },
      { verb: "set_fonts", args: { fonts: ["Inter"] } },
    ]);
    expect(result.ok).toBe(true);
    expect(getBrandOverrides()).toEqual({ "brand-primary": "blue", "brand-accent": "green" });
    expect(getPendingFonts()).toEqual(["Inter"]);

    expect(brandCommands.undo().ok).toBe(true);
    expect(getBrandDraft()).toEqual(before);
    expect(brandCommands.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
  });

  it("undoes a whole brand_preview apply in one step", () => {
    applyBrandPreviewOps({ reset: true, tokens: { "brand-primary": "blue" }, fonts: ["Inter"] });
    expect(getBrandOverrides()).toEqual({ "brand-primary": "blue" });

    brandCommands.undo();
    expect(getBrandDraft()).toEqual({ tokens: {}, fonts: null });
  });

  it("leaves the draft unchanged and pushes no history on invalid args", () => {
    setBrandOverride("brand-primary", "red");
    const before = getBrandDraft();

    const result = brandCommands.execute([
      { verb: "set_tokens", args: { tokens: { "brand-accent": "green" } } },
      { verb: "set_fonts", args: { fonts: [42] } } as unknown as BrandCommand,
    ]);
    expect(result).toMatchObject({ ok: false, failedIndex: 1, verb: "set_fonts", code: "INVALID_ARGS" });
    expect(getBrandDraft()).toEqual(before);
    expect(brandCommands.undo().ok).toBe(false);
  });

  it("get_draft reads without pushing history", () => {
    setBrandOverride("brand-primary", "red");
    setPendingFonts(["Inter"]);

    const result = brandCommands.execute([{ verb: "get_draft", args: {} }]);
    expect(result.ok && result.results[0]).toEqual({ tokens: { "brand-primary": "red" }, fonts: ["Inter"] });
    expect(brandCommands.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
  });

  it("set_tokens with an empty value clears that override", () => {
    setBrandOverride("brand-primary", "red");
    brandCommands.execute([{ verb: "set_tokens", args: { tokens: { "brand-primary": "" } } }]);
    expect(getBrandOverrides()).toEqual({});
  });
});

describe("history after Discard / Publish", () => {
  it("clearBrandDraftHistory leaves nothing to undo (the studio calls it on Discard and after Publish)", () => {
    applyBrandPreviewOps({ tokens: { "brand-primary": "blue" } });
    brandCommands.execute([{ verb: "set_fonts", args: { fonts: ["Inter"] } }]);

    // Discard: the studio drops the draft, then the history.
    setBrandDraft({ tokens: {}, fonts: null });
    clearBrandDraftHistory();
    expect(brandCommands.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
    expect(getBrandDraft()).toEqual({ tokens: {}, fonts: null });

    // Publish: the published draft stays, the pre-Publish one is unreachable.
    applyBrandPreviewOps({ tokens: { "brand-accent": "green" } });
    clearBrandDraftHistory();
    expect(brandCommands.undo()).toEqual({ ok: false, error: "NOTHING_TO_UNDO" });
    expect(getBrandOverrides()).toEqual({ "brand-accent": "green" });
  });
});

describe("brand_preview payloads", () => {
  it("old payload and commands payload produce the identical draft", () => {
    const old = { reset: true, tokens: { "brand-primary": "oklch(0.6 0.2 260)", "brand-accent": "blue" }, fonts: ["Inter", "Lora"] };

    setBrandOverride("stale", "x");
    applyBrandPreviewOps(old);
    const fromOld = getBrandDraft();

    setBrandDraft({ tokens: { stale: "x" }, fonts: null });
    applyBrandPreviewOps({ commands: brandPreviewCommands(old) });
    expect(getBrandDraft()).toEqual(fromOld);
    expect(fromOld).toEqual({ tokens: old.tokens, fonts: ["Inter", "Lora"] });
  });

  it("translates reset first, then tokens, then fonts — fonts only when non-empty", () => {
    expect(brandPreviewCommands({ fonts: ["Inter"], tokens: { a: "1" }, reset: true }).map((c) => c.verb)).toEqual([
      "reset",
      "set_tokens",
      "set_fonts",
    ]);
    expect(brandPreviewCommands({ tokens: { a: "1" }, fonts: [] }).map((c) => c.verb)).toEqual(["set_tokens"]);
  });

  it("a token-only op preserves staged fonts", () => {
    setPendingFonts(["Inter"]);
    applyBrandPreviewOps({ tokens: { "brand-primary": "red" } });
    expect(getPendingFonts()).toEqual(["Inter"]);

    applyBrandPreviewOps({ commands: [{ verb: "set_tokens", args: { tokens: { "brand-accent": "blue" } } }] });
    expect(getPendingFonts()).toEqual(["Inter"]);
  });

  it("a historical card applies nothing", () => {
    expect(applyBrandPreviewOps({ tokens: { "brand-primary": "red" }, __historical: true })).toBeUndefined();
    expect(applyBrandPreviewOps({ commands: [{ verb: "reset", args: {} }], __historical: true })).toBeUndefined();
    expect(getBrandOverrides()).toEqual({});
  });

  it("an invalid commands payload leaves the draft unchanged", () => {
    setBrandOverride("brand-primary", "red");
    const result = applyBrandPreviewOps({ commands: [{ verb: "publish", args: {} } as unknown as BrandCommand] });
    expect(result).toMatchObject({ ok: false, code: "UNKNOWN_VERB" });
    expect(getBrandOverrides()).toEqual({ "brand-primary": "red" });
  });
});

describe("step_token", () => {
  it("moves one rung along the palette ramp, from the draft or the saved value", () => {
    setBrandRampContext(RAMPS);

    // Not overridden: starts from the saved value (blue-100).
    expect(brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: 1 } }]).ok).toBe(true);
    expect(getBrandOverrides()["brand-primary"]).toBe("var(--color-blue-200)");

    expect(brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: -1 } }]).ok).toBe(true);
    expect(getBrandOverrides()["brand-primary"]).toBe("var(--color-blue-100)");
  });

  it("refuses past either end with a structured failure and leaves the draft alone", () => {
    setBrandRampContext(RAMPS);
    setBrandOverride("brand-primary", "var(--color-blue-200)");

    const up = brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: 1 } }]);
    expect(up).toMatchObject({ ok: false, failedIndex: 0, verb: "step_token", code: "APPLY_FAILED" });
    expect(!up.ok && up.error).toMatch(/last step of the blue ramp/);

    setBrandOverride("brand-primary", "var(--color-blue-50)");
    const down = brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: -1 } }]);
    expect(!down.ok && down.error).toMatch(/first step of the blue ramp/);
    expect(getBrandOverrides()["brand-primary"]).toBe("var(--color-blue-50)");
  });

  it("refuses a token not on a ramp, an unloaded palette, and a bad step", () => {
    expect(brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: 1 } }])).toMatchObject({
      ok: false,
      code: "APPLY_FAILED",
    });
    setBrandRampContext(RAMPS);
    expect(brandCommands.execute([{ verb: "step_token", args: { token: "brand-accent", by: 1 } }])).toMatchObject({
      ok: false,
      code: "APPLY_FAILED",
    });
    expect(
      brandCommands.execute([{ verb: "step_token", args: { token: "brand-primary", by: 2 } } as unknown as BrandCommand]),
    ).toMatchObject({ ok: false, code: "INVALID_ARGS" });
    expect(getBrandDraft()).toEqual({ tokens: {}, fonts: null });
  });
});
