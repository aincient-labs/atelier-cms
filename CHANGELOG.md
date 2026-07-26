# Changelog

Notable changes to **Atelier by AIncient Labs**, newest first. Each entry is a
public snapshot published from the development source.

> Maintainers: this file is the public-facing changelog and is layered on via
> `bin/atelier-overlay/`. When you run `bin/deploy-atelier`, add the new deploy's
> line here (it mirrors the ledger subject in `bin/atelier-deploys.tsv`).

## 2026-07-26
- **First-run setup is two steps.** The screen that only asked your name is gone — its
  welcome now sits on *Connect your AI*, where the work starts. Setup finishes on the
  studio's own welcome, with three ideas you can build in one click, instead of putting
  someone else's sentence in your composer for you to clear.
- **Setup stops talking about models unless you want it to.** Answer what matters most —
  *Best value*, *Balanced*, *Best quality* — and that's the whole step. The model chosen for
  each job is one click away under *Choose per role*, for anyone who wants to look.
- **Atelier asks what to call you after your first page exists**, not before you've made
  anything. Asked once, above the composer; skip it and it never comes back.
- **Connecting a provider now tells you what it reached** — "Connected · 14 models ready" —
  so you know the key works, not just that it was saved.
- **Your model tier is remembered.** It used to be a one-shot: setup computed your models
  and forgot which answer produced them, so every configured site reported *Custom*, and
  refreshing our curated picks couldn't act on a standing "keep me on Balanced". Setup now
  reopens on the tier you're actually on, and *Check for updates* keeps it current instead
  of leaving your models frozen on the day you chose them. Hand-pick any single model and
  the site becomes *Custom*, where nothing is ever moved for you again.
- Fix: **models served through a proxy failed with an authentication error.** With an
  OpenAI-compatible proxy (LiteLLM, OpenRouter) connected, a model bound to one of Atelier's
  jobs could be sent to a different provider than the one it was bound to — which then had
  no key for it. In practice only a model named `gpt-4` worked reliably. A bound model now
  always goes to the provider it was bound to.
- Fix: **"Read the docs" on the demo homepage led to a page that doesn't exist.** It now
  opens the Atelier documentation.
- Fix: **choosing your models now works when they come through an AI proxy.** If the only
  thing you've connected is an OpenAI-compatible proxy (LiteLLM, OpenRouter), *Best value*,
  *Balanced* and *Best quality* all resolved to the same arbitrary model: our curated picks
  are written per vendor, and a proxy serves those same models under names like
  `anthropic/claude-sonnet-5`. The three answers now genuinely differ. A provider you
  connected directly always wins over the same model reached through a proxy.
- Fix: a model served through a proxy keeps its badge — **Recommended**, or the warning on a
  model its vendor has retired — instead of every one reading "untested". They sort by it
  again too, so the models we back are back at the top of the list.
- Fix: a provider we don't ship a logo for no longer borrows Atelier's own mark in the setup
  screens. An unfamiliar provider now shows no logo rather than one that isn't theirs.
- Maintenance: the bundled workflow engine now tracks a tagged release (FlowDrop
  `2.0.0-alpha11`) instead of a pinned development snapshot. Same code, no behaviour
  change.
- Changed: **building a page now uses your "High thinking" model.** Composing a page is
  the most demanding thing the assistant does — it plans a whole layout, then drives its
  own tools to build it — but it had been running on the everyday tier. Expect stronger
  layouts at a higher cost per page; rebind the role if you'd rather have the cheaper
  tier.
- New: **choosing your models is one question, not five.** Setup now leads with
  **Best value / Balanced / Best quality** and fills every role from your pick; the
  five per-role pickers are one click away under "Choose per role", and a re-run on a
  configured site keeps the choices you already made.
- New: the curated model recommendations can be **refreshed on request** ("Check for
  updates"), so guidance keeps pace with models being retired and repriced between
  releases. Nothing is fetched unless you click it, nothing about your site is sent,
  and a failure changes nothing — the recommendations you already have keep working.
- Fix: **pages built in chat came out doubled** — two heroes, two feature bands, two
  calls to action from a single request. The page-building loop could re-run a step it
  had already completed and rebuild the page from scratch; a step now runs at most
  once per request.
- Fix: **editing a page raised a stray "Approval required" card** for an action that
  had already run. Approving or declining it changed nothing; the prompt is gone.

## 2026-07-25
- Fix: the assistant now works when your AI provider is a **LiteLLM proxy in front of
  Claude**. Every chat turn failed with a provider error, because a tool that takes no
  arguments was sent with an empty schema the proxy rejected — which failed the whole
  request, not just that one tool. Other providers were unaffected.
- Changed: the Drupal Forge hosted demo is now built and maintained as its own image, so
  the `.devpanel/` build config no longer ships here. Trying Atelier on Drupal Forge is
  unaffected.

## 2026-07-24
- New: run Atelier as a hosted demo on Drupal Forge. A `.devpanel/` build config installs
  Atelier there and wires an AI trial key in automatically, so you can try the full
  chat-driven experience without setting up your own key.
- New: LiteLLM is now an available AI provider — point Atelier at any LiteLLM-compatible
  endpoint (optional, disabled by default).

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
