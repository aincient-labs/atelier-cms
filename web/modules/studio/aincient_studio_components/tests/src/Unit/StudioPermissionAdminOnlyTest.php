<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * The Components studio is admin-only (DECISIONS 0455): its permission — which
 * also covers switching a pack override back to the original — is held by no
 * role in config/sync. Only the is_admin administrator role reaches it.
 *
 * @group aincient
 */
final class StudioPermissionAdminOnlyTest extends UnitTestCase {

  public function testNoShippedRoleHoldsTheComponentsPermission(): void {
    $dir = dirname(__DIR__, 7) . '/config/sync';
    $files = glob($dir . '/user.role.*.yml') ?: [];
    $this->assertNotEmpty($files, "No roles found under $dir.");
    foreach ($files as $file) {
      $role = Yaml::decode((string) file_get_contents($file));
      if (!empty($role['is_admin'])) {
        continue;
      }
      $this->assertNotContains('use aincient studio components', $role['permissions'] ?? [], basename($file) . ' must not hold the Components studio permission.');
    }
  }

}
