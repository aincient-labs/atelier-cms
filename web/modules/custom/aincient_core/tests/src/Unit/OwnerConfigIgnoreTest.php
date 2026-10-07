<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Owner-set config survives converge's full `config:import`.
 *
 * converge re-imports config/sync on every upgrade, and config_ignore applies
 * the INCOMING list from config/sync (its default `config_ignore_storage`), so
 * a pattern listed here is in force on the very upgrade that ships it.
 *
 * @group aincient_core
 */
final class OwnerConfigIgnoreTest extends UnitTestCase {

  /**
   * What the owner writes from the product: Model rates, model preferences,
   * the models form, and the Settings studio's on/off switches.
   */
  private const OWNER_SET = [
    'aincient_chat.settings:disabled_studios',
    'aincient_core.model_preferences',
    'aincient_core.model_roles',
    'aincient_core.pricing',
  ];

  public function testShippedIgnoreListFencesOwnerSetConfig(): void {
    $file = dirname(__DIR__, 7) . '/config/sync/config_ignore.settings.yml';
    $shipped = Yaml::decode((string) file_get_contents($file));
    foreach (self::OWNER_SET as $pattern) {
      $this->assertContains($pattern, $shipped['ignored_config_entities'], "$pattern is reset by every upgrade's config:import.");
    }
  }

}
