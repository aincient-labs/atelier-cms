#!/usr/bin/env bash
#
# Integration smoke test for the appliance — the REAL container, real Drupal.
# Codifies the critical user experiences verified by hand on 2026-05-31:
#   fresh install (from config/sync) · console reachability · upgrade branch · rollback.
#
# Heavier than the bats unit tests (builds + runs Docker); meant for a CI lane.
# Runs in an ISOLATED compose project so it never touches a running dev stack.
#
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE=(docker compose -p atelier_smoke -f "$DOCKER_DIR/compose.yaml")
NET=atelier_smoke_default
export AINCIENT_IMAGE=aincient/cms:smoke
export HASH_SALT="smoke-$(openssl rand -hex 16)"
export HTTP_PORT=8099

pass=0; fail=0
ok()   { echo "  ✓ $1"; pass=$((pass+1)); }
bad()  { echo "  ✗ $1"; fail=$((fail+1)); }
drush() { "${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@"; }
http()  { docker run --rm --network "$NET" curlimages/curl:latest -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null; }

cleanup() { echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "== build image =="
# The Dockerfile vendor stage authenticates composer to the GitHub API with a
# BuildKit secret (github-oauth token) to dodge the 60/hr anonymous rate limit.
# All deps (incl. flowdrop, from public drupal.org) are public, so any
# GITHUB_TOKEN works; locally it falls back to `gh auth token` if present, and
# an empty token just means anonymous. Diagnostic: report the token LENGTH
# (never the value) so a missing/empty secret is visible in the log.
_tok="${GITHUB_TOKEN:-$(gh auth token 2>/dev/null || true)}"
export GITHUB_TOKEN="$_tok"
echo "   GITHUB_TOKEN present in build env: length=${#_tok}"
build_secret=()
if [ -n "$_tok" ]; then
  build_secret=(--secret "id=composer_github_token,env=GITHUB_TOKEN")
fi
# CI opts into BuildKit layer caching by setting AINCIENT_BUILD_CACHE (e.g. "gha"
# in GitHub Actions, with buildx + the cache runtime token exposed). The vendor
# (composer) + apt layers then cache-hit whenever composer.lock is unchanged,
# shaving minutes off every run. scope=appliance is shared with the release
# workflow's publish build so they reuse each other's amd64 layers. Left unset
# locally → a plain single-arch build, exactly as before.
#
# ignore-error=true: the cache export is a pure optimization. When the GHA cache
# backend has an outage (HTTP 400 "services aren't available"), a fatal export
# error would otherwise kill an otherwise-green build. Treat a failed write as a
# cache miss, not a build failure.
if [ -n "${AINCIENT_BUILD_CACHE:-}" ]; then
  docker buildx build --load \
    --cache-from "type=${AINCIENT_BUILD_CACHE},scope=appliance" \
    --cache-to "type=${AINCIENT_BUILD_CACHE},scope=appliance,mode=max,ignore-error=true" \
    "${build_secret[@]}" \
    -f "$DOCKER_DIR/Dockerfile" -t "$AINCIENT_IMAGE" "$DOCKER_DIR/.." >/dev/null
else
  DOCKER_BUILDKIT=1 docker build "${build_secret[@]}" \
    -f "$DOCKER_DIR/Dockerfile" -t "$AINCIENT_IMAGE" "$DOCKER_DIR/.." >/dev/null
fi

echo "== fresh install (clean DB) =="
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
"${COMPOSE[@]}" up -d db app >/dev/null
# Wait for converge to finish (health check passes → container healthy).
for _ in $(seq 1 60); do
  "${COMPOSE[@]}" logs app 2>&1 | grep -q "converge OK" && break
  sleep 3
done

echo "== assert: install-from-config brought up the stack =="
for m in aincient_core aincient_chat aincient_assistant_ui ai ai_provider_anthropic key; do
  if drush pm:list --status=enabled --field=name 2>/dev/null | grep -qx "$m"; then ok "module enabled: $m"; else bad "module NOT enabled: $m"; fi
done
if drush php:eval 'print \Drupal::entityTypeManager()->getStorage("user_role")->load("content_editor")->hasPermission("use aincient operator console") ? "Y" : "N";' 2>/dev/null | grep -q Y; then
  ok "console permission granted to content_editor"; else bad "console permission NOT granted"; fi

echo "== assert: console reachability =="
[ "$(http http://app/)" = "200" ]           && ok "front page 200"            || bad "front page not 200"
[ "$(http http://app/user/login)" = "200" ] && ok "/user/login 200"           || bad "/user/login not 200"
[ "$(http http://app/atelier)" = "403" ]    && ok "/atelier 403 for anon"    || bad "/atelier not 403 for anon"

echo "== assert: files tree is www-data-owned after converge (upload/derivative writability) =="
# Regression guard: converge runs drush AS ROOT (site:install, cache:rebuild,
# demo seed), creating root-owned subdirs inside sites/default/files. If the
# entrypoint's post-converge chown regresses, www-data can't write image-style
# derivatives → uploads render as broken images. Assert nothing under files/ is
# left owned by root, then prove www-data can actually write into styles/.
strays="$("${COMPOSE[@]}" exec -T app sh -c 'find /opt/drupal/web/sites/default/files ! -user www-data -printf "%u %p\n" 2>/dev/null | head -20')"
if [ -z "$strays" ]; then ok "no non-www-data paths under files/"; else bad "root-owned paths under files/ (uploads/derivatives will fail):"; printf '%s\n' "$strays" | sed 's/^/      /'; fi
# Prove www-data can write a fresh nested path under files/ — mirrors a real
# upload landing in a month dir / an image style writing a derivative subtree.
if "${COMPOSE[@]}" exec -T -u www-data app sh -c 'd=/opt/drupal/web/sites/default/files/.smoke/styles; mkdir -p "$d" && touch "$d/probe" && rm -rf /opt/drupal/web/sites/default/files/.smoke' 2>/dev/null; then
  ok "www-data can write a nested subtree under files/"; else bad "www-data CANNOT write under files/ (broken uploads/thumbnails)"; fi

echo "== assert: upgrade branch (recreate on same DB) =="
# Clear the prior converge result so we read THIS upgrade's outcome, not the
# fresh-install one (converge writes /shared/converge.result = ok|rolledback on
# every boot; the upgrade-signal volume carries it across the recreate).
"${COMPOSE[@]}" exec -T app sh -c 'rm -f /shared/converge.result' 2>/dev/null || true
"${COMPOSE[@]}" up -d --force-recreate app >/dev/null
# Wait for the upgrade converge to record its deterministic result FILE — not a
# `compose logs` grep, which can buffer/drop the line under some Docker engines
# (the snapshot assert below already uses a file for this same reason). updatedb
# + a full config:import + rebuild + health gate is as heavy as a fresh install
# (which gets 180s), so give it a comparable window.
result=""
for _ in $(seq 1 80); do
  # `|| true`: the file is absent on early iterations, and a failing command
  # substitution would otherwise trip `set -e` and abort the whole script.
  result="$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' | tr -d '[:space:]')" || true
  [ -n "$result" ] && break
  sleep 3
done
# Assert the snapshot ARTIFACT (the rollback safety net) independently of outcome.
if "${COMPOSE[@]}" exec -T app test -f /opt/drupal/private/snapshots/pre-converge.sql.gz; then ok "upgrade snapshotted the DB"; else bad "no snapshot on upgrade"; fi
if [ "$result" = "ok" ]; then
  ok "upgrade converged healthy"
else
  bad "upgrade did not converge (converge.result='${result:-<none>}')"
  # Self-diagnose: surface the converge/health log from the upgrade boot so a
  # real failure (rollback, pending updates, config:import error) is visible in
  # CI instead of just a red ✗.
  echo "---- converge/health log (upgrade boot) ----"
  "${COMPOSE[@]}" logs app 2>&1 | grep -E '\[converge\]|\[health\]|\[entrypoint\]' | tail -40
  echo "--------------------------------------------"
fi

echo "== assert: rollback round-trip reverts a change =="
drush config:set system.site name "SMOKE-GOOD" -y >/dev/null
drush sql:dump --gzip --result-file=/opt/drupal/private/snapshots/smoke.sql >/dev/null
drush config:set system.site name "SMOKE-BROKEN" -y >/dev/null
drush sql:drop -y >/dev/null
"${COMPOSE[@]}" exec -T app sh -c 'zcat /opt/drupal/private/snapshots/smoke.sql.gz | /opt/drupal/vendor/bin/drush --root=/opt/drupal/web sql:cli' >/dev/null 2>&1
drush cache:rebuild >/dev/null
if drush config:get system.site name --format=string 2>/dev/null | grep -q "SMOKE-GOOD"; then ok "rollback reverted the change"; else bad "rollback did NOT revert"; fi

echo "== result: $pass passed, $fail failed =="
[ "$fail" -eq 0 ]
