# Brand agent — live-turn eval corpus

One case per defect fixed in the brand agent (cms #36–#44), so each stays fixed.
Run with `ddev drush aincient:brand-eval` (real model calls — not part of
`phpunit-parallel`). Format → `BrandEvalCase`; assertion grammar →
`BrandEvalAssertions`; when to run → `docs/testing.md` "Live-turn evals (brand)".

A case marked `expected_fail: true` documents a defect we know is open (the set is
named in `BrandEvalCorpusTest`); it
reports XFAIL/XPASS and never moves the exit code. Decide those from runs
(`plans/brand-eval.md` Phase 2), not from one turn.
