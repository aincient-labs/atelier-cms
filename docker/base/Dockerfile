# syntax=docker/dockerfile:1
# Atelier base image — the OS layer of the appliance (DECISIONS 0537).
#
# Debian trixie-slim + PHP 8.4, Apache, ImageMagick, the PostgreSQL client and
# the checks that prove them. Published as ghcr.io/aincient-labs/atelier-base by
# .github/workflows/base.yml (atelier-cms), cosign-signed, multi-arch. The app
# image (docker/Dockerfile) pins it BY DIGEST, so its ~56 MB of packages are
# downloaded once per base, not once per Atelier release: `apt-get` is never
# reproducible (the archive moves, dpkg writes its own logs), so as long as it
# ran inside the app build every release re-published this layer.
#
# Changing this file changes nothing for users until a cms commit bumps the pin
# (bin/base-image bump). Labels live under dev.atelier.base.* ONLY: the app
# image inherits them, and must never inherit an org.opencontainers.* or
# dev.atelier.* label the Manager reads.
#
# Build locally:  docker build -f docker/base/Dockerfile -t atelier-base:local docker/base
FROM debian:trixie-slim@sha256:a72b4433de1cf7d6bae0c4cee74d0547f58601a643b3375f070c51600d63b6b2

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

ARG BASE_VERSION=dev
ARG BASE_REVISION=dev
LABEL dev.atelier.base.version="${BASE_VERSION}" \
      dev.atelier.base.revision="${BASE_REVISION}" \
      dev.atelier.base.source="https://github.com/aincient-labs/atelier-cms/tree/main/docker/base"
