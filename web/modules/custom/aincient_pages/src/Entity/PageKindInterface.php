<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * A page kind — one CONTEXT of the composition grammar.
 *
 * Generalises the two hardcoded regimes ('landing' / 'blog') into config: a
 * kind names which components (and which of their variants/tones) a page of
 * this kind may compose, in which mode. The kind is the tunable GOVERNANCE
 * layer above the admission-gate floor: it may only NARROW what discovery
 * admitted — never invent a prop, re-enable a rejected component, or relax a
 * schema constraint (plans/byo-components.md §3.2; the security-floor-vs-policy
 * split, DECISIONS 0368).
 *
 * The kind id doubles as the page's stored `field_page_type` value (the
 * derived query index CollectionResolver filters on), so ids are permanent
 * once pages of the kind exist.
 */
interface PageKindInterface extends ConfigEntityInterface {

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
   * TRUE when pages of this kind compose a section stack.
   */
  public function isComposition(): bool;

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

}
