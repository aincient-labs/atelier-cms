<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * The reserved leading-segment namespace for page URL aliases.
 *
 * With the '/pages/' prefix dropped (pathauto.pattern.aincient_page), a page
 * alias is a single root segment, so it can shadow an app path — Drupal's
 * inbound alias processor rewrites the request path before the router runs.
 * Pathauto already reserves every live route AND every real file/dir on its own
 * (AliasUniquifier::isRoute), so '/atelier', '/admin', '/node', '/core',
 * '/sites', '/robots.txt' … need no entry here. This is only the machine
 * namespaces that are NOT routed but must still never be claimed by content:
 * 'aincient' (the machine namespace) and 'atelier' (the public product/console
 * base — kept reserved even if its route ever moves).
 *
 * Enforced on both alias vectors: auto-generated aliases via
 * {@see aincient_pages_pathauto_is_alias_reserved()} (pathauto then suffixes
 * them, 'aincient' → 'aincient-0'), and manually-entered aliases via the
 * ReservedAlias validation constraint on the page's 'path' field.
 */
final class ReservedAliases {

  /**
   * Reserved leading path segments (no leading slash).
   */
  public const SEGMENTS = ['aincient', 'atelier'];

  /**
   * The reserved leading segment $alias would occupy, or NULL if it is free.
   *
   * @param string $alias
   *   A URL alias, with or without a leading slash (e.g. '/aincient/foo').
   */
  public static function match(string $alias): ?string {
    $segment = explode('/', ltrim($alias, '/'), 2)[0];
    return in_array($segment, self::SEGMENTS, TRUE) ? $segment : NULL;
  }

}
