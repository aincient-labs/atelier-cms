#!/usr/bin/env bash
#
# AIncient converge — make the site's STATE (database + config) match the CODE
# baked into this image. Idempotent: safe to run on every boot.
#
#   - Empty database      → fresh install from the baked-in config/sync.
#   - Existing database   → snapshot → run pending updates → rebuild → health
#                           check → roll back to the snapshot if anything fails.
#
# This is the Drupal-side of the appliance upgrade story. It does NOT pull new
# code — new PHP arrives only as a new image + restart (see docker/README.md).
# The migration engine is still Drupal's own hook_update_N / hook_post_update;
# converge just automates invoking it and wraps it in a snapshot + health gate.
#
set -euo pipefail

# --- Config (override via environment) --------------------------------------
DRUPAL_ROOT="${DRUPAL_ROOT:-/opt/drupal/web}"
DRUSH="${DRUSH:-/opt/drupal/vendor/bin/drush} --root=${DRUPAL_ROOT}"
SNAPSHOT_DIR="${AINCIENT_SNAPSHOT_DIR:-/opt/drupal/private/snapshots}"
PRIVATE_DIR="${AINCIENT_PRIVATE_DIR:-/opt/drupal/private}"
ADMIN_USER="${AINCIENT_ADMIN_USER:-admin}"
ADMIN_PASS="${AINCIENT_ADMIN_PASS:-}"
INSTALL_PROFILE="${AINCIENT_INSTALL_PROFILE:-minimal}"
DB_MAX_WAIT="${AINCIENT_DB_MAX_WAIT:-60}"
# Overridable so tests can stub the health gate.
HEALTHCHECK_CMD="${HEALTHCHECK_CMD:-$(dirname "$0")/healthcheck.sh}"
# The oldest site version this image will migrate a database from, and where that
# is baked. Overridable so tests can set a floor without rebuilding the image.
UPGRADE_FLOOR_FILE="${AINCIENT_UPGRADE_FLOOR_FILE:-/etc/atelier/upgrade-floor}"
# The State key recording which Atelier version last converged this database.
# The floor is meaningless without it: the image knows what it needs, only the
# site knows where it is coming from.
VERSION_STATE_KEY="aincient.appliance_version"
# When the updater mounts /shared, converge reports its outcome here so the
# sidecar can tell a healthy upgrade from one that rolled back (the server is
# started either way, so container-start alone can't signal failure).
SHARED_DIR="${AINCIENT_SHARED_DIR:-/shared}"

log() { printf '[converge] %s\n' "$*"; }
die() { printf '[converge] FATAL: %s\n' "$*" >&2; exit 1; }

# Printed whenever the site ends up unhealthy, so the operator gets an honest
# signal + a recovery path instead of a container that reports "healthy" while
# serving a 404. The web server is still started (see entrypoint.sh) so the
# broken state can be inspected, but the health check has already failed, so the
# container is marked unhealthy rather than silently OK.
recovery_hint() {
  log "----------------------------------------------------------------"
  log "  The site did not converge to a healthy state."
  log "  If this was a first install that was interrupted, the database"
  log "  may be half-populated. To reinstall from a clean slate, remove"
  log "  the data volumes and start again (THIS DELETES SITE DATA):"
  log "      docker compose down -v && docker compose up -d"
  log "  If you have data you need, take a backup of the db volume first."
  log "----------------------------------------------------------------"
}

# Record the convergence outcome (ok|rolledback|install-failed) on the shared
# volume, if one is mounted. No-op otherwise (e.g. the slim curl topology).
write_result() {
  [ -d "$SHARED_DIR" ] || return 0
  printf '%s' "$1" > "${SHARED_DIR}/converge.result" 2>/dev/null || true
}

# --- Helpers ----------------------------------------------------------------

# True once the database is reachable (settings.php reads creds from the env).
db_ready() { $DRUSH sql:query 'SELECT 1;' >/dev/null 2>&1; }

# True once Drupal is installed and bootstraps cleanly.
site_installed() {
  $DRUSH status --field=bootstrap 2>/dev/null | grep -qi 'Successful'
}

wait_for_db() {
  local waited=0
  until db_ready; do
    [ "$waited" -ge "$DB_MAX_WAIT" ] && die "database not reachable after ${DB_MAX_WAIT}s"
    log "waiting for database… (${waited}s)"
    sleep 2; waited=$((waited + 2))
  done
  log "database is reachable"
}

# Activate the pgvector extension so `vector`-typed columns are usable the moment
# any module needs them. Idempotent (CREATE EXTENSION IF NOT EXISTS); runs on
# every boot before install/upgrade. The db image (pgvector/pgvector:pg16) ships
# the extension files; the `aincient` DB user owns its database, so it may create
# the extension. Postgres only — settings.appliance.php still accepts a
# mysql/mariadb DATABASE_URL, and there this is a no-op. Not fatal: nothing uses
# vector yet (search integration is deferred — see plans/pgvector.md), so a
# failure here warns rather than gating boot.
ensure_pgvector() {
  case "${DATABASE_URL:-pgsql://}" in
    pgsql:*) ;;
    *) return 0 ;;
  esac
  if $DRUSH sql:query 'CREATE EXTENSION IF NOT EXISTS vector;' >/dev/null 2>&1; then
    log "pgvector extension present"
  else
    log "WARN: could not create the pgvector extension (continuing)"
  fi
}

# --- Installed modules whose code this image no longer carries ---------------
#
# THE HAZARD THE VERSION FLOOR CANNOT REACH. A release that drops a package
# deletes the module's code, and a site that still has that module INSTALLED can
# no longer boot: Drupal resolves every installed extension's path at container
# compile time and fatals on the one it cannot find, before `updatedb` or `cim`
# get a chance to uninstall it. The floor is the general answer to "this state is
# too old", but it compares VERSIONS — and the sites most exposed here are on
# `edge` builds, whose `edge+<sha>` stamp has no position in the version order at
# all. Nothing version-shaped can gate them.
#
# This check needs no version: it asks the state and the filesystem directly, so
# it also covers every FUTURE release that removes a module without anyone having
# to predict which one. Concretely, it is what stands between a pre-0.1.0 `edge`
# install (which still has `ai`, `ai_agents`, `ai_provider_*` installed) and the
# release that finally drops those packages.
#
# BOOTSTRAP-FREE, and it has to be: the very condition it detects is the one that
# makes bootstrapping impossible, so `drush pm:list` would fatal instead of
# reporting. It reads `core.extension` out of the config table with `sql:query`
# (credentials only) and compares against the `*.info.yml` files on disk.

# Echo every installed extension name, one per line. Exit 3 when the answer can't
# be read at all — the caller treats that as "unknown", never as "nothing missing".
#
# `convert_from` asks Postgres for the serialized blob AS TEXT. `config.data` is a
# bytea, which otherwise comes back as `\x<hex>` and would need decoding; getting
# text out of the query instead of decoding it afterwards is what keeps this to
# grep, with no PHP and no hex tooling in the path. On a non-Postgres database the
# function doesn't exist, the query fails, and the check reports "unknown" and
# stands aside — the same way ensure_pgvector is Postgres-only.
installed_extensions() {
  local raw
  raw="$($DRUSH sql:query \
    "SELECT convert_from(data, 'UTF8') FROM config WHERE name = 'core.extension';" \
    2>/dev/null)" || return 3
  [ -n "$raw" ] || return 3
  # core.extension serializes as {module: {name: weight}, theme: {name: weight},
  # profile: "name"} — so every `s:LEN:"name";i:WEIGHT;` pair in it is an
  # extension name, and nothing else in it has that shape. Matching all of them
  # covers themes as well as modules, which is the same hazard for the same
  # reason. Names are `[a-z0-9_]` by Drupal's own rule.
  local names
  names="$(printf '%s' "$raw" | grep -oE 's:[0-9]+:"[a-z0-9_]+";i:[0-9]+;' \
    | sed -e 's/^s:[0-9]*:"//' -e 's/";i:[0-9]*;$//')"
  [ -n "$names" ] || return 3
  printf '%s\n' "$names"
}

# Echo every extension name this image actually ships, one per line. Deliberately
# not `find -printf` (GNU-only): the basename is stripped with sed so this runs
# anywhere, including the busybox find in the bats test image.
available_extensions() {
  find "$DRUPAL_ROOT" -name '*.info.yml' 2>/dev/null \
    | sed -e 's|.*/||' -e 's/\.info\.yml$//' | sort -u
}

# Refuse, without side effects, if any installed module's code is gone.
check_installed_code_present() {
  local installed missing
  if ! installed="$(installed_extensions)"; then
    log "WARN: could not read the installed module list, so a module whose code is"
    log "WARN: missing from this image would not be caught here. Continuing."
    return 0
  fi

  # `comm -23` = in the installed list, not in what this image ships.
  missing="$(comm -23 <(printf '%s\n' "$installed" | sort -u) <(available_extensions) | tr -d '\r')"
  [ -n "$missing" ] || return 0

  log "----------------------------------------------------------------"
  log "  REFUSING TO UPGRADE — this image is missing code your site uses."
  log ""
  log "  These modules are installed on your site, but this release no longer"
  log "  contains them:"
  printf '%s\n' "$missing" | while IFS= read -r m; do
    [ -n "$m" ] && log "      ${m}"
  done
  log ""
  log "  Drupal cannot start without an installed module's code, so it cannot"
  log "  uninstall them from here either — that has to happen on a version that"
  log "  still ships them."
  log ""
  log "  Go back to the version you were running (or the newest release older"
  log "  than this one), let it start and converge — it will uninstall these as"
  log "  part of applying its own configuration — then upgrade again:"
  log ""
  log "      AINCIENT_IMAGE=<the previous image> docker compose up -d"
  log ""
  log "  YOUR DATABASE HAS NOT BEEN TOUCHED. Nothing was migrated, so running the"
  log "  previous image again is a clean revert."
  log "----------------------------------------------------------------"
  write_result missing-code
  die "refusing to migrate: installed modules absent from this image ($(printf '%s' "$missing" | tr '\n' ' '))"
}

# --- Branch 1: fresh install ------------------------------------------------
fresh_install() {
  log "no site found → fresh install"

  # Never ship a known default credential. If the operator did not pin
  # AINCIENT_ADMIN_PASS, mint a random one and surface it (log + a private file)
  # so the site is not born with admin/admin.
  local generated=0
  if [ -z "$ADMIN_PASS" ]; then
    # 20 alnum chars from the kernel CSPRNG. Read a fixed 256-byte chunk first so
    # the `head -c`/`tr`/`cut` pipeline never SIGPIPEs under `set -o pipefail`,
    # and depend on nothing beyond coreutils (no openssl in minimal images).
    ADMIN_PASS="$(head -c 256 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-20)"
    generated=1
  fi

  # Install straight from the baked-in config/sync (install-from-config), NOT by
  # re-applying the recipe. config/sync is the appliance's desired state: it ships
  # the full AIncient site incl. the branded aincient_theme as the default front
  # end (the recipe leaves Olivero default + unbranded). The recipe is dev/demo
  # only now. See .dev DECISIONS 2026-06-16 "Appliance installs from config/sync".
  $DRUSH site:install "$INSTALL_PROFILE" --existing-config -y \
    --account-name="$ADMIN_USER" \
    --account-pass="$ADMIN_PASS" \
    --site-name="Atelier"

  if [ "$generated" = "1" ]; then
    mkdir -p "$PRIVATE_DIR"
    printf '%s\n' "$ADMIN_PASS" > "${PRIVATE_DIR}/INITIAL_ADMIN_PASSWORD"
    chmod 600 "${PRIVATE_DIR}/INITIAL_ADMIN_PASSWORD" 2>/dev/null || true
    log "================================================================"
    log "  Generated admin password for '${ADMIN_USER}': ${ADMIN_PASS}"
    log "  Also saved to ${PRIVATE_DIR}/INITIAL_ADMIN_PASSWORD — change it after first login."
    log "================================================================"
  fi
  # On a cold first boot the module/extension registry is stale right after
  # install-from-config, so a follow-up pm:install can't see node/aincient_pages
  # as installed and tries to re-install them (PreExistingConfigException). Clear
  # caches first so the registry reflects the just-imported config.
  $DRUSH cache:rebuild

  # Seed the out-of-box branded front door. aincient_demo is deliberately NOT in
  # config/sync (it's one-shot showcase content — see its .install docstring), so
  # install-from-config doesn't enable it. Enable it explicitly here, exactly as
  # the recipe did: hook_install composes the branded homepage and repoints
  # page.front at it. Idempotent + cleanly uninstallable. Tolerate its absence so
  # a demo-less build still converges (log the reason rather than swallowing it).
  if demo_out="$($DRUSH pm:install aincient_demo -y 2>&1)"; then
    log "seeded the branded demo homepage (aincient_demo)"
  else
    log "WARN: demo seed skipped (site installs unbranded front page). drush said:"
    printf '%s\n' "$demo_out" | sed 's/^/[converge]   /'
  fi

  $DRUSH cache:rebuild
  log "fresh install complete (from config/sync)"
}

# --- The upgrade floor ------------------------------------------------------
#
# A release can reach a point where it CANNOT migrate arbitrarily old state: the
# clearest case is a release that deletes code a still-pending update needs (drop
# `drupal/ai` from composer.json and the hook_update_N that uninstalls it is gone
# with it, so a site that never ran it can never run it here). Before this guard
# such an upgrade was still attempted: updatedb or the health check failed, the
# database rolled back, and the operator got a stack trace with no path forward.
#
# So each image DECLARES the oldest version it can migrate from, and refuses
# anything older BEFORE it touches the database — naming the waypoint to go
# through. The manager plans that route automatically (`atelier app update` walks
# the chain), but the manager is not the only way in: people run `docker compose
# pull` and re-run install.sh, and for them this refusal IS the instruction.
#
# It is a REFUSAL, not a rollback: nothing has been changed, so the recovery is
# simply to run the old image again. Recorded as its own converge.result so the
# updater sidecar can tell it apart from a migration that actually broke.

# The floor this image declares, or empty if it declares none. Comments and
# whitespace stripped, so the file can document itself.
upgrade_floor() {
  if [ -n "${AINCIENT_UPGRADE_FLOOR:-}" ]; then
    printf '%s' "$AINCIENT_UPGRADE_FLOOR"
    return 0
  fi
  [ -f "$UPGRADE_FLOOR_FILE" ] || return 0
  sed -e 's/#.*//' -e '/^[[:space:]]*$/d' "$UPGRADE_FLOOR_FILE" \
    | head -1 | tr -d '[:space:]'
}

# The version that last converged this database, or empty if none is recorded.
recorded_version() {
  $DRUSH state:get "$VERSION_STATE_KEY" --format=string 2>/dev/null | tr -d '[:space:]'
}

# Echo $1 as a bare comparable release (leading `v` dropped), or nothing at all
# when it isn't one. `dev` and `edge+<sha>` are deliberately NOT releases: they
# have no position in the version order, so no floor can be checked against them.
release_version() {
  local v="${1#v}"
  case "$v" in
    '' | *[!0-9.]*) return 0 ;;
  esac
  printf '%s' "$v"
}

# True when release $1 is strictly older than release $2.
version_lt() {
  [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -1)" = "$1" ]
}

# Record which version converged this database — the other half of the floor.
#
# Called only after the branch SUCCEEDED, so a rolled-back upgrade leaves the
# previous value in place. That is automatic rather than careful: the rollback
# restores a snapshot taken before this ran, and the value lives in that snapshot.
#
# An unstamped build (`dev`, or no stamp at all) records NOTHING. Absence already
# means "no released version has converged this site", which is exactly what a
# local build leaves behind, and a recorded 'dev' would only be a value the floor
# check has to special-case a second time.
record_version() {
  local stamp="${ATELIER_VERSION:-}"
  [ -n "$stamp" ] && [ "$stamp" != "dev" ] || return 0
  $DRUSH state:set "$VERSION_STATE_KEY" "$stamp" >/dev/null 2>&1 \
    && log "recorded this site as converged at ${stamp}" \
    || log "WARN: could not record the converged version (${stamp})"
}

# Refuse, loudly and without side effects, if this site is below the floor.
check_upgrade_floor() {
  local floor site
  floor="$(release_version "$(upgrade_floor)")"
  # No floor declared (or a local build with an unparseable one) → migrate as
  # before. The floor only ever ADDS a refusal; it can't gate the common path.
  [ -n "$floor" ] || return 0

  site="$(recorded_version)"
  if [ -z "$site" ]; then
    # Converged by an image from before this recording existed. We can't place
    # it in the version order, and refusing every unrecorded site would strand
    # installs that are perfectly current, so proceed under the snapshot: the
    # rollback is the net that was there all along. The manager covers this gap
    # from outside — it reads the version LABEL of the image already on the host,
    # which is recorded whether or not the site ever wrote State.
    log "WARN: this site has no recorded Atelier version, so the upgrade floor"
    log "WARN: (>= ${floor}) can't be verified. Continuing under the snapshot."
    return 0
  fi

  local comparable
  comparable="$(release_version "$site")"
  if [ -z "$comparable" ]; then
    log "site last converged at '${site}', an unreleased build — no position in the"
    log "version order, so the floor (>= ${floor}) can't be checked. Continuing."
    return 0
  fi

  version_lt "$comparable" "$floor" || return 0

  log "----------------------------------------------------------------"
  log "  REFUSING TO UPGRADE — this site is too old for this release."
  log ""
  log "    this site last converged at:  ${site}"
  log "    this image can migrate from:  ${floor} or newer"
  log ""
  log "  Upgrade to ${floor} FIRST, then to this version. With the manager"
  log "  this is one command, which walks the whole route for you:"
  log ""
  log "      atelier app update"
  log ""
  log "  By hand, run the waypoint image, let it converge, then come back:"
  log ""
  log "      AINCIENT_IMAGE=ghcr.io/aincient-labs/atelier-cms:v${floor} \\"
  log "        docker compose up -d"
  log ""
  log "  YOUR DATABASE HAS NOT BEEN TOUCHED. Nothing was migrated and there is"
  log "  nothing to restore — running the previous image again is a clean revert."
  log "----------------------------------------------------------------"
  write_result too-old
  die "refusing to migrate a ${site} site with an image that requires >= ${floor}"
}

# --- Branch 2: upgrade existing site ----------------------------------------
take_snapshot() {
  mkdir -p "$SNAPSHOT_DIR"
  SNAPSHOT_FILE="${SNAPSHOT_DIR}/pre-converge.sql.gz"
  log "snapshotting database → ${SNAPSHOT_FILE}"
  $DRUSH sql:dump --gzip --result-file="${SNAPSHOT_FILE%.gz}" >/dev/null
}

restore_snapshot() {
  [ -f "$SNAPSHOT_FILE" ] || die "no snapshot to restore from — manual recovery required"
  log "ROLLBACK: restoring database from snapshot"
  $DRUSH sql:drop -y
  zcat "$SNAPSHOT_FILE" | $DRUSH sql:cli
  $DRUSH cache:rebuild || true
}

upgrade() {
  log "existing site → upgrade"
  # BEFORE the snapshot: a refusal must cost nothing, not even a dump. Two
  # refusals, asking different questions — the floor asks whether this state is
  # too OLD to migrate, the code check asks whether this image is missing
  # something the state depends on. A site can fail the second while passing the
  # first (an `edge` build has no comparable version at all), which is exactly
  # why both run.
  check_upgrade_floor
  check_installed_code_present
  take_snapshot

  # From here, any failure rolls the database back to the snapshot AND records
  # the rollback so the updater can re-pin the previous image. The health check
  # (which now includes a front-door render, see healthcheck.sh) runs under this
  # trap too, so an upgrade that leaves the site serving a 404 rolls back and
  # reports failure instead of being declared healthy.
  trap 'log "upgrade failed — the converged site is not healthy"; restore_snapshot; write_result rolledback; recovery_hint; die "rolled back to pre-upgrade snapshot"' ERR

  # Drop the caches that pin extension FILE PATHS before anything bootstraps.
  #
  # Drupal resolves a module's path by scanning the filesystem exactly once, when
  # the service container is compiled (DrupalKernel::setExtensionData), then bakes
  # the result into the compiled container's `container.modules` parameter and the
  # `system.module.files` state entry. Both live in the DATABASE, which is a
  # mounted volume that survives an image swap — so after an upgrade that MOVES a
  # module on disk, the first thing to bootstrap would load the old path and die
  # with a "failed to open stream" fatal before updatedb could run.
  #
  # That is not hypothetical: flowdrop moved from web/modules/contrib/flowdrop to
  # web/modules/engine/flowdrop (composer.json installer-paths, so the appliance
  # image can cache the fast-moving engine tier as its own layer). Any future
  # release that relocates a module has the same exposure.
  #
  # This must be bootstrap-free, which rules out `drush cache:rebuild` — that has
  # to boot the very container we are trying to invalidate. `sql:query` only needs
  # the DB credentials, so it works even when the cached container is unloadable.
  # Cheap and idempotent, so it runs on every upgrade rather than being special-
  # cased to one release.
  log "invalidating cached extension paths (pre-bootstrap)"
  $DRUSH sql:query "TRUNCATE cache_container; DELETE FROM key_value WHERE collection = 'state' AND name = 'system.module.files';" \
    || die "could not invalidate the extension-path caches"

  # The migration engine: runs every pending hook_update_N / hook_post_update
  # (schema changes, data migrations, anything config:import can't express).
  $DRUSH updatedb -y --no-cache-clear

  # Re-assert the product's canonical config from the baked-in config/sync, so a
  # release ships config changes (new studios, fields, flows, blocks) without
  # hand-writing a post_update hook for each. This is SAFE because config_ignore
  # fences off the site-owned objects (system.site, model_roles, the brand/chrome/
  # identity trio, mail, language) — cim never touches them, so a site's name,
  # model picks and look survive every update. Credentials were never at risk:
  # they live in State, not config. See docker/README.md "Config on update" and
  # config/sync/config_ignore.settings.yml. Escape hatch: set
  # AINCIENT_IMPORT_CONFIG=0 to skip the import for a release.
  if [ "${AINCIENT_IMPORT_CONFIG:-1}" = "1" ]; then
    $DRUSH config:import -y
  else
    log "config:import skipped (AINCIENT_IMPORT_CONFIG=0)"
  fi

  $DRUSH cache:rebuild

  # Health check runs UNDER the rollback trap: an unhealthy upgrade rolls back
  # too, not just a failed migration.
  log "running health check"
  "$HEALTHCHECK_CMD"

  trap - ERR
  log "upgrade complete (healthy)"
}

# --- Main -------------------------------------------------------------------
main() {
  wait_for_db
  ensure_pgvector
  if site_installed; then
    # upgrade() runs its own health check under the rollback trap; on failure it
    # writes result=rolledback and exits non-zero before we reach here.
    upgrade
  else
    fresh_install
    # No snapshot on a fresh DB — nothing to roll back to, so just gate. The
    # health check renders the front page, so an install that finished without a
    # working front door (e.g. the demo homepage seed failed and page.front was
    # never repointed off the empty /node) fails HERE rather than booting a site
    # that greets its first visitor with a 404.
    log "running health check"
    "$HEALTHCHECK_CMD" || { write_result install-failed; recovery_hint; die "post-install health check failed"; }
  fi
  record_version
  write_result ok
  log "converge OK — site is on $($DRUSH status --field=drupal-version 2>/dev/null || echo '?')"
}

# Run only when executed directly; sourcing (e.g. from tests) exposes the
# functions without invoking the whole flow.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  main "$@"
fi
