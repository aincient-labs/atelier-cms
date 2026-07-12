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
