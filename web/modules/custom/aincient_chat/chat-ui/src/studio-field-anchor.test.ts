// @vitest-environment jsdom
import { describe, expect, it, beforeEach } from "vitest";
import {
  FIELD_ANCHOR_PARAM,
  fieldAnchorId,
  focusStudioField,
  requestedFieldAnchor,
} from "./studio-field-anchor";

/**
 * The per-field deep-link anchor scheme (DECISIONS 0372) — the durable contract
 * Phase 3 leans on to route "use this as my logo" → Identity → Logo.
 */
describe("studio-field-anchor", () => {
  it("maps a namespaced key to a stable, selector-safe id", () => {
    expect(fieldAnchorId("identity.logo")).toBe("ain-fa--identity-logo");
    expect(fieldAnchorId("site.page_404")).toBe("ain-fa--site-page-404");
    // Idempotent shape: dots and underscores both collapse to hyphens.
    expect(fieldAnchorId("chrome.header.logo_size")).toBe("ain-fa--chrome-header-logo-size");
  });

  it("reads the requested field off the ?field= query axis", () => {
    expect(requestedFieldAnchor(`?${FIELD_ANCHOR_PARAM}=identity.logo`)).toBe("identity.logo");
    expect(requestedFieldAnchor("?thr=7")).toBeNull();
    expect(requestedFieldAnchor("")).toBeNull();
  });

  describe("focusStudioField", () => {
    beforeEach(() => {
      document.body.innerHTML = "";
    });

    it("finds an anchored field and reports a hit", () => {
      const wrap = document.createElement("div");
      wrap.id = fieldAnchorId("identity.logo");
      const input = document.createElement("input");
      wrap.appendChild(input);
      document.body.appendChild(wrap);
      expect(focusStudioField("identity.logo", document)).toBe(true);
    });

    it("is a no-op miss when the field isn't mounted yet", () => {
      expect(focusStudioField("identity.logo", document)).toBe(false);
    });
  });
});
