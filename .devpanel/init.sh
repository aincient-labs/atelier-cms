#!/usr/bin/env bash
#
# .devpanel/init.sh — Atelier on Drupal Forge (DevPanel), build mode.
#
# DevPanel clones this repo (the public atelier-cms artifact) as the app root and
# builds it on its own base image. This script runs the build: composer install,
# install Atelier from config/sync, and — when DevPanel injects a LiteLLM trial
# key — wire it up so demo visitors land in a working console with no BYO-key step.
#
# It is INERT outside DevPanel: a plain clone of atelier-cms never runs it, and the
# settings include below is gated on $DP_APP_ID. See the project README ("Deploy on
# Drupal Forge") and, internally, aincient-workspace/plans/forge-demo.md.
#
# Env (DevPanel-provided): DB_* (MySQL), DP_APP_ID, DP_HOSTNAME,
#   DP_AI_VIRTUAL_KEY (LiteLLM trial key), DP_AI_HOST (default ai.drupalforge.org).
#
export PATH="$APP_ROOT/vendor/bin:$PATH"
if [ -n "${DEBUG_SCRIPT:-}" ]; then set -x; fi
set -eu -o pipefail
cd "$APP_ROOT"

# Budget model: cheapest capable Claude everywhere so the $1 trial stretches to
# ~"3 pages + 1 brand". Image generation is left UNBOUND (no image model on the
# proxy) — the roles.image gate then cleanly hides the AI-image affordances.
DEMO_MODEL="anthropic/claude-haiku-4-5"

export COMPOSER_NO_AUDIT=1 COMPOSER_NO_BLOCKING=1 COMPOSER_NO_SECURITY_BLOCKING=1

echo "== Composer install =="
composer -n install --no-progress

# --- Ensure Drupal reads DevPanel's DB/settings ----------------------------------
# DevPanel provides MySQL via DB_* env; settings.devpanel.php maps them onto
# $databases. Make settings.php include it (gated on DP_APP_ID, so a normal clone
# is unaffected) BEFORE install — drush si reads the DB connection from here.
SETTINGS="web/sites/default/settings.php"
sudo chmod u+w web/sites/default 2>/dev/null || true
if [ ! -f "$SETTINGS" ]; then
  cp web/sites/default/default.settings.php "$SETTINGS"
fi
sudo chmod u+w "$SETTINGS" 2>/dev/null || true
if ! grep -q 'settings.devpanel.php' "$SETTINGS"; then
  cat >> "$SETTINGS" <<'PHP'

/**
 * Load DevPanel (Drupal Forge) settings, if available. Active only under DevPanel.
 */
$devpanel_settings = dirname($app_root) . '/.devpanel/settings.devpanel.php';
if (getenv('DP_APP_ID') !== FALSE && file_exists($devpanel_settings)) {
  include $devpanel_settings;
}
PHP
fi

[ -d private ] || mkdir -m 775 private
[ -d config/sync ] || mkdir -pm 775 config/sync

# --- Install Atelier, or update on subsequent boots ------------------------------
if [ -z "$(drush status --field=db-status 2>/dev/null)" ]; then
  echo "== Install Atelier from config/sync =="
  # Throwaway public demo: a known admin/admin is intentional so visitors can drive
  # the site (they ARE the admin). Never do this outside a sandbox demo.
  drush -n site:install minimal --existing-config -y \
    --site-name="Atelier" --account-name=admin --account-pass=admin
  drush -n cache:rebuild

  # Seed the branded homepage (aincient_demo is not in config/sync — enable it
  # explicitly, exactly as the appliance converge does).
  drush -n pm:install aincient_demo -y || echo "WARN: demo seed skipped"
  drush -n cache:rebuild

  # --- Wire the LiteLLM trial key (only when DevPanel injected one) ---------------
  if [ -n "${DP_AI_VIRTUAL_KEY:-}" ]; then
    echo "== Wire LiteLLM trial key + model roles =="
    drush -n pm:install ai_provider_litellm -y

    # Env-provider Key entity: the secret is read live from the container env and
    # is never written to config, State, or the database.
    drush -n key:save litellm_api_key \
      --label="LiteLLM trial key (DevPanel)" \
      --key-type=authentication \
      --key-provider=env \
      --key-input=none \
      --key-provider-settings='{"env_variable":"DP_AI_VIRTUAL_KEY","base64_encoded":false,"strip_line_breaks":true}'

    drush -n config:set ai_provider_litellm.settings api_key    litellm_api_key
    drush -n config:set ai_provider_litellm.settings host       "${DP_AI_HOST:=https://ai.drupalforge.org}"
    drush -n config:set ai_provider_litellm.settings moderation false --input-format=yaml

    # Bind reasoning/task/fast to the budget model + project onto ai.settings /
    # flowdrop_chat (the exact API onboarding uses). image role stays unbound.
    drush -n php:eval '
      $r = \Drupal::service("aincient_core.model_role_resolver");
      foreach (["reasoning", "task", "fast"] as $role) {
        $r->bind($role, "litellm", "'"$DEMO_MODEL"'");
      }
      $r->project();
    '

    # A provider is configured, so skip the first-run wizard.
    drush -n state:set aincient_onboarding.completed 1
    echo "== LiteLLM wired: roles → $DEMO_MODEL, image gen off, onboarding skipped =="
  else
    echo "== No DP_AI_VIRTUAL_KEY — demo boots keyless (onboarding wizard will show) =="
  fi

  drush -n cache:rebuild
else
  echo "== Existing site — run database updates =="
  drush -n updb -y
fi

echo "== Warm caches =="
drush cron || :
drush cache:rebuild || :

echo "== Fix ownership =="
sudo chown -R "${APACHE_RUN_USER:=www-data}" web/sites/default/files private config/sync 2>/dev/null || true

echo "== init.sh complete =="
