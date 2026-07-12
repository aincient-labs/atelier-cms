<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * The component & layout registry — the single source of truth for the names
 * the page grammar (and the page agent) may use, and the NAMING CONVENTION
 * that keeps them predictable.
 *
 * Sibling of DesignTokens: where DesignTokens is the catalogue the brand agent
 * reasons over, this is the catalogue the PAGE agent reasons over. PageStore
 * (the validator), PageSpikeController (the renderer) and PreviewPage (the
 * page-studio agent prompt) all read their vocabulary from HERE so the
 * allow-list, the renderer and the prompt can never drift apart.
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
 * The TIER is carried by this registry's grouping, NEVER by a name prefix
 * (apart from chrome's `site-*`), so names stay idiomatic.
 *
 * TWO RULES the agent depends on (both linted in ComponentCatalogTest):
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
 * sole PLACEABLE layout container (see {@see LAYOUT}); `card` is the child it
 * renders (never placed directly) and `stack` stays reserved (page-level
 * stacking is already implicit in the flat section list).
 */
final class ComponentCatalog {

  /**
   * SECTION components — the landing-page allow-list (the composition palette).
   *
   * The order is the rough top-to-bottom order the agent should compose in.
   * Each: 'use' (one-line selection hint for the agent) + 'props' (key => hint,
   * keys MUST be in PROP_VOCAB). A '|'-joined hint renders as an enum, a hint
   * starting '[' renders as a shape; '' renders the bare prop name.
   */
  public const SECTIONS = [
    'hero' => [
      'use' => 'Top-of-page opener; the only full-viewport section. Open every page with one, never stack two.',
      'props' => [
        'variant' => 'centered|split',
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'cta_label' => '', 'cta_url' => '',
        'secondary_label' => '', 'secondary_url' => '',
        'image' => '',
      ],
    ],
    'logos' => [
      'use' => 'A quiet row of partner/customer logos under the hero — instant "trusted by" credibility. One line of marks.',
      'props' => [
        'tone' => '',
        'heading' => '',
        'logos' => '[{name,image,url}]',
      ],
    ],
    'stats' => [
      'use' => 'A row of headline numbers; great social proof under a hero.',
      'props' => [
        'tone' => '',
        'items' => '[{value,label}]',
      ],
    ],
    'features' => [
      'use' => 'A heading plus a grid of feature cards. icon = ONE glyph/emoji (e.g. ✶ ◆ ⚡ 🚀). columns reflow the cards.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'columns' => '2|3',
        'features' => '[{icon,title,body}]',
      ],
    ],
    'content' => [
      'use' => 'A prose block paired with an image — explain one idea in depth. Alternate the image side (image-left/right) as you stack these; text-only drops the image.',
      'props' => [
        'variant' => 'image-right|image-left|text-only',
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'body' => '',
        'image' => '',
        'cta_label' => '', 'cta_url' => '',
      ],
    ],
    'markdown' => [
      'use' => 'A block of long-form body text authored in Markdown — headings, lists, links, bold/italic, blockquotes. Reach for it for free-form formatted copy that no structured section (hero/features/content/…) models. Rendered with a reading-optimised prose measure.',
      'props' => [
        'tone' => '',
        'markdown' => '',
      ],
    ],
    'gallery' => [
      'use' => 'A grid of images (screenshots, work, photos). columns reflows the grid.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'columns' => '2|3|4',
        'images' => '[{image,caption}]',
      ],
    ],
    'image' => [
      'use' => 'A single standalone image with an optional caption — a figure. Use to place ONE picture on its own (a screenshot, photo, diagram, chart) when no richer section fits — content pairs an image WITH text, gallery is a GRID of images. The caption shows beneath.',
      'props' => [
        'tone' => '',
        'image' => '',
        'caption' => '',
      ],
    ],
    'testimonials' => [
      'use' => 'Customer quotes — social proof in their own words. One strong quote, or a grid of several.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'columns' => '2|3',
        'quotes' => '[{quote,author,role,avatar}]',
      ],
    ],
    'team' => [
      'use' => 'The people behind the company — head-shots, names, roles. columns reflows the grid.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'columns' => '2|3|4',
        'members' => '[{name,role,bio,avatar}]',
      ],
    ],
    'pricing' => [
      'use' => 'Plan/price tiers side by side. Mark one tier featured to draw the eye. Follow with faq or cta.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'tiers' => '[{name,price,period,description,features,cta_label,cta_url,featured}]',
      ],
    ],
    'faq' => [
      'use' => 'Frequently-asked questions — answer objections before the closing cta. Plain-text answers only; for RICH answers (formatted copy, lists, links) use accordion.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'questions' => '[{question,answer}]',
      ],
    ],
    'accordion' => [
      'use' => 'A disclosure list whose panels hold RICH content, unlike faq\'s plain text. Each panel is a label + one or more content blocks. Use for layered detail: documentation, nested how-tos, spec breakdowns. Panels nest content blocks ONE level only — never a section or another container. exclusive = at most one panel open at a time. Allowed block components: markdown, image.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'exclusive' => '',
        'panels' => '[{label,open,blocks:[{component,props}]}]',
      ],
    ],
    'banner' => [
      'use' => 'A short, bold full-width statement band — one big sentence (a mission line or pull-quote). One idea, no list.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'cta_label' => '', 'cta_url' => '',
      ],
    ],
    'newsletter' => [
      'use' => 'An email sign-up band — capture subscribers. One field + one button.',
      'props' => [
        'tone' => '',
        'heading' => '', 'subheading' => '',
        'cta_label' => '',
        'placeholder' => '',
      ],
    ],
    'cta' => [
      'use' => 'Closing call-to-action band; usually tone "brand". Give the visitor one action — close every page with one.',
      'props' => [
        'tone' => '',
        'heading' => '', 'subheading' => '',
        'cta_label' => '', 'cta_url' => '',
      ],
    ],
    'divider' => [
      'use' => 'A light separator between sections — a rule, breathing space, or a small centered label. Use sparingly.',
      'props' => [
        'variant' => 'line|space|label',
        'tone' => '',
        'label' => '',
      ],
    ],
  ];

  /**
   * PLACEABLE LAYOUT containers — a distinct tier from SECTIONS but placed the
   * same way (the agent adds them as top-level blocks; PageStore/renderer treat
   * them via {@see placeableNames()}). Same def shape as SECTIONS.
   *
   * Only `grid` is placeable (the one bounded container we ratified). It carries
   * its `card` children INLINE as a `cards` array prop (exactly like `features`
   * carries feature cards), so the renderer never nests components — the flat
   * model holds. `card` and `stack` stay reserved words (see LAYOUT_RESERVED).
   */
  public const LAYOUT = [
    'grid' => [
      'use' => 'A bounded grid of equal "card" tiles — ONE level deep, never nested. Use for a homogeneous set that no named section fits. columns reflows the tiles.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'columns' => '2|3|4',
        'cards' => '[{title,body,icon,image,cta_label,cta_url}]',
      ],
    ],
  ];

  /**
   * REFERENCE placeables — sections whose body is another Drupal entity rather
   * than inline copy (Phase 4b/5). Distinct from SECTIONS (inline content) and
   * LAYOUT (containers), but placed the same way and gated through
   * {@see placeableNames()}. Same def shape as SECTIONS.
   *
   *  - `embed`  surfaces ANY existing entity (a page, an article, …) rendered in
   *    a chosen view mode, via {@see EntityEmbedResolver::render()}. Its `entity`
   *    prop holds an embed TOKEN (`entity:node:15@teaser` / `media:42`) resolved
   *    at render — CONTENT (a translation may embed its own entity; an empty
   *    overlay inherits the source token).
   *  - `block`  places a reusable GLOBAL BLOCK (a saved `aincient_block` fragment)
   *    by id; the renderer expands the block's own sections inline. Its `ref` prop
   *    is STRUCTURAL ({@see PageSchemaCodec::STRUCTURAL_PROPS}) — "which block goes
   *    here" is a shared layout decision; the block entity owns its per-language
   *    copy. Edit the block once, every page that references it updates.
   */
  public const REFERENCE = [
    'embed' => [
      'use' => 'Embed an existing piece of content (a page, an article, a media item) rendered in a chosen view mode. Use to surface REAL site content inside a composed page rather than re-typing it.',
      'props' => [
        'tone' => '',
        'eyebrow' => '', 'heading' => '', 'subheading' => '',
        'entity' => '',
      ],
    ],
    'block' => [
      'use' => 'Place a reusable GLOBAL BLOCK — a saved fragment (a shared CTA, banner, or footer note). Edit the block once and every page that uses it updates. Reach for it when the SAME content must appear on many pages.',
      'props' => [
        'ref' => '',
      ],
    ],
  ];

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
   * CHROME — the site wrapper on every page. Not agent-placeable per page
   * (the renderer wraps content in these). `site-*` is the one family prefix.
   */
  public const CHROME = ['site-header', 'site-footer'];

  /**
   * CONTENT atoms — composed INSIDE locked recipes / listings, never placed as
   * sections. The blog recipe arranges these; not in the landing allow-list.
   */
  public const CONTENT = ['article-header', 'prose', 'byline', 'article-teaser'];

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
   * Per-component `variant` enums (the first value is the clamp default). The
   * validator clamps an unknown/missing variant to the default so a hallucinated
   * value can never trip the SDC's required enum (a 500).
   */
  public const VARIANTS = [
    'hero' => ['centered', 'split'],
    'content' => ['image-right', 'image-left', 'text-only'],
    'divider' => ['line', 'space', 'label'],
  ];

  /**
   * Back-compat alias for the hero variant enum (consumed by the studio editor
   * manifest). Prefer {@see VARIANTS} for new code.
   */
  public const HERO_VARIANTS = self::VARIANTS['hero'];

  /**
   * The LOCKED prop vocabulary (Rule 2): every prop word, one canonical meaning,
   * always spelled in full. A section/component may only use props from here, so
   * the agent learns a prop once and knows it everywhere (`columns` is always a
   * column count; `tone` is always the surface/mood enum).
   */
  public const PROP_VOCAB = [
    'tone' => 'surface/mood enum: default | muted | brand | inverted.',
    'variant' => 'named arrangement of a component (enumerated per component).',
    'columns' => 'column count for a grid of fluid children (integer).',
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
    'cta_url' => 'primary action URL.',
    'secondary_label' => 'secondary action label.',
    'secondary_url' => 'secondary action URL.',
    'items' => 'repeatable rows: [{value,label}].',
    'features' => 'repeatable feature cards: [{icon,title,body}]. `body` accepts inline Markdown (links/bold/italic/code).',
    'logos' => 'repeatable logo marks: [{name,image,url}].',
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
   * The section allow-list (names only) — the canonical landing palette that
   * PageStore validates against and PageSpikeController renders.
   */
  public static function sectionNames(): array {
    return array_keys(self::SECTIONS);
  }

  /**
   * The placeable LAYOUT container names (grid).
   */
  public static function layoutNames(): array {
    return array_keys(self::LAYOUT);
  }

  /**
   * The reference placeable names (embed, block).
   */
  public static function referenceNames(): array {
    return array_keys(self::REFERENCE);
  }

  /**
   * Every component the page agent may PLACE as a top-level block: the section
   * palette, the placeable layout containers, and the reference placeables
   * (embed / block). This is the allow-list the validator (PageStore), the
   * renderer (PageSpikeController) and the agent's op grammar (PreviewPage) all
   * gate against.
   */
  public static function placeableNames(): array {
    return array_merge(self::sectionNames(), self::layoutNames(), self::referenceNames());
  }

  /**
   * The def (use + props) for any placeable name — section, layout container or
   * reference placeable.
   */
  public static function placeable(string $name): ?array {
    return self::SECTIONS[$name] ?? self::LAYOUT[$name] ?? self::REFERENCE[$name] ?? NULL;
  }

  /**
   * Every emitted identifier across all tiers — used by the uniqueness lint and
   * as the reserved-word set the grammar must not collide with.
   */
  public static function reservedNames(): array {
    return array_merge(
      self::sectionNames(),
      self::referenceNames(),
      self::CHROME,
      self::CONTENT,
      self::LAYOUT_RESERVED,
    );
  }

  /**
   * The Twig call the page agent's stored system prompt carries where the
   * component menu goes; the Prompt Template node renders it through the
   * `component_catalog()` Twig function ({@see Twig\ComponentCatalogExtension}),
   * inlining {@see self::manifest()} at render time. Keeping the token here —
   * beside the text that fills it — makes the menu a SINGLE source: the prompt
   * config can never hold a stale copy, because it holds no copy at all.
   */
  public const MANIFEST_TOKEN = '{{ component_catalog() }}';

  /**
   * The AI-facing component listing, injected into the page agent's system
   * prompt (sibling of DesignTokens::manifestSummary) in place of
   * {@see self::MANIFEST_TOKEN}. Reproduces the composition palette + the
   * placeable layout tier from this single source so the prompt can never drift
   * from the allow-list. Grammar/taste rules stay in the prompt; this is just
   * the menu.
   */
  public static function manifest(): string {
    $lines = ['LANDING sections (compose 3–6, in this rough order):'];
    foreach (self::SECTIONS as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . self::describeProps($def['props']);
    }
    $lines[] = '';
    $lines[] = 'LAYOUT containers (use only when no named section fits; never nest):';
    foreach (self::LAYOUT as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . self::describeProps($def['props']);
    }
    $lines[] = '';
    $lines[] = 'REFERENCE placeables (point at real content instead of typing it):';
    foreach (self::REFERENCE as $name => $def) {
      $lines[] = sprintf('- %s — %s', $name, $def['use']);
      $lines[] = '    props: ' . self::describeProps($def['props']);
    }
    return implode("\n", $lines);
  }

  /**
   * The compact prop signature for one placeable (section or layout container),
   * e.g. `tone(default|…), eyebrow, heading, quotes:[{quote,author,role,avatar}]`.
   * Empty string for an unknown name. Used by the schema linter to tell the
   * agent exactly which props a component takes.
   */
  public static function signature(string $name): string {
    $def = self::placeable($name);
    return $def ? self::describeProps($def['props']) : '';
  }

  /**
   * Format a def's props into the compact prompt notation: bare name, an
   * enum `tone(a|b|c)`, or a repeatable `items:[{…}]` shape.
   */
  private static function describeProps(array $props): string {
    $out = [];
    foreach ($props as $prop => $hint) {
      if ($hint === '') {
        $out[] = $prop === 'tone' ? 'tone(' . implode('|', self::TONES) . ')' : $prop;
      }
      elseif (str_starts_with($hint, '[')) {
        $out[] = $prop . ':' . $hint;
      }
      else {
        $out[] = $prop . '(' . $hint . ')';
      }
    }
    return implode(', ', $out);
  }

}
