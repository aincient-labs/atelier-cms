#!/usr/bin/env bash
#
# Published-image hop tests — the `manager#2` data-loss incident, reproduced and
# then fixed, on REAL published images and a real PostgreSQL database.
#
# Unlike every sibling here, this suite does NOT build anything. It pulls the
# actual tags from GHCR, because the whole point is what the SHIPPED artifacts do
# to each other. It therefore needs network and is not part of the push gate; run
# it when the converge branch logic changes, or before sending a recovery route
# to a user.
#
# Three scenarios, each on its OWN volume set (`down -v` between them — the
# scenario-5 attempt that was withdrawn from the 0.5.1 release failed precisely
# because it leaked `/shared` and `private` state between scenarios):
#
#   1. ee6a1cf -> v0.4.2   the defect, reproduced. Expected to WIPE and report ok.
#                          Observational: v0.4.2 is a published image we cannot
#                          change, so this scenario REPORTS rather than asserts.
#                          It is here because it is the control — if it ever stops
#                          wiping, scenario 2 proves nothing.
#   2. ee6a1cf -> v0.5.1   the fix. Refuses, names the modules, database intact.
#   3. ee6a1cf -> v0.2.0 -> v0.5.1
#                          the recovery route offered to affected users. v0.2.0 is
#                          the one published release that still SHIPS the fourteen
#                          modules while requiring none of them and omitting all
#                          fourteen from config/sync/core.extension.yml — so their
#                          database takes the UPGRADE branch and `config:import`
#                          performs the uninstalls, under converge's snapshot.
#
# Background: DECISIONS 0348/0349/0353, plans/converge-branch-guard.md.
#
#   ./published-hops-e2e.sh              # all three
#   HOP_NO_TEARDOWN=1 ./published-hops-e2e.sh   # leave the last stack up to inspect
#
set -uo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PROJ=atelier_hops
COMPOSE=(docker compose -p "$PROJ" -f "$DOCKER_DIR/compose.yaml")
export COMPOSE_PROJECT_NAME="$PROJ"
export HASH_SALT="hops-$(openssl rand -hex 16)"
export HTTP_PORT=8096

REG=ghcr.io/aincient-labs/atelier-cms
IMG_INCIDENT=$REG:sha-ee6a1cf   # 2026-07-24; the reporter's build
IMG_DESTROYER=$REG:v0.4.2       # 2026-08-05; what wiped them
IMG_WAYPOINT=$REG:v0.2.0        # 2026-08-04; the recovery waypoint
IMG_CURRENT=$REG:v0.5.1         # the fix

# Installed on the incident build, shipped by no release after v0.2.0.
FOURTEEN="ai ai_agents ai_metering ai_observability ai_provider_anthropic
ai_provider_mistral ai_provider_nanobanana ai_provider_ollama ai_provider_openai
ai_provider_openrouter flowdrop_ai_provider flowdrop_chat gemini_provider modeler_api"

pass=0; fail=0
ok()  { echo "  ✓ $1"; pass=$((pass+1)); }
bad() { echo "  ✗ $1"; fail=$((fail+1)); }
drush() { "${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@" 2>/dev/null; }

cleanup() {
  if [ -n "${HOP_NO_TEARDOWN:-}" ]; then
    echo "== teardown SKIPPED (HOP_NO_TEARDOWN) =="
    echo "   remove: docker compose -p $PROJ -f $DOCKER_DIR/compose.yaml down -v"
    return 0
  fi
  echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Recreate `app` on $1 and wait for converge to record a result.
# Progress goes to STDERR — this function's STDOUT *is* the converge result.
boot() {
  local image="$1" label="$2" result="" i
  echo >&2; echo "== boot: $label ==" >&2
  export AINCIENT_IMAGE="$image"
  # Clear the previous boot's result so we read THIS one's.
  "${COMPOSE[@]}" exec -T app sh -c 'rm -f /shared/converge.result' >/dev/null 2>&1 || true
  "${COMPOSE[@]}" up -d --force-recreate app >/dev/null 2>&1
  for i in $(seq 1 100); do
    result="$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' 2>/dev/null | tr -d '[:space:]')"
    [ -n "$result" ] && break
    sleep 3
  done
  echo "   converge.result = ${result:-<none>}" >&2
  printf '%s' "$result"
}

# Query the DB service directly, not through drush: these must work while the
# app container is refusing to converge.
psql_1() {
  "${COMPOSE[@]}" exec -T db psql -U aincient -d aincient -tAc "$1" 2>/dev/null | tr -d '[:space:]'
}
marker_rows() { psql_1 "SELECT count(*) FROM node_field_data WHERE title = 'hop-marker-node';"; }
table_count() { psql_1 "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';"; }

converge_log() { "${COMPOSE[@]}" logs app 2>&1 | grep -E '\[converge\]' | tail -"${1:-14}" | sed 's/^/      /'; }

# Fresh volumes + the incident build + a marker row. Echoes "rows tables".
build_fixture() {
  "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
  export AINCIENT_IMAGE="$IMG_INCIDENT"
  "${COMPOSE[@]}" up -d db >/dev/null 2>&1
  sleep 5
  local r; r="$(boot "$IMG_INCIDENT" "the reporter's build ($IMG_INCIDENT)")"
  if [ "$r" != "ok" ]; then bad "fixture: ee6a1cf did not converge (got '${r:-none}')"; return 1; fi
  ok "fixture: ee6a1cf converged"
  drush php:eval '$n=\Drupal\node\Entity\Node::create(["type"=>"page","title"=>"hop-marker-node"]);$n->save();' >/dev/null 2>&1
  drush state:set hop.marker present >/dev/null 2>&1
  local rows; rows="$(marker_rows)"
  [ "$rows" = "1" ] && ok "fixture: marker node seeded" || { bad "fixture: marker node NOT seeded"; return 1; }
  return 0
}

# The premise the whole incident rests on. Assert it rather than assume it: if a
# future ee6a1cf-equivalent stops carrying these, the refusal scenarios would go
# green for the wrong reason.
assert_fourteen_installed() {
  local enabled n=0 m
  enabled="$(drush pm:list --status=enabled --field=name)"
  for m in $FOURTEEN; do
    printf '%s\n' "$enabled" | grep -qx "$m" || { n=$((n+1)); echo "      not installed: $m"; }
  done
  [ "$n" -eq 0 ] && ok "premise: all fourteen modules installed" \
                 || bad "premise BROKEN: $n of 14 not installed — later assertions are meaningless"
}

################################################################ scenario 1
scenario_incident() {
  echo; echo "########## 1. ee6a1cf → v0.4.2 — the defect, on the shipped image ##########"
  build_fixture || return
  assert_fourteen_installed
  local before after r
  before="$(table_count)"
  r="$(boot "$IMG_DESTROYER" "v0.4.2 (the image that destroyed their site)")"
  after="$(table_count)"
  echo "   marker rows: 1 → ${after:+$(marker_rows)}   tables: $before → ${after:-<gone>}"
  # Observational by construction — a published image we cannot fix. Reported,
  # never counted, so this suite's pass/fail reflects only OUR behaviour.
  if [ "$(marker_rows)" = "1" ]; then
    echo "  ! NOT reproduced: v0.4.2 left the data alone (result='$r')."
    echo "    Scenario 2 is weakened — it may be refusing a state that was never dangerous."
  else
    echo "  ! REPRODUCED: v0.4.2 wiped the database and reported result='$r'."
  fi
  converge_log 8
}

################################################################ scenario 2
scenario_refusal() {
  echo; echo "########## 2. ee6a1cf → v0.5.1 — the fix refuses ##########"
  build_fixture || return
  local before r
  before="$(table_count)"
  r="$(boot "$IMG_CURRENT" "v0.5.1 (current release)")"
  [ "$r" != "ok" ] && ok "refused (result='$r')" || bad "reported ok — a destructive branch ran"
  [ "$r" = "missing-code" ] && ok "refusal is missing-code (the guard that can name the cause)" \
                            || echo "  – refusal was '$r', not missing-code"
  [ "$(marker_rows)" = "1" ] && ok "DATA INTACT — marker node still present" \
                             || bad "DATA LOST — marker rows='$(marker_rows)'"
  [ "$(table_count)" = "$before" ] && ok "schema untouched ($before tables)" \
                                   || bad "table count changed: $before → $(table_count)"
  # The refusal must NAME the modules — an unexplained refusal sends the user to
  # support instead of to the waypoint.
  if "${COMPOSE[@]}" logs app 2>&1 | grep -q 'installed modules absent from this image'; then
    ok "refusal names the missing modules"
  else
    bad "refusal did not name the missing modules"
  fi
  converge_log 6
}

################################################################ scenario 3
scenario_recovery() {
  echo; echo "########## 3. ee6a1cf → v0.2.0 → v0.5.1 — the recovery route ##########"
  build_fixture || return
  assert_fourteen_installed
  drush config:set system.site name "HOP-MARKER-ORIGINAL" -y >/dev/null 2>&1

  local r
  r="$(boot "$IMG_WAYPOINT" "v0.2.0 (the waypoint)")"
  [ "$r" = "ok" ] && ok "v0.2.0 converged on their database (upgrade branch)" \
                  || { bad "v0.2.0 did not converge (got '${r:-none}')"; converge_log; return; }

  local enabled still=0 m
  enabled="$(drush pm:list --status=enabled --field=name)"
  for m in $FOURTEEN; do
    printf '%s\n' "$enabled" | grep -qx "$m" && { still=$((still+1)); echo "      still installed: $m"; }
  done
  [ "$still" -eq 0 ] && ok "config:import uninstalled all fourteen" \
                     || bad "$still of 14 still installed after v0.2.0"

  [ "$(marker_rows)" = "1" ] && ok "marker node survived the waypoint" || bad "marker node lost at v0.2.0"
  [ "$(drush config:get system.site name --format=string)" = "HOP-MARKER-ORIGINAL" ] \
    && ok "site config survived the waypoint" || bad "site config lost at v0.2.0"

  local pending
  pending="$(drush updatedb:status --format=string 2>&1 | tr -d '[:space:]')"
  { [ -z "$pending" ] || printf '%s' "$pending" | grep -qi 'nopending\|noupdate'; } \
    && ok "updatedb clean on v0.2.0" || bad "pending updates remain on v0.2.0: $pending"

  r="$(boot "$IMG_CURRENT" "v0.5.1 (current release)")"
  [ "$r" = "ok" ] && ok "v0.5.1 converged from the waypoint" \
                  || { bad "v0.5.1 did not converge (got '${r:-none}')"; converge_log; return; }

  [ "$(marker_rows)" = "1" ] && ok "marker node survived onto the current release" || bad "marker node lost at v0.5.1"
  [ "$(drush config:get system.site name --format=string)" = "HOP-MARKER-ORIGINAL" ] \
    && ok "site config survived onto the current release" || bad "site config lost at v0.5.1"

  local ver code
  ver="$("${COMPOSE[@]}" exec -T app sh -c 'printenv ATELIER_VERSION' 2>/dev/null | tr -d '[:space:]')"
  [ "$ver" = "v0.5.1" ] && ok "running v0.5.1" || bad "unexpected version stamp: '$ver'"
  # A DYNAMIC route, deliberately: `/` serves from the page cache and stays 200
  # through a site whose every compiled-container request 500s.
  code="$(docker run --rm --network "${PROJ}_default" curlimages/curl:latest \
          -s -o /dev/null -w '%{http_code}' http://app/user/login 2>/dev/null)"
  [ "$code" = "200" ] && ok "/user/login 200 after the route" || bad "/user/login returned $code"
}

echo "== pulling the published images =="
for i in "$IMG_INCIDENT" "$IMG_DESTROYER" "$IMG_WAYPOINT" "$IMG_CURRENT"; do
  docker pull -q "$i" >/dev/null 2>&1 && echo "   $i" || { echo "   FAILED to pull $i"; exit 1; }
done

scenario_incident
scenario_refusal
scenario_recovery

echo
echo "== result: $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
