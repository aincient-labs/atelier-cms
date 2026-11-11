<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Entity;

use Aincient\PageModel\Catalog\PageKindDefinitionInterface;
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
 *
 * The catalog-facing reads (mode, components, opener, limits, … and the MODE_*
 * constants) are declared on PageKindDefinitionInterface, the seam
 * CatalogCompiler type-hints (docs/packages.md, aincient/page-model).
 */
interface PageKindInterface extends ConfigEntityInterface, PageKindDefinitionInterface {

  /**
   * TRUE when pages of this kind compose a section stack.
   */
  public function isComposition(): bool;

}
