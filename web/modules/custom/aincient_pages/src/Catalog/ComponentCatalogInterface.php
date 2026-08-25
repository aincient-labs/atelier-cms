<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

/**
 * The component-catalog service — the ONE discovery path for the page grammar.
 *
 * `for($kind)` is the whole public API (ratified decision #3): consumers get a
 * compiled, immutable {@see EffectiveCatalog} and never touch discovery,
 * constraint or kind config themselves. Nothing here may assume a component is
 * file-backed (study steal S2) — `block` has no SDC and still appears.
 */
interface ComponentCatalogInterface {

  /**
   * The compiled palette one kind sees: discovered ∩ site-constraint ∩ kind.
   *
   * NEVER FATAL: an unknown kind id degrades to landing semantics with the
   * whole discovered palette (decision #4) — a stale field_page_type value or
   * a deleted kind must not 500 a render.
   */
  public function for(string $kind): EffectiveCatalog;

  /**
   * The available kind ids, keyed to their labels + hints + modes — the list
   * the create-page picker and the agent's capability text enumerate.
   *
   * @return array<string, array{label: string, hint: string, mode: string}>
   */
  public function kinds(): array;

  /**
   * The UNDIMINISHED discovered palette — no site constraint, no kind
   * narrowing. The governance surfaces (the Components pane, later the kind
   * editor) render their checkboxes from this: `for($kind)` has the removals
   * already applied, so it cannot show what is currently removed.
   */
  public function discovered(): EffectiveCatalog;

  /**
   * Drop the per-request memos. Needed only by a caller that WRITES the
   * constraint or a kind and must serve the recompiled state in the SAME
   * request (the cache tags handle every later request).
   */
  public function reset(): void;

  /**
   * The kind ids a collection may list (`collection_source: true`) — the
   * allow-list behind the collection component's `source` prop. Never empty:
   * degrades to ['blog'] so an unflagged site cannot blank every listing.
   *
   * @return string[]
   */
  public function collectionSources(): array;

}
