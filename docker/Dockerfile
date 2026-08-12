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

# Stock scaffold .htaccess EXEMPTS /favicon.ico from the index.php rewrite, so
# Apache 404s it before Drupal's /favicon.ico route (aincient_pages.favicon —
# operator icon or bundled default) can answer. Drop the exemption; grep -c
# fails the build if a future core scaffold rewords the line and the sed
# silently stops matching.
RUN test "$(grep -c '!=/favicon\.ico' web/.htaccess)" = 1 \
    && sed -i '/!=\/favicon\.ico/d' web/.htaccess

# --- Partition the resolved tree into cache tiers ---------------------------
# The four big trees composer just materialised change at very different rates,
# but a single `COPY --from=vendor /app` put them in ONE 216 MB layer — so the
# fastest-moving tier dragged all the others with it on every dependency bump.
# Measured across the 50 deploys in the umbrella's ledger (bin/atelier-deploys.tsv),
# as a share of the 11 bumps that actually changed composer.lock's runtime set:
#
#   web/core            80 MB   18%   Drupal core releases
#   web/modules/contrib 24 MB   36%   almost entirely the uninstalled ai_provider_*
#                                     set, which leaves next release → ~0% after
#   vendor              76 MB   54%   symfony/ai-* bridges are pre-1.0 and thin
#   web/modules/engine  22 MB   63%   flowdrop — versions with the product
#
# Four of the eleven bumps were flowdrop and nothing else: 22 MB of change that
# re-shipped 216 MB to every appliance. Moving each tier OUT of /app lets stage 2
# copy them as four independent layers, ordered least- to most-volatile.
#
# /app keeps whatever is left — the scaffolded docroot remainder (index.php,
# .htaccess, robots.txt, sites/, profiles/, libraries/) plus the manifests — and
# is copied as a catch-all, so anything composer-scaffold adds in a future core
# release still ships without anyone editing a file list here. That catch-all is
# copied LAST because it holds composer.json/composer.lock, which change on
# *every* dependency bump; ahead of the big tiers it would invalidate all of them.
#
# mkdir -p first: `mv` on a non-existent path is a hard build failure, and
# web/modules/engine only exists while a flowdrop package is required.
RUN mkdir -p /split /app/web/modules/contrib /app/web/modules/engine \
    && mv /app/web/core            /split/core \
    && mv /app/vendor              /split/vendor \
    && mv /app/web/modules/contrib /split/contrib \
    && mv /app/web/modules/engine  /split/engine

# Normalise mtimes across the resolved tree so the COPY --from=vendor layers below
# is byte-stable across rebuilds. composer stamps every file with the build time;
# BuildKit's layer digest hashes those mtimes, so a cache MISS on this stage (GHA
# cache eviction, a cancelled cache export, …) would otherwise hand the unchanged
# core+contrib+vendor layer a brand-new digest and force every appliance to
# re-download ~75 MB it already has. Pinning to a fixed epoch makes identical
# content → identical layer, independent of when it was built. (-h so symlinks are
# stamped in place; -t <YYYYMMDDhhmm> is busybox-portable — this is Alpine.) The
# runtime image's opcache runs validate_timestamps=0, so these mtimes are inert.
RUN find /app /split -exec touch -h -t 202401010000 {} +

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

# ---------------------------------------------------------------------------
# LAYER ORDER BELOW IS LOAD-BEARING. Every layer is re-downloaded by every
# appliance whenever it OR ANY LAYER ABOVE IT changes, so these are ordered
# least- to most-volatile. Churn figures are measured over the 50 deploys in the
# umbrella's ledger (bin/atelier-deploys.tsv) — see the partition comment in the
# vendor stage. Adding a COPY in the wrong place silently inflates every future
# appliance update; put new static config high and new product source low.
# ---------------------------------------------------------------------------

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

# Converge + health scripts. Below the static ini layers (a converge.sh edit
# shouldn't re-digest them) but above the dependency tiers, since these three
# scripts changed in only 3 of 49 deploys.
COPY docker/converge.sh docker/healthcheck.sh docker/entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/converge.sh /usr/local/bin/healthcheck.sh /usr/local/bin/entrypoint.sh

# The upgrade floor — the oldest site version this image's converge will migrate.
# A FILE, not only the build-arg that stamps the label below, because converge
# reads it INSIDE the container and must be right even in a local `docker build`
# that passes no build-args. Its own header documents when to raise it.
COPY docker/upgrade-floor /etc/atelier/upgrade-floor

# --- The composer-resolved dependency tiers, least- to most-volatile ---------
# Partitioned in the vendor stage; see the rationale there. Each is an
# independent layer, so a flowdrop bump now re-ships 22 MB instead of 216 MB.
COPY --from=vendor /split/core    /opt/drupal/web/core
COPY --from=vendor /split/contrib /opt/drupal/web/modules/contrib
COPY --from=vendor /split/vendor  /opt/drupal/vendor
COPY --from=vendor /split/engine  /opt/drupal/web/modules/engine
# The scaffolded docroot remainder + the manifests. LAST of the tiers: it carries
# composer.json/composer.lock, which change on every dependency bump, so placing
# it above the four big trees would invalidate all of them every time.
COPY --from=vendor /app           /opt/drupal

# Environment-driven settings.php (DB creds + salt come from the container).
# MUST stay after the remainder COPY above: core-composer-scaffold can place its
# own sites/default/settings.php, which would otherwise overwrite ours.
COPY docker/settings.appliance.php /opt/drupal/web/sites/default/settings.php

# --- The product's own source, least- to most-volatile ----------------------
# These are what make this image "Atelier" and not vanilla Drupal. Ordered by
# measured churn across the 49 build-to-build transitions: recipes 6%, custom
# themes 14%, config 34%, custom modules 59%.
COPY recipes /opt/drupal/recipes
COPY web/themes/custom /opt/drupal/web/themes/custom
COPY config /opt/drupal/config
COPY web/modules/custom /opt/drupal/web/modules/custom

# The application code must be READABLE BY www-data, whatever the build host's
# file modes were. `COPY` preserves the context's permissions, so a checkout with
# a restrictive umask (or any tool that leaves 600/700 behind) produces an image
# whose modules Apache cannot read — while root, and therefore every `drush`
# command converge runs, reads them fine.
#
# That failure is vicious out of proportion to its cause. Drupal resolves every
# extension by scanning for `*.info.yml`; an unreadable one makes
# InfoParserDynamic parse `false` and die on `array_keys(null)`, so ANY request
# that compiles the service container 500s — while `/` keeps serving 200 from
# the page cache, and converge (running as root) reports a healthy site. It cost
# a full session to diagnose on 2026-08-07, because CI checks out fresh at 644
# and never reproduces it; only a developer's working copy does.
#
# a+rX, not a blanket chmod: `X` sets the execute bit on directories and on files
# that already have one, so traversal works without making every YAML executable.
RUN chmod -R a+rX /opt/drupal

# Writable state dirs; the webserver owns files, root runs converge/install.
# After every COPY above — the remainder tier creates web/sites.
RUN mkdir -p /opt/drupal/private/snapshots /opt/drupal/web/sites/default/files \
    && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private

# settings.php is expected to read DB creds + hash salt from the environment
# (DATABASE_URL, HASH_SALT) and the config sync dir — standard containerised
# Drupal wiring. (Left to the deployment to mount/template; noted in README.)

# ---------------------------------------------------------------------------
# Build stamp — what this image IS. Two carriers, because they're read by two
# different consumers at two different times:
#
#   ENV ATELIER_VERSION  — read at RUNTIME from inside the container (PHP
#     getenv()), so the site can show it on the status report. This is the
#     line a bug reporter pastes.
#   LABEL org.opencontainers.image.*  — read from OUTSIDE via
#     `docker image inspect`, WITHOUT starting the container. This is what the
#     manager compares before/after a pull to report "0.1.0 → 0.2.0" and to
#     refuse a downgrade.
#
# MUST stay at the end of the file. These are zero-byte metadata layers, but a
# build-arg changes on every deploy — placed earlier, it would invalidate every
# COPY/RUN below it and re-digest the big core+contrib+vendor (~75 MB) and
# ImageMagick (~54 MB) layers, defeating the SOURCE_DATE_EPOCH reproducibility
# work in the release workflow and making every appliance re-pull ~130 MB it
# already has.
#
# Both default to "dev": a local `docker build` (and docker/tests/smoke.sh)
# passes no build-arg, and an unstamped image must be recognisably NOT a
# release rather than silently claiming to be one.
ARG ATELIER_VERSION=dev
ARG ATELIER_REVISION=dev
ENV ATELIER_VERSION=${ATELIER_VERSION}
LABEL org.opencontainers.image.title="Atelier by AIncient Labs" \
      org.opencontainers.image.version="${ATELIER_VERSION}" \
      org.opencontainers.image.revision="${ATELIER_REVISION}" \
      org.opencontainers.image.source="https://github.com/aincient-labs/atelier-cms" \
      org.opencontainers.image.licenses="GPL-2.0-or-later"

# The upgrade floor, as METADATA — the same value as /etc/atelier/upgrade-floor,
# stamped by the release workflow (which reads that file, so the two cannot be
# typed apart). This carrier exists because the manager has to know an image's
# floor BEFORE deciding to pull it: `docker buildx imagetools inspect` reads a
# label straight from the registry, while the file inside the image is only
# readable once the ~500 MB it lives in is already on the machine — by which
# point the doomed upgrade is the cheap part. Absent on a local build; the
# manager reads absence as "declares no floor" and plans a single hop.
ARG ATELIER_UPGRADE_FLOOR=
LABEL dev.atelier.upgrade.min-from="${ATELIER_UPGRADE_FLOOR}"

# The non-core extensions this image SHIPS, comma-separated — the second thing
# the manager needs to know before pulling. The floor answers "is this site too
# old?"; this answers "does this image still contain the code this site has
# installed?", which is the question that took a user's site down on 2026-08-06:
# fourteen installed modules the new release no longer carried, so Drupal could
# not resolve their paths and could not boot (DECISIONS 0348/0353).
#
# Read from the REGISTRY without pulling, for the same reason as the floor —
# by the time the ~500 MB image is local, the doomed update is the cheap part.
# The manager diffs it against the site's installed extensions and refuses
# BEFORE the pull, naming the modules to uninstall while their code still
# exists. converge.sh asks the same question inside the container against the
# real filesystem and REMAINS THE AUTHORITY: this label is absent on older
# images, excludes core (~700 more names for 18 KB of metadata, and core
# extensions don't vanish between our releases without a Drupal major, which
# the floor gates), and describes the image rather than the running site.
#
# Stamped by the release workflow, which extracts the list from the
# smoke-validated image it just built. Empty on a local build → "unknown", never
# "ships nothing". See plans/channel-hardening.md.
ARG ATELIER_EXTENSIONS=
LABEL dev.atelier.extensions="${ATELIER_EXTENSIONS}"

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
  CMD /usr/local/bin/healthcheck.sh || exit 1

# Converge on every start, then run Apache in the foreground.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
