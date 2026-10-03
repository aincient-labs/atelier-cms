/**
 * `@console/kit` — the console component library every studio imports
 * (DECISIONS 0430; plans/studio-modules.md, "`kit/` — the console component
 * library").
 *
 * WHAT IT IS: the primitives and the studio layout a studio is built from, styled
 * with system tokens only (a studio never ships its own colours or spacing), with
 * Radix underneath when a primitive needs real behaviour (Popover, Menu,
 * Dialog, Tabs — `radix-ui` is a kit-only dependency: the fence refuses it
 * anywhere else, the way assistant-ui is refused outside `aui/`). Each primitive
 * is a `<name>.tsx` + `<name>.css` pair; kit.css loads the CSS ahead of
 * styles.css. The gallery at /atelier/dev/kit shows every one in both
 * modes. A studio imports it as `@console/kit` and nothing else of the console's visual layer — that single name
 * is what the import fence (`../studio-fence.ts`) allows.
 *
 * EXTRACTED, NOT DESIGNED: every primitive here already existed as a CSS
 * family the console and studios wrote by hand (`.ain-btn`, `.ain-field`,
 * `.ain-seg`, `.ain-menu`, `.ain-confirm`, …). Its rules moved out of styles.css
 * unchanged and the component emits those same classes, so hand-written markup
 * and the component render identically while call sites move over one at a
 * time. A primitive no existing screen needs is not added here ahead of its
 * first user.
 *
 * TWO WAYS IN: console internals keep importing the files directly
 * (`./kit/icons`) — they are not behind the studio fence and gain nothing from a
 * barrel that would pull every primitive into each import graph — while studios use
 * this barrel. The kit may import only from itself, `react`, and the few console
 * back-references frozen in `KIT_BACKREFS_ALLOWED` (`../studio-fence.ts`); that
 * list is meant to shrink as each primitive is cut loose, never to grow.
 */
// ORDER MATTERS. The barrel is on an import cycle: `data-table` reaches back
// into console internals (KIT_BACKREFS_ALLOWED) which reach the generated
// studio registry, which imports every studio's `ui/index.tsx`, which imports
// THIS barrel. When a test (or any graph) enters through the barrel, a studio
// module evaluates while the barrel is still in progress and sees only what was
// exported BEFORE the edge that led there. So the self-contained pieces come
// first and `data-table` — the one with back-references — last: a studio's
// top-level `makeSafeAssistantToolUI(...)` then always finds its factory.
export * from "./icons";
export * from "./cx";
export * from "./button";
export * from "./field";
export * from "./chip";
export * from "./segmented";
export * from "./skeleton";
export * from "./notice";
export * from "./empty-state";
export * from "./loading-state";
export * from "./studio-group";
export * from "./studio-status";
export * from "./portal";
export * from "./popover";
export * from "./menu";
export * from "./dialog";
export * from "./filter-select";
export * from "./tabs";
export * from "./error-boundary";
export * from "./studio-ui";
export * from "./panel-bar";
export * from "./field-revert";
export * from "./reference-field";
export * from "./link-field";
export * from "./data-table";
