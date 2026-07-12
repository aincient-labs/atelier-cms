#!/usr/bin/env bash
#
# AIncient container entrypoint: converge first, then hand off to the web
# server. Because converge runs on every start, simply restarting the
# container on a new image performs the upgrade — there is no separate
# "migrate" step for the operator to remember.
#
set -euo pipefail

HERE="$(dirname "$0")"

# Correct ownership of the mounted state volumes so Apache/PHP (www-data) can
# write uploads and image-style derivatives. The Dockerfile's build-time chown
# never sees these paths — the `files`/`private` volumes mount OVER them at
# runtime, so a volume left root-owned leaves them unwritable by www-data;
# Apache/PHP then can't save uploads or generate image-style derivatives →
# images silently fail to save/render.
fix_state_ownership() {
  chown -R www-data:www-data \
    "${DRUPAL_ROOT:-/opt/drupal/web}/sites/default/files" \
    /opt/drupal/private 2>/dev/null || true
}

# Pass 1 (pre-converge): repair a volume created by an earlier image so converge
# and its health check see a writable tree from the start.
fix_state_ownership

# Converge state to the code in this image. If it fails (and rolls back), the
# previous database is intact; we still start the server so the operator can
# inspect the running (pre-upgrade) site rather than getting a dead container.
if "${HERE}/converge.sh"; then
  echo "[entrypoint] converge succeeded"
else
  echo "[entrypoint] converge FAILED — starting server on the rolled-back state for inspection" >&2
fi

# Pass 2 (post-converge): converge runs drush AS ROOT, so a fresh install (or an
# upgrade) creates root-owned subdirs inside sites/default/files — the demo seed
# copies branded images in, and site:install/cache:rebuild create styles/, css/,
# js/ and php/. Pass 1 ran before any of that existed, so re-assert ownership
# now, before Apache serves the first request, or the first upload's derivative
# can't be written into the root-owned styles/ tree and renders broken.
fix_state_ownership

# Hand off to whatever the image's CMD is (php-fpm, apache2-foreground, …).
exec "$@"
