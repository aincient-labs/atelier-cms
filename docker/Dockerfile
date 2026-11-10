# syntax=docker/dockerfile:1
# AIncient CMS — the appliance image. The image IS the product and the unit of
# versioning: "get the next AIncient" = pull the next tag of this image. Code is
# frozen here at build time; state (DB, files) lives in mounted volumes.
#
# Build from the repo root:  docker build -f docker/Dockerfile -t aincient/cms:dev .

# --- Stage 1: resolve PHP dependencies (core + contrib + vendor) ------------
FROM composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS vendor
WORKDIR /app
# Copy only the manifests first so this layer caches until deps change.
COPY composer.json composer.lock patches.lock.json ./
# The local patches referenced by extra.patches (cweagans/composer-patches) must
# be present in the build context before `composer install`, or composer-patches
# can't stat the file, falls into its remote-download branch, and dies with a
# TypeError (RemoteFilesystem::copy() gets a null origin URL). patches/ changes
# rarely, so it sits alongside the manifests without hurting layer caching.
#
# patches.lock.json rides along for the same reason, one level up: under
# composer-patches 2.x it, not composer.json, is what the plugin applies. The
# plugin would regenerate a missing one from composer.json and still patch
# correctly, but then the image's patch set would be resolved at build time
# instead of pinned by the sha256 we committed. Copying it makes the appliance
# build reproduce exactly the patch set CI verified.
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

# Drop Drupal core's test trees (~45 MB) before they reach a runtime layer. They
# are never loaded in production: the --no-dev autoloader maps nothing under them,
# and extension discovery skips tests/ dirs unless extension_discovery_scan_tests
# is set (the appliance never sets it). Shipping them made scanners flag the
# package_manager fake_site fixture's installed.json (drupal/core 9.8.0) as High/
# Critical vulnerabilities in the image (DECISIONS 0505).
#
# Contrib, engine (FlowDrop) and vendor test trees go too (DECISIONS 0510): test
# code and fixtures get far less review than the code they test, so they are
# attack and scanner surface the appliance never runs. Contrib/engine: every
# tests/ dir at any depth (submodules and their test modules included; discovery
# skips them for the same reason as core's). Vendor: only a package's root-level
# tests/ or test/ — nothing in the --no-dev autoloader maps there (checked
# against installed.json), whereas a capitalised Test/ (symfony/*/Test) is a
# PSR-4 namespace inside the package and stays. Docs dirs stay: markdown, not
# code, and drush reads its own docs/ for `drush topic`. Our own modules' tests
# never enter the build context (.dockerignore).
#
# Core uses the same any-depth sweep: its test trees also sit in profiles/tests,
# recipes/*/tests and nested submodules, which a fixed glob list misses.
RUN find web/core web/modules/contrib web/modules/engine -depth -type d -name tests -exec rm -rf {} + \
    && find vendor -mindepth 3 -maxdepth 3 -type d \( -name tests -o -name test \) -exec rm -rf {} + \
    && test ! -e web/core/modules/package_manager/tests \
    && test -z "$(find web/core web/modules/contrib web/modules/engine -type d -name tests -print -quit)"

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
# runtime image's opcache runs validate_timestamps=0, so these mtimes are inert
# for PHP. They are NOT inert for Drupal: since 11.4 the `file_parsing` cache bin
# (parsed *.routing.yml / *.libraries.yml) survives cache clears and invalidates on
# filemtime alone, so a fixed mtime makes a changed YAML file look unchanged across
# an image swap. converge.sh truncates that bin on every upgrade to compensate —
# do not drop that truncate while this normalisation stands.
RUN find /app /split -exec touch -h -t 202401010000 {} +

# --- Stage 2: runtime -------------------------------------------------------
FROM debian:trixie-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2 AS runtime

# The runtime is Debian's own packages — PHP 8.4, Apache, ImageMagick — on the
# official slim base, with nothing compiled. Before this the image came from
# php:8.4-apache plus a from-source ImageMagick: that base installs a compiler
# toolchain ($PHPIZE_DEPS, ~335 MB) in one of ITS layers, and purging it in ours
# hid it from scanners but could never shrink the download — a later layer
# cannot take bytes out of an earlier one. Debian packages also put PHP and
# ImageMagick under Debian's security tracker, so the release scan sees them as
# what they are, and the layer is reproducible (apt, not autotools).
#
# What the official php image gave us that Debian does not, re-created below:
# Apache logging to the container's stdout/stderr, the apache2-foreground
# launcher (clears a stale pid file so a restarted container comes up), and
# Apache's SIGWINCH graceful stop.
#
# PHP version floor: Debian backports security fixes into its own package
# version, so PHP_VERSION can stay at a number while carrying later fixes. The
# floor still guards the case it exists for — a stale cached layer older than
# the PHP2Shell fix (CVE-2026-109618/109619, fixed in 8.4.27).
#
# Extension parity with the php:8.4-apache image is checked by name below; Debian
# packages a few extensions that image did not load (calendar, exif, ffi, …) —
# they are disabled so the attack surface does not grow with the swap.
#
# Debian's ImageMagick reads far more formats than our old source build (TIFF,
# JPEG 2000, RAW, DjVu, …), and every reader is parsing surface for an uploaded
# file. policy.xml narrows it to an allowlist: the formats imagemagick.settings
# enables (PNG, JPEG, GIF, WebP, AVIF), HEIC/HEIF (the libheif coder AVIF rides
# on) and xc: (the probes below). No external delegates. The build fails if a
# TIFF write still works. `apt-get upgrade`: the slim base can lag Debian's
# security archive (zlib, 2026-11) — the release scan would block it otherwise.
ARG DEBIAN_FRONTEND=noninteractive
STOPSIGNAL SIGWINCH
EXPOSE 80
RUN set -eux \
    && apt-get update && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl \
        apache2 libapache2-mod-php8.4 \
        php8.4-cli php8.4-common php8.4-curl php8.4-gd php8.4-mbstring php8.4-opcache \
        php8.4-pgsql php8.4-readline php8.4-sqlite3 php8.4-xml php8.4-zip \
        imagemagick libheif-plugin-aomenc libheif-plugin-dav1d libheif-plugin-libde265 \
        postgresql-client unzip tar gzip \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'exit(version_compare(PHP_VERSION, "8.4.27", "<") ? 1 : 0);' \
    && for m in calendar exif ffi ftp gettext pgsql shmop sockets sysvmsg sysvsem sysvshm xsl; do \
         if [ -e "/etc/php/8.4/mods-available/$m.ini" ]; then phpdismod -s ALL "$m"; fi; \
       done \
    && sed -i 's#</policymap>#  <policy domain="delegate" rights="none" pattern="*"/>\n  <policy domain="coder" rights="none" pattern="*"/>\n  <policy domain="coder" rights="read|write" pattern="{AVIF,GIF,HEIC,HEIF,JPEG,JPG,PNG,PNG8,PNG24,PNG32,PNG48,PNG64,PNG00,WEBP,XC}"/>\n</policymap>#' /etc/ImageMagick-7/policy.xml \
    && grep -q 'pattern="{AVIF,GIF,' /etc/ImageMagick-7/policy.xml \
    && a2dismod -q mpm_event || true \
    && a2enmod -q mpm_prefork php8.4 rewrite headers \
    && ln -sfT /dev/stderr /var/log/apache2/error.log \
    && ln -sfT /dev/stdout /var/log/apache2/access.log \
    && ln -sfT /dev/stdout /var/log/apache2/other_vhosts_access.log \
    && printf '%s\n' \
      '#!/bin/sh' \
      'set -e' \
      ': "${APACHE_CONFDIR:=/etc/apache2}"' \
      '. "$APACHE_CONFDIR/envvars"' \
      'rm -f "$APACHE_PID_FILE"' \
      'mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"' \
      'exec apache2 -DFOREGROUND "$@"' \
      > /usr/local/bin/apache2-foreground \
    && chmod +x /usr/local/bin/apache2-foreground \
    && apache2ctl -t \
    && hash -r && ! command -v gcc && ! command -v cc && ! command -v make \
    && pkgs="$(dpkg-query -W -f='${Package}\n')" \
    && ! printf '%s\n' "$pkgs" | grep -E -e '-dev(:|$)' \
    && php -m > /tmp/php-m \
    && for ext in ctype curl date dom fileinfo filter gd hash iconv json libxml mbstring openssl pcre PDO \
         pdo_pgsql pdo_sqlite Phar posix random readline Reflection session SimpleXML sodium SPL sqlite3 \
         standard tokenizer xml xmlreader xmlwriter 'Zend OPcache' zip zlib; do \
         grep -qx "$ext" /tmp/php-m || { echo "missing PHP extension: $ext" >&2; exit 1; }; done \
    && rm /tmp/php-m \
    && php -r '$g = gd_info(); exit($g["WebP Support"] && $g["JPEG Support"] && $g["FreeType Support"] && $g["PNG Support"] ? 0 : 1);' \
    && for bin in psql pg_dump unzip tar gzip convert magick; do command -v "$bin" || exit 1; done \
    # Debian's list carries a module column (`AVIF  HEIC  rw+`); accept it, as smoke.sh does.
    && magick -list format > /tmp/magick-formats \
    && grep -qE '^[[:space:]]*AVIF\*?[[:space:]]+([A-Z0-9]+[[:space:]]+)?rw' /tmp/magick-formats \
    && for fmt in HEIC WEBP PNG JPEG; do grep -qE "^[[:space:]]*${fmt}\*?[[:space:]]+([A-Z0-9]+[[:space:]]+)?r" /tmp/magick-formats || exit 1; done \
    && rm /tmp/magick-formats \
    && magick -size 8x8 xc:red /tmp/avif-probe.avif && magick /tmp/avif-probe.avif /tmp/avif-probe.png \
    && magick -size 8x8 xc:red /tmp/probe.webp && magick -size 8x8 xc:red /tmp/probe.jpg \
    && convert -size 8x8 xc:red /tmp/probe-v6.avif \
    && ! magick -size 8x8 xc:red /tmp/probe.tiff 2>/dev/null \
    && rm /tmp/avif-probe.avif /tmp/avif-probe.png /tmp/probe.webp /tmp/probe.jpg /tmp/probe-v6.avif

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
# Freeze & Live (DECISIONS 0416): when private/frozen/current points at a
# snapshot, anonymous visitors get its files; a request carrying a Drupal
# session cookie, a console path, or a path the snapshot lacks falls through to
# Drupal. No symlink = Live. The DDEV nginx config mirrors this rule; keep both
# in step. Live is "no symlink", never "symlink to the docroot" — a
# file-exists rule aimed at the docroot would serve settings.php.
RUN printf '%s\n' \
      '<VirtualHost *:80>' \
      '  DocumentRoot /opt/drupal/web' \
      '  <Directory /opt/drupal/web>' \
      '    AllowOverride All' \
      '    Require all granted' \
      '    DirectoryIndex index.php' \
      '  </Directory>' \
      '  <Directory /opt/drupal/private/frozen>' \
      '    Options FollowSymLinks' \
      '    AllowOverride None' \
      '    Require all granted' \
      '    <FilesMatch "^\.|\.ph(p[0-9]?|tml|ar)$">' \
      '      Require all denied' \
      '    </FilesMatch>' \
      '  </Directory>' \
      '  RewriteEngine On' \
      '  RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$' \
      '  RewriteCond %{HTTP_COOKIE} !(^|;\s*)S?SESS[0-9a-f]+= [NC]' \
      '  RewriteCond $1 !^(atelier|user|api|session|admin|system)(/|$)' \
      '  RewriteCond /opt/drupal/private/frozen/current/$1/index.html -f' \
      '  RewriteRule ^/(.*)$ /opt/drupal/private/frozen/current/$1/index.html [L,E=ATELIER_FROZEN:1]' \
      '  RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$' \
      '  RewriteCond %{HTTP_COOKIE} !(^|;\s*)S?SESS[0-9a-f]+= [NC]' \
      '  RewriteCond $1 !^(atelier|user|api|session|admin|system)(/|$)' \
      '  RewriteCond $1 !(^|/)\.' \
      '  RewriteCond $1 !\.ph(p[0-9]?|tml|ar)$ [NC]' \
      '  RewriteCond /opt/drupal/private/frozen/current/$1 -f' \
      '  RewriteRule ^/(.+)$ /opt/drupal/private/frozen/current/$1 [L,E=ATELIER_FROZEN:1]' \
      '  Header set X-Atelier-Served frozen env=ATELIER_FROZEN' \
      '  Header set Cache-Control "public, max-age=300, stale-while-revalidate=86400" env=ATELIER_FROZEN' \
      '</VirtualHost>' \
      > /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Apache 2.4 defaults Timeout to 60s, which would sever a long console/AI request
# before PHP's 600s budget (and the 540s flow loop) elapses. Match the PHP budget.
RUN printf 'Timeout 600\n' > /etc/apache2/conf-available/aincient-timeout.conf \
    && a2enconf aincient-timeout

# Core's web/.htaccess wraps its static-asset ExpiresDefault (1 year; Off for
# .php) in <IfModule mod_expires.c> — inert unless the module is on, so static
# assets shipped with no Cache-Control at all. mod_headers is already enabled
# above; layer content-addressed immutability on top: aggregated CSS/JS under
# sites/default/files/{css,js} carry a hash in the filename, so they never need
# revalidation once cached. Pages (routed through index.php) are untouched —
# core's own ExpiresActive Off for .php keeps them off this path.
RUN a2enmod expires \
    && printf '%s\n' \
      '<LocationMatch "^/sites/default/files/(css|js)/">' \
      '  Header merge Cache-Control "immutable"' \
      '</LocationMatch>' \
      > /etc/apache2/conf-available/aincient-cache.conf \
    && a2enconf aincient-cache

# Debian's apache2 and cli php.ini are already production-based (display_errors
# Off). Our overrides are mods-available snippets enabled for BOTH SAPIs, so
# drush (cli) and Apache read the same limits — as the single php.ini did.

# Drush (recipe apply, updatedb) needs more than PHP's default 128M.
RUN printf 'memory_limit = 512M\n' > /etc/php/8.4/mods-available/aincient-memory.ini \
    && phpenmod -s ALL aincient-memory

# Long-request budget for the operator console. A console turn runs the FlowDrop
# agent loop inline within the request; the loop's own wall-clock budget is 540s
# (flowdrop.settings.yml / FlowDropDispatcher `timeout`). PHP's php.ini-production
# default max_execution_time is only 30s, which would kill the request long before
# the flow budget — so pin 600s here to leave ~60s headroom above the loop. Mirrors
# .ddev/php/timeouts.ini for the local dev stack.
RUN printf 'max_execution_time = 600\n' > /etc/php/8.4/mods-available/aincient-timeouts.ini \
    && phpenmod -s ALL aincient-timeouts

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
      > /etc/php/8.4/mods-available/aincient-opcache.ini \
    && phpenmod -s ALL aincient-opcache

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

# Pack seams (plans/byo-components.md §6): /opt/drupal/packs.d holds the
# declarative enablement drop-ins a client image COPYs (or `atelier pack dev`
# mounts); /opt/drupal/packs is where a DEV stack bind-mounts a pack source —
# converge symlinks it into web/modules/packs so Drupal discovers it while the
# repo root stays outside the docroot (its non-module files are never served).
# Production packs are BAKED into web/modules/custom instead (§6.5).
RUN mkdir -p /opt/drupal/packs.d /opt/drupal/packs /opt/drupal/web/modules/packs

# --- The composer-resolved dependency tiers, least- to most-volatile ---------
# Partitioned in the vendor stage; see the rationale there. Each is an
# independent layer, so a flowdrop bump now re-ships 22 MB instead of 216 MB.
COPY --chmod=a+rX --from=vendor /split/core    /opt/drupal/web/core
COPY --chmod=a+rX --from=vendor /split/contrib /opt/drupal/web/modules/contrib
COPY --chmod=a+rX --from=vendor /split/vendor  /opt/drupal/vendor
COPY --chmod=a+rX --from=vendor /split/engine  /opt/drupal/web/modules/engine
# The scaffolded docroot remainder + the manifests. LAST of the tiers: it carries
# composer.json/composer.lock, which change on every dependency bump, so placing
# it above the four big trees would invalidate all of them every time.
COPY --chmod=a+rX --from=vendor /app           /opt/drupal

# Environment-driven settings.php (DB creds + salt come from the container).
# MUST stay after the remainder COPY above: core-composer-scaffold can place its
# own sites/default/settings.php, which would otherwise overwrite ours.
COPY --chmod=a+rX docker/settings.appliance.php /opt/drupal/web/sites/default/settings.php

# --- The product's own source, least- to most-volatile ----------------------
# These are what make this image "Atelier" and not vanilla Drupal. Ordered by
# measured churn across the 49 build-to-build transitions: recipes 6%, custom
# themes 14%, config 34%, custom modules 59%.
COPY --chmod=a+rX recipes /opt/drupal/recipes
COPY --chmod=a+rX web/themes/custom /opt/drupal/web/themes/custom
COPY --chmod=a+rX config /opt/drupal/config
COPY --chmod=a+rX web/modules/custom /opt/drupal/web/modules/custom
# The studio tier (DECISIONS 0430) is its own layer, not folded into custom's:
# studios will churn at their own rate, like engine/ above, and a studio-only
# change should not re-ship the whole core tier. Tiny today (a README), so the
# layer costs nothing until studios land.
COPY --chmod=a+rX web/modules/studio /opt/drupal/web/modules/studio

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
# So every COPY into /opt/drupal above carries --chmod=a+rX. Not a `RUN chmod -R`
# after them: that rewrote every file into a second 137 MB layer, below all the
# volatile COPYs, so each release re-shipped the whole app twice.
#
# a+rX, not a blanket mode: `X` sets the execute bit on directories and on files
# that already have one, so traversal works without making every YAML executable.
# The `find` below fails the build if a new COPY forgets the flag.

# Writable state dirs; the webserver owns files, root runs converge/install.
# After every COPY above — the remainder tier creates web/sites.
RUN mkdir -p /opt/drupal/private/snapshots /opt/drupal/web/sites/default/files \
    && chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/private \
    && unreadable="$(find /opt/drupal \( ! -perm -o+r -o \( -type d ! -perm -o+x \) \) -print | head -5)" \
    && { [ -z "$unreadable" ] || { echo "not readable by www-data (COPY without --chmod=a+rX?):" >&2; echo "$unreadable" >&2; exit 1; }; }

# The build fingerprint: one content hash of everything converge brings a
# database TO — the code tree, config/sync, recipes, converge's own scripts and
# the upgrade floor. converge.sh records it in State after a converge succeeds;
# a later start of the same build on that database (a restart, a woken resting
# site) skips the upgrade work it would only repeat. After every COPY above, so
# any code or config change is a new fingerprint and a full upgrade. Contents
# and paths only (no mtimes), so it is as reproducible as the layers it hashes.
RUN { find /opt/drupal \( -path /opt/drupal/web/sites/default/files -o -path /opt/drupal/private \) -prune \
        -o -type f -print0; \
      printf '%s\0' /usr/local/bin/converge.sh /usr/local/bin/healthcheck.sh \
        /usr/local/bin/entrypoint.sh /etc/atelier/upgrade-floor; } \
    | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-64 > /etc/atelier/build-fingerprint \
    && touch -h -t 202401010000 /etc/atelier/build-fingerprint

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
# Debian-packages (~188 MB) layers, defeating the SOURCE_DATE_EPOCH reproducibility
# work in the release workflow and making every appliance re-pull ~260 MB it
# already has.
#
# Both default to "dev": a local `docker build` (and docker/tests/smoke.sh)
# passes no build-arg, and an unstamped image must be recognisably NOT a
# release rather than silently claiming to be one.
ARG ATELIER_VERSION=dev
ARG ATELIER_REVISION=dev
ENV ATELIER_VERSION=${ATELIER_VERSION}
LABEL org.opencontainers.image.title="Atelier CMS by AIncient Labs" \
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
