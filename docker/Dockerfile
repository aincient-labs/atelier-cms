# syntax=docker/dockerfile:1
# AIncient CMS — the appliance image. The image IS the product and the unit of
# versioning: "get the next AIncient" = pull the next tag of this image. Code is
# frozen here at build time; state (DB, files) lives in mounted volumes.
#
# Build from the repo root:  docker build -f docker/Dockerfile -t aincient/cms:dev .

# --- Stage 1: resolve PHP dependencies (core + contrib + vendor) ------------
FROM composer:2 AS vendor
WORKDIR /app
# Copy only the manifests first so this layer caches until deps change.
COPY composer.json composer.lock ./
# The local patches referenced by extra.patches (cweagans/composer-patches) must
# be present in the build context before `composer install`, or composer-patches
# can't stat the file, falls into its remote-download branch, and dies with a
# TypeError (RemoteFilesystem::copy() gets a null origin URL). patches/ changes
# rarely, so it sits alongside the manifests without hurting layer caching.
COPY patches ./patches
# Materialise web/core, web/modules/contrib, vendor/, and the scaffolded docroot.
# --no-dev: the appliance image never ships phpunit/phpcs/etc.
# --ignore-platform-reqs: this stage only fetches packages; the runtime image
# below has the real PHP extensions (gd, pdo_pgsql, …).
#
# All deps are public (flowdrop resolves from drupal.org), so composer needs no
# private credentials — but the many Packagist dist zipballs served from
# api.github.com hit a 60/hr anonymous rate limit. A GitHub token (the caller's
# GITHUB_TOKEN) wired into github-oauth avoids that. It's provided as a BuildKit
# secret (id=composer_github_token); --mount=secret (not a build-arg) keeps it
# OUT of every image layer and the history. Optional: no secret → anonymous.
RUN --mount=type=secret,id=composer_github_token \
    if [ -s /run/secrets/composer_github_token ]; then \
      echo "[composer] github token secret received: $(wc -c < /run/secrets/composer_github_token) bytes"; \
      composer config --global --auth github-oauth.github.com "$(cat /run/secrets/composer_github_token)"; \
    else \
      echo "[composer] no github token secret — using anonymous GitHub API (subject to the 60/hr rate limit)"; \
    fi && \
    composer install --no-dev --no-interaction --no-progress --prefer-dist --ignore-platform-reqs \
      --optimize-autoloader

# --- Stage 2: runtime -------------------------------------------------------
FROM php:8.4-apache AS runtime

# PHP extensions Drupal needs plus ImageMagick compiled with explicit AVIF support.
# We build ImageMagick from source to avoid Debian Bookworm's minimalist stock package,
# ensuring the libheif-plugin-aomenc encoder is linked to eliminate Error 1.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libzip-dev libpq-dev postgresql-client unzip \
        tar gzip \
        build-essential libheif-dev libavif-dev libheif-plugin-aomenc wget \
    && cd /tmp \
    && wget https://github.com/ImageMagick/ImageMagick/archive/refs/tags/7.1.2-27.tar.gz -O ImageMagick.tar.gz \
    && tar xzf ImageMagick.tar.gz \
    && cd ImageMagick-7.1.2-27 \
    && ./configure --with-heic=yes --with-avif=yes --with-gslib=no \
    && make -j"$(nproc)" \
    && make install \
    && ldconfig /usr/local/lib \
    && cd /tmp && rm -rf ImageMagick* \
    && apt-get purge -y --auto-remove build-essential wget \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd opcache pdo_pgsql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV DRUPAL_ROOT=/opt/drupal/web
WORKDIR /opt/drupal

# Bring in the composer-built tree (core, contrib, vendor, scaffolded docroot)…
COPY --from=vendor /app /opt/drupal
# …then overlay the product's own source: custom modules, recipes, and the
# canonical config. These are what make this image "AIncient" and not vanilla.
COPY web/modules/custom /opt/drupal/web/modules/custom
COPY web/themes/custom /opt/drupal/web/themes/custom
COPY recipes /opt/drupal/recipes
COPY config /opt/drupal/config

# Environment-driven settings.php (DB creds + salt come from the container).
COPY docker/settings.appliance.php /opt/drupal/web/sites/default/settings.php

# Writable state dirs; the webserver owns files, root runs converge/install.
RUN mkdir -p /opt/drupal/private/snapshots /opt/drupal/web/sites/default/files \
    && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private

# Apache serves the Drupal docroot. A <Directory> grant is required — just
# moving DocumentRoot leaves the new path under Debian's default "deny", and
# AllowOverride All lets Drupal's .htaccess rewrite rules (clean URLs) work.
RUN printf '%s\n' \
      '<VirtualHost *:80>' \
      '  DocumentRoot /opt/drupal/web' \
      '  <Directory /opt/drupal/web>' \
      '    AllowOverride All' \
      '    Require all granted' \
      '    DirectoryIndex index.php' \
      '  </Directory>' \
      '</VirtualHost>' \
      > /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Apache 2.4 defaults Timeout to 60s, which would sever a long console/AI request
# before PHP's 600s budget (and the 540s flow loop) elapses. Match the PHP budget.
RUN printf 'Timeout 600\n' > /etc/apache2/conf-available/aincient-timeout.conf \
    && a2enconf aincient-timeout

# Converge + health scripts.
COPY docker/converge.sh docker/healthcheck.sh docker/entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/converge.sh /usr/local/bin/healthcheck.sh /usr/local/bin/entrypoint.sh

# Start from PHP's shipped production ini (display_errors Off, hardened defaults)
# rather than the compiled-in dev-leaning defaults, then layer our overrides on top.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Drush (recipe apply, updatedb) needs more than PHP's default 128M.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/aincient-memory.ini

# Long-request budget for the operator console. A console turn runs the FlowDrop
# agent loop inline within the request; the loop's own wall-clock budget is 540s
# (flowdrop.settings.yml / FlowDropDispatcher `timeout`). PHP's php.ini-production
# default max_execution_time is only 30s, which would kill the request long before
# the flow budget — so pin 600s here to leave ~60s headroom above the loop. Mirrors
# .ddev/php/timeouts.ini for the local dev stack.
RUN printf 'max_execution_time = 600\n' > /usr/local/etc/php/conf.d/aincient-timeouts.ini

# OPcache, tuned for an immutable appliance image. Code is frozen at build time,
# so validate_timestamps=0 skips the per-request stat() entirely — the whole
# opcache is warmed once and never revalidated for the life of the container.
# (Safe here precisely because "new code" means a new image tag, never an in-place
# edit — the dev-mode stale-opcache gotcha does not apply to the appliance.)
RUN printf '%s\n' \
      'opcache.enable=1' \
      'opcache.enable_cli=0' \
      'opcache.validate_timestamps=0' \
      'opcache.memory_consumption=256' \
      'opcache.interned_strings_buffer=32' \
      'opcache.max_accelerated_files=100000' \
      > /usr/local/etc/php/conf.d/aincient-opcache.ini

# settings.php is expected to read DB creds + hash salt from the environment
# (DATABASE_URL, HASH_SALT) and the config sync dir — standard containerised
# Drupal wiring. (Left to the deployment to mount/template; noted in README.)

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
  CMD /usr/local/bin/healthcheck.sh || exit 1

# Converge on every start, then run Apache in the foreground.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
