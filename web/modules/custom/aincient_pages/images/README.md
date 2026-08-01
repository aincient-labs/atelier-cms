# The shipped photo set

Three photographs that ship in-repo so every demo surface looks finished
**offline**, out of the box — no remote hotlinks, no third-party CDN requests.

They live in `aincient_pages` (always installed) because two consumers share
them:

- **`aincient_demo`** mints each one into a **media entity** on install
  (`aincient_demo.install` → `AINCIENT_DEMO_IMAGES`) and its homepage brief
  references it by an `@<slug>` placeholder. That path gives them alt text and
  puts them in the operator's Media library.
- **The spike/demo briefs** in `../briefs/` reference them as plain files via a
  `%module%/images/…` placeholder, resolved to a URL in
  `PageSpikeController::render()`. A brief can't name a not-yet-created entity,
  so these render as a bare `<img>`, not media.

Replace a file with the same name to reskin — no code change. If one is missing,
install still succeeds and that section renders without its image.

## The set

| File | Depicts | Size |
| --- | --- | --- |
| `type-blocks.webp` | Desk beside a window; two hands setting a row of wooden letterpress type blocks onto sheets of paper, jar of brushes behind, swatches in mustard/cream with one red strip. Warm daylight from the left, shallow focus, visible grain. | 1024×1024, 66 KB |
| `paper-grid.webp` | Flat-lay, straight down. Two hands placing a 6×5 grid of cream/grey/ochre paper squares on a tan surface, one wide crimson rectangle at the centre. Mug top-right, sunlight stripes top-left. | 1024×1024, 82 KB |
| `paper-swatches.webp` | Oblique view of a dark walnut table; two hands placing a loose 4×4 grid of terracotta/orange/ochre paper squares, strips scattered at the edges. Dim, heavy vignette. | 1024×1024, 45 KB |

**Format:** WebP, quality ~82, sRGB. Keep each file well under ~400 KB — the
renderer derives its own image styles (and crops per slot), so these are the
masters, not the delivered sizes. Square is fine; the slots crop to fit.

## Where each one lands

| File | `aincient_demo` homepage | `/spike/showcase` | `/spike/blog` |
| --- | --- | --- | --- |
| `type-blocks.webp` | `@hero` — hero (split) | hero (split), gallery #2 | cover |
| `paper-grid.webp` | `@pages` — "Describe it. Watch it build." | content (image-right), gallery #3 | — |
| `paper-swatches.webp` | `@brand` — "Your brand, on every page" | gallery #1 | — |

`/demo/brand` (the Brand studio's live preview) and `/spike/landing` are
deliberately **image-free** — every pixel on those pages is token-driven, so
they repaint completely when the brand changes.

## Art direction (locked to the brand)

Warm, human, hand-made — a craftsman's **atelier**, not a SaaS stock desk.
Palette: gesso paper `#F0EDE7`, near-black `#191714`, a single **cinnabar**
accent `#B94430` / `#E0694E`. Soft natural window light, shallow depth of field,
gentle film grain. **No text, no logos, no screens/UI, no faces** (hands and
objects only — keeps it timeless and avoids the uncanny).

## Known gaps in the current set

Worth fixing whenever these get regenerated:

1. **Orientation.** The art direction calls for *landscape*; all three masters
   are square 1024×1024.
2. **Duplicate motif.** `paper-grid` and `paper-swatches` are the same idea
   (hands arranging a paper grid). On the demo homepage they sit one section
   apart and read as the same photo twice. `paper-swatches` was briefed as a
   flat-lay of colour swatches, paper samples and serif type specimens — a
   palette coming together — and isn't that.
3. **Palette drift.** Only `type-blocks` lands near gesso + near-black + a single
   cinnabar. `paper-grid` drifts warm-tan with a cool crimson accent;
   `paper-swatches` is terracotta-on-walnut with no gesso at all.
4. **Exposure.** `paper-swatches` is underexposed and punches a dark hole into a
   gesso-light layout.
5. **Crop safety.** Subjects are centred and edge-to-edge busy; the
   split/image-right/image-left slots crop hard. Leave quiet margins.

## AI generation prompts

Portable across Nano Banana / Imagen / Midjourney / DALL·E. Append your tool's
aspect flag (e.g. Midjourney `--ar 4:3`).

**type-blocks.webp**
> A pair of hands arranging paper swatches and small wooden type blocks on a warm
> gesso-toned workbench, soft natural window light from the left, one object in
> cinnabar red as the single accent, shallow depth of field, warm analog film
> photograph, fine grain, muted earthy palette, no text, no screens, landscape.

**paper-grid.webp**
> Close-up of hands laying out cut-paper rectangles into a clean grid on a warm
> off-white worktable, composing a layout piece by piece, soft directional light,
> a hint of cinnabar red among neutral tones, shallow focus, warm editorial film
> photograph, fine grain, no text, no screens, landscape.

**paper-swatches.webp**
> A tidy flat-lay of warm colour swatches, paper samples and a few serif type
> specimens arranged on gesso paper, gesso and cinnabar-red tones, a designer's
> palette coming together, soft even natural light, warm analog film photograph,
> fine grain, no text, no logos, landscape.

## Unsplash fallback (if you'd rather not generate)

Warm, hands-on craft shots; download the ~1600px WebP/JPG and re-save to the
names above. Prefer photos with a warm, muted palette and a single red accent so
they sit with the brand:

- `type-blocks` — search **"hands workbench craft warm"** or **"artisan studio hands"**.
- `paper-grid` — search **"paper layout flat lay warm"** or **"typesetting letterpress"**.
- `paper-swatches` — search **"colour swatches palette flat lay"** or **"paper samples warm"**.

Check the Unsplash licence (free to use, no attribution required) before
shipping — and **download the file into this directory**; never hotlink.
