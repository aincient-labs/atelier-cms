#!/usr/bin/env bash
#
# End-to-end test for the two upgrade REFUSALS — against real Drupal, real
# PostgreSQL and the real updater sidecar, which is the half converge.bats cannot
# reach. The bats suite drives converge with a mock drush, so it proves the
# branching; it cannot prove that the query actually reads PostgreSQL's bytea
# column, that a refusal leaves a real database untouched, or that the sidecar
# re-pins the previous image when it sees the new result values.
#
#   1. TOO OLD        — a site below the incoming image's declared upgrade floor.
#                       Expect the refusal in the log, naming the waypoint,
#                       converge.result=too-old, and NO snapshot taken.
#   2. MISSING CODE   — an installed module whose code the incoming image no
#                       longer ships (the shape of dropping drupal/ai from
#                       composer.json). Same containment, result=missing-code.
#                       This is the guard that reaches the sites the version floor
#                       cannot: their `edge+<sha>` stamp has no position in the
#                       version order at all.
#   3. CONTAINMENT    — the same refusal through the real updater sidecar: it must
#                       re-pin the app to the previous image and leave the site up.
#   4. FLOOR MET      — the control. An image whose floor this site satisfies must
#                       upgrade normally, or 1–3 prove only that we broke upgrading.
#
# 1 and 2 run converge DIRECTLY (see direct_converge) rather than through the
# sidecar, because the sidecar's own correctness destroys the evidence: it re-pins
# on refusal, so the refusing container and its log are gone before any assertion
# can read them. 3 then tests the sidecar on its own terms.
#
# Deliberately built on the same harness as upgrade-e2e.sh (ephemeral registry so
# `docker compose pull` does real work; isolated compose project so a dev stack is
# never touched; derived images pushed under one tag).
#
# Heavy (builds + runs Docker + a registry); a CI lane, not a pre-commit hook.
#
#   E2E_BASE_IMAGE   skip the appliance build and use this local image as the base
#                    (must already carry the current converge code).
#   E2E_REGISTRY_PORT / E2E_HTTP_PORT   override the host ports (defaults below).
#   E2E_NO_TEARDOWN  leave the stack + registry up on exit for inspection.
#
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$DOCKER_DIR/.." && pwd)"

PROJECT=atelier_floor_e2e
REG_NAME=atelier_floor_e2e_registry
REG_PORT="${E2E_REGISTRY_PORT:-36255}"
REG="localhost:${REG_PORT}"
IMAGE="${REG}/aincient/cms"
TAG="${IMAGE}:appliance"
HTTP_PORT="${E2E_HTTP_PORT:-8098}"

# The module we delete from the image in scenario 2. Installed (it is in
# config/sync/core.extension.yml) and contrib, so removing its directory models
# exactly what `composer remove` does to a package.
VICTIM_MODULE=redirect

export AINCIENT_IMAGE="$TAG"
export HASH_SALT="floor-e2e-$(openssl rand -hex 16)"
export HTTP_PORT
export COMPOSE_PROJECT_NAME="$PROJECT"
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
  docker rmi -f atelier-floor-e2e:raw atelier-floor-e2e:base atelier-floor-e2e:too-old \
    atelier-floor-e2e:missing-code atelier-floor-e2e:good >/dev/null 2>&1 || true
}
[ -n "${E2E_NO_TEARDOWN:-}" ] || trap cleanup EXIT

# --- helpers ----------------------------------------------------------------

app_cid()   { "${COMPOSE[@]}" ps -q app 2>/dev/null; }
app_image() { local c; c="$(app_cid)"; [ -n "$c" ] && docker inspect --format '{{.Image}}' "$c"; }
in_app()    { "${COMPOSE[@]}" exec -T app sh -c "$1"; }
shared()    { in_app "cat /shared/$1 2>/dev/null" 2>/dev/null | tr -d '\r\n'; }
http_code() { docker run --rm --network "${PROJECT}_default" curlimages/curl:latest \
                -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null; }

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

wait_status() { # expected timeout
  local want="$1" t="${2:-300}" waited=0 cur
  while [ "$waited" -lt "$t" ]; do
    cur="$(shared upgrade.status)"
    [ "$cur" = "$want" ] && return 0
    case "$cur" in done|failed) [ "$cur" = "$want" ] && return 0 || return 1 ;; esac
    sleep 3; waited=$((waited + 3))
  done
  return 1
}

# Build a candidate image FROM THE BASE, never from the current $TAG. Chaining
# derivations (which is right for upgrade-e2e.sh, where each step builds on the
# last) is wrong here: scenario 2 would inherit scenario 1's raised floor and be
# refused for the wrong reason, and the control would inherit scenario 2's deleted
# module. Each scenario must differ from the base in exactly one way.
build_candidate() { # local_tag extra_dockerfile
  printf 'FROM %s\n%s\n' "$BASE_TAG" "$2" \
    | docker build -q -t "$1" -f - "$DOCKER_DIR" >/dev/null
}

# Publish a locally-built candidate as the tag the stack pulls.
publish() { docker tag "$1" "$TAG" && docker push -q "$TAG" >/dev/null; }

request_upgrade() { in_app "rm -f /shared/upgrade.status; : > /shared/upgrade.request"; }

# Run ONE image's converge against the live database, with no sidecar in the loop:
# same compose service (so identical env, DB, /shared and private volumes), but
# --entrypoint bypasses entrypoint.sh so apache never starts and we get converge's
# own exit status and output directly.
#
# This is how the refusal MESSAGE gets asserted at all. Through the sidecar the
# assertions are unwinnable by construction: the moment converge refuses, the
# updater re-pins the app to the previous image, so by the time a poll runs, the
# refusing container is gone (taking its log with it) and the recovery converge on
# the old image has since taken a snapshot of its own — which is exactly how the
# first version of this test managed to report "a snapshot exists" and "no refusal
# logged" about a refusal that had worked perfectly.
direct_converge() { # image
  AINCIENT_IMAGE="$1" "${COMPOSE[@]}" run --rm --no-deps \
    --entrypoint /usr/local/bin/converge.sh app 2>&1
}

no_snapshot() { in_app "test ! -e /opt/drupal/private/snapshots/pre-converge.sql.gz"; }

# Assert a refusal was contained: the expected result value, the app back on the
# previous image, and the site still serving.
assert_contained() { # expected_result previous_image label
  local want="$1" prev="$2" label="$3"
  if wait_status failed 300; then
    ok "$label: updater reported status=failed"
  else
    bad "$label: did not reach status=failed (got '$(shared upgrade.status)')"
  fi
  [ "$(shared converge.result)" = "$want" ] \
    && ok "$label: converge.result=$want" \
    || bad "$label: converge.result='$(shared converge.result)', expected '$want'"
  local now; now="$(app_image)"
  [ "$now" = "$prev" ] \
    && ok "$label: app RE-PINNED to the previous image" \
    || bad "$label: app NOT rolled back (on '$now', expected '$prev')"
  [ "$(wait_http http://app/ 120)" = "200" ] \
    && ok "$label: console still reachable" \
    || bad "$label: console not 200"
}

# --- 0. ephemeral registry + base image -------------------------------------

info "start ephemeral registry on :${REG_PORT}"
docker rm -f "$REG_NAME" >/dev/null 2>&1 || true
docker run -d --name "$REG_NAME" -p "${REG_PORT}:5000" registry:2 >/dev/null
for _ in $(seq 1 20); do curl -fsS "http://${REG}/v2/" >/dev/null 2>&1 && break; sleep 1; done

RAW_BASE=atelier-floor-e2e:raw
BASE_TAG=atelier-floor-e2e:base

if [ -n "${E2E_BASE_IMAGE:-}" ]; then
  info "using base image ${E2E_BASE_IMAGE}"
  docker tag "$E2E_BASE_IMAGE" "$RAW_BASE"
else
  info "building appliance image (this is the slow part)"
  docker build -f "$DOCKER_DIR/Dockerfile" -t "$RAW_BASE" "$REPO_ROOT" >/dev/null
fi
# Stamp the base as a released version, so the site RECORDS one and the floor has
# something to compare against. An unstamped build records nothing on purpose.
printf 'FROM %s\nENV ATELIER_VERSION=v0.1.0\n' "$RAW_BASE" \
  | docker build -q -t "$BASE_TAG" -f - "$DOCKER_DIR" >/dev/null
publish "$BASE_TAG"

# --- 1. fresh install, which records its version ----------------------------

info "fresh install (v0.1.0)"
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d >/dev/null
if wait_log app "converge OK" 300; then ok "fresh install converged"; else bad "fresh install did not converge"; fi
[ "$(wait_http http://app/ 120)" = "200" ] && ok "console reachable" || bad "console not 200"

RECORDED="$(in_app "/opt/drupal/vendor/bin/drush --root=/opt/drupal/web state:get aincient.appliance_version --format=string" 2>/dev/null | tr -d '\r\n[:space:]')"
[ "$RECORDED" = "v0.1.0" ] \
  && ok "site recorded its version (aincient.appliance_version=v0.1.0)" \
  || bad "version not recorded (got '$RECORDED')"

BASE_IMAGE_ID="$(app_image)"
no_snapshot && ok "no snapshot yet (a fresh install has nothing to roll back)" \
            || bad "unexpected snapshot after fresh install"

# Both candidates, each one change away from the base.
build_candidate atelier-floor-e2e:too-old 'ENV ATELIER_VERSION=v0.9.0
RUN printf "0.9.0\n" > /etc/atelier/upgrade-floor'
build_candidate atelier-floor-e2e:missing-code "ENV ATELIER_VERSION=v0.2.0
RUN rm -rf /opt/drupal/web/modules/contrib/${VICTIM_MODULE}"

# --- 2. TOO OLD, directly ---------------------------------------------------

info "scenario 1: an image whose floor (0.9.0) this v0.1.0 site is below"
OUT="$(direct_converge atelier-floor-e2e:too-old || true)"
grep -q "REFUSING TO UPGRADE" <<<"$OUT" \
  && ok "too-old: refused" || bad "too-old: no refusal (output: $(tail -3 <<<"$OUT"))"
grep -q "0.9.0" <<<"$OUT" \
  && ok "too-old: names the version to go through" || bad "too-old: does not name the waypoint"
[ "$(shared converge.result)" = "too-old" ] \
  && ok "too-old: converge.result=too-old" \
  || bad "too-old: converge.result='$(shared converge.result)'"
# The refusal's whole promise: it happens before the snapshot, so nothing was
# migrated and there is nothing to restore.
no_snapshot && ok "too-old: NO snapshot taken (refused before touching the database)" \
            || bad "too-old: a snapshot exists — the refusal ran too late"
grep -qE "updatedb|config:import" <<<"$OUT" \
  && bad "too-old: it ran migration steps anyway" \
  || ok "too-old: no migration step ran"

# --- 3. MISSING CODE, directly ----------------------------------------------

info "scenario 2: an image missing an INSTALLED module's code (${VICTIM_MODULE})"
OUT="$(direct_converge atelier-floor-e2e:missing-code || true)"
grep -q "missing code your site uses" <<<"$OUT" \
  && ok "missing-code: refused" || bad "missing-code: no refusal (output: $(tail -3 <<<"$OUT"))"
grep -q "$VICTIM_MODULE" <<<"$OUT" \
  && ok "missing-code: names the module (${VICTIM_MODULE})" \
  || bad "missing-code: does not name the module"
[ "$(shared converge.result)" = "missing-code" ] \
  && ok "missing-code: converge.result=missing-code" \
  || bad "missing-code: converge.result='$(shared converge.result)'"
no_snapshot && ok "missing-code: NO snapshot taken" \
            || bad "missing-code: a snapshot exists — the refusal ran too late"

# --- 4. CONTAINMENT through the real sidecar --------------------------------

info "scenario 3: the updater must contain a refusal (re-pin, site stays up)"
publish atelier-floor-e2e:too-old
request_upgrade
assert_contained too-old "$BASE_IMAGE_ID" "sidecar"

# --- 5. FLOOR MET (control) -------------------------------------------------

info "scenario 4 (control): an image this site DOES satisfy must upgrade"
build_candidate atelier-floor-e2e:good 'ENV ATELIER_VERSION=v0.2.0
RUN printf "0.1.0\n" > /etc/atelier/upgrade-floor'
publish atelier-floor-e2e:good
request_upgrade
if wait_status done 300; then ok "control: updater reported status=done"; else bad "control: did not reach status=done (got '$(shared upgrade.status)')"; fi
[ "$(shared converge.result)" = "ok" ] && ok "control: converge.result=ok" || bad "control: result not ok"
[ "$(app_image)" != "$BASE_IMAGE_ID" ] && ok "control: app moved to the new image" || bad "control: app did not move"
[ "$(wait_http http://app/ 120)" = "200" ] && ok "control: console reachable after upgrade" || bad "control: console not 200"
RECORDED="$(in_app "/opt/drupal/vendor/bin/drush --root=/opt/drupal/web state:get aincient.appliance_version --format=string" 2>/dev/null | tr -d '\r\n[:space:]')"
[ "$RECORDED" = "v0.2.0" ] \
  && ok "control: the successful upgrade re-recorded the version (v0.2.0)" \
  || bad "control: version not updated (got '$RECORDED')"

# --- result -----------------------------------------------------------------
info "result: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
