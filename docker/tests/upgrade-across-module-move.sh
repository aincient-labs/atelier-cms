#!/usr/bin/env bash
#
# Validates the ONE scenario docker/tests/smoke.sh structurally cannot: an
# appliance installed by a PREVIOUS image upgrading to one where a module MOVED
# on disk.
#
# smoke.sh upgrades an image to ITSELF, so module paths are identical on both
# sides and the hazard never appears. But flowdrop just moved from
# web/modules/contrib/flowdrop to web/modules/engine/flowdrop (composer.json
# installer-paths, so the appliance can cache the fast-moving engine tier as its
# own layer), and Drupal resolves extension paths exactly once — at container
# compile time — then persists them in the DATABASE, in the compiled container's
# `container.modules` parameter and the `system.module.files` state entry. The DB
# is a mounted volume that survives the image swap, so without the pre-bootstrap
# cache invalidation in converge.sh's upgrade(), the first thing to bootstrap
# after the swap loads the OLD path and dies before updatedb can run.
#
# This test installs with the published :edge image (flowdrop in contrib), then
# recreates onto the locally built image (flowdrop in engine) against the same
# DB volume, and asserts the upgrade converges healthy and Drupal resolves the
# module at its new path.
#
#   OLD_IMAGE=ghcr.io/...:edge NEW_IMAGE=atelier-cms:split-test bash $0
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OLD_IMAGE="${OLD_IMAGE:-ghcr.io/aincient-labs/atelier-cms:edge}"
NEW_IMAGE="${NEW_IMAGE:-atelier-cms:split-test}"

export HASH_SALT="movetest-$(openssl rand -hex 16)"
export HTTP_PORT="${HTTP_PORT:-8098}"
COMPOSE=(docker compose -p atelier-movetest -f "$DOCKER_DIR/compose.yaml")

ok()  { printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
cleanup() { echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "== fresh install on the OLD image ($OLD_IMAGE) =="
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
AINCIENT_IMAGE="$OLD_IMAGE" "${COMPOSE[@]}" up -d >/dev/null

# Wait for the install to record its converge result.
for _ in $(seq 1 120); do
  r=$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' || true)
  [ -n "$r" ] && break
  sleep 5
done
[ "${r:-}" = "ok" ] || bad "old image did not install cleanly (converge.result='${r:-<none>}')"
ok "old image installed"

old_path=$("${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web \
  php:eval 'print \Drupal::service("extension.list.module")->getPath("flowdrop");' 2>/dev/null || true)
echo "     flowdrop path on the old image: ${old_path:-<unresolved>}"
case "$old_path" in
  *modules/contrib/flowdrop) ok "old image has flowdrop in the contrib tier (the move is real)" ;;
  *modules/engine/flowdrop)  echo "  note: old image ALREADY has the engine layout — the move is not exercised" ;;
  *) bad "could not resolve flowdrop on the old image" ;;
esac

echo "== upgrade onto the NEW image ($NEW_IMAGE), same DB volume =="
"${COMPOSE[@]}" exec -T app sh -c 'rm -f /shared/converge.result' >/dev/null 2>&1 || true
AINCIENT_IMAGE="$NEW_IMAGE" "${COMPOSE[@]}" up -d --force-recreate app >/dev/null

for _ in $(seq 1 120); do
  r=$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' || true)
  [ -n "$r" ] && break
  sleep 5
done

if [ "${r:-}" != "ok" ]; then
  echo "---- converge log (upgrade boot) ----"
  "${COMPOSE[@]}" logs --tail 120 app || true
  bad "upgrade across the module move did not converge (converge.result='${r:-<none>}')"
fi
ok "upgrade converged healthy across the module move"

new_path=$("${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web \
  php:eval 'print \Drupal::service("extension.list.module")->getPath("flowdrop");' 2>/dev/null || true)
case "$new_path" in
  *modules/engine/flowdrop) ok "flowdrop resolves at its new path ($new_path)" ;;
  *) bad "flowdrop did not move (resolved '$new_path')" ;;
esac

code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/" 2>/dev/null || true)
[ "$code" = "200" ] && ok "front door serves 200 after the upgrade" \
  || bad "front door returned '$code' after the upgrade"

echo "== PASS: upgrade across a module move is safe =="
