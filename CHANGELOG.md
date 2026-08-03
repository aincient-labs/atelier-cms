# Changelog

Notable changes to **Atelier by AIncient Labs**, newest first. Each entry is a
public snapshot published from the development source.

> Maintainers: this file is the public-facing changelog and is layered on via
> `bin/atelier-overlay/`. When you run `bin/deploy-atelier`, add the new deploy's
> line here (it mirrors the ledger subject in `bin/atelier-deploys.tsv`).

## [0.1.0] — 2026-08-03

**Atelier has version numbers from here on.** Entries above this point are dated snapshots; from
now on a release gets a version like `0.1.0`, published as `ghcr.io/aincient-labs/atelier-cms:0.1.0`
alongside `:latest`. `:edge` continues to track the newest development build for anyone who wants
it. While Atelier is on `0.x` we are not yet promising that every upgrade is safe — take a backup
before one.

- **New: you can find out which Atelier you're running.** Open **Reports → Status report** and look
  for **Atelier version**. Atelier updates itself when you pull a newer image, so the day you
  installed says nothing about the code you're on — this row does. Include it when you report a
  problem; it's the difference between a bug we've already fixed and one we haven't heard about.
- **Updates download far less.** All the dependencies sat in a single 216 MB layer, so bumping any
  one of them re-downloaded all of them. They are now four layers ordered by how often each
  changes: an update that only moves the workflow engine is **about 22 MB instead of 216 MB**.
- **Fixed: upgrades survive a module moving inside the image.** Drupal remembers where it found
  each module and that memory outlives an image swap, so an appliance upgrading across such a move
  would have failed on start before its database updates could run. Upgrades now clear that memory
  first — on every upgrade, not just this one.

## 2026-08-03
- **Model rates are now yours to set**, at Configuration → Atelier → Model rates. The rates we
  ship became suggestions: through a proxy the model name is an alias from your own
  configuration, so we cannot know what a call really costs — you can. Rates you had already
  corrected are untouched.
- **The rate sheet reads as a ledger**, organised by model rather than by role, opening one row
  at a time onto the decision. Models that have been billed with no rate sort to the top, since
  those are the ones under-reporting right now.
- **A rate you set no longer wins in silence** — it records who set it and when, ages like ours
  do, and when our suggestion later disagrees the row says so and offers the new figure in one
  click.
- **New: OpenAI models are priced.** Every OpenAI call was reported as unpriced from the moment
  the provider became connectable again, so the usage dashboard showed nothing rather than a
  cost. Seven models now carry published rates.
- **Fixed: module tables on the modules page overflowed their own card** by 20px.

## 2026-08-02
- **New: Ollama, OpenAI and Mistral are connectable again**, each with a single field in setup
  — a key, or for Ollama the address of your own server. Existing model choices keep working;
  nothing needs to be re-picked.
- **New: connect any OpenAI-compatible endpoint** — DeepSeek, Groq, OpenRouter, LiteLLM, vLLM,
  LM Studio and anything else speaking that shape — by giving a base URL and a key.
- **The setup picker only offers providers Atelier can actually use.** It previously listed
  providers that would fail the moment you tried to use them.
- **New: an AI usage dashboard and a call log**, showing what each part of Atelier is spending
  and where the calls came from.
- **New: an editable rate sheet** for model prices, which tells you when a price has gone stale
  rather than quietly reporting an old number. Costs were previously under-reported.
- **Fixed: starting a new page from chat could show a "not found" screen** instead of the page
  you were creating.
- **Clearer field labels in the Globals editor**, and a pager that keeps its shape at both ends
  of a long list.
- **Maintenance:** the appliance self-test was still checking for AI modules Atelier no longer
  uses, which failed the build for the release above. Installing from the bundled recipe no
  longer enables one of them either, so both install paths now produce the same site.
- **Fixed: setting up an OpenAI-compatible endpoint could pick a model that does not exist.**
  The model list included entries that are not chat models — a proxy's wildcard model groups
  (such as `openai/*`), and speech, audio, image, video and moderation models — and one of them
  could end up chosen for every role, leaving the assistant unable to answer. Setup's three
  tiers (best value, balanced, best quality) also now pick different models on such an endpoint,
  instead of all three landing on the same one.

## 2026-08-01
- **New: link a button to a page by picking it.** A call-to-action can now point at a page you
  choose from your own site, instead of a URL you had to go and look up first — on hero buttons
  and inside cards, pricing tiers and logo rows. A picked page keeps working if its address
  later changes, and a button whose page is deleted or unpublished disappears rather than
  leading nowhere.
- **The assistant links to real pages.** Ask it to "link this to the pricing page" and it finds
  the page itself rather than guessing an address — and tells you when no such page exists.
- **Fixed: page edits made through chat could report success and never appear in your preview.**
  Introduced in the release published earlier today; if you are on that release, update. The
  edits were being made correctly the whole time — the result just never reached the screen.
- **Fixed: a chat turn could end with an empty reply**, or with the single word "success",
  instead of telling you what it had done. When something goes wrong you now see the reason.
- **A malformed step no longer throws away the rest of a turn's work.** Whatever the assistant
  completed is applied to your preview, with a note that it hit a problem along the way.

## 2026-08-01
- **Fixed: chat in every studio works again.** On the 2026-07-31 release, asking a studio
  assistant to change something returned an empty reply and saved nothing — no message, no
  error, no explanation. Design System, Globals, Content, Library and Checks were all
  affected, and the whole chat-driven way of working was unusable. If you are running that
  release, update. (The cause was a regression in the workflow engine: a workflow input
  declared as unconstrained stopped accepting anything but a plain scalar, and the studios
  send their unsaved draft as one.)
- **New: the logo has a size.** Set it to small, medium or large for the header and the
  footer, from the Globals tabs or by asking the assistant. Existing sites are unchanged —
  the previous fixed size is the default.
- **Fixed: a small logo no longer renders full-width** in the studio preview. The published
  site was always correct; only the preview was wrong.
- **The bundled demo pages no longer fetch stock photography from the internet.** They now
  use images that ship with Atelier, so a fresh install looks finished offline and makes no
  third-party requests.

## 2026-07-31
- **Maintenance:** dependency update and internal config cleanup. No change to how Atelier
  behaves.

## 2026-07-30
- **Fixed: a provider hiccup no longer breaks a conversation for good.** If the AI provider
  faltered part-way through a task — after it had used its tools, before it replied — that
  conversation was left mid-sentence, and every message you sent afterwards failed. Starting a
  new conversation worked fine, which made it look like the AI itself was down. Conversations
  now survive an interrupted step, and a conversation already stuck this way repairs itself on
  your next message: nothing you wrote is lost, and Atelier tells the assistant the step was
  interrupted rather than letting it assume the work finished.
- **Failed AI requests explain themselves.** "Server error (HTTP 503) occurred." was the whole
  message; the reason the provider gave was being thrown away. Errors now carry what the
  provider actually said.
- Updated the workflow engine to its latest build.

## 2026-07-27
- **New: tell Atelier which models it may use.** `aincient_core.model_preferences` takes two
  lists: `avoid` rules a model — or a whole vendor — out of every job, and `prefer` puts your
  own pick ahead of our suggestions for one job. Our published recommendations are written
  for everyone and can't know that your site isn't licensed for a vendor, or that your AI
  proxy lists models it has no working credential for; this is where you say so.
- Exclusions hold. A ruled-out model can't come back through a fallback, and a job left with
  nothing is **left unset** rather than filled with something you refused. Excluding a vendor
  also excludes their models reached through a proxy — ruling out Anthropic also rules out
  Anthropic served via LiteLLM or OpenRouter. Preferences are the softer half: one that can't
  be met falls back to our suggestions instead of stranding the job.
- Setup and the model settings now say when your site is narrowing the choices, so a preset
  that differs from our published suggestions is never left unexplained. Both lists are empty
  on a fresh install and on upgrade — a site that declares nothing behaves exactly as before.

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
