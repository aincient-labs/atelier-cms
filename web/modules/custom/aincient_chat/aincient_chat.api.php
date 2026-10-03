<?php

/**
 * @file
 * Hooks and extension points provided by the AIncient Chat module.
 */

/**
 * @defgroup aincient_studio Studios (manifest plugins)
 * @{
 * A STUDIO is one workspace of the operator console.
 *
 * The console is always in exactly one studio: it scopes the conversation
 * history, the agent a new chat runs, and — for the specialised studios — the
 * editor/preview split pane. A studio has two halves:
 *
 * - The SERVER half, this plugin type: the key, the admin label, the display
 *   order, the access gate and the studio's index (UI entry, flows, owned
 *   capabilities, demo source). Declared by a YAML manifest,
 *   `<module>.studios.yml`, in every enabled module — a studio module under
 *   `web/modules/studio/` (DECISIONS 0430) or a component pack
 *   (plans/console-extension-point.md, DECISIONS 0424). No PHP class: the
 *   manifest IS the plugin; `#[Studio]` attribute plugins are gone (0436).
 *   General, the open fallback, is the manager's own and has no manifest.
 * - The BROWSER half: the manifest's `ui:` map. `ui.entry` names the
 *   rail/preview module, compiled by the one console build into the studio's
 *   own chunk and loaded the first time the studio opens (studio modules);
 *   `ui.name` (defaults to `label`) and `ui.icon` (a kit icon name, defaults
 *   to the chat glyph) are what the console's nav shows before that chunk
 *   loads. A manifest with no `ui.entry` — a pack, until the imperative mount
 *   boundary lands (`window.atelier.console`, Phase 4 of that plan) — is a
 *   chat-only studio: visible to the server (permission, settings form,
 *   catalog) and in the nav under its name and icon, rendered as chat.
 *
 * @code
 * # acme_reports/acme_reports.studios.yml
 * acme_reports:
 *   label: 'Reports'
 *   description: 'Usage and traffic reports.'
 *   weight: 90
 *   ui:
 *     name: 'Reports'
 *     icon: sliders
 * @endcode
 *
 * Three rules, all of them load-bearing:
 *
 * - THE ID IS A PUBLIC CONTRACT AND APPEND-ONLY. It is the same word in the
 *   `studios` map of `aincient_chat.settings`, in the `use aincient studio
 *   <id>` permission, in the front-end registry and in every stored console
 *   deep link. Renaming one breaks saved links and un-configures the studio.
 * - A THIRD-PARTY ID IS PREFIXED WITH ITS MODULE NAME and is a plain machine
 *   name (`acme_reports`, not `reports` and not `acme:reports`): ids share one
 *   flat namespace and travel into permission names and URL values.
 * - A STUDIO IS GATED UNLESS IT SAYS OTHERWISE. Its permission is derived from
 *   its id and minted automatically; only `open: TRUE` (the General studio)
 *   opts out, so a studio cannot ship itself ungated by omission.
 *
 * A studio brings no new agent VERBS with it. The capabilities an agent can
 * spend are Atelier's own, and a pack that ships a `Plugin/AiCapability`
 * directory is rejected by `atelier:pack-validate` (DECISIONS 0368) — a pack
 * studio composes FlowDrop workflows over the capabilities Atelier ships.
 * @}
 */

/**
 * Alter the discovered studio definitions.
 *
 * Runs once per discovery (the definitions are cached). Use it to relabel or
 * reorder a studio, or to remove one an install must not offer.
 *
 * Removing a studio removes its permission and drops it from the console
 * switcher; it does NOT delete its `studios` config row, so restoring the
 * plugin restores the configured agents. Removing `general` is legal but
 * leaves the console falling back to the first remaining studio
 * ({@see \Drupal\aincient_chat\Studio\StudioManager::defaultId}).
 *
 * @param array $definitions
 *   The studio plugin definitions, keyed by studio id.
 *
 * @ingroup aincient_studio
 */
function hook_aincient_studio_info_alter(array &$definitions): void {
  // This site does not do page health audits.
  unset($definitions['checks']);

  // House style: the Library is called the Shelf here.
  if (isset($definitions['library'])) {
    $definitions['library']['label'] = 'Shelf';
  }
}
