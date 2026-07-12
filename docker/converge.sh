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
# When the updater mounts /shared, converge reports its outcome here so the
# sidecar can tell a healthy upgrade from one that rolled back (the server is
# started either way, so container-start alone can't signal failure).
SHARED_DIR="${AINCIENT_SHARED_DIR:-/shared}"

log() { printf '[converge] %s\n' "$*"; }
die() { printf '[converge] FATAL: %s\n' "$*" >&2; exit 1; }

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
    --site-name="AIncient CMS"

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
  take_snapshot

  # From here, any failure rolls the database back to the snapshot AND records
  # the rollback so the updater can re-pin the previous image.
  trap 'log "upgrade failed"; restore_snapshot; write_result rolledback; die "rolled back to pre-upgrade snapshot"' ERR

  # The migration engine: runs every pending hook_update_N / hook_post_update
  # (schema changes, data migrations, anything config:import can't express).
  $DRUSH updatedb -y --no-cache-clear

  # Re-assert the product's canonical config from the baked-in config/sync, so a
  # release ships config changes (new studios, fields, flows, blocks) without
  # hand-writing a post_update hook for each. This is SAFE because config_ignore
  # fences off the site-owned objects (system.site, ai.settings, model_roles,
  # provider settings) — cim never touches them, so a site's name/model/API
  # choices survive every update. See docker/README.md "Config on update" and
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
    # No snapshot on a fresh DB — nothing to roll back to, so just gate.
    log "running health check"
    "$HEALTHCHECK_CMD" || { write_result install-failed; die "post-install health check failed"; }
  fi
  write_result ok
  log "converge OK — site is on $($DRUSH status --field=drupal-version 2>/dev/null || echo '?')"
}

# Run only when executed directly; sourcing (e.g. from tests) exposes the
# functions without invoking the whole flow.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  main "$@"
fi
