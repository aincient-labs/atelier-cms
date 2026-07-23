# Changelog

Notable changes to **Atelier by AIncient Labs**, newest first. Each entry is a
public snapshot published from the development source.

> Maintainers: this file is the public-facing changelog and is layered on via
> `bin/atelier-overlay/`. When you run `bin/deploy-atelier`, add the new deploy's
> line here (it mirrors the ledger subject in `bin/atelier-deploys.tsv`).

## 2026-07-24
- New: after you publish a page, the celebration now shows a single, quiet invitation to
  star the project on GitHub — a plain link, shown once and never again after you act on
  it. No tracking.

## 2026-07-22
- Fix: relative colour requests like "make the primary lighter" now adjust your actual
  brand colour, instead of occasionally drifting to an unrelated hue.
- Fix: discarding a brand draft now fully clears the conversation, so a discarded idea
  can't resurface in a later request.

## 2026-07-21
- Fix: discarded brand changes no longer affect later requests. When you discard a
  proposed change — or leave and return to the Design System studio — the assistant
  starts a fresh chat, so a follow-up like "make the primary lighter" adjusts your
  saved brand instead of an abandoned idea.

## 2026-07-21
- Fix: relative brand tweaks like "make the primary colour darker" now build on your
  current look — including edits you just made by hand in the studio — instead of an
  older remembered value.
- Fix: connecting a local Ollama during setup now works. The first-run wizard and the
  in-chat Connect AI panel point at the correct address for reaching Ollama on your
  machine.

## 2026-07-21
- Update to Drupal 11.4.
- Slimmer appliance image: updates now build reproducible, content-addressed layers and
  no longer bundle non-runtime build sources, so each update downloads far less data.
- Security: refresh Composer and the chat build toolchain to clear known advisories.
- Fix: changing your site's homepage now takes effect immediately. Switching the front
  page in the Globals studio used to need a manual cache clear before visitors saw it.

## 2026-07-20
- Add a security policy (SECURITY.md) — report vulnerabilities privately through GitHub's
  security advisories, with clear response times and a coordinated-disclosure process.
- Add a contribution guide and issue templates for bug reports and feature requests.
- One-click "Deploy to Netlify / Cloudflare / Vercel" buttons in the README, for hosting
  your exported site.

## 2026-07-19
- Add a product demo to the README — watch a site get built from one sentence, then
  exported to static HTML and deployed, as a short video and looping preview.
- Fix: the warm off-white default theme now applies on appliance installs too. New
  appliances boot with it, and existing appliances pick it up on update — without
  overwriting any brand changes you've made.
- New default look: fresh installs open on a warm off-white background with clean
  white cards and softened ink — a calmer, paper-like default. Existing sites keep
  their own brand.
- Maintenance: internal test fixes for the new default theme (no visible change).
- Fix the one-line installer command shown in the README and `install.sh` — it now
  points at the working `https://aincient-labs.com/atelier/install.sh`.

## 2026-07-18
- Sign published images with cosign.
- Appliance image moved to `ghcr.io/aincient-labs/atelier-cms`.
- Composer package renamed to `drupal/atelier`.
- Language switcher for multilingual sites.
- Mistral support and reliable page-building across every AI provider.

## 2026-07-17
- Homebrew install, cleaner page URLs, and a refreshed README.
- Appliance images now ship as native multi-architecture builds.
- Smoother AI setup: connect providers and choose models independently.

## 2026-07-16
- Appliance installs under a consistent "Atelier" identity.
- Fix: in-place upgrades keep your site's front page and settings.
- Fix: long chat messages no longer overflow their bubble.
- Console and sign-in screen bug fixes.
- Appliance now reports unhealthy when the site has no working front door.
- Install with one command — no login required.
- Build the appliance image without a private access token.

## 2026-07-15
- Rebrand to Atelier, and new blog, export & email features.

## 2026-07-12
- Atelier by AIncient Labs — first public snapshot.
