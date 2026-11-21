<?php

/**
 * @file
 * Post-update functions for aincient_pages.
 */

declare(strict_types=1);

/**
 * Store every component name as a full id (0.30.0, DECISIONS 0535/0536).
 *
 * Pages and blocks (every revision and language), page kinds and the site
 * constraint: `hero` becomes `atelier:hero`, a pack's `spotlight` becomes
 * `<pack>:spotlight`; a name no installed module provides is kept as
 * `unresolved:<name>` and listed in the log. Runs after update 10020 has
 * enabled the atelier module. Safe to run again: full ids are kept.
 */
function aincient_pages_post_update_0300_component_ids(array &$sandbox): ?string {
  /** @var \Drupal\aincient_pages\Update\ComponentIdRewrite $rewrite */
  $rewrite = \Drupal::service('aincient_pages.component_id_rewrite');
  if (!isset($sandbox['tables'])) {
    $rewrite->start($sandbox);
    $sandbox['config'] = $rewrite->rewriteConfig($sandbox);
  }
  $rewrite->step($sandbox);
  return $sandbox['#finished'] >= 1 ? $rewrite->finish($sandbox, $sandbox['config']) : NULL;
}
