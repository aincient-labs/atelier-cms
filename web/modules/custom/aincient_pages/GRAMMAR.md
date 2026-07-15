# AIncient page grammar — how the agent composes beautiful pages

This is the **moat**: the rules that turn "make me a page" into something that
looks designed, every time. The agent never writes CSS or HTML — it emits a
**page-schema (JSON)**, and these rules + the SDC `.component.yml` enums make any
valid composition look good.

## The page-schema (the agent's output contract)

```jsonc
{
  "type": "landing" | "blog",
  // landing:
  "sections": [ { "component": "...", "props": { ... } } ]
  // blog:
  "title": "...", "lead": "...", "author": "...", "body_md": "# Markdown source…"
}
```

`PageSpikeController` renders this to SDC components. The schema *is* the API; the
component schemas are the validation; this file is the taste.

## Two regimes

### Blog — a LOCKED recipe (consistency by construction)
The agent supplies **content only**. Layout is fixed and not negotiable:

```
article-header (eyebrow←category, title, lead, author, date, cover)
prose          (the body — reading-optimised measure + type scale)
byline         (author card ← author, author_bio)
```

There is no `sections` array for a blog. Every post looks coherent because the
*template* owns the layout, not the model. The agent's freedom = words + a cover.

Write the body as **Markdown** in `body_md` (headings `##`/`###`, `**bold**`,
`*italic*`, `` `code` ``, lists, `> quotes`, links). It is compiled to sanitised
HTML and rendered through the branded `prose` component — so author the *content*,
never HTML or inline styles. Raw HTML in the source is escaped, not rendered.

### Landing — a composition GRAMMAR (bounded creativity)
The agent arranges a `sections` list from the **allow-list** (unknown components
are dropped). Each section is individually beautiful and constrained to
enumerated `variant`/`tone` values, so "expressive" can't become "ugly".

Section palette: `hero` · `logos` · `stats` · `features` · `content` ·
`gallery` · `image` · `testimonials` · `team` · `pricing` · `faq` · `accordion` ·
`banner` · `newsletter` · `cta` · `divider`, plus the `grid` layout container. The set
covers blogs, company/marketing sites and news sites without bespoke components;
`ComponentCatalog::manifest()` is the live, authoritative menu.

Rules the renderer enforces or the agent should follow:
1. **Open with a `hero`.** It's the only full-viewport opener.
2. **Close with a `cta`** (usually `tone: brand`) — give the visitor one action.
3. **Vary tone for rhythm.** Adjacent sections shouldn't share a tone; the
   renderer auto-alternates `default`/`muted` for sections that don't pin one.
   Use `inverted`/`brand` sparingly as punctuation (1–2 per page).
4. **Never stack two heroes.**
5. **3–6 sections** is the sweet spot for a landing page.
6. **Reach for `grid` only when no named section fits** — a homogeneous set of
   `card` tiles (it's the one bounded container; see the layout model below).

Tones (every section accepts one): `default` · `muted` · `brand` · `inverted`.

## Brand is decoupled from structure (and pages never override it)
A page carries **no colour/font scheme of its own**. The look comes entirely from
the site's **brand tokens** (CSS variables), set once in Brand studio and injected
at render time — so the **same components and the same composition** reskin the
whole site when the brand changes, with zero component edits. Pages must never
hardcode a palette: a page that imposed its own colours would disrespect the
brand the user established. (A page-level `preset` brand-override lever existed
once and was removed 2026-06-16 for exactly this reason.) A future *page template*
library will let users reuse a page's **structure**, never its brand.

## Why this gets to "stunning with no effort"
Curated components (each looks great) × enumerated variants (every choice is a
good one) × a grammar (good arrangement) × the live brand tokens (cohesion) =
**any composition the agent produces is designed.** That's the bet for 1M users.

## Component & layout naming convention
The names above aren't ad-hoc — they follow a convention so the agent can pick
them from its pretraining priors (the same bet as the design-token convention),
and so the component library can grow predictably. The machine-readable source
of truth is `src/ComponentCatalog.php`; the rules:

**Adopt ecosystem vocabulary, don't invent.** Names come from conventions an LLM
already knows:
- **Sections** (the landing palette) → Tailwind Plus *Marketing › Page Sections*
  names: `hero`, `features`, `stats`, `cta`, `pricing`, `testimonials`, `logos`,
  `faq`, `team`, `gallery`, `content`, `banner`, `newsletter`, `divider`. Bare
  idiomatic nouns. (We use the short ecosystem noun `logos`, not `logo-cloud`.)
- **Content atoms** (composed inside locked recipes) → shadcn/Atomic-Design atom
  names: `prose`, `byline`, `article-header`, `article-teaser`, `card`.
- **Chrome** (site wrapper, every page) → the one family prefix `site-*`
  (`site-header`, `site-footer`), because "site-scoped" is a real distinction.
- **Layout** (containers) → the de-facto container vocabulary (Every Layout /
  WordPress core blocks): `grid`, `stack`, `columns`.

The **tier is carried by the registry's grouping, never a name prefix** (apart
from chrome's `site-*`) — so names stay idiomatic.

**Two rules the agent depends on** (both linted in `ComponentCatalogTest`):
1. **Unique names — one word, one concept.** Every component/layout name is
   globally unique and never reuses a reserved layout word. This is why a grid
   of features is the section **`features`**, not `feature-grid` (`grid` belongs
   to the layout tier alone). Idiomatic-first: when two ecosystem names would
   collide, specialise the component name — never coin an unfamiliar word.
2. **Layout lives in one place.** Arrangement is a **prop** on the
   container/section (`columns`, `tone`, `variant`); children are layout-agnostic
   and fluid-fill. Switching a 2-col grid to 3-col is a single `columns` edit and
   the children reflow — no per-child layout flags. Props are a small, **shared,
   locked vocabulary** (`ComponentCatalog::PROP_VOCAB`): a prop word means the
   same thing on every component and is always the full word (`columns`, never
   `col`). Reusing a prop word across components is the *goal* (consistency); the
   uniqueness rule is about names, not props.

### Layout model — flat + one bounded `grid` (ratified 2026-06-16)
Composition is **flat**: full-width sections stacked in order. Layout is
expressed by (a) the page regime (landing/blog), (b) section order, and (c)
enumerated layout props on each section — *not* by nesting containers. We
deliberately did **not** adopt a general nesting tier: nesting moves "is this
composition designed?" from the curated grammar into the agent's judgement,
which is the one guarantee we're protecting.

The single exception is **one bounded `grid` container**: one level deep, holding
a homogeneous set of `card` children, with no further nesting — enough to express
"a grid of N cards" without a `*-grid` component per content type, while keeping
the validity space small and responsive trivial. `grid` is now a **placeable
layout container** (`ComponentCatalog::LAYOUT`, a distinct tier from sections but
placed the same way); it carries its `card` children INLINE as a `cards` array
prop (exactly like `features` carries feature cards), so the renderer never nests
components and the flat model holds. `card` is the SDC `grid` renders per item
(never placed directly); `stack` stays reserved for a future bounded container.
The whole vocabulary (`grid`, `card`, `stack`) remains protected by the
uniqueness lint.

### One bounded HETEROGENEOUS container — `accordion` (extended 2026-06-24)
The same bounded-container reasoning admits a second, narrow exception: the
`accordion` section, whose panels hold a small **heterogeneous** set of content
blocks (where `grid` holds homogeneous cards). It is the answer to "the `faq`
section is plain-text only" — an accordion panel can carry rich, composed content.
It stays inside the moat by the **same three rules** that bound `grid`, plus an
explicit child allow-list:
1. **One level deep.** A panel child is always a leaf/content primitive; a
   container (`accordion`, `grid`, `stack`) can never be a child, so depth = 1.
2. **Allow-listed children** (`ComponentCatalog::ACCORDION_BLOCKS`): an unknown or
   container child is dropped by `PageStore`, exactly like an unknown section.
3. **Chrome-light children.** The renderer renders each child in its `bare`
   variant (no section band/padding) — the panel owns the surface + spacing — so
   every allow-listed child must declare a `bare` variant.
Panels are carried INLINE as a `panels` prop (`[{label, open, blocks}]`, like
`grid.cards`); the renderer flattens each panel's blocks to a `body_html` string
(an SDC slot can't nest per array row — the `ROW_PICTURES` precedent), so the
renderer still never nests SDC components and the flat model holds. Allowed
blocks: **`markdown`** (rich text) and **`image`** (a single figure); widen
`ACCORDION_BLOCKS` further as block-friendly `bare` variants land. `faq` stays
the plain-text shorthand; `accordion` is the rich composable sibling.
