---
name: rift-responsive-images
description: Wire an aincient_pages component image to render responsively through Rift (a <picture> for single images, or a container-query element for grid/gallery cells). Use when adding a new image prop to a page component, converting an existing <img src> to the responsive pipeline, or adjusting the (component,prop) → view-mode mapping. Covers the rift.settings model and the known fallback/CSS/soft-integration traps.
---

# Wire a component image through Rift

The `aincient_pages` page components render images responsively through
`drupal/rift`. Page content stores an opaque `media:<id>` token; the render layer
turns it into a `<picture>` (single images) or a container-query element
(grid/gallery cells). **The agent never sees Rift** — this is a render-layer change
only. Do not touch the page-schema, the validator, `find_reference`, or the studio
picker.

Full mechanics + traps: `web/modules/custom/aincient_pages/AGENTS.md`. This skill is
the procedure.

## Step 0: single image or repeatable cell?

Decide which path before editing — they use different mechanisms.

- **Single image** (`hero.image`, `content.image`, `article-header.cover`): width ≈
  viewport, wants a semantic `<img>` → renders as a Rift **`<picture>`** via an SDC
  **slot**.
- **Repeatable cell** (`grid.cards[].image`, `gallery.images[].image`): a cell is
  never 100vw, and SDC slots can't nest per array row → renders as a **container
  query**, pre-rendered to HTML in a sibling `<key>_html` field.
- **Decorative** (logos, avatars): small/fixed-size — **leave it on a plain image
  style** (`IMAGE_STYLES`). Don't put it through Rift. This is a deliberate skip.

Ask the user if it's genuinely ambiguous; otherwise pick by the rule above.

## Step 1: ensure a view mode exists

`config/sync/rift.settings.yml` → `view_modes:` holds the aspect ratio, sizes,
breakpoints, formats, and `fallback_transform` per mode. Reuse an existing one if
the aspect matches (`hero`/`content`/`gallery` 4x3, `cover` 2x1, `card` 16x9,
`avatar` 1x1), or add a new one. If you add one:

- Set `fallback_transform` to an aspect-matched **dimension** combined-style at the
  largest 1x breakpoint: `1280w960h-webp-80` (4x3), `1280w640h-webp-80` (2x1),
  `1280w720h-webp-80` (16x9), `512w512h-webp-80` (1x1).
- **Never** use an aspect-ratio style (`4x3-webp-80`) as the fallback — the `4x3`
  crop is `crop_crop`, a no-op without a manual crop. Dimension styles use
  `focal_point_scale_and_crop` and actually downsize+crop.
- `drush cim`, then later `drush cex` and **revert** any auto-generated `WxHh`
  image-style files it tries to add — those are runtime artifacts, not config.

## Step 2a: single image → SDC slot + `<picture>`

1. **`PageSpikeController::VIEW_MODES`** — add `'<component>' => ['<prop>' => '<viewMode>']`.
   Presence here is the switch.
2. **The twig** — replace the `<img>` with a `.ain-img` wrapper around a same-named
   block (keep the layout classes the `<img>` carried — radius/shadow/ring/aspect):
   ```twig
   <div class="ain-img w-full rounded-[var(--card-radius)] overflow-hidden aspect-[4/3]">{% block <prop> %}{{ <prop> }}{% endblock %}</div>
   ```
3. **The `.component.yml`** — move `<prop>` from `props:` to `slots:`; delete any
   `<prop>_alt` prop (alt comes from the Media entity, inside the picture).

## Step 2b: repeatable cell → container query

1. **`PageSpikeController::ROW_PICTURES`** — add
   `'<component>' => ['rows' => '<arrayProp>', 'image' => '<keyInRow>', 'view_mode' => '<viewMode>']`.
   `rowPictures()` will resolve each cell's token to a CQ picture (it passes
   `enable_container_queries`) and store the markup in `<keyInRow>_html`.
2. **The twig** — prefer the pre-rendered HTML, fall back to the plain `<img>`:
   ```twig
   {% if row.<image>_html %}{{ row.<image>_html|raw }}{% elseif row.<image> %}<img src="{{ row.<image> }}" alt="…" class="aspect-… object-cover">{% endif %}
   ```
3. **Do NOT** convert the row array into a slot — it breaks both SDC (slots are flat)
   and the agent contract (the array prop must stay identical).

## Step 3: CSS

`.ain-img picture img { object-fit: cover }` and the 2 base CQ rules
(`.rift-cq-image-container`/`.rift-cq-image`) already live in `build/input.css`. The
bespoke page shell doesn't auto-attach `#attached` libraries, so any NEW shared CSS
must go in `build/input.css` and be rebuilt (`assets/aincient-pages.css`), not left
to a Drupal library.

## Step 4: verify

- `drush cr`, then `drush php:script` a schema through `renderSchema()` and grep the
  HTML:
  - single image → `<picture>` with multiple `<source>`s + `image/webp`;
  - repeatable → `rift-cq-image-container` + inline `@container` at the right
    `aspect-ratio`.
- Confirm a raw-URL spike brief still falls back to a plain `<img>` (rift soft
  integration / back-compat).
- Fetch one derivative URL → expect `200` + `image/webp`, and a **relative**
  (`/sites/...`) path.
- Run the kernel suite:
  `SIMPLETEST_DB=pgsql://db:db@db:5432/db ../vendor/bin/phpunit -c core/phpunit.xml.dist modules/custom/aincient_pages/tests/src/Kernel`.

## Don't

- Don't make `rift` a hard dependency — it's injected `@?`; `component()` falls back
  to a plain `<img>` slot when it's absent.
- Don't give the agent a view-mode / art-direction choice — the `(component,prop) →
  view mode` mapping is deterministic and system-owned.
- Don't commit auto-generated `WxHh` image styles to `config/sync`.
