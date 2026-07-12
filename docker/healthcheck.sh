#!/usr/bin/env bash
#
# AIncient health check — is the converged site actually well?
# Used by converge.sh as the post-upgrade gate, and by compose as the
# container healthcheck. Drush-level only (no webserver required), so it can
# run during converge before the HTTP server is up.
#
set -euo pipefail

DRUPAL_ROOT="${DRUPAL_ROOT:-/opt/drupal/web}"
DRUSH="${DRUSH:-/opt/drupal/vendor/bin/drush} --root=${DRUPAL_ROOT}"

fail() { printf '[health] FAIL: %s\n' "$*" >&2; exit 1; }

# 1. Drupal bootstraps.
$DRUSH status --field=bootstrap 2>/dev/null | grep -qi 'Successful' \
  || fail "Drupal does not bootstrap"

# 2. The AIncient modules are enabled.
for m in aincient_core aincient_chat aincient_assistant_ui aincient_pages; do
  $DRUSH pm:list --status=enabled --field=name 2>/dev/null | grep -qx "$m" \
    || fail "module not enabled: $m"
done

# 3. No database updates are left pending (i.e. converge finished the job).
if $DRUSH updatedb:status --format=string 2>/dev/null | grep -q .; then
  fail "pending database updates remain after converge"
fi

# 4. A default chat provider is configured (warns rather than fails — a site may
#    be mid-setup before a provider is connected). Provider-neutral: we check for
#    a pinned default chat provider, not a vendor-specific key. Probe by printing
#    a token and grepping it: exit() codes from `drush php:eval` are not reliable.
if ! $DRUSH php:eval '$d = \Drupal::service("ai.provider")->getDefaultProviderForOperationType("chat"); print !empty($d["provider_id"]) ? "AI_OK" : "";' 2>/dev/null | grep -q AI_OK; then
  printf '[health] WARN: no AI provider connected yet — chat will not work until you connect a provider in the console (first-run onboarding wizard)\n' >&2
fi

printf '[health] OK\n'
