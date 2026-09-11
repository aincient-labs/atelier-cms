#!/usr/bin/env bash
#
# Atelier — one-line installer.
#
# Install (or upgrade) the Atelier appliance with a single command:
#
#   curl -fsSL https://aincient-labs.com/atelier/install.sh | bash
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
#   AINCIENT_IMAGE   image tag to run         (default: ghcr.io/aincient-labs/atelier-cms:latest)
#   HTTP_PORT        host port for the console (default: 41221 — "AINCI" in leet)
#   ATELIER_HOME     install dir              (default: ~/.atelier)
#
# Channels: `:latest` is retagged on every release — that's the default, and what
# the `atelier` CLI calls the *stable* channel. `:edge` is rebuilt on every merge
# to main (unreleased, may break); `:vX.Y.Z` pins one version forever. Setting
# AINCIENT_IMAGE here records the choice, so the manager's one-time move of old
# `:edge` installs onto releases leaves a deliberate pin alone.
#
# No AI key is set here: a fresh install boots keyless and prompts you to connect
# a provider in the console's first-run onboarding wizard.

set -euo pipefail

# Remember whether the operator named an image before defaulting: an explicit
# choice is written to .env as the chosen channel, so nothing later "corrects" it.
AINCIENT_IMAGE_EXPLICIT="${AINCIENT_IMAGE:-}"
AINCIENT_IMAGE="${AINCIENT_IMAGE:-ghcr.io/aincient-labs/atelier-cms:latest}"
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

# The slim runtime topology: edge + app + db (no build context, no updater).
# Keep both heredocs byte-for-byte in step with the manager's stack.rs templates.
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
    image: ${AINCIENT_IMAGE:-ghcr.io/aincient-labs/atelier-cms:latest}
    depends_on:
      db:
        condition: service_healthy
    environment:
      DATABASE_URL: pgsql://aincient:${DB_PASSWORD:-aincient}@db/aincient
      HASH_SALT: ${HASH_SALT:?set HASH_SALT in .env}
      AINCIENT_TRUSTED_HOSTS: ${AINCIENT_TRUSTED_HOSTS:-}
      AINCIENT_ADMIN_PASS: ${ADMIN_PASS:-}
      AINCIENT_REVERSE_PROXY: "1"
    volumes:
      - files:/opt/drupal/web/sites/default/files
      - private:/opt/drupal/private
    restart: unless-stopped
  edge:
    image: nginx:1.28-alpine
    depends_on:
      - app
    ports:
      - "${HTTP_PORT:-41221}:80"
    volumes:
      - ./edge.conf:/etc/nginx/conf.d/default.conf:ro
      - private:/srv/private:ro
    restart: unless-stopped
volumes:
  db-data:
  files:
  private:
YAML

# The edge rule (same file the repo ships as docker/edge.conf).
cat > "$INSTALL_DIR/edge.conf" <<'NGINX'
# Atelier edge — the appliance's only published port (DECISIONS 0416, Phase 3).
#
# Frozen (private/frozen/current -> a snapshot): an anonymous GET/HEAD for a path
# the snapshot has is answered here from disk — PHP never runs for visitors — and
# a path the snapshot lacks gets the snapshot's own 404 page with a real 404.
# Live (no symlink): everything proxies to `app`. Always proxied, frozen or not:
# a request carrying a Drupal session cookie, a console/Drupal-owned path, a
# non-GET method, dotfiles (the snapshot marker) and PHP-looking paths.
# The Apache vhost in Dockerfile and the DDEV nginx config carry the same rule as
# the in-container fallback; keep the three in step.

# Docker's embedded DNS, re-resolved often: the updater recreates `app` with a
# new address and nginx must follow without a reload.
resolver 127.0.0.11 valid=5s ipv6=off;

map $http_cookie $frz_cookie {
    default 0;
    "~*(^|;\s*)S?SESS[0-9a-f]+=" 1;
}
map $uri $frz_path {
    default 0;
    "~*^/(atelier|user|api|session|admin|system)(/|$)" 1;
    "~(^|/)\." 1;
    "~*\.ph(p[0-9]?|tml|ar)$" 1;
}
map $request_method $frz_method {
    default 1;
    GET 0;
    HEAD 0;
}
# Any 1 = bypass the snapshot: a root that never exists sends try_files to @miss.
map "$frz_cookie$frz_path$frz_method" $frz_root {
    default /srv/private/frozen/current;
    "~1" /nonexistent/frozen-bypass;
}
# Behind an outer TLS terminator, pass its scheme on; otherwise report our own.
map $http_x_forwarded_proto $edge_proto {
    default $http_x_forwarded_proto;
    "" $scheme;
}

proxy_http_version 1.1;
proxy_set_header Host $http_host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $edge_proto;
proxy_set_header Connection "";
# A console turn runs the agent loop inline: match Apache's Timeout / PHP's 600s.
proxy_read_timeout 600s;
proxy_send_timeout 600s;
# Console responses stream; never hold them back.
proxy_buffering off;
# PHP enforces its own upload limits.
client_max_body_size 0;

server {
    listen 80 default_server;
    server_name _;
    absolute_redirect off;

    gzip on;
    gzip_vary on;
    gzip_types text/css text/plain text/xml application/xml application/javascript application/json image/svg+xml;

    location / {
        root $frz_root;
        add_header X-Atelier-Served frozen;
        add_header Cache-Control "public, max-age=300, stale-while-revalidate=86400";
        try_files $uri/index.html $uri @miss;
    }

    # Nothing in the frozen tree: the request was bypassed, we are Live, or the
    # snapshot lacks the path.
    location @miss {
        root $frz_root;
        # Frozen: sealed. The snapshot's own 404 page, status 404, no PHP.
        error_page 404 /404.html;
        if (-f $document_root/404.html) {
            return 404;
        }
        # Live or bypassed: Drupal. A variable target is resolved per request.
        set $app http://app:80;
        proxy_pass $app;
        # `=`: answer with @updating's own 503, not the upstream's 502.
        error_page 502 503 504 = @updating;
    }

    # `app` is restarting (an upgrade converging) or not up yet.
    location @updating {
        default_type text/html;
        add_header Retry-After 5 always;
        add_header Cache-Control "no-store" always;
        return 503 "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"5\"><title>Atelier is starting</title><style>body{font:16px/1.5 system-ui,sans-serif;margin:15vh auto;max-width:32rem;padding:0 1.5rem;color:#222}h1{font-size:1.4rem}</style></head><body><h1>Atelier is starting</h1><p>The site is updating or has just been started. This page retries every few seconds.</p></body></html>";
    }
}
NGINX

# Which channel an image reference means — the same classification the manager
# does (only our own repo's two moving tags are channels; everything else is a pin).
channel_of() {
  case "$1" in
    ghcr.io/aincient-labs/atelier-cms:latest) printf 'stable' ;;
    ghcr.io/aincient-labs/atelier-cms:edge)   printf 'edge' ;;
    *)                                        printf 'pinned' ;;
  esac
}

# Write .env only on first install — never clobber an existing key/salt.
ENV_FILE="$INSTALL_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
  # ADMIN_PASS left blank on purpose: converge mints a random admin password on
  # first boot and records it (app log + private/INITIAL_ADMIN_PASSWORD). Set a
  # value here only if you want to pin your own.
  cat > "$ENV_FILE" <<ENV
HASH_SALT=$(openssl rand -hex 32)
AINCIENT_IMAGE=${AINCIENT_IMAGE}
AINCIENT_CHANNEL=$(channel_of "$AINCIENT_IMAGE")
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
  upsert HTTP_PORT "$HTTP_PORT"

  existing_image="$(sed -n 's/^AINCIENT_IMAGE=//p' "$ENV_FILE" | head -1)"
  existing_channel="$(sed -n 's/^AINCIENT_CHANNEL=//p' "$ENV_FILE" | head -1)"
  if [ -n "$AINCIENT_IMAGE_EXPLICIT" ]; then
    # This run named an image: reconcile to it, and record it as the choice.
    upsert AINCIENT_IMAGE "$AINCIENT_IMAGE"
    upsert AINCIENT_CHANNEL "$(channel_of "$AINCIENT_IMAGE")"
    say "Reusing ${dim}${ENV_FILE}${rst} (image + port reconciled to this run)"
  elif [ -z "$existing_channel" ] && [ "$existing_image" = "ghcr.io/aincient-labs/atelier-cms:edge" ]; then
    # An install from before releases existed: it follows :edge because :edge was
    # the only tag, not because anyone asked for unreleased builds. Move it onto
    # releases once, and record the choice so this never fires again.
    upsert AINCIENT_IMAGE "$AINCIENT_IMAGE"
    upsert AINCIENT_CHANNEL stable
    say "Switching this install to released versions ${dim}(was ${existing_image})${rst}"
    say "To follow unreleased builds again: ${bold}atelier app channel edge${rst}"
  else
    # Anything else on disk is a decision — a pinned version, or a channel the
    # operator picked. Leave it; re-running the installer is an upgrade, not a
    # channel change.
    AINCIENT_IMAGE="${existing_image:-$AINCIENT_IMAGE}"
    say "Reusing ${dim}${ENV_FILE}${rst} (keeping ${AINCIENT_IMAGE}; port reconciled)"
  fi
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
  printf '  Lost pw?  %sread it back — your data lives in the volume either way:%s\n' "$dim" "$rst"
  printf '            %sdocker compose -f %s/compose.yaml exec app cat /opt/drupal/private/INITIAL_ADMIN_PASSWORD%s\n' "$dim" "$INSTALL_DIR" "$rst"
  printf '            %sto set a new one, use the atelier CLI: atelier app password --set <newpass>%s\n' "$dim" "$rst"
else
  warn "Console didn't answer on ${url} within ~5 min."
  warn "Check logs:  docker compose -f ${INSTALL_DIR}/compose.yaml logs -f app"
  exit 1
fi
