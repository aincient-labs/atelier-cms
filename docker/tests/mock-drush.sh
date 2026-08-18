#!/usr/bin/env bash
#
# Mock drush for converge.sh unit tests. Records every invocation to $DRUSH_LOG
# and returns scripted outcomes driven by env vars:
#
#   MOCK_DB_READY=1       sql:query fails (database unreachable)
#   MOCK_INSTALLED=1      status reports a bootstrapped (installed) site
#   MOCK_UPDATEDB_FAIL=1  updatedb fails (simulates a bad migration)
#   MOCK_CIM_FAIL=1       config:import fails (simulates a bad config import)
#   MOCK_STATE_<key>      seeds State, e.g. MOCK_STATE_aincient_appliance_version
#                         (dots → underscores) — what the upgrade floor reads
#   MOCK_CORE_EXTENSION   serialized core.extension blob returned for the
#                         installed-extension read (unset = query returns nothing)
#   MOCK_CORE_EXTENSION_FAIL=1  that query fails outright
#   MOCK_TABLE_COUNT      how many tables the database holds — what decides the
#                         install/upgrade/refuse branch. Defaults to 0 when
#                         MOCK_INSTALLED=0 and 42 when it's 1, so the ordinary
#                         cases need only the one variable; set it explicitly to
#                         build the dangerous state (data present, site down).
#   MOCK_TABLE_COUNT_FAIL=1  information_schema is unavailable, forcing the
#                         key_value fallback in database_state()
#   MOCK_SQL_FAIL_PATTERN  a sql:query whose text contains this substring fails
#                         (e.g. cache_file_parsing → the bin's table is absent)
#
# On sql:dump it writes a real .gz so restore_snapshot's `[ -f ]` + zcat work.
#
: "${DRUSH_LOG:?DRUSH_LOG required}"

# Drop a leading --root=… (converge always passes it); match on the real verb.
args=()
for a in "$@"; do
  case "$a" in --root=*) ;; *) args+=("$a") ;; esac
done
set -- "${args[@]}"
printf '%s\n' "$*" >> "$DRUSH_LOG"

case "$*" in
  *core.extension*)
    # The bootstrap-free installed-extension read. MOCK_CORE_EXTENSION holds the
    # serialized blob; unset means "the query returned nothing", which converge
    # must treat as unknown rather than as an empty install.
    printf '%s' "${MOCK_CORE_EXTENSION:-}"
    exit "${MOCK_CORE_EXTENSION_FAIL:-0}" ;;
  *information_schema.tables*)
    # The table count that decides the branch. Default from MOCK_INSTALLED so the
    # existing cases keep meaning what they say.
    [ "${MOCK_TABLE_COUNT_FAIL:-0}" = "1" ] && exit 1
    if [ -n "${MOCK_TABLE_COUNT:-}" ]; then
      printf '%s\n' "$MOCK_TABLE_COUNT"
    elif [ "${MOCK_INSTALLED:-0}" = "1" ]; then
      printf '42\n'
    else
      printf '0\n'
    fi
    exit 0 ;;
  *"FROM key_value LIMIT"*)
    # The fallback probe: does an installed Drupal's key_value table exist?
    if [ "${MOCK_TABLE_COUNT:-$([ "${MOCK_INSTALLED:-0}" = "1" ] && echo 42 || echo 0)}" -gt 0 ]; then
      exit 0
    fi
    exit 1 ;;
  "sql:query"*)
    # An individual statement can be made to fail (a table that does not exist on
    # this site) without pretending the whole database is unreachable.
    if [ -n "${MOCK_SQL_FAIL_PATTERN:-}" ]; then
      case "$*" in *"$MOCK_SQL_FAIL_PATTERN"*) exit 1 ;; esac
    fi
    exit "${MOCK_DB_READY:-0}" ;;
  "status --field=bootstrap"*)      [ "${MOCK_INSTALLED:-0}" = "1" ] && echo "Successful" ; exit 0 ;;
  "status --field=drupal-version"*) echo "11.3.10" ; exit 0 ;;
  "site:install"*)                  exit 0 ;;
  "recipe "*)                       exit 0 ;;
  "sql:dump"*)
    f=""
    for a in "$@"; do case "$a" in --result-file=*) f="${a#--result-file=}" ;; esac; done
    [ -n "$f" ] && { echo "snapshot-data" | gzip > "${f}.gz"; }
    echo "Database dump saved"
    exit 0 ;;
  "state:get "*)
    # `state:get <key> --format=string` → the seeded value, or nothing.
    key="$2"
    var="MOCK_STATE_${key//./_}"
    printf '%s' "${!var:-}"
    [ -n "${!var:-}" ] && echo
    exit 0 ;;
  "state:set "*)                    exit 0 ;;
  "updatedb"*)                      exit "${MOCK_UPDATEDB_FAIL:-0}" ;;
  "config:import"*)                 exit "${MOCK_CIM_FAIL:-0}" ;;
  "cache:rebuild"*)                 exit 0 ;;
  "sql:drop"*)                      exit 0 ;;
  "sql:cli"*)                       cat >/dev/null 2>&1 || true ; exit 0 ;;
  *)                                exit 0 ;;
esac
