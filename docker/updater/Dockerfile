# The updater sidecar: just the Docker CLI + compose plugin and the watch loop.
# Tiny on purpose — its only job is to pull the new app image and recreate the
# app container when the console asks.
#
# Built by compose with context: docker/ (dockerfile: updater/Dockerfile), so
# the COPY source is relative to docker/ — hence updater/updater.sh, not updater.sh.
#
# Docker 29: the 28 line is no longer rebuilt, so its Go binaries and OpenSSL stay on
# old, advisory-laden versions (umbrella DECISIONS 0503, weekly scan, cms #88). The 29
# CLI negotiates down to the host's API: pull / up / ps / rollback verified against
# Engine 24, 25 and 27. `apk upgrade` takes Alpine's fixes the base hasn't been
# rebuilt with yet.
FROM docker:29-cli

RUN apk upgrade --no-cache

COPY updater/updater.sh /usr/local/bin/updater.sh
RUN chmod +x /usr/local/bin/updater.sh

ENTRYPOINT ["/usr/local/bin/updater.sh"]
