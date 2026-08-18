#!/usr/bin/env bash
#
# Validates the scenario every other upgrade test structurally misses: an
# appliance installed by the LAST RELEASED image upgrading to this build.
#
# smoke.sh upgrades an image to ITSELF, and upgrade-e2e.sh swaps between two
# images built from the SAME tree. In both, the database the migration runs
# against was written by the code being tested, so a migration that assumes
# unreleased state passes. Real installs are not shaped like that: they carry
# the config the last RELEASE shipped, and nothing since.
#
# That gap shipped a real abort. The Phase 6 post_update rewired the six agent
# workflows by node id, and was authored against a dev database that had already
# taken an unreleased commit's config. On a genuine v0.8.x site the ids it
# expected did not exist, `updatedb` aborted, and converge rolled the upgrade
# back — every appliance would have refused to move. Nothing caught it, because
# no test had ever installed an old site and upgraded it.
#
# The load-bearing assertion is not "the migration ran" but `config:status`:
# whatever route a release takes — post_update, config:import, or both — a
# converged site must END matching the config/sync it shipped with. That holds
# the invariant without naming any one release's migration, so this test does
# not need editing when the next release migrates something else.
#
# Heavy (pulls a released image, runs Docker + PostgreSQL); a CI lane, not a
# pre-commit hook. Requires the NEW image to be built already.
#
#   OLD_IMAGE   the released baseline. Defaults to :latest, which the release
#               workflow moves to each pinned vX.Y.Z (:edge would NOT do — it
#               follows main and carries the very unreleased state whose absence
#               this test exists to simulate).
#   NEW_IMAGE   the candidate build.
#
#   OLD_IMAGE=ghcr.io/...:v0.8.1 NEW_IMAGE=atelier-cms:candidate bash $0
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OLD_IMAGE="${OLD_IMAGE:-ghcr.io/aincient-labs/atelier-cms:latest}"
NEW_IMAGE="${NEW_IMAGE:-atelier-cms:candidate}"

export HASH_SALT="fromreleased-$(openssl rand -hex 16)"
export HTTP_PORT="${HTTP_PORT:-8099}"
COMPOSE=(docker compose -p atelier-fromreleased -f "$DOCKER_DIR/compose.yaml")

ok()  { printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
cleanup() { echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

drush() { "${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@"; }

await_converge() {
  local r=''
  for _ in $(seq 1 120); do
    r=$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' || true)
    [ -n "$r" ] && break
    sleep 5
  done
  printf '%s' "${r:-}"
}

echo "== fresh install on the RELEASED image ($OLD_IMAGE) =="
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
AINCIENT_IMAGE="$OLD_IMAGE" "${COMPOSE[@]}" up -d >/dev/null

r=$(await_converge)
[ "$r" = "ok" ] || bad "released image did not install cleanly (converge.result='${r:-<none>}')"
ok "released image installed"

# The same state key converge.sh stamps and reads back as recorded_version().
old_version=$(drush state:get aincient.appliance_version --format=string 2>/dev/null | tr -d '[:space:]' || true)
echo "     baseline version: ${old_version:-<unrecorded>}"

# The baseline must be a PINNED release. An edge build carries unreleased state
# and would silently reproduce the blind spot this test exists to remove.
case "$old_version" in
  *+edge.*) bad "baseline is an edge build ('$old_version') — point OLD_IMAGE at a released vX.Y.Z" ;;
esac

echo "== upgrade onto the CANDIDATE image ($NEW_IMAGE), same DB volume =="
"${COMPOSE[@]}" exec -T app sh -c 'rm -f /shared/converge.result' >/dev/null 2>&1 || true
AINCIENT_IMAGE="$NEW_IMAGE" "${COMPOSE[@]}" up -d --force-recreate app >/dev/null

r=$(await_converge)
if [ "$r" != "ok" ]; then
  echo "---- converge log (upgrade boot) ----"
  "${COMPOSE[@]}" logs --tail 200 app || true
  bad "upgrade from $old_version did not converge (converge.result='${r:-<none>}')"
fi
ok "upgrade from the released baseline converged healthy"

# THE assertion: the site now matches the config/sync this image shipped. A
# migration that half-ran, or a post_update that skipped a workflow it could not
# find, shows up here as a difference even when converge reported ok.
status=$(drush config:status --format=string 2>/dev/null || true)
if [ -n "$(printf '%s' "$status" | tr -d '[:space:]')" ]; then
  echo "---- config:status ----"
  printf '%s\n' "$status"
  bad "the upgraded site does not match config/sync"
fi
ok "config:status clean — the site matches the config/sync it shipped with"

# Config that a release DELETES has to actually go. cim processes deletes before
# creates and updates, so a retired node type is removed while workflows still
# declare it as a dependency; this asserts nothing cascaded the dependents away.
orphans=$(drush php:eval \
  'print implode(",", \Drupal::entityQuery("flowdrop_workflow")->accessCheck(FALSE)->execute());' \
  2>/dev/null || true)
for wf in aincient_operator_agent_loop aincient_pages_agent aincient_audit_agent \
          aincient_chrome_agent aincient_image_agent brand_studio; do
  case ",$orphans," in
    *",$wf,"*) ;;
    *) bad "workflow '$wf' did not survive the upgrade (a dependency removal cascaded?)" ;;
  esac
done
ok "all six agent workflows survived the config deletes"

# Every route THIS build declares has to exist at runtime, not just on disk.
#
# Drupal >= 11.4 parses *.routing.yml through the `file_parsing` cache bin, which
# survives cache clears by design and invalidates on filemtime alone — and the
# image stamps a fixed mtime for reproducible layers, so across an image swap a
# changed routing.yml looks unchanged and the site keeps the OLD image's parse.
# v0.8.2 shipped that way: aincient_onboarding.refresh_recommendations existed in
# the file, was absent from the router, and every console page load by an admin
# threw RouteNotFoundException from the code that links to it. converge.sh now
# truncates the bin; this asserts the outcome rather than the mechanism, so it
# also catches the next cache that pins a stale parse.
#
# Scoped to OUR modules on purpose: contrib and core legitimately remove routes
# during the ALTER pass (unmet _module_dependencies, views, rest), so a whole-site
# comparison would fail on routes that are absent by design.
missing=$(drush php:eval '
$dirs = array_filter(
  \Drupal::service("module_handler")->getModuleDirectories(),
  fn($d) => str_contains($d, "/modules/custom/")
);
$discovery = new \Drupal\Core\Discovery\YamlDiscovery("routing", $dirs);
$provider = \Drupal::service("router.route_provider");
$missing = [];
foreach ($discovery->findAll() as $routes) {
  unset($routes["route_callbacks"]);
  foreach (array_keys($routes) as $name) {
    try { $provider->getRouteByName($name); }
    catch (\Throwable $e) { $missing[] = $name; }
  }
}
print implode(",", $missing);' 2>/dev/null || true)
if [ -n "${missing//[[:space:]]/}" ]; then
  bad "routes this build declares are missing from the router after the upgrade: $missing"
fi
ok "every route our modules declare resolves after the upgrade (no stale parsed-YAML cache)"

code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/" 2>/dev/null || true)
[ "$code" = "200" ] && ok "front door serves 200 after the upgrade" \
  || bad "front door returned '$code' after the upgrade"

echo "== PASS: a released appliance upgrades to this build =="
