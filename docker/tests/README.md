# Appliance tests

Coverage for the safety-critical converge UX (fresh install · upgrade · rollback ·
health). Two tiers:

## Unit — `converge.bats` (fast, hermetic, no Docker-in-the-loop)

Runs `converge.sh` against a **mock `drush`** (`mock-drush.sh`), asserting the
*orchestration*: which branch is taken, that the snapshot precedes `updatedb`, and —
critically — that a failed migration **or** a failed health check rolls back. Test 5
is a regression guard for the 2026-05-31 bug where health failures didn't roll back.

```bash
./run.sh          # runs the suite in the bats/bats Docker image (no host install)
```

## Integration — `smoke.sh` (real container, real Drupal)

Builds the image, brings up an **isolated** compose project (`atelier_smoke`, port
8099 — won't touch a running dev stack), and asserts the real UX: install-from-config
brings up the stack, the console permission is granted, the front page + login serve (200),
`/atelier` is 403 for anonymous, the upgrade branch snapshots + converges healthy, and
the snapshot→restore round-trip reverts a change. Tears itself down.

```bash
./smoke.sh        # ~2–3 min; needs Docker
```

## Integration — `upgrade-e2e.sh` (the updater SIDECAR, real image swap)

The only test that drives the **one-click upgrade** end to end: it self-hosts an
ephemeral registry (so `docker compose pull` does real work), fresh-installs via the
repo `compose.yaml` (db + app + **updater**), then exercises the full protocol — push a
new digest → write `/shared/upgrade.request` → the sidecar pulls + recreates + converges
→ `status=done`. It then pushes a **health-breaking** image and asserts the failure
path: converge rolls the DB back, the updater **re-pins the app to the previous image**,
`status=failed`, and the site stays up.

```bash
./upgrade-e2e.sh                              # builds the appliance image first (slow)
E2E_BASE_IMAGE=aincient/cms:dev ./upgrade-e2e.sh   # reuse a prebuilt image (fast)
```

> ⚠️ The happy-path assertions are currently **red** — fresh install via the converge
> path fails on the `core/recipes/standard`-on-`minimal` Stark config-sync conflict (see
> `../README.md` "Known release blocker"). The sidecar **rollback** assertions pass. Wire
> this into CI once fresh install is fixed.

## Integration — `upgrade-floor-e2e.sh` (the two upgrade REFUSALS)

Same harness as `upgrade-e2e.sh` (ephemeral registry, isolated compose project, derived
images pushed under one tag), pointed at the guards that stop an upgrade the appliance
cannot perform. `converge.bats` proves the branching against a mock drush; only this
proves the query really reads PostgreSQL's `bytea` column, that a refusal leaves a **real**
database untouched, and that the sidecar re-pins for the new `converge.result` values.

Three scenarios: **too-old** (site below the incoming image's declared floor), **missing-code**
(an installed module whose code the image no longer ships — the shape of dropping
`drupal/ai`), and a **control** proving an image whose floor the site *does* satisfy still
upgrades normally. The control is the point: without it the first two would pass equally well
if we had simply broken upgrading.

```bash
./upgrade-floor-e2e.sh                                  # builds the appliance image (slow)
E2E_BASE_IMAGE=aincient/cms:dev ./upgrade-floor-e2e.sh  # reuse a prebuilt image (fast)
```

> The base image must carry the **current** `converge.sh` — a prebuilt image from before the
> guards exists will pass scenario 3 and silently skip the refusals.

## CI

`.github/workflows/ci.yml` runs all three suites as parallel jobs on push / PR to
`main`:

```
composer test            # php-tests       — agent loop + Commands (Kernel, sqlite)
docker/tests/run.sh      # converge-unit   — converge logic (bats, mock drush)
docker/tests/smoke.sh    # appliance-smoke — real container: install/upgrade/rollback
```
