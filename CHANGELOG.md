# Changelog

Notable changes to **Atelier by AIncient Labs**, newest first. Each entry is a
public snapshot published from the development source.

> Maintainers: this file is the public-facing changelog and is layered on via
> `bin/atelier-overlay/`. When you run `bin/deploy-atelier`, add the new deploy's
> line here (it mirrors the ledger subject in `bin/atelier-deploys.tsv`).

## [0.8.0] — 2026-08-09

- **Workflows can now ask for your approval before acting.** With the updated workflow
  engine (FlowDrop 2.2.0), a step that has side effects — saving content, sending a
  message, calling an external service — can pause and wait for you to approve or decline
  that exact action, whether it runs from a wired-up workflow or is invoked by the AI
  assistant mid-conversation. Approving allows one execution of exactly what you saw;
  declining tells the assistant "no" and the conversation carries on.
- **You decide what needs approval.** Each workflow building block carries a governance
  setting — always ask, never ask, or follow whether the step has side effects — plus
  controls over whether workflow authors may waive or demand the gate, all behind a
  dedicated permission. New side-effecting blocks default to asking; the steps the
  built-in assistants rely on keep running unattended, so nothing you use today pauses.
- Safety details: unanswered approval questions expire instead of holding a run open
  forever, secrets never appear in approval prompts, and one approval can never authorize
  two executions.

## [0.7.0] — 2026-08-08

- **Show a collection of posts as a set of tiles.** Drop a collection onto a page and your
  posts render as teaser cards — either a short strip or a full, paginated index — with a
  matching archive page. The tiles are built on the server, so they show up even before any
  scripts load, and the index can load more as the reader scrolls.
- **Tiles that read well on dark backgrounds.** Teaser cards and their links now adapt their
  accent colour to the surrounding tone, so a collection stays legible on a dark section.

## [0.6.0] — 2026-08-08

- **Attach images to a chat, and the assistant can see them.** Drop an image into the
  composer and the assistant works from what is actually in the picture instead of a
  description of it. Uploads are checked and stored privately to your own site.
- **Turn a design file into brand tokens.** Attach a design document (Markdown or plain
  text) and the assistant reads it, then proposes matching brand tokens you can preview in
  the Design system studio. Nothing touches your brand until you publish it yourself.
- **Hand the assistant a brief.** Attach a brief and the assistant applies what it
  describes — while treating any instructions written inside the file as content to work
  from, never as commands to obey.
- **Globals, reorganized into three clearer studios.** The single Globals area is now
  Identity (logo, colours, type), Navigation & Pages, and Settings (email, consent), so
  each thing you manage has an obvious home.
- **Clearer provider errors, and threads that stop getting stuck.** When an AI provider
  fails mid-turn, the chat now shows a plain card explaining what happened instead of
  stalling, and interrupted sessions recover on their own far sooner.
- **A roomier chat composer.** The message box keeps its full width on its own row, with a
  dedicated control for attaching images.
- **Security updates.** Refreshed bundled libraries with their latest fixes.

## [0.5.1] — 2026-08-07

- **Fixed: the appliance can no longer reinstall itself over your site.** When a site could not
  start on a new image — most often because that release no longer ships a module the site still
  had installed — the boot-time convergence read "Drupal is not answering" as "there is nothing
  installed here" and set up a fresh site over the existing database. It then reported success, so
  nothing rolled it back, and it happened again on the next restart, which destroyed restored
  backups too. The decision now turns on whether the database is **empty**, which is a different
  question: an empty database installs, a working site upgrades as before, and a database with data
  in it that cannot start **refuses to do anything at all** and says why, leaving the data exactly
  where it is. Managed updates re-pin the previous image automatically when that happens. The
  install step also re-checks that the database is empty immediately before it runs, so no future
  change can reintroduce this.
- **Fixed: pre-upgrade snapshots no longer overwrite each other.** Every upgrade wrote the same
  file, so a run of restarts — precisely what happens when something is wrong — left only the most
  recent copy. Each is now timestamped, and the last five are kept.
- **Changed: unreleased "edge" builds now have a place in the version order.** An edge build called
  itself `edge+` a short code, which is not a version — so the check that stops a release from
  migrating a site that is too old could never apply to one, and edge installs were the ones most
  exposed to it. A build off main now reports as the release it descends from plus the build code
  (`v0.5.1+edge.a1b2c3d`), so the same protections apply to it as to everyone else. Nothing changes
  for released versions, and older edge builds keep working exactly as they did.
- **New: an image now advertises the modules it contains, so an update can be refused before it is
  downloaded.** The appliance already refuses to start on an image that is missing code your site
  has installed. That refusal arrives late — after the download, at boot. Each image now publishes
  the list, and the Manager checks it against your site *before pulling anything*, so the answer
  comes as "uninstall these two modules first" while you are still on the version that has them.

- **Fixed: a refusal caused by missing module code now happens before anything else.** The check
  that names the modules a release has dropped could previously only run on sites that were already
  able to start, which is the opposite of when it is needed. It now runs first, so the site that
  cannot start is told exactly which modules to uninstall, on the version that still has them.

## [0.5.0] — 2026-08-06

- **Fixed: a provider that cannot answer now says so in one sentence, instead of turning the room
  red.** An expired key, a rate limit and a model that is no longer there all arrived the same way:
  a failed step in the work trail wearing the provider's own error text, with the engine's "Node
  execution failed for agent_reason:" in front of it. Nothing in that said whose problem it was or
  what to do — and a key you could replace in thirty seconds should never look like a crash. The
  chat now shows one calm line and at most one action, chosen by what actually went wrong: a
  rejected key offers **Reconnect provider**, a missing model offers **Change model**. A rate limit
  or an outage says the limit is theirs, with nothing to click, because there is nothing on your
  side to fix. Failures that are *not* the provider's — a broken flow, a tool that threw — read
  exactly as before, so the two are never confused for each other.
- **New: a failure you can simply send again.** Where the provider was rate-limiting, unreachable,
  or failed for a reason we could not name, the card now offers **Send again**, which re-sends your
  last message as a fresh turn. Never automatic, and it disarms the moment you press it. It is
  deliberately withheld where repeating cannot help, and withheld again whenever part of the turn
  had already taken effect — a page created, a brand staged, a picture made. Sending those words
  twice would do that work twice, so the card explains the absence rather than risking it.
- **Fixed: an answer the model was cut off in the middle of is reported as a failure, not served as
  the answer.** When a turn hit the model's output limit, the provider handed back what it managed
  to write — a sentence stopping mid-thought, or a tool call it never finished emitting, which then
  simply never ran. Nothing asked whether the turn had finished, so you could get half an answer, or
  a confident reply about work that was never done, with every step marked successful. Atelier now
  reads the provider's own finish reason and names it: "this turn was too long — start a new
  conversation, or ask for it in smaller pieces." Nothing is inferred from an answer's length, so a
  provider that reports nothing behaves exactly as before.
- **Fixed: a conversation no longer gets stuck saying the operator is still working.** If the
  process running a turn died part-way through — the model had answered, but the work after it never
  happened — the thread went on believing that turn was in progress and refused every later message,
  permanently, while telling you to give it a moment. The refusal now checks whether any work is
  genuinely in flight rather than trusting a flag: if nothing is running, the thread is released and
  your message goes through. A turn that really is still working is untouched, however long it takes.
  Recovery previously waited on a maintenance task that runs every few hours.
- **New: every room says what it can do — and the Media room is no longer taken away.** A small row
  above the composer names things in plain words: **Write** (titles, descriptions, copy), **Describe**
  (reads a picture you give it), **Draw** (makes one that didn't exist). Each room shows only the
  verbs its own assistant can spend, so the General studio no longer advertises pictures it has no
  tool to make. A chip you cannot use dims and says why, and the two reasons read differently: this
  chat has no tool for it (nothing to fix), or the site has no provider behind it — which, for an
  administrator, links to the setup wizard, where a key and a model are actually captured. The Media
  room used to vanish entirely when no image provider was connected, which removed a working
  conversation to protect one tool inside it; naming an image or writing its alt text from your own
  words needs no image provider at all. It now always opens, and the assistant is told the same
  facts the chips show, from the same source, so it cannot offer you a picture this install can't make.
- **Fixed: a Google key now draws on its own, and an install that can't draw is told why.**
  Connecting a Google AI Studio key as Gemini gave you chat and vision and an empty image picker —
  picture models were reachable only under a second provider entry, backed by the very same key, with
  nothing on the page saying so. Gemini now offers its own picture models, so one key turns on the
  Media studio's AI rail without connecting anything else. Separately, when the only thing connected
  is a shared gateway forwarding to other services, the image role no longer sits empty and silent:
  it says pictures need a provider connected directly, and names one. (Google's picture models need
  billing enabled on the key; a free key lists them and refuses at use time — which now arrives as
  one calm line rather than a red room.)
- **Fixed: asking the General room to build a landing page opens the right room, carrying your
  sentence with it.** Its most prominent starter ask answered with four cards and "pick a room to
  open it" — and picking Pages landed you on an empty composer, where you retyped what you had just
  written. A specific ask now gets a single card naming the room it belongs to, and opening it hands
  your own sentence back to you in that room's composer, ready to edit and send. Never sent for you,
  used once and gone. "Show me around" still shows the whole map.
- **Fixed: the first rebrand of a session actually repaints the preview.** Asking the design
  assistant for a new look from outside the Design System studio produced a run of "Applied to
  preview" cards over a preview still showing the old brand; reloading and asking again worked. The
  new tokens were staged before the preview existed to receive them — it now adopts whatever is
  already staged the moment it opens. A replayed card from an earlier session reads as a record
  ("staged earlier — no longer active") rather than as live state.
- **Fixed: accepting "Start a new thread" after Publish no longer leaves the page unsaveable.**
  Publishing and taking the offer of a fresh conversation froze the studio — Discard, Save draft and
  Publish all dead, only Archive alive — while it still claimed the conversation was wrapped up,
  right after you had started a new one. The verdict was cached and refreshed only when a
  conversation *got* wrapped up, never when you moved to a different one. It is now read from the
  conversation you are actually in, which fixes the same staleness on every other way of leaving a
  wrapped-up thread: the sidebar, a link, archiving then switching.
- **New: a provider's credential can come from the environment.** Set `ATELIER_<PROVIDER>_API_KEY`
  (and `ATELIER_<PROVIDER>_ENDPOINT` where a base URL is needed) and Atelier uses it — nothing is
  written to the database, so the secret appears in no dump and no backup, and rotates by changing
  the variable and restarting. Such a provider shows in the wizard as **Set by this server**, with
  no key field and no Disconnect, since this process cannot unset a variable it does not own; both
  actions previously reported success and changed nothing. A second form,
  `ATELIER_DEFAULT_<PROVIDER>_API_KEY`, is a *default rather than a rule*: it is copied into the
  credential store once, on the first boot after it is set, and is an ordinary key from then on —
  the owner can replace it, and a Disconnect stays disconnected across restarts. The variable's name
  is the whole configuration surface; there is nothing else to set.
- **Fixed: your email API key survives an update.** The appliance re-imports configuration on every
  boot, and that import deleted any stored key not present in the shipped configuration — including
  the mail key, leaving outgoing email with an empty credential after every update. Stored
  credentials are now treated as the runtime state they are, and are neither exported nor deleted.
- **Changed: three unused modules are gone.** Layout Builder, the Views UI and Views data export are
  uninstalled. None was used by anything Atelier ships, and Layout Builder in particular is a second,
  contradictory page-building model sitting in the admin UI next to the one the product is built on.
- **Changed: a room's starter suggestions come from its own configuration and nowhere else.** The
  console also carried a hardcoded fallback list, so which suggestions a room offered depended on
  which of the two happened to resolve — and they said different things. A room's chips are its own
  promise, so a room with none configured now shows none rather than borrowing asks it cannot
  fulfil. The composer still takes any request, and the room's welcome still says what it does.

## [0.4.2] — 2026-08-05

- **Fixed: chat did not work at all in 0.4.1 — if you are on that version, update.** A
  wiring mistake meant Atelier could not assemble the part of itself that talks to AI providers, so
  every message failed immediately, before any model was reached — including the retry handling 0.4.1
  was released to add, which never actually ran. Nothing was wrong with your provider, your key or
  your model choice. Our tests could not see it: they built that component a different way than the
  running product does, so they passed while the release could not answer a single message. They now
  check the way the product itself does it.
- **Fixed: the studios no longer silently ignore a value and report success.** When the assistant
  wrote a number, a true/false or a piece of structure in a slightly different shape than expected,
  brand tokens and page sections could be dropped without a word — and the reply would still say the
  change had been made. Sizes, weights and spacing were the usual casualties, which is why it looked
  intermittent: colours are always written the one accepted way. Values are now accepted in the forms
  a model actually writes, and anything genuinely unusable is named as skipped instead of vanishing.

## [0.4.1] — 2026-08-04

- **Fixed: a busy or briefly unavailable AI provider no longer throws away your whole request.**
  Atelier used to give up the moment a provider answered "too many requests" or "server error" — one
  bad second from upstream and the turn was gone, however far along it was. It now waits and tries
  again, up to three times: for as long as the provider itself asks, or a second, then two, then
  four. Failures that trying again cannot mend still stop straight away, as they should.
- **Fixed: when a provider does refuse, the message tells you whose problem it is and what to do.**
  A rejected key, someone else's rate limit and an outage at the provider all used to arrive as
  whatever raw text the provider's API happened to return — three different situations reading
  identically, none of them saying what would help. Each now has its own plain sentence and its own
  suggestion: reconnect the provider, wait and retry, choose a different model, shorten the
  conversation. The provider's exact words are still kept in the log, where they are useful.

## [0.4.0] — 2026-08-04

- **Changed: while Atelier works, it tells you what it is doing — in your words, not its own.** A
  running turn used to show the engine's internal job list: "Running the workflow · 7 steps",
  "Workflow ran", and rows printing a step's label beside its machine name. Now the steps read as
  outcomes ("Generating an image" while it runs, "Generated an image" once done), the plumbing steps
  are hidden entirely, and a reply that needed no real work shows no box at all.
- **Fixed: a long request no longer looks stuck.** Building a page can spend most of a minute in a
  single model call, and the console had nothing to say for that whole wait — the status line simply
  froze. Atelier now says **"Thinking…"** the moment it calls the model, counts the seconds, and
  changes what it says as the wait gets longer, so you can tell it is still working.
- **Fixed: when something goes wrong, you see it.** A failed step is now always shown, opened, with
  the error text — previously a backend failure could leave the screen looking like nothing happened.

## [0.3.0] — 2026-08-04

- **Changed: Atelier is leaner — twelve modules it no longer uses have been removed.** Atelier used
  to reach AI providers through a stack of contrib modules; it now talks to them directly, and has
  done since 0.1.0. Those modules were switched off then and are now gone from the download
  altogether, along with three patches that only existed to fix them. Nothing you use changes:
  Anthropic, OpenAI, Gemini, Mistral, Ollama, DeepSeek, Kimi, GLM, Qwen and any OpenAI-compatible
  endpoint all connect exactly as before. The image is smaller and there is less to keep up to date.
- **New: an upgrade that can't work now stops before it starts, and tells you what to do.** Because
  this release drops that code, a site running a build old enough to still have those modules
  switched **on** cannot upgrade straight to it — and previously it would have tried, failed partway
  and rolled itself back with nothing useful on screen. Atelier now checks first, **before touching
  your database**, and if the upgrade can't work it stops and names the version to go through on the
  way. Nothing is changed, so starting your previous version again puts you exactly back.
  - This applies to anyone who installed a pre-release `edge` build before 0.1.0. If you have
    updated at any point since, you are unaffected and upgrade normally.
  - The `atelier` manager can plan the route for you: `atelier app update` works out the versions to
    pass through, shows you the plan, and walks it after you confirm.
- **Changed: your site now records which version last updated it**, so Atelier can tell how far back
  an upgrade is coming from and give a straight answer instead of a guess.

## [0.2.0] — 2026-08-04

- **New: DeepSeek, Kimi, GLM and Qwen are providers you pick by name.** Each one is in the setup
  list with its own logo: choose it, paste your key, done. Previously they were reachable only
  through the generic "OpenAI-compatible endpoint" row, which meant looking up the vendor's base URL
  yourself and getting it exactly right.
- **Fixed: models reached through an OpenAI-compatible endpoint all came out the same.** Atelier
  picks three models for you — one for hard thinking, one for everyday work, one for speed — by
  recognising model families by name. It only knew the families sold by the big proxies, so a
  vendor's own catalogue matched nothing and all three settled on whatever model came back first.
  Your "fast" model could be the most expensive one you had. The families of the vendors above are
  now recognised directly.
- **Changed: no provider is labelled "Recommended" any more.** Atelier works with all of them; which
  one suits you is your call, not ours. Providers we have put through their paces read **Tested** —
  Anthropic, OpenAI, Gemini, Mistral, Ollama and DeepSeek at this point. Individual model
  recommendations are unchanged: they say a model does the job well, not that you should buy from
  that vendor.
- **Changed: the setup list keeps what you have connected at the top** and scrolls within a fixed
  height, instead of pushing the rest of the wizard off the screen as more providers arrive.
- **Changed: installs follow releases.** New installs track `:latest` — the newest release — rather
  than `:edge`, the rolling development build. `:edge` remains a supported choice for anyone who
  wants unreleased fixes early.
- **Fixed: re-running the installer could move you off a version you pinned.** If you had asked for
  a specific version, an installer re-run silently replaced it with the current default. It now
  leaves your image alone unless the run names one.

## [0.1.2] — 2026-08-04

- **Fixed: every site warned "no AI provider connected yet" on every boot, connected or not.** The
  startup self-test asked a component that a recent release removed. The call failed, the failure was
  swallowed, and the warning printed unconditionally — so the one line that should tell you setup is
  still owed said the same thing on a fully configured site. It now asks the part of Atelier that
  actually knows, and stays quiet unless no model is connected.

## [0.1.1] — 2026-08-03

- **Fixed: an agent could answer "success" and change nothing.** Asking for a whole page in one go
  could run past the limit on how much an agent may write in a single step. The half-written step was
  discarded rather than applied in part, so the chat reported success, the page stayed empty, and
  nothing anywhere said why. The limit is raised eightfold, and agents now build a long page in
  passes — sections first, then the detail — so you watch it appear instead of waiting for one large
  step that might not arrive. This affects every agent, and models that think before answering most
  of all, since their thinking spent part of the same allowance.

## [0.1.0] — 2026-08-03

**Atelier has version numbers from here on.** Entries above this point are dated snapshots; from
now on a release gets a version like `0.1.0`, published as
`ghcr.io/aincient-labs/atelier-cms:v0.1.0` (note the `v`) alongside `:latest`. `:edge` continues to
track the newest development build for anyone who wants
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
