# aincient_pages — agent notes (Rift responsive images)

Scoped to the **responsive-image integration** (token → image → Rift). The page
*grammar* (how the agent composes a page) lives in `GRAMMAR.md`; this file is the
render-layer mechanics + the traps that are easy to re-derive wrongly. Pairs with
the DECISIONS 2026-06-23 entries in the umbrella process repo.

## The shape of it

Page content stores images as opaque **tokens** (`media:<id>`), never URLs. The
agent only ever picks a token — it has **no idea Rift exists**. The render layer
(`PageSpikeController` + `EntityEmbedResolver`) turns a token into a responsive
image. Changing how images render is invisible to the agent, the page-schema, the
validator, `find_reference`, and the studio picker. Keep it that way: this is a
render-layer concern, not a content-layer one.

Two render modes, chosen deterministically by `(component, prop)` — never by the
agent (no art-direction choice; that's deliberately deferred):

| Surface | Mechanism | Output | Why |
|---|---|---|---|
| **Single images** — `hero.image`, `content.image`, `article-header.cover` | SDC **slot** holding a Rift render array | real `<picture>` (srcset, webp, alt, SEO) | width ≈ viewport; semantic `<img>` matters |
| **Repeatables** — `grid.cards[].image`, `gallery.images[].image` | **pre-rendered HTML** in a sibling `<key>_html` field | container-query `<figure>` (sized by its box, not the viewport) | a grid/gallery cell is never 100vw; slots can't nest per array row |
| **Decorative** — `logos[].image`, `testimonials`/`team` `avatar` | plain core image style (`IMAGE_STYLES`) | `<img src>` | small, fixed-size; Rift buys nothing (Phase 4 skip — by design, not missed) |

## The deterministic maps (all in `PageSpikeController`)

- **`VIEW_MODES`** — `[component][prop] => viewMode`. PRESENCE is the switch that
  lifts a single-image prop OUT of the typed string props into an SDC `#slot`
  rendered as a Rift `<picture>`. Add a line here to convert a single image.
- **`ROW_PICTURES`** — `[component => ['rows', 'image', 'view_mode']]`. The
  repeatable equivalent: `rowPictures()` walks the row array, resolves each cell's
  token to a **container-query** picture, and stores the markup in `<image>_html`.
- **`CONTAINER_QUERY`** — `[component][prop] => bool` for the SLOT path only
  (currently empty; the only CQ consumers are repeatables, which use
  `ROW_PICTURES`). A single-image slot whose width ≈ viewport stays on `<picture>`.
- **`IMAGE_STYLES`** — the plain-`<img>` URL path: the fallback when Rift is
  absent, AND the final home for the decorative skip (logos/avatars).

View-mode config (aspect ratio, sizes, breakpoints, formats, `fallback_transform`)
lives in `config/sync/rift.settings.yml` under `view_modes:`. The six modes
(`hero` 4x3, `cover` 2x1, `content` 4x3, `gallery` 4x3, `card` 16x9, `avatar` 1x1)
match the component slots.

## Wiring a NEW image slot

A **single** image (renders as `<picture>`):
1. Add a `view_modes:` entry to `rift.settings.yml` (or reuse one) + `drush cim`.
2. `VIEW_MODES['<component>']['<prop>'] = '<viewMode>'` in `PageSpikeController`.
3. In the twig, replace `<img src="{{ <prop> }}">` with a `.ain-img` wrapper around
   a same-named block: `<div class="ain-img …aspect-…">{% block <prop> %}{{ <prop> }}{% endblock %}</div>`.
   The `.ain-img` wrapper owns layout (radius/shadow/ring/aspect); the `<picture>`
   fills it.
4. In the `.component.yml`, move `<prop>` from `props:` to `slots:`; drop any
   `<prop>_alt` prop (alt rides inside the picture, from the Media entity).

A **repeatable** cell (renders as a container query): add a `ROW_PICTURES` entry
and make the twig prefer `{{ row.<image>_html|raw }}`, falling back to the existing
`<img src="{{ row.<image> }}">`. Do NOT turn the row array into a slot — slots are
flat top-level regions and can't nest per row; that would also break the agent
contract (the `cards`/`images` prop arrays must stay byte-identical).

## Gotchas (each one cost real time)

- **`fallback_transform: rift_fallback` is BROKEN.** Rift ships that default but
  `rift_fallback` isn't a real image style (no width/height → the combined-image-
  style source builds an empty derivative → `styles//public/...`, a broken `<img>`).
  Point each view mode's `fallback_transform` at an aspect-matched **dimension**
  combined-style at the largest 1x breakpoint: `1280w960h-webp-80` (4x3),
  `1280w640h-webp-80` (2x1), `1280w720h-webp-80` (16x9), `512w512h-webp-80` (1x1).
- **Use dimension styles, NOT aspect-ratio styles, for the fallback.**
  `4x3-webp-80` does NOT work: the `4x3` crop style is `crop_crop`, a no-op without
  a manually-placed crop (verified — returns the full-size original at the wrong
  ratio). The `WxHh` dimension styles use `focal_point_scale_and_crop` (default
  center) so they downsize + crop any image.
- **Auto-generated `WxHh` image styles are RUNTIME artifacts.** Rift creates them
  on demand. They appear in `drush cex` once referenced — do **not** commit them to
  `config/sync` (revert those files). `rift.settings.yml` itself IS committed and
  exports cleanly.
- **The bespoke page shell does NOT auto-attach `#attached` libraries.**
  `PageSpikeController::shell()` hand-builds `<head>` and only inlines
  `aincient-pages.css`, and CQ images are rendered with `renderInIsolation`
  (drops `#attached`). So the `rift_container_query` base CSS won't auto-inject —
  the 2 base rules (`.rift-cq-image-container`/`.rift-cq-image`) are folded into
  `build/input.css` (rebuild → `assets/aincient-pages.css`). The per-image
  `@container` CSS rides inline in the CQ markup, so it's fine.
- **Rift is a SOFT integration.** `EntityEmbedResolver` injects the rift view-modes
  manager as `@?` (null without rift); `picture()` returns NULL, and `component()`
  falls back to a plain `<img>` slot. Composition never requires rift; kernel tests
  stay light. Don't turn this into a hard dependency.
- **Derivative URLs are relative** (fixed upstream in `drupal/rift` 2.x, 2026-06-23):
  the `combined_image_style` source plugin wraps its URL in
  `FileUrlGenerator::transformRelative()`. If you ever see absolute
  `https://host/sites/...` in srcset/`background-image`, the rift pin regressed.

## Verifying

`drush php:script` a schema through `PageSpikeController::renderSchema()` and grep
the HTML: single images → `<picture>` with N `<source>`s + webp; repeatables →
`rift-cq-image-container` + inline `@container` rules at the right `aspect-ratio`;
raw-URL spike briefs (`/spike/blog`, `/spike/showcase`) must still fall back to
plain `<img>`. Then fetch one derivative URL (expect 200, `image/webp`).
