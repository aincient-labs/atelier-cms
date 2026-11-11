<?php

declare(strict_types=1);

namespace Aincient\PageModel\Catalog;

/**
 * What the catalog compiler reads off a page kind.
 *
 * The seam that keeps CatalogCompiler free of the config-entity API: the
 * page_kind config entity's PageKindInterface extends this, and a unit test or
 * the lean app can hand the compiler any object that answers these
 * (docs/packages.md, aincient/page-model). See PageKindInterface for what a
 * kind is.
 */
interface PageKindDefinitionInterface {

  /**
   * Open composition — an ordered section list (the landing regime).
   */
  public const MODE_COMPOSITION = 'composition';

  /**
   * A locked recipe — typed content fields, no placeable palette (blog).
   */
  public const MODE_RECIPE = 'recipe';

  /**
   * The composition mode: composition | recipe.
   */
  public function mode(): string;

  /**
   * The one-line hint shown in the create-page picker (and to the agent).
   */
  public function hint(): string;

  /**
   * The component narrowing map: name => {variants?: [...], tones?: [...]}.
   *
   * An EMPTY map means "the whole discovered palette" — no narrowing — so a
   * newly installed pack component reaches the kind without editing it. A
   * non-empty map is an allow-list: only the named components, optionally
   * narrowed to a subset of their declared variants/tones.
   */
  public function components(): array;

  /**
   * The kind's required first section, or NULL when unconstrained.
   */
  public function opener(): ?string;

  /**
   * Per-component placement limits: name => max count. Empty = unlimited.
   */
  public function limits(): array;

  /**
   * TRUE when a collection may list pages of this kind (source: <id>).
   */
  public function isCollectionSource(): bool;

  /**
   * TRUE for a composition that is not a page — no URL, no opener, never a
   * collection source, never offered as a page type (the `block` kind,
   * DECISIONS 0455). A fragment kind governs what a reusable block contains.
   */
  public function isFragment(): bool;

  /**
   * TRUE when components the allow map does not name are offered ("allowed
   * automatically" — new pack components arrive on their own); FALSE when the
   * map is an allow list ("off until I allow them"). DECISIONS 0455.
   */
  public function includesNew(): bool;

  /**
   * The deny list: component names this kind never offers. Applied after the
   * allow list, so an empty allow list + a deny list = "every component,
   * including new pack ones, except these" (DECISIONS 0455).
   *
   * @return string[]
   */
  public function removed(): array;

  /**
   * The kind id (doubles as the page's stored field_page_type value).
   *
   * @return string|int|null
   *   The id (a string for a saved kind).
   */
  public function id();

}
