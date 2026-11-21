# Preview placeholders (DECISIONS 0455, D9)

Neutral images the **built-in components' `examples:`** reference, so the
Components studio's previews look finished on a fresh site with no media.

- Referenced from an example as a `placeholder:<name>` token (e.g.
  `placeholder:landscape`, `placeholder:avatar-2`, `placeholder:logo-4`).
- Resolved to this folder's URL **only by the preview route**
  (`ExampleRenderer`). They are never media entities, never in the Library, and
  a page cannot store the token (it is not a `media:<id>` token).
- Packs can do the same with their own files: a `placeholder:<name>` token
  resolves against the DECLARING module's `images/placeholders/<name>.svg`
  first, then this set.

Files: `landscape`, `portrait`, `square`, `wide`, `avatar-1..3`, `logo-1..5` (SVG).
