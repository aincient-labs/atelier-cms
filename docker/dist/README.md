# Try Atelier

Thanks for testing Atelier — an AI-first, chat-driven site builder built on Drupal.
This folder is everything you need to run it locally: a Docker Compose file and
an environment template. The app ships as a single container image; Compose just
runs it alongside a database.

## 1. Prerequisites

- **Docker** with the **Compose** plugin (Docker Desktop on Mac/Windows, or
  Docker Engine + `docker compose` on Linux). Check with `docker compose version`.
- A **registry login** for the private image. The maintainer will give you a
  username and a read-only token. Log in once:

  ```bash
  echo "<TOKEN-FROM-MAINTAINER>" | docker login ghcr.io -u <USERNAME-FROM-MAINTAINER> --password-stdin
  ```

- A credential for **an AI provider** for the AI features — an API key from Anthropic
  (https://console.anthropic.com/) or OpenAI (https://platform.openai.com/api-keys), or a
  local Ollama server URL. You can also leave it blank and connect a provider later in the
  in-app onboarding wizard.

## 2. Configure

From this folder:

```bash
cp .env.example .env
```

Open `.env` and set the one required value:

- `HASH_SALT` — run `openssl rand -hex 32` and paste the result.

Everything else has sensible defaults. Connect an AI provider from the console's
first-run onboarding wizard after install — there's no key to set here.

## 3. Run

```bash
docker compose up -d
```

First boot takes a minute or two — the container installs and configures itself
(watch progress with `docker compose logs -f app`). When it's done:

- Open **http://localhost:41221/**
- Log in, then go to **/atelier** for the operator console.

**Admin login:** the username is `admin`. If you left `ADMIN_PASS` blank, a
random password is generated on first boot and saved to the **persistent**
`private` volume — so you can recover it at any time, even after an upgrade.
Run any of these from this folder:

```bash
# Show the password generated on first boot:
docker compose exec app cat /opt/drupal/private/INITIAL_ADMIN_PASSWORD

# …or just set a new one:
docker compose exec app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web user:password admin 'YourNewPassword'

# …or get a one-time login link (no password needed):
docker compose exec app /opt/drupal/vendor/bin/drush --root=/opt/drupal/web uli --uri=http://localhost:41221
```

(It's also echoed in the first-boot log: `docker compose logs app | grep "admin password"`.)

## 4. Upgrade to a newer build

```bash
docker compose pull && docker compose up -d
```

That's the whole upgrade. The app converges itself on start — it snapshots the
database, runs any pending updates, health-checks, and **automatically rolls
back** if something fails. Your data (database + uploaded files) lives in Docker
volumes and survives upgrades.

## 5. Stop / start / reset

```bash
docker compose stop          # stop, keep data
docker compose up -d         # start again
docker compose down          # remove containers, KEEP data (volumes remain)
docker compose down -v       # remove containers AND wipe all data (fresh start)
```

## Troubleshooting

- **`docker compose up` can't pull the image** (`denied` / `unauthorized`, or
  `manifest unknown`). You're not logged in to the registry — re-run the
  `docker login ghcr.io …` step in section 1 with the token from the maintainer.
- **The console doesn't load at http://localhost:41221/.** First boot can take a
  couple of minutes. Watch it converge: `docker compose logs -f app`. If it
  finished but the page errors, share that log with the maintainer.
- **Port 41221 is already in use.** Set a different `HTTP_PORT` in `.env`, then
  `docker compose up -d`, and open the new port.
- **Forgot / never saw the admin password.** See "Admin login" in section 3 —
  it's recoverable from the persistent volume; you're never locked out.
- **Start completely fresh.** `docker compose down -v` wipes all data (database,
  files, and the saved admin password), so the next `up` installs from scratch.

## Notes

- **Local evaluation only by default.** The container answers any Host header
  and is meant for `localhost`. Don't put it on a public address without setting
  `AINCIENT_TRUSTED_HOSTS` (see `.env.example`).
- **No data is sent anywhere** except to your chosen AI provider for the AI
  features, using the credential you provide (nothing leaves the machine if you
  run a local model server like Ollama).
- Found a bug or have feedback? Send it back to the maintainer — please include
  the output of `docker compose logs app` if something went wrong.
