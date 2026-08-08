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

## Integration — `published-hops-e2e.sh` (the incident, on REAL published images)

The only suite that builds **nothing**. It pulls actual tags from GHCR, because what
it tests is what the shipped artifacts do to each other's databases. Needs network;
not part of the push gate. Run it when the converge branch logic changes, or before
sending a recovery route to an affected user.

Three scenarios, each on its **own volume set** — the withdrawn scenario 5 of
`upgrade-floor-e2e.sh` failed precisely because it leaked `/shared` and `private`
between scenarios:

1. **`sha-ee6a1cf` → `v0.4.2`** — the defect, reproduced: `no site found → fresh
   install`, database wiped, `converge.result=ok`. **Observational, never counted** —
   `v0.4.2` is published and unchangeable. It is the *control*: if it ever stops
   wiping, scenario 2 is refusing a state that was never dangerous.
2. **`sha-ee6a1cf` → `v0.5.1`** — the fix refuses (`missing-code`), names the modules,
   and leaves the marker row and all 111 tables untouched.
3. **`sha-ee6a1cf` → `v0.2.0` → `v0.5.1`** — the recovery route. `v0.2.0` is the one
   published release that still *ships* the fourteen modules while requiring none of
   them and omitting all fourteen from `config/sync/core.extension.yml`, so the
   database takes the **upgrade** branch and `config:import` performs the uninstalls.

```bash
./published-hops-e2e.sh                    # ~10 min; needs network + Docker
HOP_NO_TEARDOWN=1 ./published-hops-e2e.sh  # leave the last stack up to inspect
```

> This is what finally covered `refuse_unbootable`'s sibling guard on a **real**
> database. Background: DECISIONS 0348/0349/0353/0356,
> `plans/converge-branch-guard.md`.

## CI

`.github/workflows/ci.yml` runs all three suites as parallel jobs on push / PR to
`main`:

```
composer test            # php-tests       — agent loop + Commands (Kernel, sqlite)
docker/tests/run.sh      # converge-unit   — converge logic (bats, mock drush)
docker/tests/smoke.sh    # appliance-smoke — real container: install/upgrade/rollback
```
