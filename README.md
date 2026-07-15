# Atelier

**The AI-native CMS, by AIncient Labs.** Log in and run your site by *talking* to it —
create, find, update, and delete content through an AI operator console instead of hunting
through admin forms.

Atelier is a product, not a kit of parts: it ships as a versioned container appliance that
installs itself, updates itself, and rolls itself back if an upgrade fails. Under the hood
it is built on [Drupal](https://www.drupal.org) — deliberately curated and restricted to
the Atelier experience by default. If you know Drupal, the full platform is underneath and
yours to unlock; if you don't, you'll never need to.

## Why we build this

Our aim — as far as a free, open-source project can carry it — is to make working with
Drupal, and leveraging what is happening in AI, as simple as it can possibly be.
Especially if you have never used Drupal: you shouldn't need to know what a content type
or a view is to run a serious website. You should be able to just say what you want.

Everything here is the real product: open source, self-hosted, no account, nothing held
back for a paid tier. In a world where everything cool hides behind a SaaS login, we'd
like this to feel different. (And if your organization needs an enterprise arrangement —
support, guarantees, commercial licensing — we're happy to talk. That funds the free
product; it never shrinks it.)

Atelier gets better through the people who run it. Try it, break it, and tell us what
happened — bug reports, feature requests, questions, and shared experiences in the issue
queue are all participation, and so is asking for help.

---

## Running Atelier

Atelier is distributed as a **versioned container image** — the image is the product and
the unit of versioning, the way Discourse, GitLab Omnibus, or Ghost are delivered. You run
it with Docker Compose; everything you need is in [`docker/dist/`](docker/dist/):

```bash
cd docker/dist
cp .env.example .env    # set HASH_SALT — openssl rand -hex 32
docker compose up -d
```

First boot installs and configures the site by itself. Open **http://localhost:41221/**,
log in, and you land in the operator console at **`/atelier`**.

Upgrading is the same motion:

```bash
docker compose pull && docker compose up -d
```

On every start the appliance **converges**: it snapshots the database, applies any pending
updates and configuration, health-checks itself, and rolls back automatically if anything
fails. Your content, files, and connected provider keys live in volumes and survive every
upgrade. See [`docker/dist/README.md`](docker/dist/README.md) for the full walkthrough
(admin login, ports, reset) and [`docker/README.md`](docker/README.md) for how convergence
works.

> **Early access:** prebuilt images are currently published to a private registry — ask us
> for evaluation credentials. You can also build the image yourself from this repository
> ([`docker/Dockerfile`](docker/Dockerfile)).

## The 30-second activation

1. Log in. Atelier sends you straight to the operator console at **`/atelier`**.
2. Connect an AI provider in the first-run onboarding wizard — an Anthropic or OpenAI key,
   or a local Ollama server. Keys are stored in the site's state storage, never in code or
   configuration.
3. Type what you want: *"Write a short article about the Rosetta Stone and save it as a
   draft."* Watch the assistant call the right tool, create the content, and report back —
   in one turn.

From there: *"find the article about pyramids and unpublish it"*, *"rename that to … and
publish it"*, *"delete it"*. Full content management, by conversation. Content is created
under **your** account and permissions — the assistant can't do anything you couldn't do
yourself.

## Requirements

- **Docker** with the Compose plugin — that's it for the supported path.
- An API key for an AI provider (Anthropic, OpenAI, …) or a local Ollama server, connected
  through the in-app onboarding wizard.

The appliance bundles **PostgreSQL 16** (with pgvector) — the only supported database.

## Supported use

Atelier restricts a lot by default — that is the product, not a limitation. It is a
specifically and thoughtfully crafted solution: an opinionated selection of modules,
locked-down defaults, one supported database (PostgreSQL), one supported way to run it (the
appliance). Install it, let it update itself, and operate the site through the console.

If you know Drupal, "jailbreaking" Atelier to reach the full platform underneath is fairly
easy — and encouraged. The complete source is right here under the GPL, and everything
Drupal can do is a module or a settings change away. Do it at your own risk, though: Drupal
can do so much that we can't support what you build past the restrictions, and the
appliance re-asserts each release's configuration on upgrade, so local forks of shipped
config will be overwritten by design.

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

…plus supporting modules (first-run onboarding, brand system, checks/auditing, demo
content) and the front-end themes under `web/themes/custom/`. (`aincient` is the product's
internal namespace — AIncient Labs is the company behind Atelier.)

## About this repository

This is the public source home of Atelier. Each release lands as a single snapshot commit
produced by our deployment pipeline, with a plain-language changelog as its message, so the
history here tracks shipped states rather than day-to-day development. Bug reports and
feature requests are welcome in the issue queue.

## License

- The codebase is **GPL-2.0-or-later** — see [LICENSE](LICENSE). Atelier is free software:
  it is built on Drupal and distributed under the same license.
- The operator console's React app,
  [`web/modules/custom/aincient_chat/chat-ui/`](web/modules/custom/aincient_chat/chat-ui/),
  is licensed separately under the permissive **MIT license** — it is browser-side code, not
  a Drupal derivative; see [its LICENSE](web/modules/custom/aincient_chat/chat-ui/LICENSE).
- Custom Atelier modules and themes are © AIncient Labs.

Third-party credits: [ACKNOWLEDGEMENTS.md](ACKNOWLEDGEMENTS.md).

Drupal is a registered trademark of [Dries Buytaert](https://dri.es/).
