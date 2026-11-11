<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Aincient\PageModel\DesignTokens;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Builds DesignTokens from the module's two data files.
 *
 * DesignTokens takes plain file paths so it carries no Drupal dependency
 * (docs/packages.md, aincient/page-model); this factory is the Drupal side that
 * knows where aincient_pages keeps design-tokens.yml and build/tw-palette.json
 * (where scripts/build-tokens.php and the Tailwind build put them).
 */
final class DesignTokensFactory {

  public static function create(ModuleExtensionList $moduleList): DesignTokens {
    $path = $moduleList->getPath('aincient_pages');
    return new DesignTokens($path . '/design-tokens.yml', $path . '/build/tw-palette.json');
  }

}
