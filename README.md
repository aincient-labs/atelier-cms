<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".github/readme/hero-dark.jpg" />
  <img src=".github/readme/hero-light.jpg" alt="Atelier CMS. A website worthy of your work. Free and open source, built on Drupal, runs on your computer." width="1000" />
</picture>

# Atelier CMS

**The open-source, self-hosted AI website builder.** Tell it about your business and it builds
the pages; change any word by hand. Built on Drupal, runs on your computer, and exports a static
site you can host anywhere. No account, no paid tier: the free version is the whole product.

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-2B2B2B.svg)](LICENSE)
[![Container image](https://img.shields.io/badge/image-ghcr.io%2Faincient--labs%2Fatelier--cms-2B2B2B?logo=docker&logoColor=white)](https://github.com/aincient-labs/atelier-cms/pkgs/container/atelier-cms)
[![Self-hosted](https://img.shields.io/badge/self--hosted-no%20account%20required-2B2B2B.svg)](#get-started)
[![Built on Drupal](https://img.shields.io/badge/built%20on-Drupal-2B2B2B.svg)](https://www.drupal.org)

[**Website**](https://aincient-labs.com) · [**Docs**](https://aincient-labs.com/docs) · [**Watch the film**](https://aincient-labs.com/#made) · [**Download**](https://github.com/aincient-labs/manager/releases/latest)

</div>

## Get started

Download **Atelier Manager** for macOS, Windows or Linux from the
[latest release](https://github.com/aincient-labs/manager/releases/latest). It installs Atelier
and starts it for you. Or, from a terminal:

```bash
brew install aincient-labs/tap/atelier
atelier app install && atelier app open
```

You need [Docker](https://docs.docker.com/get-docker/) and an AI key (Anthropic, OpenAI, Gemini,
Mistral, or a local [Ollama](https://ollama.com)). The one-line installer, Docker Compose and
server installs are covered in the **[Quickstart](https://aincient-labs.com/docs/quickstart)**.

## What you can do

- **Build pages by asking.** Describe a page and the assistant composes it in a live preview
  → [Page studio](https://aincient-labs.com/docs/page-studio)
- **Set your brand once.** Colours, type and voice carry through every page
  → [Brand studio](https://aincient-labs.com/docs/brand-studio)
- **Publish in several languages**, one language at a time
  → [Multilingual pages](https://aincient-labs.com/docs/translation)
- **Check before you publish.** Broken links, SEO and share-card issues show up, and the assistant can fix them
  → [Checks](https://aincient-labs.com/docs/checks)
- **Host it anywhere** as plain HTML, with no server to run
  → [Static export](https://aincient-labs.com/docs/static-export)

<p>
  <a href="https://app.netlify.com/start/deploy?repository=https://github.com/aincient-labs/atelier-deploy-template"><img src="https://www.netlify.com/img/deploy/button.svg" alt="Deploy to Netlify" height="32" /></a>
  &nbsp;
  <a href="https://deploy.workers.cloudflare.com/?url=https://github.com/aincient-labs/atelier-deploy-template"><img src="https://deploy.workers.cloudflare.com/button" alt="Deploy to Cloudflare" height="32" /></a>
  &nbsp;
  <a href="https://vercel.com/new/clone?repository-url=https://github.com/aincient-labs/atelier-deploy-template"><img src="https://vercel.com/button" alt="Deploy with Vercel" height="32" /></a>
</p>

## Why Atelier

- **One product that looks after itself.** Atelier updates in place, takes a snapshot first, and
  rolls back if anything fails.
- **Your site, your keys.** Everything runs on your machine. You pay your AI provider directly,
  and the assistant can only do what your account can.
- **Drupal underneath.** A proven platform, with only the Atelier experience shown by default.
  If you know Drupal, the full platform is there when you want it.

## For developers

Atelier ships as a signed container image,
[`ghcr.io/aincient-labs/atelier-cms`](https://github.com/aincient-labs/atelier-cms/pkgs/container/atelier-cms)
(verify with [`cosign.pub`](cosign.pub); SBOMs on every release →
[docs](https://aincient-labs.com/docs/sbom)). Build it yourself from
[`docker/`](docker/README.md). Product code lives in `web/modules/custom/`. Changes to shipped
config are overwritten on upgrade by design, and support covers the defaults.

Each release lands here as one snapshot commit, with its changelog in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Try it, then [report what breaks or what's missing](https://github.com/aincient-labs/atelier-cms/issues).
You don't need to write code to help. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

**GPL-2.0-or-later** ([LICENSE](LICENSE), grant in [COPYRIGHT.txt](COPYRIGHT.txt)). The console's
React app in [`chat-ui/`](web/modules/custom/aincient_chat/chat-ui/) is
[MIT](web/modules/custom/aincient_chat/chat-ui/LICENSE). Credits:
[ACKNOWLEDGEMENTS.md](ACKNOWLEDGEMENTS.md). Drupal is a registered trademark of
[Dries Buytaert](https://dri.es/).

<div align="center">

**[aincient-labs.com](https://aincient-labs.com)** · [Documentation](https://aincient-labs.com/docs) · [Issues](https://github.com/aincient-labs/atelier-cms/issues)

</div>
