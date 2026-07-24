# `.devpanel/` — Deploy Atelier on Drupal Forge

These files let [Drupal Forge](https://www.drupalforge.org/) (DevPanel) build and run
Atelier as a hosted demo, in **build mode**: DevPanel clones this repo as the app root,
`composer install`s it, and runs `init.sh` to install Atelier from `config/sync`.

They are **inert for a normal clone** — nothing here runs unless DevPanel does, and the
settings include is gated on `$DP_APP_ID`.

- **`init.sh`** — build/startup: composer install → install Atelier from config → wire the
  LiteLLM trial key (when DevPanel injects `DP_AI_VIRTUAL_KEY`) → skip onboarding.
- **`settings.devpanel.php`** — Drupal DB/settings for a DevPanel app (MySQL via `DB_*`).
  Loaded from `settings.php` only when running under DevPanel.
- **`config.yml`** — DevPanel git-integration hooks (rebuild deps on push to `main`).

## Demo environment (set in the DevPanel app)

| Var | Value |
|-----|-------|
| `DP_AI_VIRTUAL_KEY` | DevPanel-injected LiteLLM trial key (unset ⇒ keyless demo) |
| `DP_AI_HOST` | `https://ai.drupalforge.org` |

The demo binds all model roles to `anthropic/claude-haiku-4-5` (budget) and leaves image
generation off. AI image generation needs your own key on a self-hosted install.
