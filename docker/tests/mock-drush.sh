#!/usr/bin/env bash
#
# Mock drush for converge.sh unit tests. Records every invocation to $DRUSH_LOG
# and returns scripted outcomes driven by env vars:
#
#   MOCK_DB_READY=1       sql:query fails (database unreachable)
#   MOCK_INSTALLED=1      status reports a bootstrapped (installed) site
#   MOCK_UPDATEDB_FAIL=1  updatedb fails (simulates a bad migration)
#   MOCK_CIM_FAIL=1       config:import fails (simulates a bad config import)
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
  "sql:query"*)                     exit "${MOCK_DB_READY:-0}" ;;
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
  "updatedb"*)                      exit "${MOCK_UPDATEDB_FAIL:-0}" ;;
  "config:import"*)                 exit "${MOCK_CIM_FAIL:-0}" ;;
  "cache:rebuild"*)                 exit 0 ;;
  "sql:drop"*)                      exit 0 ;;
  "sql:cli"*)                       cat >/dev/null 2>&1 || true ; exit 0 ;;
  *)                                exit 0 ;;
esac
