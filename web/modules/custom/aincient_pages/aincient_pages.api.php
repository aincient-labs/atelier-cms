<?php

/**
 * @file
 * Hooks provided by the AIncient Pages module.
 */

declare(strict_types=1);

use Drupal\node\NodeInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Contribute markup to the bottom of a rendered AIncient page's <body>.
 *
 * An `aincient_page` node renders chrome-less from its page-schema through a
 * bespoke HTML shell (PageSpikeController), NOT Drupal's themed page pipeline —
 * so `hook_page_bottom()` never fires for it, and neither does the `#attached`
 * asset pipeline. This hook is the seam for operator chrome that must appear on
 * the live page (e.g. aincient_chat's "Edit in Console" pill).
 *
 * Called ONLY for a real canonical render — never the studio's stateless
 * preview or the spike briefs (those live inside the console already).
 *
 * The return is a render array rendered in isolation and appended to the page
 * body. Because the shell builds its own <head> and skips `#attached`, the
 * contribution must be SELF-CONTAINED: link any stylesheet directly in the
 * markup (see aincient_chat's implementation) rather than attaching a library.
 *
 * Implementations are responsible for their own access gating; the hook fires
 * regardless of the current user.
 *
 * @param \Drupal\node\NodeInterface $node
 *   The page node being rendered, in the active content language.
 *
 * @return array
 *   A render array to append to the page body, or an empty array to add nothing.
 */
function hook_aincient_pages_shell_bottom(NodeInterface $node): array {
  if (!\Drupal::currentUser()->hasPermission('use some permission')) {
    return [];
  }
  return [
    '#markup' => \Drupal\Core\Render\Markup::create('<div class="my-chrome">…</div>'),
  ];
}

/**
 * @} End of "addtogroup hooks".
 */
