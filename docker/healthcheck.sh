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

# 4. The front door actually opens for an anonymous visitor. A site can bootstrap
#    with every module enabled and no pending updates, yet still serve a 404 at
#    `/` — e.g. page.front points at a route that no longer resolves (the classic
#    "installed but the homepage was never seeded" state). Checks 1-3 all pass on
#    such a site, so without this check converge would declare a locked-out site
#    healthy. We render `/` through Drupal's HTTP kernel as an internal subrequest
#    (NOT curl) so this works during converge, before the web server is up. The
#    subrequest goes through the `http_kernel` service, which does not re-apply the
#    trusted-host check, so a synthetic `localhost` request is fine. A marker keeps
#    us robust against any incidental output from the eval.
front_status="$($DRUSH php:eval '
  $switcher = \Drupal::service("account_switcher");
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try {
    $request = \Symfony\Component\HttpFoundation\Request::create("/");
    $response = \Drupal::service("http_kernel")->handle($request);
    print "FRONTDOOR=" . $response->getStatusCode();
  } finally {
    $switcher->switchBack();
  }
' 2>/dev/null | sed -n 's/.*FRONTDOOR=\([0-9]\{1,\}\).*/\1/p' | tail -1)"
case "$front_status" in
  ''|*[!0-9]*)
    fail "front page did not render — Drupal returned no status for an anonymous request to /" ;;
esac
if [ "$front_status" -ge 400 ]; then
  front=$($DRUSH cget system.site page.front --format=string 2>/dev/null || echo '?')
  fail "front page returns HTTP $front_status for an anonymous visitor (page.front = ${front}) — the site has no working front door"
fi

# 5. A DYNAMIC route renders. `/` is the one URL on the site that is always warm:
#    it is anonymously page-cacheable, so a site whose every uncached route throws
#    can still serve check 4 from cache and be certified healthy. That is exactly
#    the WSOD reported in manager#2 — the console was announced ready, and every
#    route the user then clicked was a 500. `/user/login` is uncacheable (it carries
#    a form token), so rendering it exercises routing, the theme and the container
#    for real. Same internal-subrequest mechanism as check 4, and for the same
#    reason: it has to work during converge, before the web server is up.
#
#    NOT YET COVERED, deliberately: this still runs as root, so a site whose code
#    the WEB USER cannot read passes — the failure mode that cost a day in the
#    0.5.1 gate (431 files mode 600). The unprivileged fix is a real HTTP request
#    as www-data, which needs a Host the site will accept; `trusted_host_patterns`
#    is operator-configured (AINCIENT_TRUSTED_HOSTS), so a `localhost` probe can
#    legitimately return 400 and would fail healthy sites. Tracked separately.
login_status="$($DRUSH php:eval '
  $switcher = \Drupal::service("account_switcher");
  $switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
  try {
    $request = \Symfony\Component\HttpFoundation\Request::create("/user/login");
    $response = \Drupal::service("http_kernel")->handle($request);
    print "DYNROUTE=" . $response->getStatusCode();
  } finally {
    $switcher->switchBack();
  }
' 2>/dev/null | sed -n 's/.*DYNROUTE=\([0-9]\{1,\}\).*/\1/p' | tail -1)"
case "$login_status" in
  ''|*[!0-9]*)
    fail "dynamic route did not render — Drupal returned no status for an anonymous request to /user/login" ;;
esac
if [ "$login_status" -ge 500 ]; then
  fail "/user/login returns HTTP $login_status for an anonymous visitor — the site serves its cached front page but no dynamic route works"
fi

# 6. A model is bound for the default role (warns rather than fails — a site may
#    be mid-setup before a provider is connected). Provider-neutral: we ask
#    whether the DEFAULT ROLE resolves to a provider+model, not whether any
#    vendor-specific key exists.
#
#    This asks the ROLE LAYER, which is the only authority left. It used to call
#    \Drupal::service("ai.provider"), but drupal/ai was uninstalled (2026-08-02)
#    and that service no longer exists — so the call threw, the `|| true` shape
#    of the test swallowed it, and every healthy site warned "no AI provider
#    connected" on every single boot. A check that always fires is a check
#    nobody reads.
#
#    Probe by printing a token and grepping it: exit() codes from `drush php:eval`
#    are not reliable.
if ! $DRUSH php:eval '
  $r = \Drupal::service("aincient_core.model_role_resolver");
  $b = $r->binding($r->defaultRole());
  print !empty($b["provider_id"]) && !empty($b["model_id"]) ? "AI_OK" : "";
' 2>/dev/null | grep -q AI_OK; then
  printf '[health] WARN: no AI provider connected yet — chat will not work until you connect a provider in the console (first-run onboarding wizard)\n' >&2
fi

printf '[health] OK\n'
