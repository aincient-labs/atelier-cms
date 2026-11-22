#!/usr/bin/env bash
#
# Release A1 on real Docker: identity per provider, one component per short
# name per page (umbrella DECISIONS 0528, 0535, 0539; A1 plan m3-e6).
#
# A1 changes no stored data. A site whose pack shares a short name with a
# built-in (`testimonials`) must upgrade with no page change. This proves, on a
# site installed by the RELEASED 0.30.0 image with that pack mounted:
#
#   1. under 0.30.0 the pack's `testimonials` is refused (global names), so the
#      seeded pages hold the built-in `atelier:testimonials`;
#   2. the upgrade onto the candidate converges `ok` with the pack still there;
#   3. every page table is byte-identical, config:status is clean;
#   4. every seeded page renders the same HTML as before (asset version stamps
#      normalised) — the built-in, never the pack's component;
#   5. live, through the page store: a landing page (whose kind now offers the
#      pack's) asks for the switch; a kind that names atelier:testimonials keeps
#      the built-in and lets the edit through.
#
#   OLD_IMAGE=ghcr.io/aincient-labs/atelier-cms:v0.30.0 NEW_IMAGE=atelier-cms:candidate bash $0
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CMS_DIR="$(cd "$DOCKER_DIR/.." && pwd)"
OLD_IMAGE="${OLD_IMAGE:-ghcr.io/aincient-labs/atelier-cms:v0.30.0}"
NEW_IMAGE="${NEW_IMAGE:-atelier-cms:candidate}"
PACK_SRC="$CMS_DIR/web/modules/custom/aincient_pages/tests/modules/atelier_test_pair_pack"

export HASH_SALT="pair-$(openssl rand -hex 16)"
export HTTP_PORT="${HTTP_PORT:-8097}"
WORK="$(mktemp -d)"
mkdir -p "$WORK/packs.d"
printf 'module: atelier_test_pair_pack\n' >"$WORK/packs.d/pair.yml"
# Mounted where a PRODUCTION pack is baked (web/modules/custom), so it is on disk
# from the first moment of every boot, on both images. (A dev-stack mount under
# /opt/drupal/packs is linked only by enable_packs, after the missing-code check —
# a separate, pre-existing issue, see the A1 plan.)
cat >"$WORK/compose.pack.yaml" <<YAML
services:
  app:
    volumes:
      - $PACK_SRC:/opt/drupal/web/modules/custom/atelier_test_pair_pack:ro
      - $WORK/packs.d:/opt/drupal/packs.d:ro
YAML
COMPOSE=(docker compose -p atelier-pair -f "$DOCKER_DIR/compose.yaml" -f "$WORK/compose.pack.yaml")

ok()  { printf '  \033[32mok\033[0m   %s\n' "$*"; }
bad() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
cleanup() { echo "== teardown =="; "${COMPOSE[@]}" down -v >/dev/null 2>&1 || true; rm -rf "$WORK"; }
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

checksums() {
  for t in "${PAGE_TABLES[@]}"; do
    [ "$(psql_q "SELECT to_regclass('public.$t') IS NOT NULL")" = "t" ] || continue
    printf '%s %s\n' "$t" "$(psql_q "SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) || ' ' || count(*) FROM $t t")"
  done
}

page_html() { # page_html <nid> — the page body, asset version stamps normalised
  curl -sL "http://localhost:${HTTP_PORT}/node/$1" | sed -E 's/\?v=[0-9a-z]+/?v=X/g; s/(itok|h)=[A-Za-z0-9_-]+/\1=X/g'
}

echo "== fresh install on the RELEASED image ($OLD_IMAGE), pair pack mounted =="
"${COMPOSE[@]}" down -v >/dev/null 2>&1 || true
AINCIENT_IMAGE="$OLD_IMAGE" "${COMPOSE[@]}" up -d >/dev/null
r=$(await_converge)
[ "$r" = "ok" ] || bad "released image did not install cleanly (converge.result='${r:-<none>}')"
old_version=$(drush state:get aincient.appliance_version --format=string 2>/dev/null | tr -d '[:space:]' || true)
case "$old_version" in *+edge.*|'') bad "baseline must be a released vX.Y.Z (got '${old_version:-<unrecorded>}')" ;; esac
ok "released image $old_version installed"
[ "$(drush php:eval "echo (int) \\Drupal::moduleHandler()->moduleExists('atelier_test_pair_pack');")" = "1" ] \
  || bad "the pair pack is not enabled on $old_version"
provider=$(drush php:eval "echo \\Drupal::service('aincient_pages.catalog')->discovered()->sections()['testimonials']['provider'] ?? '';")
[ "$provider" = "atelier" ] || bad "on $old_version testimonials should be the built-in (pack refused by global names), got '$provider'"
ok "pair pack enabled; under $old_version its testimonials is refused, the built-in is offered"

echo "== seed pages holding the built-in testimonials =="
"${COMPOSE[@]}" exec -T app sh -c 'cat > /tmp/seed.php' <<'PHP'
<?php
use Drupal\node\Entity\Node;
$publish = function ($e) { if ($e->hasField('moderation_state')) { $e->set('moderation_state', 'published'); } $e->setPublished(); };
$cf = \Drupal::configFactory();
$cf->getEditable('aincient_pages.page_kind.pressroom')->setData([
  'uuid' => \Drupal::service('uuid')->generate(), 'langcode' => 'en', 'status' => TRUE, 'dependencies' => [],
  'id' => 'pressroom', 'label' => 'Press room', 'hint' => 'Seeded.', 'mode' => 'composition',
  'components' => ['atelier:hero' => [], 'atelier:testimonials' => []], 'opener' => '',
  'limits' => [], 'collection_source' => FALSE, 'fragment' => FALSE, 'removed' => [], 'include_new' => FALSE,
])->save();
$structure = ['type' => 'landing', 'slots' => [
  ['id' => 'hero0001', 'component' => 'atelier:hero', 'variant' => 'centered'],
  ['id' => 'quot0001', 'component' => 'atelier:testimonials'],
  ['id' => 'faq00001', 'component' => 'atelier:faq'],
]];
$content = ['title' => 'Proof', 'slots' => [
  'hero0001' => ['heading' => 'Hello from 0.30.0'],
  'quot0001' => ['heading' => 'Kind words', 'quotes' => [['quote' => 'Solid and calm.', 'author' => 'Grace', 'role' => 'Editor']]],
  'faq00001' => ['heading' => 'Questions', 'items' => [['question' => 'Does it update?', 'answer' => 'Yes.']]],
]];
$ids = [];
foreach (['landing', 'landing', 'pressroom'] as $i => $type) {
  $s = $structure; $s['type'] = $type;
  $node = Node::create([
    'type' => 'aincient_page', 'title' => "Pair $i", 'status' => 1, 'uid' => 1,
    'field_page_structure' => json_encode($s), 'field_page_content' => json_encode($content),
  ]);
  if ($node->hasField('field_page_type')) { $node->set('field_page_type', $type); }
  $publish($node);
  $node->save();
  $node->setNewRevision(TRUE); $node->setTitle("Pair $i r2"); $publish($node); $node->save();
  $ids[] = (int) $node->id();
}
print json_encode($ids);
PHP
ids=$(drush php:script /tmp/seed.php) || bad "seeding failed"
read -r L1 L2 PR <<<"$(printf '%s' "$ids" | tr -d '[]' | tr ',' ' ')"
echo "     seeded landing $L1 $L2, pressroom $PR"
for n in $L1 $L2 $PR; do
  code=$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:${HTTP_PORT}/node/$n")
  [ "$code" = "200" ] || bad "page /node/$n returned $code before the upgrade"
  page_html "$n" >"$WORK/before-$n.html"
  grep -q 'Solid and calm.' "$WORK/before-$n.html" || bad "page $n does not render the built-in testimonials before the upgrade"
done
ok "3 pages serve 200 and render the built-in testimonials"
before=$(checksums)
# A pack is enabled from its packs.d declaration, after config:import, by design —
# so core.extension differs from config/sync on any site with one. The upgrade
# must leave config:status exactly as it found it.
status_before=$(drush config:status --format=list 2>/dev/null | grep -v '^$' || true)

echo "== upgrade onto $NEW_IMAGE, pack still mounted =="
r=$(boot "$NEW_IMAGE")
[ "$r" = "ok" ] || bad "upgrade did not converge (converge.result='${r:-<none>}')"
ok "converge ok"
provider=$(drush php:eval "echo \\Drupal::service('aincient_pages.catalog')->discovered()->sections()['testimonials']['provider'] ?? '';")
hidden=$(drush php:eval "echo implode(',', \\Drupal::service('aincient_pages.catalog')->discovered()->hiddenIds());")
[ "$provider" = "atelier_test_pair_pack" ] && [ "$hidden" = "atelier:testimonials" ] \
  || bad "after the upgrade the pair should be admitted with the pack's offered (got provider '$provider', hidden '$hidden')"
ok "pair admitted: the pack's testimonials offered site-wide, the built-in hidden"

after=$(checksums)
[ "$before" = "$after" ] || { diff <(echo "$before") <(echo "$after") || true; bad "page tables changed on an upgrade that must change no data"; }
ok "every page table byte-identical ($(echo "$after" | wc -l | tr -d ' ') tables)"

status=$(drush config:status --format=list 2>/dev/null | grep -v '^$' || true)
[ "$status" = "$status_before" ] || bad "config:status changed on the upgrade: before '$status_before', after '$status'"
ok "config:status as before the upgrade (${status:-clean})"

for n in $L1 $L2 $PR; do
  page_html "$n" >"$WORK/after-$n.html"
  grep -q 'pair-testimonial' "$WORK/after-$n.html" && bad "page $n now renders the PACK's testimonials"
  if ! diff -q "$WORK/before-$n.html" "$WORK/after-$n.html" >/dev/null; then
    diff "$WORK/before-$n.html" "$WORK/after-$n.html" | head -20
    bad "page $n renders differently after the upgrade"
  fi
done
ok "all 3 pages render the same HTML as on $old_version (the built-in)"

echo "== live: what the page agent meets =="
"${COMPOSE[@]}" exec -T app sh -c 'cat > /tmp/live.php' <<'PHP'
<?php
$store = \Drupal::service('aincient_pages.store');
$out = [];
foreach ([(string) getenv('L1'), (string) getenv('PR')] as $id) {
  $schema = $store->load($id);
  $r = $store->applyOps($schema, [['op' => 'update_section', 'id' => 'hero0001', 'props' => ['heading' => 'Changed']]], TRUE);
  $out[$id] = ['components' => array_column($schema['sections'], 'component'), 'refused' => array_column($r['rejected'], 'reason')];
}
print json_encode($out);
PHP
live=$("${COMPOSE[@]}" exec -T -e L1="$L1" -e PR="$PR" app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web php:script /tmp/live.php) || bad "live check failed"
echo "     $live"
printf '%s' "$live" | jq -e --arg l "$L1" '.[$l].components == ["hero","atelier:testimonials","faq"] and (.[$l].refused[0] | test("switch_components")) and ((.[$l].refused[0] | test("atelier:")) | not)' >/dev/null \
  || bad "the landing page should read the built-in by full id and ask for the switch"
printf '%s' "$live" | jq -e --arg p "$PR" '.[$p].components == ["hero","testimonials","faq"] and .[$p].refused == []' >/dev/null \
  || bad "the press-room kind names atelier:testimonials, so its page should read short and take the edit"
ok "landing page asks for the switch (short names); the kind naming the built-in keeps it and takes the edit"

echo "== PASS =="
