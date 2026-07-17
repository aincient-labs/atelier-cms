<div align="center">

<img src="logo.png" alt="Atelier" width="120" />

# Atelier

### Run your website by *talking* to it.

**The AI-native CMS, by [AIncient Labs](https://aincient-labs.com).**
Create, find, update, and publish content through an AI operator console —
no admin forms, no jargon, no SaaS login.

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)
[![Container image](https://img.shields.io/badge/image-ghcr.io%2Faincient--labs%2Fatelier-000?logo=docker&logoColor=white)](https://github.com/aincient-labs/atelier/pkgs/container/atelier)
[![Self-hosted](https://img.shields.io/badge/self--hosted-no%20account%20required-c0392b.svg)](#running-atelier)
[![Built on Drupal](https://img.shields.io/badge/built%20on-Drupal-0678BE.svg)](https://www.drupal.org)

[**Quick start**](#quick-start) · [**See it work**](#see-it-work-in-30-seconds) · [**Why Atelier**](#why-we-build-this) · [**Whats inside**](#whats-inside) · [**License**](#license)

</div>

---

## Quick start

One command lays down a Docker Compose stack and starts it:

```bash
curl -fsSL https://aincient-labs.com/install.sh | bash
```

First boot installs and configures the site by itself. When it settles, open
**http://localhost:41221/**, log in, and you land in the operator console at **`/atelier`**.

That's the whole setup. No account to create, no key to paste in a config file, nothing held
back for a paid tier — you're now running the real product.

> **Prefer to drive it yourself?** See [Running Atelier](#running-atelier) for the manual
> Docker Compose path and how upgrades work.

## See it work in 30 seconds

1. **Log in.** Atelier drops you straight into the operator console at **`/atelier`**.
2. **Connect an AI provider** in the first-run wizard — an Anthropic or OpenAI key, or a
   local [Ollama](https://ollama.com) server. Keys live in the site's state storage, never in
   code or config.
3. **Just say what you want:**

   > *"Write a short article about the Rosetta Stone and save it as a draft."*

   Watch the assistant pick the right tool, create the content, and report back — in one turn.

From there it's a conversation:

> *"Find the article about pyramids and unpublish it."*
> *"Rename that to … and publish it."*
> *"Delete it."*

Full content management, by chat. Everything happens under **your** account and permissions —
the assistant can't do anything you couldn't do yourself.

## Why we build this

**Working with a serious website should be as simple as saying what you want.** You shouldn't
need to know what a content type or a view is to run one — especially if you've never touched
Drupal. Our aim, as far as a free and open-source project can carry it, is to make Drupal plus
everything happening in AI genuinely approachable.

- 🎯 **A product, not a kit of parts.** Atelier ships as a versioned container appliance that
  installs itself, updates itself, and rolls itself back if an upgrade fails.
- 🔓 **Everything here is the real thing.** Open source, self-hosted, no account, nothing
  reserved for a paid tier. In a world where everything cool hides behind a login, we'd like
  this to feel different.
- 🏛️ **Drupal underneath, curated on top.** Built on [Drupal](https://www.drupal.org) and
  deliberately restricted to the Atelier experience by default. If you know Drupal, the full
  platform is yours to unlock. If you don't, you'll never need to.

> Need an enterprise arrangement — support, guarantees, commercial licensing? We're happy to
> talk. That funds the free product; it never shrinks it.

## Running Atelier

Atelier is distributed as a **versioned public container image** — the image *is* the product
and the unit of versioning, the way Discourse, GitLab Omnibus, and Ghost are delivered. It's
published to GHCR at
[`ghcr.io/aincient-labs/atelier`](https://github.com/aincient-labs/atelier/pkgs/container/atelier)
and is **public — no login or token required.**

**The fastest way in** is the one-line installer:

```bash
curl -fsSL https://aincient-labs.com/install.sh | bash
```

**Prefer to run it yourself?** Everything you need is in [`docker/dist/`](docker/dist/):

```bash
cd docker/dist
cp .env.example .env    # set HASH_SALT — openssl rand -hex 32
docker compose up -d
```

Either way, first boot installs and configures the site on its own. Open
**http://localhost:41221/** and log in.

### Upgrading

Same motion as install — re-run the installer, or from the repo:

```bash
docker compose pull && docker compose up -d
```

On every start the appliance **converges**: it snapshots the database, applies any pending
updates and configuration, health-checks itself, and rolls back automatically if anything
fails. Your content, files, and connected provider keys live in volumes and survive every
upgrade.

See [`docker/dist/README.md`](docker/dist/README.md) for the full walkthrough (admin login,
ports, reset) and [`docker/README.md`](docker/README.md) for how convergence works. You can
also build the image yourself from [`docker/Dockerfile`](docker/Dockerfile).

## Requirements

- **[Docker](https://docs.docker.com/get-docker/)** with the Compose plugin — that's it for
  the supported path.
- An **API key** for an AI provider (Anthropic, OpenAI, …) or a local **Ollama** server,
  connected through the in-app onboarding wizard.

The appliance bundles **PostgreSQL 16** (with pgvector) — the only supported database.

## What's inside

Atelier is a deliberately curated Drupal build — an opinionated selection of core and
contributed modules with locked-down defaults, not a stock Drupal site. The product code
lives under `web/modules/custom/`:

| Module | Role |
| --- | --- |
| `aincient_core` | Foundation: shared permissions, the model-role layer, base services. |
| `aincient_chat` | The streaming chat layer: SSE endpoint, agent loop, thread/turn persistence, the typed event protocol. |
| `aincient_assistant_ui` | The operator console (React + [assistant-ui](https://www.assistant-ui.com/)) and its studios. |
| `aincient_pages` | Typed page schemas rendered through single-directory components. |
| `aincient_flows` | [FlowDrop](https://www.drupal.org/project/flowdrop) integration — site capabilities exposed as agent tool nodes. |

…plus supporting modules (first-run onboarding, brand system, checks/auditing, demo content)
and the front-end themes under `web/themes/custom/`. *(`aincient` is the product's internal
namespace — AIncient Labs is the company behind Atelier.)*

## Supported use — and going off the map

Atelier restricts a lot by default. **That's the product, not a limitation:** an opinionated
selection of modules, locked-down defaults, one supported database (PostgreSQL), one supported
way to run it (the appliance). Install it, let it update itself, and operate the site through
the console.

Know Drupal and want more? "Jailbreaking" Atelier to reach the full platform underneath is
fairly easy — and encouraged. The complete source is right here under the GPL, and everything
Drupal can do is a module or a settings change away. Two caveats: Drupal can do so much that
we can't support what you build past the restrictions, and the appliance re-asserts each
release's configuration on upgrade, so local forks of shipped config will be overwritten by
design.

## Contributing

**Atelier gets better through the people who run it** — and every install and every report
brings us closer to our north star of a million running sites. You don't need to write code to
help:

- 🐛 **Try it and break it.** Bug reports in the [issue queue](https://github.com/aincient-labs/atelier/issues)
  are real participation.
- 💡 **Tell us what's missing.** Feature requests and shared experiences shape what ships next.
- 🙋 **Ask for help.** Questions are welcome — if something confused you, it'll confuse the next
  person too.

## About this repository

This is the public source home of Atelier. Each release lands as a single snapshot commit
produced by our deployment pipeline, with a plain-language changelog as its message — so the
history here tracks *shipped states* rather than day-to-day development.

## License

- The codebase is **GPL-2.0-or-later** — see [LICENSE](LICENSE). Atelier is free software:
  built on Drupal and distributed under the same license.
- The operator console's React app,
  [`web/modules/custom/aincient_chat/chat-ui/`](web/modules/custom/aincient_chat/chat-ui/), is
  licensed separately under the permissive **MIT license** — it's browser-side code, not a
  Drupal derivative; see [its LICENSE](web/modules/custom/aincient_chat/chat-ui/LICENSE).
- Custom Atelier modules and themes are © AIncient Labs.

Third-party credits: [ACKNOWLEDGEMENTS.md](ACKNOWLEDGEMENTS.md).

---

<div align="center">

Drupal is a registered trademark of [Dries Buytaert](https://dri.es/).

**[aincient-labs.com](https://aincient-labs.com)** · [Documentation](https://aincient-labs.com/docs) · [Issues](https://github.com/aincient-labs/atelier/issues)

</div>
