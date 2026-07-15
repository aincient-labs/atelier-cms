# Demo homepage images

These three images are minted into **media entities** on install (see
`aincient_demo.install` → `AINCIENT_DEMO_IMAGES`) and referenced from
`briefs/home.json` by their `@<slug>` placeholder. They ship in-repo so the
homepage looks finished **offline**, out of the box — no remote URLs.

The shipped set (below) was generated in the Media studio and exported here.
Replace a file with the same name to reskin; if one is missing, install still
succeeds and that section just renders without its image.

| Slug     | File         | Where it appears            | Size (px)          |
| -------- | ------------ | --------------------------- | ------------------ |
| `@hero`  | `hero.webp`  | Hero (split)                | **1024×1024**      |
| `@pages` | `pages.webp` | "Describe it. Watch it build." (image-right) | **1024×1024** |
| `@brand` | `brand.webp` | "Your brand, on every page" (image-left)     | **1024×1024** |

**Format:** WebP, quality ~82, sRGB. Keep each file well under ~400 KB — the
renderer derives its own image styles (and crops per slot), so these are the
masters, not the delivered sizes. Square is fine; the slots crop to fit.

## Art direction (locked to the brand)

Warm, human, hand-made — a craftsman's **atelier**, not a SaaS stock desk.
Palette: gesso paper `#F0EDE7`, near-black `#191714`, a single **cinnabar**
accent `#B94430` / `#E0694E`. Soft natural window light, shallow depth of field,
gentle film grain. **No text, no logos, no screens/UI, no faces** (hands and
objects only — keeps it timeless and avoids the uncanny). Landscape orientation.

## AI generation prompts

Portable across Nano Banana / Imagen / Midjourney / DALL·E. Append your tool's
aspect flag (e.g. Midjourney `--ar 4:3`).

**hero.webp**
> A pair of hands arranging paper swatches and small wooden type blocks on a warm
> gesso-toned workbench, soft natural window light from the left, one object in
> cinnabar red as the single accent, shallow depth of field, warm analog film
> photograph, fine grain, muted earthy palette, no text, no screens, landscape.

**pages.webp**
> Close-up of hands laying out cut-paper rectangles into a clean grid on a warm
> off-white worktable, composing a layout piece by piece, soft directional light,
> a hint of cinnabar red among neutral tones, shallow focus, warm editorial film
> photograph, fine grain, no text, no screens, landscape.

**brand.webp**
> A tidy flat-lay of warm colour swatches, paper samples and a few serif type
> specimens arranged on gesso paper, gesso and cinnabar-red tones, a designer's
> palette coming together, soft even natural light, warm analog film photograph,
> fine grain, no text, no logos, landscape.

## Unsplash fallback (if you'd rather not generate)

Warm, hands-on craft shots; download the ~1600px WebP/JPG and re-save to the
names above. Prefer photos with a warm, muted palette and a single red accent so
they sit with the brand:

- Hero — search **"hands workbench craft warm"** or **"artisan studio hands"**.
- Pages — search **"paper layout flat lay warm"** or **"typesetting letterpress"**.
- Brand — search **"colour swatches palette flat lay"** or **"paper samples warm"**.

Check the Unsplash licence (free to use, no attribution required) before shipping.
