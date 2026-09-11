<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * The grammar FLOOR — the shared, non-discoverable rules of the page grammar.
 *
 * The component palette itself (sections, layout containers, reference
 * placeables, their `use` hints and props) is DISCOVERED: each component's own
 * `.component.yml` carries it under `thirdPartySettings.atelier`, compiled by
 * {@see \Drupal\aincient_pages\Catalog\CatalogCompiler} into the
 * {@see \Drupal\aincient_pages\Catalog\EffectiveCatalog} one kind sees
 * (service `aincient_pages.catalog`, plans/byo-components.md W1). What stays
 * HERE is the floor every pack and every kind is subject to and none may relax:
 * the locked prop vocabulary, the reserved layout words, the tone enum, the
 * prop-flag sets the studio/renderer key off, and the naming convention.
 *
 * ── NAMING CONVENTION ───────────────────────────────────────────────────────
 * We do not invent names; we adopt the ecosystem conventions an LLM already
 * knows from pretraining (the same bet as the design-token convention), so the
 * agent's priors do the work:
 *   • SECTION names  → Tailwind Plus "Marketing › Page Sections" vocabulary
 *     (hero, features, stats, cta, pricing, testimonials, logos, faq, team …).
 *   • CONTENT atoms  → shadcn/Atomic-Design atom names (prose, byline, card …).
 *   • CHROME         → the only family prefix, `site-*` (site-header/footer),
 *     because "site-scoped, on every page" is a meaningful distinction.
 *   • LAYOUT         → the de-facto container vocabulary (Every Layout /
 *     WordPress core blocks): grid, stack, columns. RESERVED — see below.
 * The TIER is carried by the atelier metadata's `tier`, NEVER by a name prefix
 * (apart from chrome's `site-*`), so names stay idiomatic. A pack component
 * with no ecosystem prior MUST carry a strong `use` hint instead.
 *
 * TWO RULES the agent depends on (linted in ComponentCatalogTest and enforced
 * on packs by the admission gate):
 *   1. UNIQUE NAMES — one word, one concept. Every emitted component/layout
 *      NAME is globally unique and never reuses a reserved layout word. (That
 *      is why a "grid of features" is the section `features`, not `feature-grid`
 *      — `grid` belongs to the layout tier alone.) Idiomatic-first: when two
 *      ecosystem names would collide, specialise the component name; never coin
 *      an unfamiliar word.
 *   2. LAYOUT LIVES IN ONE PLACE — arrangement is a PROP on the container/
 *      section (columns, tone, variant), and children are layout-agnostic and
 *      fluid-fill. Switching a 2-col grid to 3-col is a single `columns` edit;
 *      the children reflow. PROPS are a small, SHARED, locked vocabulary
 *      (PROP_VOCAB): a prop word means the same thing on every component, and
 *      is always the full word (`columns`, never `col`). Reusing a prop word
 *      across components is the GOAL (consistency), not a collision — that rule
 *      is about NAMES.
 *
 * LAYOUT MODEL (ratified 2026-06-16): flat composition — full-width sections
 * stacked in order — plus ONE bounded `grid` container (one level deep, a
 * homogeneous set of `card` children, no further nesting). The general nesting
 * tier was deliberately NOT adopted: it would move "is this composition
 * designed?" from the curated grammar into the agent's judgement. `grid` is the
 * sole PLACEABLE layout container; `card` is the child it renders (never placed
 * directly) and `stack` stays reserved (page-level stacking is already implicit
 * in the flat section list).
 */
final class ComponentCatalog {

  /**
   * The bounded allow-list of CHILD components an `accordion` panel may hold —
   * the FIRST heterogeneous container, a deliberate + bounded extension of the
   * flat layout model (see GRAMMAR.md; DECISIONS 2026-06-24). It widens the
   * `grid`/`card` precedent from a HOMOGENEOUS set to a bounded HETEROGENEOUS
   * one, under three rules that keep "is this composition designed?" inside the
   * grammar (never the agent's judgement):
   *   1. ONE level deep — every member is a leaf/content primitive; a container
   *      (`accordion`, `grid`, `stack`) can NEVER be a child, so depth stays 1.
   *   2. Allow-listed — an unknown child is dropped (PageStore), exactly like an
   *      unknown section.
   *   3. Chrome-light — the renderer renders each child in its `bare` variant
   *      (no section band/padding), so every member MUST declare a `bare`
   *      variant in its SDC. The panel owns the surface + spacing.
   * Members: `markdown` (rich text, lists, links — the upgrade over faq's plain
   * text) + `image` (a single figure beneath/between the copy). Widen as more
   * block-friendly `bare` variants land (gallery/stats/logos).
   */
  public const ACCORDION_BLOCKS = ['markdown', 'image'];

  /**
   * LAYOUT containers — RESERVED vocabulary (Every Layout / WP core blocks).
   * Documented + reserved so the uniqueness lint protects these words. `grid`
   * is now PLACEABLE (see LAYOUT); `card` is the SDC `grid` renders per item;
   * `stack` is reserved for a future bounded container.
   */
  public const LAYOUT_RESERVED = ['grid', 'card', 'stack'];

  /**
   * Enumerations shared across the grammar.
   */
  public const TONES = ['default', 'muted', 'brand', 'inverted'];

  /**
   * The LOCKED prop vocabulary (Rule 2): every prop word, one canonical meaning,
   * always spelled in full. A section/component may only use props from here, so
   * the agent learns a prop once and knows it everywhere (`columns` is always a
   * column count; `tone` is always the surface/mood enum).
   */
  public const PROP_VOCAB = [
    'anchor' => 'optional in-page link target for the section: a lowercase slug (e.g. pricing), reachable from any link prop as #pricing. Every section accepts it.',
    'tone' => 'surface/mood enum: default | muted | brand | inverted.',
    'variant' => 'named arrangement of a component (enumerated per component).',
    'columns' => 'column count for a grid of fluid children (integer).',
    'mode' => 'collection listing mode: strip (a bounded preview, no filters, no JavaScript) | index (the full list page; at most one per page).',
    'source' => 'which content set a collection lists: blog.',
    'sort' => 'listing order: newest | oldest (by the post\'s authored date).',
    'limit' => 'the maximum number of tiles a strip-mode collection shows (integer).',
    'per_page' => 'how many tiles an index-mode collection shows before "Load more" (integer).',
    'eyebrow' => 'short kicker line above a heading.',
    'heading' => 'section heading.',
    'subheading' => 'supporting line under the heading. Accepts inline Markdown (links, **bold**, *italic*, `code`).',
    'body' => 'longer body copy — a paragraph or two of prose. Accepts inline Markdown (links, **bold**, *italic*, `code`).',
    'markdown' => 'long-form body content authored in Markdown (headings, lists, links, emphasis, blockquotes); rendered to formatted prose. Write Markdown, never raw HTML.',
    'label' => 'a short standalone text label.',
    'image' => 'a single image URL.',
    'caption' => 'a short text caption shown beneath an image. Accepts inline Markdown (links, **bold**, *italic*, `code`).',
    'entity' => 'an embed reference token to an existing entity, e.g. entity:node:15@teaser (or media:42); resolved + rendered at display. Find one with find_reference.',
    'ref' => 'a reference token to the reusable global block to place here, e.g. block:7. Find one with find_reference (types "block").',
    'placeholder' => 'placeholder text for an input field.',
    'cta_label' => 'primary action label.',
    'cta_url' => 'primary action target: an absolute URL (https://…), a site path (/pricing), or a page reference token (entity:node:15) resolved to that page\'s live URL at render — prefer the token when linking to a page on this site (it survives an alias change). Find one with find_reference.',
    'secondary_label' => 'secondary action label.',
    'secondary_url' => 'secondary action target — same forms as cta_url (absolute URL, site path, or an entity:node:<id> reference token).',
    'items' => 'repeatable rows: [{value,label}].',
    'features' => 'repeatable feature cards: [{icon,title,body}]. `body` accepts inline Markdown (links/bold/italic/code).',
    'logos' => 'repeatable logo marks: [{name,image,url}]. `url` accepts the same forms as cta_url (absolute URL, site path, or an entity:node:<id> reference token).',
    'images' => 'repeatable images: [{image,caption}].',
    'quotes' => 'repeatable testimonial quotes: [{quote,author,role,avatar}]. `quote` accepts inline Markdown (links/bold/italic/code).',
    'members' => 'repeatable people: [{name,role,bio,avatar}]. `bio` accepts inline Markdown (links/bold/italic/code).',
    'tiers' => 'repeatable pricing tiers: [{name,price,period,description,features,cta_label,cta_url,featured}]. `description` accepts inline Markdown (links/bold/italic/code).',
    'questions' => 'repeatable Q&A pairs: [{question,answer}]. `answer` accepts inline Markdown (links/bold/italic/code).',
    'cards' => 'repeatable tiles for a grid container: [{title,body,icon,image,cta_label,cta_url}]. `body` accepts inline Markdown (links/bold/italic/code).',
    'exclusive' => 'whether at most one panel may be open at a time (boolean).',
    'panels' => 'repeatable disclosure panels: [{label, open, blocks:[{component,props}]}].',
  ];

  /**
   * IMAGE-bearing prop / row-field names — props (and repeatable row fields)
   * whose value is a single image. The authoring path keys off this set: the
   * studio renders a MEDIA PICKER for these instead of a bare text input, and
   * the renderer maps them to a per-prop image style (see PageSpikeController).
   *
   * One canonical meaning per name (the PROP_VOCAB discipline): `image` is the
   * single-image word everywhere (hero/content props AND logos/gallery/card row
   * fields), `avatar` the people word (testimonials/team rows), `cover` the blog
   * lead image. A new image prop MUST reuse one of these words.
   */
  public const IMAGE_PROPS = ['image', 'avatar', 'cover'];

  /**
   * TRUE if a prop / row-field name holds a single image (see IMAGE_PROPS).
   */
  public static function isImageProp(string $name): bool {
    return in_array($name, self::IMAGE_PROPS, TRUE);
  }

  /**
   * LINK-bearing prop / row-field names — props (and repeatable row fields)
   * whose value is a navigation TARGET: an absolute URL, a site path, or a
   * reference token (`entity:node:<id>`) resolved to that page's live URL at
   * render ({@see EntityEmbedResolver::resolveLinks()}).
   *
   * The token form exists because nobody knows a URL before the page exists —
   * the same problem the menu editor solves with its URL｜Page picker, and the
   * studio renders the SAME control for these props (the `link` flag in the
   * editor manifest; see PageController::manifestEntry). A token also survives
   * an alias change, where a pasted path would rot.
   *
   * One canonical meaning per name (the PROP_VOCAB discipline): `cta_url` is the
   * primary action everywhere (hero/cta props AND card/tier row fields),
   * `secondary_url` the secondary one, `url` the bare link on a logo row. A new
   * link prop MUST reuse one of these words.
   */
  public const LINK_PROPS = ['cta_url', 'secondary_url', 'url'];

  /**
   * The LABEL prop each link prop is paired with — the other half of a button.
   *
   * The twigs gate the anchor on the LABEL (`{% if cta_label %}`), so a link
   * whose target became unresolvable would still render, pointing at `#`. The
   * renderer therefore drops the pair together. A link prop absent from this map
   * (a logo's `url`) has no label to drop and simply renders unlinked.
   */
  public const LINK_LABELS = [
    'cta_url' => 'cta_label',
    'secondary_url' => 'secondary_label',
  ];

  /**
   * TRUE if a prop / row-field name holds a link target (see LINK_PROPS).
   */
  public static function isLinkProp(string $name): bool {
    return in_array($name, self::LINK_PROPS, TRUE);
  }

  /**
   * MULTILINE prop names — props whose value is long-form text that should be
   * authored in a TEXTAREA, not a single-line input. The studio keys off this
   * set (see PageController::manifestEntry → the `multiline` flag → a <textarea>
   * in page-studio). `markdown` is the long-form Markdown source for the
   * `markdown` section; a new long-form prop word MUST be added here too.
   */
  public const MULTILINE_PROPS = ['markdown'];

  /**
   * TRUE if a prop holds long-form text (render a textarea — see MULTILINE_PROPS).
   */
  public static function isMultilineProp(string $name): bool {
    return in_array($name, self::MULTILINE_PROPS, TRUE);
  }

  /**
   * BOOLEAN prop names — props typed as a boolean in their SDC schema. The
   * studio keys off this set to render a CHECKBOX (not a text input), and the
   * validator coerces these to a real bool, so a typed string can never reach
   * the SDC's `type: boolean` prop (which 500s the render). A new boolean prop
   * word MUST be added here.
   */
  public const BOOLEAN_PROPS = ['exclusive'];

  /**
   * TRUE if a prop holds a boolean (render a checkbox — see BOOLEAN_PROPS).
   */
  public static function isBooleanProp(string $name): bool {
    return in_array($name, self::BOOLEAN_PROPS, TRUE);
  }

  /**
   * Components whose `variant` enum is RENDERER-INTERNAL — the chrome-light
   * `bare` mode (drop the section band) the renderer selects for a NESTED block,
   * never the author. It is therefore absent from the component's SECTIONS props
   * AND from the {@see VARIANTS} clamp map, so PageStore neither offers it to the
   * agent nor clamps it. But the SDC validates the enum BEFORE Twig's `|default()`
   * runs, and PageStore strips the (undeclared) prop, so an absent variant reaches
   * the SDC as "" and trips the enum (a 500). The renderer MUST therefore backfill
   * a concrete value: 'default' at top level (see PageSpikeController::component()),
   * 'bare' for a panel child (see renderPanels()). Same membership as
   * {@see ACCORDION_BLOCKS} today (rule 3 makes every panel child declare a `bare`
   * variant), but "has a renderer `bare` variant" and "is an allowed accordion
   * child" are distinct facts — kept as its own list.
   */
  public const RENDERER_VARIANT_COMPONENTS = ['markdown', 'image'];

  /**
   * PANEL (nested-container) prop names — props that carry a list of disclosure
   * panels, each holding its own bounded list of CHILD content blocks
   * ({@see ACCORDION_BLOCKS}). The shape is two levels deep
   * (`[{label,open,blocks:[{component,props}]}]`), so the studio's flat rows
   * editor can't represent it — the studio keys off this set to render a
   * dedicated nested panels editor (label + open + a per-block component picker
   * and props sub-form) instead. A new nested-panels prop word MUST be added
   * here.
   */
  public const PANEL_PROPS = ['panels'];

  /**
   * TRUE if a prop is a nested panels list (render the panels editor — see
   * PANEL_PROPS). Mutually exclusive with the flat `shape` rows editor.
   */
  public static function isPanelProp(string $name): bool {
    return in_array($name, self::PANEL_PROPS, TRUE);
  }

  /**
   * The Twig call the page agent's stored system prompt carries where the
   * component menu goes; the Prompt Template node renders it through the
   * `component_catalog()` Twig function ({@see Twig\ComponentCatalogExtension}),
   * inlining {@see self::manifest()} at render time. Keeping the token here —
   * beside the text that fills it — makes the menu a SINGLE source: the prompt
   * config can never hold a stale copy, because it holds no copy at all.
   *
   * Phase 5: the call is per-kind — `page_kind` rides in from the chat turn
   * (the studio draft's `type`) so each kind's prompt carries only its own
   * palette; `|default('landing')` keeps a fresh page working.
   */
  public const MANIFEST_TOKEN = "{{ component_catalog(page_kind|default('landing')) }}";

  /**
   * The Twig call that renders the PAGE KINDS list into the prompt (Phase 5) —
   * generated from the kind registry, so the agent never believes exactly two
   * kinds exist ({@see Twig\ComponentCatalogExtension::kinds()}).
   */
  public const KINDS_TOKEN = '{{ page_kinds() }}';

  /**
   * The LINK TARGETS note — how to fill a link prop, in the agent's prompt.
   *
   * The prompt carries prop NAMES, not {@see PROP_VOCAB} meanings (see
   * {@see describeProps()}), so without this the agent has no way to know a link
   * prop takes anything but a URL — and would invent `/get-started` for a page
   * that may not exist. Deliberately ONE fixed-size paragraph naming the three
   * accepted forms and the retrieval route: the site's page list must NEVER be
   * inlined here (it would grow without bound and be stale within a turn). The
   * bounded shortlist of real destinations is injected separately from the site's
   * own main menu ({@see SiteDestinations}).
   */
  public static function linkTargetNote(): string {
    return implode("\n", [
      'LINK TARGETS (' . implode(' / ', self::LINK_PROPS) . ') accept THREE forms:',
      '  - an absolute URL for an external site — https://example.com',
      '  - a path on this site — /pricing',
      '  - a page REFERENCE TOKEN — entity:node:15 — which resolves to that page\'s live',
      '    URL at render and keeps working if the page is later renamed or re-aliased.',
      'PREFER the token for anything on this site. Never guess a path: if the destination is',
      'not in SITE DESTINATIONS below, call find_reference (types "node") to get its token.',
      'If it genuinely does not exist, leave the link prop unset and say so — a button with an',
      'invented target is worse than no button (and one pointing at a deleted or unpublished',
      'page is dropped from the page entirely at render, label and all).',
    ]);
  }

  /**
   * The IN-PAGE ANCHORS note — how a link can jump to a section on the same page.
   *
   * `anchor` is a universal structural prop ({@see PageStore::clampProps}), so it
   * never appears in a placeable's declared prop list ({@see describeProps()});
   * without this paragraph the agent could not know it exists. Headings inside a
   * `markdown` section are anchored automatically ({@see MarkdownRenderer}).
   */
  public static function anchorNote(): string {
    return implode("\n", [
      'IN-PAGE ANCHORS: EVERY section additionally accepts `anchor` — a lowercase slug',
      '(letters, digits, hyphens; e.g. anchor: "pricing"). A link prop may then point at it as',
      '`#pricing` (same page) or `/plans#pricing` (another page). Set one only when something',
      'links to it (a "jump to" nav, a button to a lower section). Headings inside a `markdown`',
      'section get an id from their text automatically: "## Our team" is reachable as #our-team.',
    ]);
  }

}
