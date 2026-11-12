import { describe, expect, it } from "vitest";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

/**
 * The frames guard (0516, rules R1 and R6; audit M3). Structure comes from rules,
 * not boxes: outside the kit, a four-sided `border` or `outline` shorthand is
 * allowed only on something R6 lets draw a box. That means controls, overlays,
 * swatches and thumbnails, machine-text chips (Law 13), and preview plates.
 *
 * Every frame in console and studio CSS (outside `src/kit/`) must be listed in
 * KEEP with the R6 reason that allows it. The studios' frames became rules in M3,
 * and the chat transcript and onboarding cards followed (0518), so a new frame
 * fails until it is drawn with rules or listed here with its reason. A listed frame
 * that no longer exists fails too, so the lists can't go stale.
 *
 * `:focus` outlines and `border: 0 / none` are not frames. Keys are
 * `<file> <selector>`, with the console's files as `chat/…` and studios as
 * `<module>/…`.
 */

type Reason = "control" | "overlay" | "swatch" | "thumbnail" | "chip" | "plate" | "highlight";

const KEEP: Record<string, Reason> = {
  "chat/kit-gallery/gallery.css .ain-kitgallery__specimen": "plate",
  "chat/styles.css .ain-wipaction": "control",
  "chat/styles.css .ain-newchat": "control",
  "chat/styles.css .ain-tli": "control",
  "chat/styles.css .ain-crumb__trigger, .ain-crumb.ain-crumb--static": "control",
  "chat/styles.css .ain-usermenu__trigger": "control",
  "chat/styles.css .ain-usermenu__avatar": "thumbnail",
  "chat/styles.css .ain-usermenu__cardavatar": "thumbnail",
  "chat/styles.css .ain-newpage__type": "control",
  "chat/styles.css .ain-account__avatar": "thumbnail",
  "chat/styles.css .ain-suggestion": "control",
  "chat/styles.css .ain-msg[data-revealed] .ain-msg__col": "highlight",
  "chat/styles.css .ain-action": "control",
  "chat/styles.css .ain-bubble code": "chip",
  "chat/styles.css .ain-bubble pre": "chip",
  "chat/styles.css .ain-scrollbtn": "control",
  "chat/styles.css .ain-composer": "control",
  "chat/styles.css .ain-attach-chip": "control",
  "chat/styles.css .ain-remote-image": "thumbnail",
  "chat/styles.css .ain-nameinvite__input": "control",
  "chat/styles.css .ain-hitl__prompt code": "chip",
  "chat/styles.css .ain-hitl__option": "control",
  "chat/styles.css .ain-provfail__retry": "control",
  "chat/styles.css .ain-activity__pre": "chip",
  "chat/styles.css .ain-tour__video": "plate",
  "chat/styles.css .ain-onboard__input, .ain-onboard__select": "control",
  "chat/styles.css .ain-wiz__provider-badge": "thumbnail",
  "chat/styles.css .ain-wiz__card": "overlay",
  "chat/styles.css .ain-wiz__input, .ain-wiz__select": "control",
  "chat/styles.css .ain-wiz__segmented": "control",
  "chat/styles.css .ain-picker__trigger": "control",
  "chat/styles.css .ain-picker__list": "overlay",
  "chat/styles.css .ain-picker__mark": "control",
  "chat/styles.css .ain-field__swatch": "swatch",
  "chat/styles.css .ain-preview__pin": "control",
  "chat/styles.css .ain-preview__tag": "overlay",
  "chat/styles.css .ain-preview__handle": "control",
  "chat/styles.css .ain-media__thumb": "thumbnail",
  "chat/styles.css .ain-mediapick__cell": "thumbnail",
  "chat/styles.css .ain-mediapick__cell[data-current]": "highlight",
  "chat/styles.css .ain-endstate--overlay": "overlay",
  "chat/styles.css .ain-social": "plate",
  "chat/styles.css .ain-serp": "plate",
  "chat/styles.css .ain-serp__fav": "thumbnail",
  "chat/styles.css .ain-teasercard": "plate",
  "aincient_brand/ui/styles.css .ain-design-system-pick__swatch": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-pick__pop": "overlay",
  "aincient_brand/ui/styles.css .ain-design-system-pick__cell": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-pick__cell:hover": "highlight",
  "aincient_brand/ui/styles.css .ain-design-system-pick__preset": "control",
  "aincient_brand/ui/styles.css .ain-design-system-pick__presetswatches span": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-dirpad__btn": "control",
  "aincient_brand/ui/styles.css .ain-design-system-swatchbtn": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-pop": "overlay",
  "aincient_brand/ui/styles.css .ain-design-system-pop__close": "control",
  "aincient_brand/ui/styles.css .ain-design-system-pop__currentsw": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-sw": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-sw--on": "highlight",
  "aincient_brand/ui/styles.css .ain-design-system-pair__tile": "swatch",
  "aincient_brand/ui/styles.css .ain-design-system-fontpick__btn": "control",
  "aincient_brand/ui/styles.css .ain-design-system-prev--radius": "plate",
  "aincient_brand/ui/styles.css .ain-design-system-prev--shadow": "plate",
  "aincient_studio_content/ui/styles.css .ain-content-insert__btn": "control",
  "aincient_studio_content/ui/styles.css .ain-content-picker": "overlay",
  "aincient_studio_media/ui/styles.css .ain-library__thumb": "thumbnail",
  "aincient_studio_media/ui/styles.css .ain-media-preview__img": "plate",
  "aincient_studio_media/ui/styles.css .ain-media-preview__fact code": "chip",
  "aincient_studio_media/ui/styles.css .ain-media-result__img": "thumbnail",
  "aincient_studio_media/ui/styles.css .ain-media-result__fact code": "chip",
  "aincient_studio_site/ui/styles.css .ain-globals-menu__add": "control",
  "aincient_studio_components/ui/styles.css .ain-components-preview__window": "plate",
};


const FRAME = /(?<![-\w])(border|outline)\s*:\s*([^;}]+)/g;
const NOT_A_FRAME = /^\s*(0|0px|none|0 none|none 0)\s*(!important)?\s*$/;

/** Selectors of rules that draw a four-sided frame (comments stripped, @media flattened). */
export function frameSelectors(css: string): string[] {
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, "");
  const out: string[] = [];
  for (const m of clean.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = m[1].trim().replace(/\s+/g, " ");
    for (const d of m[2].matchAll(FRAME)) {
      if (NOT_A_FRAME.test(d[2])) continue;
      if (d[1] === "outline" && /:focus/.test(selector)) continue;
      out.push(selector);
    }
  }
  return out;
}

describe("the frame detector", () => {
  it("finds four-sided borders and outlines, not rules, resets or focus rings", () => {
    expect(frameSelectors(".a { border: 1px solid red; } .b { border-top: 1px solid red; }")).toEqual([".a"]);
    expect(frameSelectors(".a { border: 0; } .b { border: none !important; }")).toEqual([]);
    expect(frameSelectors(".a:focus-visible { outline: 2px solid red; } .b[data-on] { outline: 2px solid red; }")).toEqual([".b[data-on]"]);
    expect(frameSelectors("@media (min-width: 1px) { .x { border: 1px solid red; } } /* .y { border: 1px solid } */")).toEqual([".x"]);
  });
});

const SRC = import.meta.dirname;
const STUDIO_TIER = join(SRC, "../../../../studio");

function cssFiles(dir: string): string[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return ["node_modules", "dist", "kit"].includes(entry) ? [] : cssFiles(full);
    return entry.endsWith(".css") && !entry.includes("generated") ? [full] : [];
  });
}

function found(): string[] {
  const console = cssFiles(SRC).map((f) => [f, `chat/${relative(SRC, f)}`]);
  const studios = existsSync(STUDIO_TIER)
    ? readdirSync(STUDIO_TIER).flatMap((m) => cssFiles(join(STUDIO_TIER, m, "ui")).map((f) => [f, relative(STUDIO_TIER, f)]))
    : [];
  return [...console, ...studios].flatMap(([file, key]) =>
    frameSelectors(readFileSync(file, "utf8")).map((sel) => `${key} ${sel}`),
  );
}

describe("frames on the real tree", () => {
  const all = found();
  const listed = new Set(Object.keys(KEEP));

  it("every frame outside the kit is listed in KEEP with its R6 reason", () => {
    const unlisted = all.filter((k) => !listed.has(k));
    expect(unlisted, `New frame — draw it with rules (--ain-rule-block / --ain-rule-row), or list it with its R6 reason:\n${unlisted.join("\n")}`).toEqual([]);
  });

  it("no listed frame is stale", () => {
    const present = new Set(all);
    const stale = [...listed].filter((k) => !present.has(k));
    expect(stale, `Remove from the frames lists:\n${stale.join("\n")}`).toEqual([]);
  });

  it("each frame is listed once", () => {
    const keys = Object.keys(KEEP);
    expect(keys.length).toBe(new Set(keys).size);
  });
});
