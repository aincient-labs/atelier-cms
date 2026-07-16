#!/usr/bin/env bash
#
# End-to-end test for the one-click upgrade path — the updater SIDECAR driving a
# real image swap, the thing converge.bats (mock drush) and smoke.sh (no sidecar)
# can't cover. It exercises the full production protocol:
#
#   console writes /shared/upgrade.request
#     → updater pulls the new image + recreates `app`
#       → app entrypoint runs converge.sh (snapshot → updatedb → health)
#         → converge writes /shared/converge.result
#           → updater reads it: ok → status=done; rolledback → re-pin previous image
#
# It self-hosts an ephemeral registry (so `docker compose pull` does real work —
# a plain local tag is a pull no-op) and runs in an ISOLATED compose project, so
# it never touches a dev stack. Two scenarios:
#
#   1. GOOD upgrade   — push a new digest → request → assert status=done, app on
#                       the new image, site healthy.
#   2. BROKEN upgrade — push an image whose health gate fails → request → assert
#                       converge rolls the DB back, the updater RE-PINS the app to
#                       the previous image (status=failed), and the site stays up.
#
# Heavy (builds + runs Docker + a registry); a CI lane, not a pre-commit hook.
#
#   E2E_BASE_IMAGE   skip the appliance build and use this local image as the
#                    base (must already carry the current converge/updater code).
#   E2E_REGISTRY_PORT / E2E_HTTP_PORT   override the host ports (defaults below).
#   E2E_NO_TEARDOWN  leave the stack + registry up on exit for inspection
#                    (default: tear everything down).
#
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$DOCKER_DIR/.." && pwd)"

PROJECT=atelier_e2e
REG_NAME=atelier_e2e_registry
REG_PORT="${E2E_REGISTRY_PORT:-36254}"
REG="localhost:${REG_PORT}"
IMAGE="${REG}/aincient/cms"
TAG="${IMAGE}:appliance"            # the single tag the operator pins; we re-push it
HTTP_PORT="${E2E_HTTP_PORT:-8097}"

export AINCIENT_IMAGE="$TAG"
export HASH_SALT="e2e-$(openssl rand -hex 16)"
export HTTP_PORT
export COMPOSE_PROJECT_NAME="$PROJECT"
# Test the PRODUCTION default: an empty admin password makes converge mint a
# random one (the hardening we assert below). Override the dev docker/.env, which
# pins ADMIN_PASS=admin for local convenience and would otherwise leak in here.
export ADMIN_PASS=""

COMPOSE=(docker compose -p "$PROJECT" -f "$DOCKER_DIR/compose.yaml")

pass=0; fail=0
ok()  { echo "  ✓ $1"; pass=$((pass+1)); }
bad() { echo "  ✗ $1"; fail=$((fail+1)); }
info(){ echo "== $1 =="; }

cleanup() {
  info "teardown"
  "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
  docker rm -f "$REG_NAME" >/dev/null 2>&1 || true
}
[ -n "${E2E_NO_TEARDOWN:-}" ] || trap cleanup EXIT

# --- helpers ----------------------------------------------------------------

app_cid()    { "${COMPOSE[@]}" ps -q app 2>/dev/null; }
app_image()  { local c; c="$(app_cid)"; [ -n "$c" ] && docker inspect --format '{{.Image}}' "$c"; }
in_app()     { "${COMPOSE[@]}" exec -T app sh -c "$1"; }
shared()     { in_app "cat /shared/$1 2>/dev/null" 2>/dev/null | tr -d '\r\n'; }
http_code()  { docker run --rm --network "${PROJECT}_default" curlimages/curl:latest \
                 -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null; }

# Poll until the URL answers 200 (the app may still be converging). Echoes the
# final code; returns 0 only on 200.
wait_http() { # url timeout
  local url="$1" t="${2:-120}" waited=0 code
  while [ "$waited" -lt "$t" ]; do
    code="$(http_code "$url")"
    [ "$code" = "200" ] && { echo 200; return 0; }
    sleep 3; waited=$((waited + 3))
  done
  echo "${code:-000}"; return 1
}

wait_log() { # service marker timeout
  local svc="$1" marker="$2" t="${3:-180}" waited=0
  while [ "$waited" -lt "$t" ]; do
    "${COMPOSE[@]}" logs "$svc" 2>&1 | grep -q "$marker" && return 0
    sleep 3; waited=$((waited + 3))
  done
  return 1
}

wait_status() { # expected timeout — poll /shared/upgrade.status
  local want="$1" t="${2:-300}" waited=0 cur
  while [ "$waited" -lt "$t" ]; do
    cur="$(shared upgrade.status)"
    [ "$cur" = "$want" ] && return 0
    case "$cur" in done|failed) [ "$cur" = "$want" ] && return 0 || return 1 ;; esac
    sleep 3; waited=$((waited + 3))
  done
  return 1
}

# Push a new digest under $TAG, derived from $1, with extra Dockerfile lines $2.
push_derived() { # base extra_dockerfile
  local base="$1" extra="$2"
  printf 'FROM %s\n%s\n' "$base" "$extra" | docker build -q -t "$TAG" -f - "$DOCKER_DIR" >/dev/null
  docker push -q "$TAG" >/dev/null
}

# --- 0. ephemeral registry + base image -------------------------------------

info "start ephemeral registry on :${REG_PORT}"
docker rm -f "$REG_NAME" >/dev/null 2>&1 || true
docker run -d --name "$REG_NAME" -p "${REG_PORT}:5000" registry:2 >/dev/null
for _ in $(seq 1 20); do curl -fsS "http://${REG}/v2/" >/dev/null 2>&1 && break; sleep 1; done

if [ -n "${E2E_BASE_IMAGE:-}" ]; then
  info "using base image ${E2E_BASE_IMAGE}"
  docker tag "$E2E_BASE_IMAGE" "$TAG"
else
  info "building appliance image (this is the slow part)"
  docker build -f "$DOCKER_DIR/Dockerfile" -t "$TAG" "$REPO_ROOT" >/dev/null
fi
docker push -q "$TAG" >/dev/null
V1_LOCAL="$(docker inspect --format '{{.Id}}' "$TAG")"

# --- 1. fresh install -------------------------------------------------------

info "fresh install via the repo compose (db + app + updater)"
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d >/dev/null
if wait_log app "converge OK" 240; then ok "fresh install converged"; else bad "fresh install did not converge"; fi
[ "$(shared converge.result)" = "ok" ] && ok "converge.result=ok recorded" || bad "converge.result not ok"
[ "$(wait_http http://app/ 120)" = "200" ] && ok "console reachable (200)" || bad "console not 200"
in_app "test -s /opt/drupal/private/INITIAL_ADMIN_PASSWORD" && ok "random admin password minted" || bad "no INITIAL_ADMIN_PASSWORD"
GOOD_IMAGE="$(app_image)"
echo "  · app running image: ${GOOD_IMAGE}"

# --- 2. GOOD upgrade --------------------------------------------------------

info "push a NEW digest under the same tag, then request an upgrade"
push_derived "$TAG" "LABEL com.aincient.e2e=\"v2-good\""
V2_LOCAL="$(docker inspect --format '{{.Id}}' "$TAG")"
[ "$V2_LOCAL" != "$V1_LOCAL" ] && ok "new image has a different digest" || bad "digest did not change"

in_app "rm -f /shared/upgrade.status; : > /shared/upgrade.request"
if wait_status done 300; then ok "updater reported status=done"; else bad "upgrade did not reach status=done (got '$(shared upgrade.status)')"; fi
[ "$(shared converge.result)" = "ok" ] && ok "upgrade converged (result=ok)" || bad "upgrade result not ok"
NEW_IMAGE="$(app_image)"
[ "$NEW_IMAGE" != "$GOOD_IMAGE" ] && ok "app recreated on the new image" || bad "app still on the old image"
[ "$(wait_http http://app/ 120)" = "200" ] && ok "console still reachable after upgrade" || bad "console not 200 after upgrade"
GOOD_IMAGE="$NEW_IMAGE"

# --- 3. BROKEN upgrade → image rollback -------------------------------------

info "push a HEALTH-BREAKING image, request upgrade, expect rollback to previous image"
push_derived "$TAG" "RUN printf '#!/usr/bin/env bash\\nexit 1\\n' > /usr/local/bin/healthcheck.sh && chmod +x /usr/local/bin/healthcheck.sh"

in_app "rm -f /shared/upgrade.status; : > /shared/upgrade.request"
if wait_status failed 300; then ok "updater reported status=failed"; else bad "broken upgrade did not reach status=failed (got '$(shared upgrade.status)')"; fi
ROLLED_IMAGE="$(app_image)"
[ "$ROLLED_IMAGE" = "$GOOD_IMAGE" ] && ok "app RE-PINNED to the previous image" || bad "app NOT rolled back (on '$ROLLED_IMAGE', expected '$GOOD_IMAGE')"
[ "$(wait_http http://app/ 120)" = "200" ] && ok "console still reachable after rollback" || bad "console not 200 after rollback"

# --- result -----------------------------------------------------------------
info "result: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
