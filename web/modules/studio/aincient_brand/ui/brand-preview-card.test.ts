import { describe, expect, it } from "vitest";
import { brandPreviewCardText } from "./brand-preview-card";

/** The card counts a typed `commands` payload the way it counts the legacy one (plans/studio-commands.md P1). */
describe("brandPreviewCardText over commands", () => {
  it("labels a commands payload like the equivalent legacy payload", () => {
    const legacy = brandPreviewCardText({ tokens: { a: "1", b: "2" }, fonts: ["Inter"] });
    const typed = brandPreviewCardText({
      commands: [
        { verb: "set_tokens", args: { tokens: { a: "1", b: "2" } } },
        { verb: "set_fonts", args: { fonts: ["Inter"] } },
      ],
    });
    expect(typed).toEqual(legacy);
    expect(typed.label).toBe("Applied to preview · 3 changes");
  });

  it("labels a commands reset as a revert", () => {
    expect(brandPreviewCardText({ commands: [{ verb: "reset", args: {} }] }).label).toBe(
      "Reverted the preview to the saved brand",
    );
  });
});
