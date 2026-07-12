# AIncient as a self-converging appliance

AIncient ships as a **versioned container image**. The image *is* the product and
the unit of versioning — getting the next AIncient means pulling the next image
tag and letting the site heal itself. This is the Drupal equivalent of how
Discourse, GitLab Omnibus, and Ghost are delivered.

## The split that makes it work

| | Lives in | Survives an upgrade? |
| --- | --- | --- |
| **Code** (Drupal core, `aincient_*`, contrib, vendor) | the **image**, frozen at build | replaced wholesale by the new image |
| **State** (database, files, secrets) | **volumes / env** | yes — this is the only thing that persists |

Because code is immutable in a running container, **Drupal cannot upgrade its own
code** — new PHP only arrives as a new image + restart. Everything else (schema +
config migration) the site does to *itself*.

## Converge: the site heals itself

`converge.sh` runs on **every** container start (via `entrypoint.sh`) and is
idempotent:

- **Empty database** → fresh install from the baked-in [`config/sync`](../config/sync)
  (`drush site:install minimal --existing-config`), then seed the branded homepage
  (`aincient_demo`). This — not the [`aincient` recipe](../recipes/aincient) — is the
  appliance's desired state: config/sync ships the full site incl. the branded
  `aincient_theme` as the default front end, whereas the recipe leaves Olivero default +
  unbranded and is now **dev/demo-only**. See `.dev` DECISIONS 2026-06-16.
- **Existing database** → **snapshot** → `drush updatedb` (runs this image's
  `hook_update_N` / `hook_post_update`) → `drush config:import` (re-asserts this
  image's `config/sync`) → `cache:rebuild` → **health check** → **roll back to the
  snapshot** if anything fails.

So restarting on a new image *is* the upgrade — there is no separate migrate step to
remember, and a failed upgrade leaves the previous database intact.

> **The migration engine is still Drupal's.** Converge doesn't replace
> `hook_update_N` — it automates invoking it and wraps it in a snapshot + health
> gate. Schema/data changes ride in `hook_update_N` / `hook_post_update`.

### Config on update — site-owned config is never clobbered

On upgrade converge runs `drush config:import`, so a release ships config changes
(new studios, fields, flows, block placements) **without** hand-writing a
`hook_post_update` for each. This is safe because **`config_ignore`** fences off the
config objects a site owns — `cim` never imports over them:

| Ignored config object              | What the site owns                          |
| ---------------------------------- | ------------------------------------------- |
| `system.site`                      | site name, slogan, mail, front page         |
| `aincient_core.model_roles`        | role → provider:model bindings + default    |
| `ai.settings`                      | operation-type → model fallbacks            |
| `ai_provider_anthropic.settings`   | Anthropic provider config                   |
| `ai_provider_openai.settings`      | OpenAI provider config                      |

(See [`config/sync/config_ignore.settings.yml`](../config/sync/config_ignore.settings.yml).
Per-provider API keys already live in Drupal **State**, not config, so they were
never at risk.)

Two consequences worth knowing:

- **The ignore is bidirectional** (config_ignore `simple` mode). `drush cex` won't
  capture your local edits to these five objects into `config/sync` either, so a
  developer's site name / model picks can never leak into the distribution. To change
  a *shipped default* (what fresh installs get), **hand-edit the YAML** in
  `config/sync` — `cex` won't do it for you.
- **An ignored object is frozen on existing sites.** Adding a new default *inside* one
  (e.g. a new entry in `model_roles`) won't reach an installed site via `cim`. For
  that, fall back to a guarded `hook_post_update` (`if (empty(...)) { … }`) that
  touches only the new key.

Escape hatch: set `AINCIENT_IMPORT_CONFIG=0` to skip the import for a release.

## One-click upgrade: the updater sidecar

Since the console can't restart itself, a small **privileged sidecar** does the
image pull + recreate:

```
console "Upgrade" / chat command   →  writes /shared/upgrade.request
updater sidecar sees the flag      →  records the running image digest
                                   →  docker compose up -d --pull always app
app restarts on the new image      →  entrypoint runs converge.sh
converge reports its outcome       →  writes /shared/converge.result (ok|rolledback)
updater reads the result           →  ok: status=done
                                   →  rolledback: RE-PIN app to the previous digest,
                                                  status=failed
```

The sidecar can't trust container-start alone: the app entrypoint starts the
webserver **even when converge rolls back** (so the operator can inspect the
rolled-back site). So the updater reads `converge.result` to tell a healthy
upgrade from a rolled-back one, and on rollback it re-pins the app to the image
digest it was running before the pull — DB *and* code return to the last-known
good state, not just the DB.

On-brand: the trigger can be a console action — *"update AIncient to the latest
version"* — with `updatedb` progress and the health result streamed back as chat.
The DB/config side is fully doable from inside Drupal; only the image pull needs the
sidecar.

> ⚠️ **Security.** The updater mounts the Docker socket — that is root-equivalent on
> the host. This is an accepted trade-off for a self-hosted appliance (it is exactly
> what Watchtower and Discourse's launcher require), but it must be documented and the
> sidecar kept minimal. Don't expose the updater to the network; it only watches a
> shared volume.

## Distribution image (private, invite-based testing)

The image is published to a **private GHCR package**: `ghcr.io/aincient-labs/cms`.
The [`Release image`](../.github/workflows/release.yml) workflow smoke-tests and pushes
it — `:edge` + `:sha-<short>` on every push to `main`, and `:v1.2.3` + `:latest` on a
`v*` git tag. Nothing is pushed unless `smoke.sh` (build → install → upgrade → rollback)
passes, and the pushed image is the exact one smoke validated.

**Inviting a tester** (one-time, per person): on GitHub → the `cms` package →
*Package settings* → *Manage access* → add their account/team with **Read**. Keep the
package **private**.

**Pulling as a tester:** GHCR needs auth even for read. Create a classic PAT with
`read:packages`, then:

```bash
echo "$GHCR_PAT" | docker login ghcr.io -u <github-username> --password-stdin
docker pull ghcr.io/aincient-labs/cms:latest   # or :v1.2.3 / :edge
```

`docker/compose.yaml` already defaults `AINCIENT_IMAGE` to
`ghcr.io/aincient-labs/cms:latest`, so once logged in a plain `up` pulls the right image.
Override `AINCIENT_IMAGE` to pin a specific tag.

> The published image is currently **linux/amd64 only** (what the CI runner builds and
> smokes). Apple-Silicon testers run it under emulation; add a buildx multi-arch step if
> native arm64 becomes a requirement.

## Try it

```bash
cp .env.example .env   # set HASH_SALT (connect an AI provider later in the console)
# Authenticate to GHCR first (see above) so the default image can be pulled,
# or build locally with --build.
docker compose -f docker/compose.yaml up -d --build
# → http://localhost:41221/  (log in → /aincient)
```

Simulate an upgrade after pushing a new image tag:

```bash
docker compose -f docker/compose.yaml exec app sh -c 'echo 1 > /shared/upgrade.request'
# the sidecar pulls the new image, recreates app, and the site converges
```

## Status

**Verified end-to-end (updated 2026-06-17)** — built and run on Docker 29.4 / Compose v5.
The full `upgrade-e2e.sh` is **12/12 green** (fresh install + good upgrade + broken-upgrade
rollback); the appliance install gate is closed (see `.dev` DECISIONS 2026-06-16/17).

- ✅ Fresh install: empty DB → `drush site:install minimal --existing-config` (from the
  baked-in `config/sync`) → `cache:rebuild` → seed branded homepage (`aincient_demo`) →
  health OK. The branded `aincient_theme` is the default front end out of the box.
- ✅ Serving: front page and `/user/login` return 200; `/aincient` returns 403 to
  anonymous (correct — the console is authenticated-only).
- ✅ Upgrade branch: recreating the container on the existing DB takes the upgrade
  path → snapshot → `drush updatedb` → cache rebuild → health check (under the
  rollback trap) → healthy.
- ✅ Rollback: the snapshot → `sql:drop` → `sql:cli` round-trip reverts a mutation
  (`GOOD-STATE` → `BROKEN` → restore → `GOOD-STATE`).
- ✅ `settings.appliance.php` reads `DATABASE_URL` / `HASH_SALT` from the env; the AI
  provider is connected in the console's first-run onboarding wizard (no env key).

**Test coverage** (`docker/tests/`, see its README):
- `converge.bats` — 9 hermetic unit tests (mock `drush`) over the branch + rollback
  logic, incl. a regression guard that a failed health check rolls back, the
  `converge.result` marker (ok|rolledback), and the random admin-password mint.
  `./tests/run.sh`.
- `smoke.sh` — integration test on a real isolated container: fresh install from
  config/sync (via converge), module/permission asserts, HTTP reachability, upgrade
  branch, rollback round-trip (14 checks).
- `upgrade-e2e.sh` — full **updater sidecar** end-to-end against an ephemeral registry:
  fresh install → push a new digest → `upgrade.request` → assert the sidecar pulls,
  recreates, converges (`status=done`); then push a health-breaking image → assert the
  DB rolls back, the app is **re-pinned to the previous image** (`status=failed`), and
  the site stays up. Heavy; a CI lane, not a pre-commit hook.

**Production hardening (done 2026-06-16):**

- **`trusted_host_patterns`** is env-driven: set `AINCIENT_TRUSTED_HOSTS` to a
  comma-separated list of regexes; unset falls back to accept-any **and logs a warning**.
- **No default admin password.** Converge mints a random one on first install, logs it,
  and writes it to `private/INITIAL_ADMIN_PASSWORD`; the installer surfaces it. Pin your
  own with `ADMIN_PASS`.
- **Image rollback.** A failed upgrade now restores the DB snapshot **and** re-pins the
  app to the previous image digest (see the upgrade flow above).

**Resolved (2026-06-17): fresh install via the converge path.** The earlier breakage —
re-applying the `aincient` recipe failed config-sync validation on the Stark theme — is
gone now that converge installs **from `config/sync`** (`site:install minimal
--existing-config`) instead of re-applying the recipe. The recipe is dev/demo-only.
`upgrade-e2e.sh`'s happy path is green; the appliance install gate is closed.

**Remaining release gate (external):** FlowDrop 2.0 publish on drupal.org — see `.dev/STATE.md`.

- CI is wired (`.github/workflows/ci.yml`): `composer test` (PHP Kernel), `converge.bats`,
  and the `smoke.sh` appliance build on every push/PR to `main`. The
  [`Release image`](../.github/workflows/release.yml) workflow publishes the smoke-tested
  image to GHCR. `upgrade-e2e.sh` is not yet a CI lane (heavy; run it on demand).
