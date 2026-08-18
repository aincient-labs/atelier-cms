#!/usr/bin/env bats
#
# Unit tests for the converge loop — the safety-critical appliance UX.
# Hermetic: converge.sh runs against a MOCK drush (no Drupal, no Docker), so
# these assert ORCHESTRATION (which branch, what order, does it roll back),
# not Drupal behaviour. The real-Drupal path is covered by tests/smoke.sh.

setup() {
  CONVERGE="$BATS_TEST_DIRNAME/../converge.sh"
  export DRUSH_LOG="$BATS_TEST_TMPDIR/drush.log"
  export AINCIENT_SNAPSHOT_DIR="$BATS_TEST_TMPDIR/snap"
  mkdir -p "$AINCIENT_SNAPSHOT_DIR"
  export DRUSH="$BATS_TEST_DIRNAME/mock-drush.sh"   # converge appends --root=…
  export DRUPAL_ROOT="/test"
  export AINCIENT_DB_MAX_WAIT=0                      # don't actually wait/sleep
  export HEALTHCHECK_CMD=true                        # healthy unless overridden
  export AINCIENT_SHARED_DIR="$BATS_TEST_TMPDIR/shared"  # the updater's volume
  export AINCIENT_PRIVATE_DIR="$BATS_TEST_TMPDIR/private"
  mkdir -p "$AINCIENT_SHARED_DIR" "$AINCIENT_PRIVATE_DIR"
  : > "$DRUSH_LOG"
}

result() { cat "$AINCIENT_SHARED_DIR/converge.result" 2>/dev/null; }

# Order helper: line number of the first log entry matching a pattern.
line_of() { grep -n -- "$1" "$DRUSH_LOG" | head -1 | cut -d: -f1; }

@test "database never reachable → fatal, nothing installed" {
  export MOCK_DB_READY=1
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"database not reachable"* ]]
  ! grep -q "site:install" "$DRUSH_LOG"
}

@test "empty DB → fresh install from config/sync (NOT the recipe), and NO snapshot taken" {
  export MOCK_INSTALLED=0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "site:install" "$DRUSH_LOG"
  grep -q -- "--existing-config" "$DRUSH_LOG"   # install-from-config, not recipe
  ! grep -q "^recipe " "$DRUSH_LOG"             # the recipe is dev/demo-only now
  ! grep -q "sql:dump"  "$DRUSH_LOG"            # nothing to snapshot on a fresh DB
}

# --- The three-state branch -------------------------------------------------
# The 2026-08-06 data-loss defect: the branch asked "can Drupal boot?" and read
# "no" as "there is no site here". These tests pin the question to "is the
# database EMPTY?" — the only one that may authorise site:install.

@test "populated DB + unbootable site → REFUSED, nothing installed, result not ok" {
  export MOCK_INSTALLED=0        # cannot bootstrap…
  export MOCK_TABLE_COUNT=42     # …but the database is full of data
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node)"   # nothing missing to blame
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO CONTINUE"* ]]
  [[ "$output" == *"HAS NOT BEEN TOUCHED"* ]]
  # The whole incident, asserted: no install, no migration, no drop.
  ! grep -q "site:install" "$DRUSH_LOG"
  ! grep -q "updatedb"     "$DRUSH_LOG"
  ! grep -q "sql:drop"     "$DRUSH_LOG"
  [ "$(result)" = "unbootable" ]   # → the updater re-pins the previous image
  [ "$(result)" != "ok" ]
}

@test "the incident shape: populated DB, unbootable, module code missing → names the module" {
  # ee6a1cf → v0.4.2. Fourteen installed modules the new image no longer ships,
  # so the site cannot boot. This used to reinstall over it and report success.
  export MOCK_INSTALLED=0
  export MOCK_TABLE_COUNT=180
  stage_docroot node aincient_core
  export MOCK_CORE_EXTENSION="$(core_extension node aincient_core ai ai_agents)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"missing code your site uses"* ]]
  [[ "$output" == *"ai_agents"* ]]
  ! grep -q "site:install" "$DRUSH_LOG"
  [ "$(result)" = "missing-code" ]
}

@test "restart on an affected image is refused again — restored data survives repetition" {
  # The property that turned a bug into an incident: the destructive branch was
  # stable under repetition, so a restored snapshot was destroyed by the next
  # boot, every time. Three converges, three refusals, no install ever.
  export MOCK_INSTALLED=0
  export MOCK_TABLE_COUNT=180
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node)"
  for _ in 1 2 3; do
    run bash "$CONVERGE"
    [ "$status" -ne 0 ]
  done
  ! grep -q "site:install" "$DRUSH_LOG"
  ! grep -q "sql:drop"     "$DRUSH_LOG"
}

@test "information_schema unavailable → key_value fallback still refuses a populated DB" {
  export MOCK_INSTALLED=0
  export MOCK_TABLE_COUNT=42
  export MOCK_TABLE_COUNT_FAIL=1     # forces the fallback probe
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  ! grep -q "site:install" "$DRUSH_LOG"
  [ "$(result)" = "unbootable" ]
}

@test "information_schema unavailable on a truly empty DB → fresh install still works" {
  export MOCK_INSTALLED=0
  export MOCK_TABLE_COUNT=0
  export MOCK_TABLE_COUNT_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "site:install" "$DRUSH_LOG"
  [ "$(result)" = "ok" ]
}

@test "a bootstrapping site upgrades even if the table count is unreadable" {
  export MOCK_INSTALLED=1
  export MOCK_TABLE_COUNT_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "updatedb" "$DRUSH_LOG"
  [ "$(result)" = "ok" ]
}

@test "existing DB → upgrade snapshots BEFORE running updatedb" {
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "sql:dump" "$DRUSH_LOG"
  grep -q "updatedb" "$DRUSH_LOG"
  [ "$(line_of sql:dump)" -lt "$(line_of updatedb)" ]
  ! grep -q "site:install" "$DRUSH_LOG"        # don't reinstall an existing site
}

@test "fresh_install refuses a non-empty database even when called directly" {
  # The assertion inside the blast radius: no future caller can reintroduce the
  # 2026-08-06 branch bug without tripping this.
  export MOCK_TABLE_COUNT=42
  run bash -c "source '$CONVERGE'; fresh_install"
  [ "$status" -ne 0 ]
  [[ "$output" == *"non-empty database"* ]]
  ! grep -q "site:install" "$DRUSH_LOG"
}

@test "snapshots are timestamped and pruned to the retention count" {
  # They used to be one fixed pre-converge.sql.gz, overwritten every converge —
  # so during an incident each restart destroyed the previous attempt's copy.
  export MOCK_INSTALLED=1
  export AINCIENT_SNAPSHOT_KEEP=2
  for i in 1 2 3 4 5; do
    : > "$AINCIENT_SNAPSHOT_DIR/pre-converge-2026010${i}-000000.sql.gz"
  done
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [ "$(ls -1 "$AINCIENT_SNAPSHOT_DIR"/pre-converge-*.sql.gz | wc -l)" -eq 2 ]
  # The dump this run took is one of the survivors, and the oldest is gone.
  [ ! -e "$AINCIENT_SNAPSHOT_DIR/pre-converge-20260101-000000.sql.gz" ]
  [ -e "$AINCIENT_SNAPSHOT_DIR/pre-converge-20260105-000000.sql.gz" ]
}

@test "upgrade truncates the parsed-YAML cache BEFORE updatedb" {
  # The `file_parsing` bin (parsed *.routing.yml / *.libraries.yml) survives cache
  # clears by core's design and invalidates on filemtime alone — and the image
  # stamps a fixed mtime, so across an image swap a changed YAML file looks
  # unchanged and the site keeps the old image's parse. v0.8.2 shipped a route
  # that existed on disk and not in the router. cache:rebuild cannot fix it, so
  # the truncate must happen, and before anything bootstraps.
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "TRUNCATE cache_file_parsing" "$DRUSH_LOG"
  [ "$(line_of 'TRUNCATE cache_file_parsing')" -lt "$(line_of updatedb)" ]
}

@test "a missing file_parsing bin does not fail the upgrade" {
  # The bin is created lazily on first write, so a site that never populated it
  # has no table — the truncate must be advisory, never fatal.
  export MOCK_INSTALLED=1 MOCK_SQL_FAIL_PATTERN=cache_file_parsing
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [ "$(result)" = "ok" ]
}

@test "upgrade runs config:import AFTER updatedb (config_ignore keeps site config safe)" {
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "config:import" "$DRUSH_LOG"
  [ "$(line_of updatedb)" -lt "$(line_of config:import)" ]
}

@test "AINCIENT_IMPORT_CONFIG=0 skips config:import" {
  export MOCK_INSTALLED=1 AINCIENT_IMPORT_CONFIG=0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  ! grep -q "config:import" "$DRUSH_LOG"
  [[ "$output" == *"config:import skipped"* ]]
}

@test "failed config:import rolls back and exits non-zero (under the same trap)" {
  export MOCK_INSTALLED=1 MOCK_CIM_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  grep -q "sql:drop" "$DRUSH_LOG"              # restore_snapshot ran
  [[ "$output" == *"rolled back"* ]]
}

@test "failed migration (updatedb) rolls back and exits non-zero" {
  export MOCK_INSTALLED=1 MOCK_UPDATEDB_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  grep -q "sql:drop" "$DRUSH_LOG"              # restore_snapshot ran
  grep -q "sql:cli"  "$DRUSH_LOG"
  [[ "$output" == *"rolled back"* ]]
}

@test "UNHEALTHY upgrade rolls back too (regression guard: health under the trap)" {
  # Guards the exact bug found on 2026-05-31: a failing health check used to
  # NOT roll back because the ERR trap was cleared before the check ran.
  export MOCK_INSTALLED=1
  export HEALTHCHECK_CMD=false
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  grep -q "sql:drop" "$DRUSH_LOG"
  [[ "$output" == *"rolled back"* ]]
}

@test "rolled-back upgrade records converge.result=rolledback for the updater" {
  export MOCK_INSTALLED=1 MOCK_UPDATEDB_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [ "$(result)" = "rolledback" ]
}

@test "healthy upgrade does NOT roll back" {
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  ! grep -q "sql:drop" "$DRUSH_LOG"
  [[ "$output" == *"converge OK"* ]]
}

@test "healthy converge records converge.result=ok for the updater" {
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [ "$(result)" = "ok" ]
}

# --- The upgrade floor ------------------------------------------------------
# The refusal is the interesting half: it must happen BEFORE the snapshot, leave
# the database untouched, and name the waypoint. Everything else about it is a
# question of "does it stay out of the way", which is most of these cases.

@test "site below the upgrade floor is REFUSED before the database is touched" {
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version=v0.1.1
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO UPGRADE"* ]]
  [[ "$output" == *"0.3.0"* ]]                 # names the waypoint to go through
  # The whole point: no dump, no migration, no rollback — nothing happened.
  ! grep -q "sql:dump"    "$DRUSH_LOG"
  ! grep -q "updatedb"    "$DRUSH_LOG"
  ! grep -q "config:import" "$DRUSH_LOG"
  ! grep -q "sql:drop"    "$DRUSH_LOG"
  [ "$(result)" = "too-old" ]                  # updater re-pins the old image
}

@test "site AT the floor upgrades normally" {
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version=0.3.0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "updatedb" "$DRUSH_LOG"
  [ "$(result)" = "ok" ]
}

@test "floor comparison is version-aware, not lexical (0.10.0 is above 0.9.0)" {
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.9.0
  export MOCK_STATE_aincient_appliance_version=0.10.0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "unrecorded site warns but still upgrades (pre-recording installs aren't stranded)" {
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  # No MOCK_STATE_… → State has no record, as on every install that converged
  # before the recording shipped.
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"no recorded Atelier version"* ]]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "an edge build compares as the release it descends from — floor MET" {
  # `v0.5.1+edge.a1b2c3d` is built from a commit descended from v0.5.1, so it
  # contains everything 0.5.1 shipped and satisfies a 0.3.0 floor.
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version="v0.5.1+edge.a1b2c3d"
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" != *"unreleased build"* ]]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "an edge build below the floor is REFUSED like any other site" {
  # The whole point of the stamp change: edge installs used to be ungateable.
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.6.0
  export MOCK_STATE_aincient_appliance_version="v0.5.1+edge.a1b2c3d"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO UPGRADE"* ]]
  [ "$(result)" = "too-old" ]
}

@test "the OLD edge stamp still has no position in the version order → warns, upgrades" {
  # Installs stamped before the change. They must not be stranded.
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version="edge+f8bdcb9"
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"unreleased build"* ]]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "no floor declared → the floor check is inert" {
  export MOCK_INSTALLED=1
  export MOCK_STATE_aincient_appliance_version=0.0.1
  unset AINCIENT_UPGRADE_FLOOR
  export AINCIENT_UPGRADE_FLOOR_FILE="$BATS_TEST_TMPDIR/absent"
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "the floor is read from the baked file, comments and blank lines ignored" {
  export MOCK_INSTALLED=1
  export MOCK_STATE_aincient_appliance_version=0.1.1
  unset AINCIENT_UPGRADE_FLOOR
  export AINCIENT_UPGRADE_FLOOR_FILE="$BATS_TEST_TMPDIR/floor"
  printf '# a comment\n\n0.4.0\n' > "$AINCIENT_UPGRADE_FLOOR_FILE"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO UPGRADE"* ]]
  [[ "$output" == *"0.4.0"* ]]
}

@test "the SHIPPED floor file parses to a bare version" {
  run bash -c "sed -e 's/#.*//' -e '/^[[:space:]]*\$/d' '$BATS_TEST_DIRNAME/../upgrade-floor' | head -1 | tr -d '[:space:]'"
  [ "$status" -eq 0 ]
  [[ "$output" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
}

# --- Installed code missing from the image ----------------------------------
# The hazard the version floor structurally cannot reach: an `edge` install whose
# stamp has no position in the version order, still carrying modules a later
# release deleted. This check asks the state and the filesystem instead.

# Stage a docroot containing exactly the named extensions, as Drupal lays them out.
stage_docroot() {
  export DRUPAL_ROOT="$BATS_TEST_TMPDIR/web"
  mkdir -p "$DRUPAL_ROOT/modules/contrib" "$DRUPAL_ROOT/core/modules"
  for m in "$@"; do
    mkdir -p "$DRUPAL_ROOT/modules/contrib/$m"
    printf 'name: %s\ntype: module\n' "$m" > "$DRUPAL_ROOT/modules/contrib/$m/$m.info.yml"
  done
}

# Serialize a core.extension blob installing exactly the named extensions.
core_extension() {
  local out='a:2:{s:6:"module";a:0:{'
  for m in "$@"; do out+="s:${#m}:\"${m}\";i:0;"; done
  printf '%s' "${out}}s:5:\"theme\";a:0:{}}"
}

@test "an installed module whose code this image lacks is REFUSED, database untouched" {
  export MOCK_INSTALLED=1
  stage_docroot node aincient_core
  export MOCK_CORE_EXTENSION="$(core_extension node aincient_core ai ai_agents)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"missing code your site uses"* ]]
  [[ "$output" == *"ai"* ]]
  [[ "$output" == *"ai_agents"* ]]
  # Same contract as the floor refusal: nothing happened at all.
  ! grep -q "sql:dump"      "$DRUSH_LOG"
  ! grep -q "updatedb"      "$DRUSH_LOG"
  ! grep -q "config:import" "$DRUSH_LOG"
  ! grep -q "sql:drop"      "$DRUSH_LOG"
  [ "$(result)" = "missing-code" ]
}

@test "every installed module present → the check is silent and the upgrade proceeds" {
  export MOCK_INSTALLED=1
  stage_docroot node aincient_core aincient_pages
  export MOCK_CORE_EXTENSION="$(core_extension node aincient_core aincient_pages)"
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" != *"missing code your site uses"* ]]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "themes count too — they fatal the same way for the same reason" {
  export MOCK_INSTALLED=1
  stage_docroot node aincient_theme
  # olivero installed but absent from this image.
  export MOCK_CORE_EXTENSION='a:2:{s:6:"module";a:0:{s:4:"node";i:0;}s:5:"theme";a:0:{s:14:"aincient_theme";i:0;s:7:"olivero";i:0;}}'
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"olivero"* ]]
  [ "$(result)" = "missing-code" ]
}

@test "an unreadable core.extension warns and stands aside (never refuses on a guess)" {
  export MOCK_INSTALLED=1
  stage_docroot node
  export MOCK_CORE_EXTENSION_FAIL=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"could not read the installed module list"* ]]
  grep -q "updatedb" "$DRUSH_LOG"
}

@test "an empty answer is unknown, not 'nothing is installed'" {
  # The dangerous misreading: treating no rows as an empty module list would make
  # every extension on disk look surplus and refuse nothing — or, inverted, make
  # a real install look entirely missing. Neither: it's unknown.
  export MOCK_INSTALLED=1
  stage_docroot node
  unset MOCK_CORE_EXTENSION
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"could not read the installed module list"* ]]
}

@test "the missing-code scan runs BEFORE the branch decision, so it speaks first" {
  # Both refusals apply here. Missing code wins: it runs before the branch is
  # chosen (that's the fix — it has to be reachable by a site that can't boot),
  # and it is the more specific diagnosis, naming the module to uninstall.
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version=0.1.1
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node ai)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"missing code your site uses"* ]]
  [ "$(result)" = "missing-code" ]
  ! grep -q "sql:dump" "$DRUSH_LOG"
}

@test "the floor alone still refuses, before the database is touched" {
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version=0.1.1
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO UPGRADE"* ]]
  [ "$(result)" = "too-old" ]
  ! grep -q "sql:dump" "$DRUSH_LOG"
}

@test "a fresh install skips both refusals (there is no state to be wrong about)" {
  export MOCK_INSTALLED=0
  stage_docroot node
  export AINCIENT_UPGRADE_FLOOR=9.9.9
  export MOCK_CORE_EXTENSION="$(core_extension node ai)"
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "site:install" "$DRUSH_LOG"
  [ "$(result)" = "ok" ]
}

@test "a successful converge records the version it converged at" {
  export MOCK_INSTALLED=1
  export ATELIER_VERSION=v0.4.0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "state:set aincient.appliance_version v0.4.0" "$DRUSH_LOG"
}

@test "a rolled-back upgrade does NOT record the new version" {
  export MOCK_INSTALLED=1 MOCK_UPDATEDB_FAIL=1
  export ATELIER_VERSION=v0.4.0
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  ! grep -q "state:set aincient.appliance_version" "$DRUSH_LOG"
}

@test "an unstamped build records nothing rather than 'dev'" {
  export MOCK_INSTALLED=1
  export ATELIER_VERSION=dev
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  ! grep -q "state:set aincient.appliance_version" "$DRUSH_LOG"
}

@test "a fresh install records its version too (so its first upgrade is checkable)" {
  export MOCK_INSTALLED=0
  export ATELIER_VERSION=v0.4.0
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "state:set aincient.appliance_version v0.4.0" "$DRUSH_LOG"
}

@test "fresh install with no AINCIENT_ADMIN_PASS mints + records a random password" {
  export MOCK_INSTALLED=0
  unset AINCIENT_ADMIN_PASS
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  # site:install received a non-empty, non-'admin' --account-pass
  grep -q -- "--account-pass=" "$DRUSH_LOG"
  ! grep -qE -- "--account-pass=admin( |$)" "$DRUSH_LOG"
  [ -s "$AINCIENT_PRIVATE_DIR/INITIAL_ADMIN_PASSWORD" ]
}
