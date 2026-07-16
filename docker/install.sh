#!/usr/bin/env bash
#
# Atelier — one-line installer.
#
# Install (or upgrade) the Atelier appliance with a single command:
#
#   curl -fsSL https://aincient-labs.com/install.sh | bash
#
# The image is public — no login or token required.
#
# This is NOT a binary downloader (Atelier is a Drupal app, not a static
# binary). It is a thin *appliance bootstrapper*: it lays down a docker-compose
# stack (app + db) and starts it. The image's own entrypoint (converge.sh) does
# the real install on first boot — see docker/README.md.
#
# Re-running is an upgrade: it pulls the newer image tag and recreates `app`,
# whose entrypoint converges (runs hook_update_N) in place. Idempotent.
#
# Honest limitations of the curl path (vs. the full compose.yaml in this repo):
#   - No `updater` sidecar (it needs the repo + Docker socket). One-click upgrade
#     from the console is unavailable; upgrade by re-running this script.
#   - Requires Docker + the Compose plugin already installed. A CMS needs PHP +
#     a database + storage — that floor can't be hidden behind a binary fetch.
#
# Overridable via env:
#   AINCIENT_IMAGE   image tag to run         (default: ghcr.io/aincient-labs/atelier:edge)
#   HTTP_PORT        host port for the console (default: 41221 — "AINCI" in leet)
#   ATELIER_HOME     install dir              (default: ~/.atelier)
#
# No AI key is set here: a fresh install boots keyless and prompts you to connect
# a provider in the console's first-run onboarding wizard.

set -euo pipefail

AINCIENT_IMAGE="${AINCIENT_IMAGE:-ghcr.io/aincient-labs/atelier:edge}"
HTTP_PORT="${HTTP_PORT:-41221}"   # "AINCI" in leet (4=A,1=I,2=N,2=C,1=I)
INSTALL_DIR="${ATELIER_HOME:-$HOME/.atelier}"

# --- pretty output ----------------------------------------------------------
if [ -t 1 ]; then
  bold=$'\033[1m'; dim=$'\033[2m'; red=$'\033[31m'; grn=$'\033[32m'; rst=$'\033[0m'
else
  bold=""; dim=""; red=""; grn=""; rst=""
fi
say()  { printf '%s==>%s %s\n' "$grn" "$rst" "$*"; }
warn() { printf '%s!! %s%s\n' "$red" "$*" "$rst" >&2; }
die()  { warn "$*"; exit 1; }

# --- preflight --------------------------------------------------------------
command -v docker >/dev/null 2>&1 \
  || die "Docker is required but not found. Install Docker Desktop/Engine, then re-run."
docker info >/dev/null 2>&1 \
  || die "Docker is installed but not running. Start Docker, then re-run."
docker compose version >/dev/null 2>&1 \
  || die "The Docker Compose plugin is required (try: docker compose version)."

# --- workspace + config -----------------------------------------------------
say "Setting up ${bold}${INSTALL_DIR}${rst}"
mkdir -p "$INSTALL_DIR"

# The slim runtime topology: app + db only (no build context, no updater).
cat > "$INSTALL_DIR/compose.yaml" <<'YAML'
name: atelier
services:
  db:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_DB: aincient
      POSTGRES_USER: aincient
      POSTGRES_PASSWORD: ${DB_PASSWORD:-aincient}
    volumes:
      - db-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U aincient -d aincient"]
      interval: 10s
      retries: 10
  app:
    image: ${AINCIENT_IMAGE:-ghcr.io/aincient-labs/atelier:edge}
    depends_on:
      db:
        condition: service_healthy
    environment:
      DATABASE_URL: pgsql://aincient:${DB_PASSWORD:-aincient}@db/aincient
      HASH_SALT: ${HASH_SALT:?set HASH_SALT in .env}
      AINCIENT_TRUSTED_HOSTS: ${AINCIENT_TRUSTED_HOSTS:-}
      AINCIENT_ADMIN_PASS: ${ADMIN_PASS:-}
    ports:
      - "${HTTP_PORT:-41221}:80"
    volumes:
      - files:/opt/drupal/web/sites/default/files
      - private:/opt/drupal/private
    restart: unless-stopped
volumes:
  db-data:
  files:
  private:
YAML

# Write .env only on first install — never clobber an existing key/salt.
ENV_FILE="$INSTALL_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
  # ADMIN_PASS left blank on purpose: converge mints a random admin password on
  # first boot and records it (app log + private/INITIAL_ADMIN_PASSWORD). Set a
  # value here only if you want to pin your own.
  cat > "$ENV_FILE" <<ENV
HASH_SALT=$(openssl rand -hex 32)
AINCIENT_IMAGE=${AINCIENT_IMAGE}
HTTP_PORT=${HTTP_PORT}
ADMIN_PASS=
ENV
  chmod 600 "$ENV_FILE"
  say "Wrote ${dim}${ENV_FILE}${rst} (HASH_SALT generated)"
else
  # Keep the secrets already on disk (HASH_SALT), but reconcile
  # the tunables this invocation may have changed — otherwise a re-run that points
  # at a new image/port leaves .env lying, and a later plain `docker compose up`
  # (no env var) silently reverts to the stale value.
  upsert() {  # key value — replace the line in place, or append if absent
    if grep -q "^$1=" "$ENV_FILE"; then
      sed -i.bak "s|^$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
    else
      printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
  }
  upsert AINCIENT_IMAGE "$AINCIENT_IMAGE"
  upsert HTTP_PORT "$HTTP_PORT"
  say "Reusing ${dim}${ENV_FILE}${rst} (image + port reconciled to this run)"
fi

# --- launch (idempotent: pull + up = install OR upgrade) --------------------
cd "$INSTALL_DIR"
say "Pulling ${bold}${AINCIENT_IMAGE}${rst}"
if ! docker compose pull --quiet; then
  warn "Couldn't pull ${AINCIENT_IMAGE} — check your network and that the tag exists."
  warn "Falling back to a local image if one is present."
fi
say "Starting the appliance"
docker compose up -d

# --- wait for the console ---------------------------------------------------
url="http://localhost:${HTTP_PORT}/"
say "Waiting for the console to converge ${dim}(first boot installs Drupal + the AI stack)${rst}"
for i in $(seq 1 60); do
  code="$(curl -fsS -o /dev/null -w '%{http_code}' "$url" 2>/dev/null || true)"
  case "$code" in 200|30[0-9]) ok=1; break ;; esac
  sleep 5
done

echo
if [ "${ok:-}" = "1" ]; then
  # converge minted a random admin password on first boot; read it back so the
  # operator can log in. (Empty on a re-run/upgrade — the password already exists.)
  admin_pw="$(docker compose exec -T app sh -c 'cat /opt/drupal/private/INITIAL_ADMIN_PASSWORD 2>/dev/null' 2>/dev/null | tr -d '\r\n' || true)"
  printf '%s✓ Atelier is running%s\n' "$grn" "$rst"
  printf '  Console:  %s%s%s\n' "$bold" "$url" "$rst"
  if [ -n "$admin_pw" ]; then
    printf '  Login:    %sadmin / %s%s  %s(change this after first login!)%s\n' "$bold" "$admin_pw" "$rst" "$dim" "$rst"
  else
    printf '  Login:    %sadmin%s  %s(set on your first install — recover it below)%s\n' "$bold" "$rst" "$dim" "$rst"
  fi
  printf '  Manage:   %sdocker compose -f %s/compose.yaml [logs|down|pull]%s\n' "$dim" "$INSTALL_DIR" "$rst"
  printf '  Lost pw?  %sread it back, or reset it — your data lives in the volume either way:%s\n' "$dim" "$rst"
  printf '            %sdocker compose -f %s/compose.yaml exec app cat /opt/drupal/private/INITIAL_ADMIN_PASSWORD%s\n' "$dim" "$INSTALL_DIR" "$rst"
  printf '            %sdocker compose -f %s/compose.yaml exec app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web user:password admin <newpass>%s\n' "$dim" "$INSTALL_DIR" "$rst"
else
  warn "Console didn't answer on ${url} within ~5 min."
  warn "Check logs:  docker compose -f ${INSTALL_DIR}/compose.yaml logs -f app"
  exit 1
fi
