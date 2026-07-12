#!/usr/bin/env sh
#
# AIncient updater sidecar — the one-click upgrade actuator.
#
# Drupal cannot pull its own code (new PHP only arrives as a new image), so the
# console can't restart itself. Instead the console writes a request flag to the
# shared volume; this sidecar — which has Docker access — performs the actual
# image pull + container recreate. The recreated app then converges itself.
#
# Protocol (shared volume, mounted at /shared in both app and updater):
#   /shared/upgrade.request   written by the console to ask for an upgrade
#   /shared/upgrade.status    written here: pending | running | done | failed
#   /shared/converge.result   written by the app's converge.sh: ok | rolledback
#
# Why we can't trust `docker compose up` exit alone: the app entrypoint starts
# the webserver EVEN WHEN converge rolls back (so the operator can inspect the
# rolled-back site). So container-start always "succeeds". To tell a healthy
# upgrade from a rolled-back one we read converge.result, and on rollback we
# re-pin the app to the image digest it was running before the pull — DB and
# code both return to the last-known-good state.
#
set -eu

PROJECT_DIR="${COMPOSE_PROJECT_DIR:-/project}"
APP_SERVICE="${APP_SERVICE:-app}"
REQUEST=/shared/upgrade.request
STATUS=/shared/upgrade.status
RESULT=/shared/converge.result
# How long to wait for the recreated app to finish converging (seconds).
CONVERGE_TIMEOUT="${AINCIENT_CONVERGE_TIMEOUT:-300}"

COMPOSE="docker compose --project-directory $PROJECT_DIR -f $PROJECT_DIR/docker/compose.yaml"

log() { printf '[updater] %s\n' "$*"; }
set_status() { printf '%s' "$1" > "$STATUS"; }

# The image digest (content-addressable ID) the app service is running right now.
# Runnable as-is without a registry, so it's our rollback target.
current_image_id() {
  cid="$($COMPOSE ps -q "$APP_SERVICE" 2>/dev/null || true)"
  [ -n "$cid" ] && docker inspect --format '{{.Image}}' "$cid" 2>/dev/null || true
}

# Wait for the recreated app to write its convergence outcome. Echoes the result
# (ok|rolledback|install-failed) or "timeout".
await_converge() {
  waited=0
  while [ "$waited" -lt "$CONVERGE_TIMEOUT" ]; do
    if [ -f "$RESULT" ]; then cat "$RESULT"; return 0; fi
    sleep 3; waited=$((waited + 3))
  done
  echo timeout
}

log "watching ${REQUEST} for upgrade requests"
while true; do
  if [ -f "$REQUEST" ]; then
    log "upgrade requested"
    rm -f "$REQUEST"
    set_status running

    # Remember the good image, and clear the previous run's outcome so we only
    # read the result this recreate produces.
    PREV_IMAGE="$(current_image_id)"
    rm -f "$RESULT"
    log "current image: ${PREV_IMAGE:-unknown}"

    # Pull the newest image for the app service and recreate just that service.
    # `up -d` recreates the container → its entrypoint runs converge.sh, which
    # snapshots + migrates + health-checks (and rolls back the DB on failure).
    if ! $COMPOSE up -d --pull always "$APP_SERVICE"; then
      log "recreate failed before converge — leaving app as-is"
      set_status failed
      continue
    fi

    RESULT_VAL="$(await_converge)"
    log "converge result: ${RESULT_VAL}"

    case "$RESULT_VAL" in
      ok)
        log "app converged healthy on the new image"
        set_status done
        ;;
      *)
        # rolledback | install-failed | timeout → the new image is bad. The DB
        # was already rolled back by converge; now re-pin the app to the
        # previous image so code matches the restored DB. The old image is still
        # present locally, so --pull never avoids re-fetching the bad tag.
        if [ -n "$PREV_IMAGE" ]; then
          log "upgrade unhealthy (${RESULT_VAL}) — re-pinning app to ${PREV_IMAGE}"
          AINCIENT_IMAGE="$PREV_IMAGE" $COMPOSE up -d --pull never --force-recreate "$APP_SERVICE" \
            && log "rolled back to previous image" \
            || log "WARN: image rollback failed — app is on the new image; manual recovery needed"
        else
          log "WARN: no previous image recorded — cannot re-pin; app is on the new image"
        fi
        set_status failed
        ;;
    esac
  fi
  sleep 5
done
