# Docs answers — live-turn eval corpus (DECISIONS 0525)

Questions for the General assistant. `covered` = the docs answer it; `uncovered`
= Atelier doesn't do it, so a good answer says the docs don't cover it and
invents nothing. Each run is a real console turn (runtime docs check included),
graded by an independent judge against WHOLE docs pages: every claim about
Atelier must be supported. A case passes only if every run passes.

Run: `ddev drush aincient:docs-eval --runs=3` (real model calls, ≈ $0.02 per run;
not part of `phpunit-parallel`). Run it after changing the operator prompt, the
docs tools, the verifier, or the docs bundle.

Case keys: `ask`, `kind`, `expect.links_any` (docs path prefixes, one must be
linked), `expect.mention_all` (regexes), `expect.prose_not` (regex).
