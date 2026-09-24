<?php

/**
 * @file
 * Hooks and extension points provided by the AIncient Chat module.
 */

/**
 * @defgroup aincient_studio Studio plugins
 * @{
 * A STUDIO is one workspace of the operator console.
 *
 * The console is always in exactly one studio: it scopes the conversation
 * history, the agent a new chat runs, and — for the specialised studios — the
 * editor/preview split pane. A studio has two halves:
 *
 * - The SERVER half, this plugin type: the key, the admin label, the display
 *   order and the access gate. Discovered from `src/Plugin/Studio` in every
 *   enabled module, so a component pack can ship one
 *   (plans/console-extension-point.md, DECISIONS 0424).
 * - The BROWSER half: the editor/preview components, registered in the console
 *   bundle's own registry. Until the imperative mount boundary lands
 *   (`window.atelier.console`, Phase 4 of that plan), only studios built into
 *   the console bundle have a browser half — a plugin-only studio is visible to
 *   the server (permission, settings form, catalog) but has nothing to render.
 *
 * @code
 * namespace Drupal\acme_reports\Plugin\Studio;
 *
 * use Drupal\aincient_chat\Attribute\Studio;
 * use Drupal\aincient_chat\Studio\StudioBase;
 *
 * #[Studio(id: 'acme_reports', label: 'Reports', weight: 90)]
 * final class Reports extends StudioBase {}
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
