<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\Core\Extension\ModuleExtensionList;

/**
 * The studio tier: `web/modules/studio/`, where OUR studio modules live.
 *
 * Atelier's own code is split in two tiers (plans/studio-modules.md, DECISIONS
 * 0430): `web/modules/custom/` is the CORE — what the product needs with every
 * studio switched off — and `web/modules/studio/` holds the studios, surfaces on
 * top of core. Both are ours, in our tree and under our gate; neither is a
 * pack. Several guards need to tell the two tiers apart from a module name or a
 * path, and they all ask here, so "where does the studio tier live" has one
 * home: the capability fence (a studio module MAY own capabilities, a pack may
 * not — 0426), the FlowDrop capability deriver (a studio's verbs are exposed to
 * the agent like core's), and the roster guard (which globs both tiers).
 *
 * Path-based on purpose. A module is in the tier because it LIVES there, not
 * because it says so: a pack can name itself anything, but converge bakes
 * packs under `web/modules/custom` (or links them under `web/modules/packs`),
 * never here. The check is a substring on the module's docroot-relative path,
 * which is what every caller — tests included — can produce without a bootstrap.
 */
final class StudioTier {

  /**
   * The tier's docroot-relative directory.
   */
  public const DIR = 'modules/studio';

  public function __construct(
    private readonly ModuleExtensionList $modules,
  ) {}

  /**
   * Whether a module path (docroot-relative or absolute) is in the studio tier.
   */
  public static function isStudioPath(string $modulePath): bool {
    return str_contains('/' . trim(str_replace('\\', '/', $modulePath), '/') . '/', '/' . self::DIR . '/');
  }

  /**
   * Whether a module (by machine name) lives in the studio tier.
   *
   * FALSE for a module Drupal does not know: an unknown module is not a studio,
   * and a guard asking about one is asking the wrong question.
   */
  public function isStudioModule(string $module): bool {
    if (!$this->modules->exists($module)) {
      return FALSE;
    }
    return self::isStudioPath($this->modules->getPath($module));
  }

}
