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

@test "existing DB → upgrade snapshots BEFORE running updatedb" {
  export MOCK_INSTALLED=1
  run bash "$CONVERGE"
  [ "$status" -eq 0 ]
  grep -q "sql:dump" "$DRUSH_LOG"
  grep -q "updatedb" "$DRUSH_LOG"
  [ "$(line_of sql:dump)" -lt "$(line_of updatedb)" ]
  ! grep -q "site:install" "$DRUSH_LOG"        # don't reinstall an existing site
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

@test "an edge build has no position in the version order → warns, upgrades" {
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

@test "the floor is checked BEFORE the missing-code scan, and either alone refuses" {
  # Both fail here; the floor speaks first because it's the cheaper question.
  export MOCK_INSTALLED=1
  export AINCIENT_UPGRADE_FLOOR=0.3.0
  export MOCK_STATE_aincient_appliance_version=0.1.1
  stage_docroot node
  export MOCK_CORE_EXTENSION="$(core_extension node ai)"
  run bash "$CONVERGE"
  [ "$status" -ne 0 ]
  [[ "$output" == *"REFUSING TO UPGRADE"* ]]
  [ "$(result)" = "too-old" ]
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
