#!/usr/bin/env bash
#
# Run the converge unit tests. Uses the bats Docker image so no host install of
# bats is needed (and it's exactly what CI runs).
#
set -euo pipefail
DOCKER_DIR="$(cd "$(dirname "$0")/.." && pwd)"   # the docker/ directory
exec docker run --rm -v "$DOCKER_DIR:/code" -w /code bats/bats:latest tests/converge.bats
