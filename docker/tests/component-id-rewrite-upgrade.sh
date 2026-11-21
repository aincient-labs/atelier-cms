#!/usr/bin/env bash
#
# The 0.30.0 component-id rewrite on real Docker (umbrella DECISIONS 0528, 0535).
#
# aincient_pages_post_update_0300_component_ids rewrites every stored component
# name (`hero` → `atelier:hero`) in pages and blocks, every revision. It commits
# in batches of 50 entities, so a failure part-way leaves some rows rewritten and
# some not. This proves, on a site installed by a RELEASED image:
#
#   1. seed ≥ 1 000 page revisions with bare names (+ one unknown name);
#   2. a failure in the middle of the revision table (a PostgreSQL trigger raises
#      after N row updates, i.e. after earlier batches have COMMITTED) makes
#      converge roll back: result=rolledback, every page table byte-identical to
#      before the upgrade, the post_update not recorded;
#   3. the released image starts again on the rolled-back database (what the
#      updater does when it re-pins the previous image);
#   4. with the fault removed, the upgrade converges: no bare name left in any
#      revision, the unknown name kept as `unresolved:`, config:status clean,
#      the seeded pages and the front page serve 200.
#
#   OLD_IMAGE=ghcr.io/aincient-labs/atelier-cms:v0.29.2 NEW_IMAGE=atelier-cms:candidate bash $0
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OLD_IMAGE="${OLD_IMAGE:-ghcr.io/aincient-labs/atelier-cms:latest}"
NEW_IMAGE="${NEW_IMAGE:-atelier-cms:candidate}"
NODES="${NODES:-120}"          # 120 pages × 9 revisions = 1 080 revisions
REVISIONS="${REVISIONS:-9}"
FAIL_AFTER="${FAIL_AFTER:-600}" # revision rows updated before the fault (batch 1 ≈ 450 rows commits)

export HASH_SALT="idrewrite-$(openssl rand -hex 16)"
export HTTP_PORT="${HTTP_PORT:-8096}"
COMPOSE=(docker compose -p atelier-idrewrite -f "$DOCKER_DIR/compose.yaml")

ok()  { printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
cleanup() { echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; }
trap cleanup EXIT

drush() { "${COMPOSE[@]}" exec -T app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web "$@"; }
psql_q() { "${COMPOSE[@]}" exec -T db psql -U aincient -d aincient -At -v ON_ERROR_STOP=1 -c "$1"; }

await_converge() {
  local r=''
  for _ in $(seq 1 120); do
    r=$("${COMPOSE[@]}" exec -T app sh -c 'cat /shared/converge.result 2>/dev/null' || true)
    [ -n "$r" ] && break
    sleep 5
  done
  printf '%s' "${r:-}"
}

boot() { # boot <image> — swap the app image on the same volumes, print converge.result
  "${COMPOSE[@]}" exec -T app sh -c 'rm -f /shared/converge.result' >/dev/null 2>&1 || true
  AINCIENT_IMAGE="$1" "${COMPOSE[@]}" up -d --force-recreate app >/dev/null
  await_converge
}

PAGE_TABLES=(node__field_page_structure node_revision__field_page_structure
             node__field_page_content node_revision__field_page_content
             media__field_page_structure media_revision__field_page_structure
             media__field_page_content media_revision__field_page_content)

existing_tables() {
  for t in "${PAGE_TABLES[@]}"; do
    [ "$(psql_q "SELECT to_regclass('public.$t') IS NOT NULL")" = "t" ] && echo "$t"
  done
}

checksums() { # one line per table: name md5(all rows) count
  for t in $(existing_tables); do
    printf '%s %s\n' "$t" "$(psql_q "SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) || ' ' || count(*) FROM $t t")"
  done
}

value_col() { case "$1" in *structure) echo field_page_structure_value ;; *) echo field_page_content_value ;; esac; }

bare_names() { # rows still holding a component name without a provider
  local n=0 c
  for t in $(existing_tables); do
    c=$(psql_q "SELECT count(*) FROM $t WHERE $(value_col "$t") ~ '\"component\": ?\"[^\":]+\"'")
    n=$((n + c))
  done
  echo "$n"
}

echo "== fresh install on the RELEASED image ($OLD_IMAGE) =="
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
AINCIENT_IMAGE="$OLD_IMAGE" "${COMPOSE[@]}" up -d >/dev/null
r=$(await_converge)
[ "$r" = "ok" ] || bad "released image did not install cleanly (converge.result='${r:-<none>}')"
old_version=$(drush state:get aincient.appliance_version --format=string 2>/dev/null | tr -d '[:space:]' || true)
case "$old_version" in *+edge.*|'') bad "baseline must be a released vX.Y.Z (got '${old_version:-<unrecorded>}')" ;; esac
ok "released image $old_version installed"

echo "== seed $NODES pages × $REVISIONS revisions with bare component names =="
"${COMPOSE[@]}" exec -T app sh -c 'cat > /tmp/seed.php' <<'PHP'
<?php
use Drupal\node\Entity\Node;
$nodes = (int) getenv('SEED_NODES');
$revs = (int) getenv('SEED_REVISIONS');
$storage = \Drupal::entityTypeManager()->getStorage('node');
$template = NULL;
foreach ($storage->loadByProperties(['type' => 'aincient_page']) as $n) {
  if (!$n->get('field_page_structure')->isEmpty() && str_contains((string) $n->get('field_page_structure')->value, '"component"')) {
    $template = $n;
    break;
  }
}
$structure = $template ? (string) $template->get('field_page_structure')->value
  : '{"type":"landing","slots":[{"id":"s1","component":"hero","variant":"centered"},{"id":"s2","component":"faq"}]}';
$content = $template ? (string) $template->get('field_page_content')->value
  : '{"title":"Seed","slots":{"s1":{"heading":"Seeded"}}}';
if (preg_match('/"component":"[a-z0-9_]+:/', $structure)) {
  fwrite(STDERR, "template already holds full ids — wrong baseline\n");
  exit(1);
}
// Pages are under content moderation: an API save without a state is a draft
// (403 for visitors). Publish them like the console does.
$publish = function ($e) { if ($e->hasField('moderation_state')) { $e->set('moderation_state', 'published'); } $e->setPublished(); };
$ids = [];
for ($i = 1; $i <= $nodes; $i++) {
  $node = Node::create([
    'type' => 'aincient_page', 'title' => "Seed $i", 'status' => 1, 'uid' => 1,
    'field_page_structure' => $structure, 'field_page_content' => $content,
  ]);
  $publish($node);
  $node->save();
  for ($r = 2; $r <= $revs; $r++) {
    $node->setNewRevision(TRUE);
    $node->setTitle("Seed $i r$r");
    $publish($node);
    $node->save();
  }
  $ids[] = $node->id();
}
// One page naming a component no module provides → must become unresolved:.
$data = json_decode($structure, TRUE);
$data['slots'][] = ['id' => 'zz', 'component' => 'farm_bogus_widget'];
$bogus = Node::create([
  'type' => 'aincient_page', 'title' => 'Seed bogus', 'status' => 1, 'uid' => 1,
  'field_page_structure' => json_encode($data), 'field_page_content' => $content,
]);
$publish($bogus);
$bogus->save();
// Site-owned governance config (config_ignored, so the rewrite is its only
// migration): a page kind and the site constraint as an owner tunes them.
$cf = \Drupal::configFactory();
$cf->getEditable('aincient_pages.page_kind.farm_kind')->setData([
  'uuid' => \Drupal::service('uuid')->generate(), 'langcode' => 'en', 'status' => TRUE, 'dependencies' => [],
  'id' => 'farm_kind', 'label' => 'Farm kind', 'hint' => 'Seeded by the farm.', 'mode' => 'composition',
  'components' => ['hero' => [], 'faq' => ['variants' => ['default']]], 'opener' => 'hero',
  'limits' => ['hero' => 1], 'collection_source' => FALSE, 'fragment' => FALSE,
  'removed' => ['newsletter'], 'include_new' => FALSE,
])->save();
$constraint = $cf->getEditable('aincient_pages.site_constraint');
$constraint->setData(array_merge($constraint->getRawData(), [
  'components' => ['hero', 'faq', 'content'], 'variants' => ['hero' => ['centered']],
]))->save();
print json_encode(['template' => $template ? (int) $template->id() : NULL, 'first' => (int) $ids[0], 'last' => (int) end($ids), 'bogus' => (int) $bogus->id()]);
PHP
seed=$("${COMPOSE[@]}" exec -T -e SEED_NODES="$NODES" -e SEED_REVISIONS="$REVISIONS" app \
  /opt/drupal/vendor/bin/drush --root=/opt/drupal/web php:script /tmp/seed.php) || bad "seeding failed"
echo "     seed: $seed"
first=$(printf '%s' "$seed" | sed -E 's/.*"first":([0-9]+).*/\1/')
last=$(printf '%s' "$seed" | sed -E 's/.*"last":([0-9]+).*/\1/')
bogus=$(printf '%s' "$seed" | sed -E 's/.*"bogus":([0-9]+).*/\1/')
revcount=$(psql_q "SELECT count(*) FROM node_revision__field_page_structure")
[ "$revcount" -ge 1000 ] || bad "only $revcount page revisions seeded (want ≥ 1000)"
# One heavily edited page: HEAVY extra revision rows on page $first, copied
# straight in SQL (seeding them through entity saves would take minutes). The
# rewrite pages by row, so this must not change its memory per step.
HEAVY="${HEAVY:-3000}"
for f in structure content; do
  psql_q "INSERT INTO node_revision__field_page_$f (bundle, deleted, entity_id, revision_id, langcode, delta, field_page_${f}_value)
          SELECT bundle, deleted, entity_id, 1000000 + g, langcode, delta, field_page_${f}_value
          FROM node_revision__field_page_$f r, generate_series(1, $HEAVY) g
          WHERE r.entity_id = $first AND r.revision_id = (SELECT min(revision_id) FROM node_revision__field_page_$f WHERE entity_id = $first)" >/dev/null
done
revcount=$(psql_q "SELECT count(*) FROM node_revision__field_page_structure")
echo "     + $HEAVY revision rows on page $first → $revcount revision rows"
before_bare=$(bare_names)
[ "$before_bare" -ge 1000 ] || bad "only $before_bare rows hold bare names before the upgrade"
ok "$revcount page revisions, $before_bare rows with bare names"
code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/node/$first" || true)
[ "$code" = "200" ] || bad "seeded page /node/$first returned '$code' on the released image before the upgrade"
ok "seeded page serves 200 before the upgrade"

before=$(checksums)

echo "== fault: raise after $FAIL_AFTER revision-row updates, then upgrade onto $NEW_IMAGE =="
"${COMPOSE[@]}" exec -T db psql -U aincient -d aincient -v ON_ERROR_STOP=1 -q <<SQL
CREATE SEQUENCE farm_fault_seq;
CREATE FUNCTION farm_fault() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
  IF nextval('farm_fault_seq') > $FAIL_AFTER THEN
    RAISE EXCEPTION 'farm: forced failure in the middle of the component-id rewrite';
  END IF;
  RETURN NEW;
END \$\$;
CREATE TRIGGER farm_fault BEFORE UPDATE ON node_revision__field_page_structure
  FOR EACH ROW EXECUTE FUNCTION farm_fault();
SQL
r=$(boot "$NEW_IMAGE")
[ "$r" = "rolledback" ] || { "${COMPOSE[@]}" logs --tail 120 app || true; bad "expected converge.result=rolledback, got '${r:-<none>}'"; }
ok "converge reported rolledback"

fired=$("${COMPOSE[@]}" logs app 2>&1 | grep -c 'forced failure in the middle' || true)
[ "$fired" -ge 1 ] || bad "the fault never fired — the rewrite did not reach row $FAIL_AFTER"
ok "the fault fired mid-table (after earlier batches committed)"

after=$(checksums)
if [ "$before" != "$after" ]; then
  diff <(printf '%s\n' "$before") <(printf '%s\n' "$after") || true
  bad "page tables differ from the pre-upgrade state after the rollback"
fi
ok "every page table is byte-identical to before the upgrade ($(printf '%s\n' "$after" | wc -l | tr -d ' ') tables)"

recorded=$(psql_q "SELECT count(*) FROM key_value WHERE collection='post_update' AND name='existing_updates' AND position('0300_component_ids' in encode(value, 'escape')) > 0")
[ "$recorded" = "0" ] || bad "the post_update is recorded as done after the rollback"
ok "post_update not recorded — the next upgrade runs it again"

echo "== the released image starts again on the rolled-back database =="
r=$(boot "$OLD_IMAGE")
[ "$r" = "ok" ] || { "${COMPOSE[@]}" logs --tail 120 app || true; bad "released image did not start on the rolled-back DB ('${r:-<none>}')"; }
code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/node/$first" || true)
[ "$code" = "200" ] || bad "seeded page /node/$first returned '$code' on the released image after rollback"
ok "released image converged ok, seeded page serves 200"

echo "== remove the fault, upgrade again =="
psql_q "DROP TRIGGER IF EXISTS farm_fault ON node_revision__field_page_structure; DROP FUNCTION IF EXISTS farm_fault(); DROP SEQUENCE IF EXISTS farm_fault_seq;" >/dev/null
r=$(boot "$NEW_IMAGE")
[ "$r" = "ok" ] || { "${COMPOSE[@]}" logs --tail 200 app || true; bad "upgrade without the fault did not converge ('${r:-<none>}')"; }
ok "upgrade converged"

"${COMPOSE[@]}" logs app 2>&1 | grep -iE 'component (name|id)s?|unresolved' | tail -8 | sed 's/^/     /' || true

left=$(bare_names)
[ "$left" = "0" ] || bad "$left rows still hold bare component names"
full=$(psql_q "SELECT count(*) FROM node_revision__field_page_structure WHERE field_page_structure_value LIKE '%\"component\":\"atelier:%'")
[ "$full" -ge 4000 ] || bad "only $full revisions hold atelier: ids"
ok "no bare name left; $full revisions hold atelier: ids"

unres=$(psql_q "SELECT count(*) FROM node__field_page_structure WHERE entity_id = $bogus AND field_page_structure_value LIKE '%unresolved:farm_bogus_widget%'")
[ "$unres" = "1" ] || bad "the unknown name was not kept as unresolved:farm_bogus_widget"
ok "unknown name kept as unresolved:farm_bogus_widget"

kind=$(drush config:get aincient_pages.page_kind.farm_kind --format=json 2>/dev/null | tr -d '[:space:]')
for want in '"atelier:hero":' '"atelier:faq":' '"opener":"atelier:hero"' '"atelier:newsletter"'; do
  case "$kind" in *"$want"*) ;; *) echo "$kind"; bad "owner page kind lacks $want after the upgrade" ;; esac
done
ok "owner page kind rewritten (components, limits, opener, removed)"
con=$(drush config:get aincient_pages.site_constraint --format=json 2>/dev/null | tr -d '[:space:]')
for want in '"atelier:hero"' '"atelier:content"' '"variants":{"atelier:hero"'; do
  case "$con" in *"$want"*) ;; *) echo "$con"; bad "site constraint lacks $want after the upgrade" ;; esac
done
ok "site constraint rewritten (components, variants)"

recorded=$(psql_q "SELECT count(*) FROM key_value WHERE collection='post_update' AND name='existing_updates' AND position('0300_component_ids' in encode(value, 'escape')) > 0")
[ "$recorded" = "1" ] || bad "the post_update is not recorded after a successful upgrade"
ok "post_update recorded"

status=$(drush config:status --format=string 2>/dev/null || true)
if [ -n "$(printf '%s' "$status" | tr -d '[:space:]')" ]; then
  printf '%s\n' "$status"; bad "config:status not clean"
fi
ok "config:status clean"

for n in "$first" "$last"; do
  code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/node/$n" || true)
  [ "$code" = "200" ] || bad "seeded page /node/$n returned '$code' after the upgrade"
done
code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/" || true)
[ "$code" = "200" ] || bad "front page returned '$code' after the upgrade"
code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/node/$bogus" || true)
echo "     /node/$bogus (unresolved component) → $code"
[ "$code" = "200" ] || bad "a page holding an unresolved component returned '$code'"
ok "seeded pages, the unresolved page and the front page serve 200"

echo "== PASS: the component-id rewrite rolls back cleanly mid-run and converges on retry =="
