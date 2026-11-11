<?php

declare(strict_types=1);

namespace Aincient\PageModel;

/**
 * The saved brand's token overrides, as TokenGrounding layers them.
 *
 * The seam that keeps TokenGrounding free of Drupal config: BrandRepository
 * implements it in the module (docs/packages.md, aincient/page-model).
 */
interface BrandTokenSourceInterface {

  /**
   * The saved brand's token overrides, keyed by token name.
   *
   * @return array<string, string>
   */
  public function tokens(): array;

}
