# The updater sidecar: just the Docker CLI + compose plugin and the watch loop.
# Tiny on purpose — its only job is to pull the new app image and recreate the
# app container when the console asks.
#
# Built by compose with context: docker/ (dockerfile: updater/Dockerfile), so
# the COPY source is relative to docker/ — hence updater/updater.sh, not updater.sh.
FROM docker:27-cli

COPY updater/updater.sh /usr/local/bin/updater.sh
RUN chmod +x /usr/local/bin/updater.sh

ENTRYPOINT ["/usr/local/bin/updater.sh"]
