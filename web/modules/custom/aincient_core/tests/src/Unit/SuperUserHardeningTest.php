<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the uid-1 super-user hardening against silent drift.
 *
 * The distribution ships with the uid-1 super-user permission bypass DISABLED
 * (`security.enable_super_user: false` in `web/sites/default/services.yml`), so
 * access is governed purely by roles/permissions. That is only SAFE while the
 * config/sync baseline ships a role with `is_admin: true` — core's installer
 * (SiteConfigureForm) assigns it to user 1, and `is_admin` roles hold every
 * permission, so uid 1 keeps full access through the role rather than the bypass.
 *
 * Disable the bypass without shipping an `is_admin` role and the appliance boots
 * a powerless uid 1 that can log in but administer nothing — a locked-out site.
 * This test fails the build the moment either half of that contract drifts:
 *  - the hardening is removed/flipped, or
 *  - the only `is_admin` role disappears from config/sync, or
 *  - the local-dev override stops restoring the bypass, or
 *  - the appliance starts loading the dev override (un-hardening production).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SuperUserHardeningTest extends UnitTestCase {

  /**
   * The repo root (apps/cms), where config/sync, web/ and docker/ live.
   */
  private function repoRoot(): string {
    // tests/src/Unit -> aincient_core -> custom -> modules -> web -> apps/cms.
    return dirname(__DIR__, 7);
  }

  /**
   * The distribution ships the super-user bypass DISABLED (hardened by default).
   */
  public function testDistributionShipsSuperUserDisabled(): void {
    $path = $this->repoRoot() . '/web/sites/default/services.yml';
    $this->assertFileExists($path, 'The committed services.yml ships with the distribution.');
    $services = Yaml::parseFile($path);
    $this->assertArrayHasKey('parameters', $services);
    $this->assertArrayHasKey('security.enable_super_user', $services['parameters'], 'services.yml pins the super-user parameter.');
    $this->assertFalse(
      $services['parameters']['security.enable_super_user'],
      'The distribution must ship security.enable_super_user: false (uid 1 relies on roles, not the bypass).',
    );
  }

  /**
   * config/sync ships at least one is_admin role, so uid 1 isn't powerless.
   */
  public function testConfigSyncShipsAnAdminRole(): void {
    $files = glob($this->repoRoot() . '/config/sync/user.role.*.yml') ?: [];
    $this->assertNotEmpty($files, 'config/sync ships user roles.');

    $adminRoles = [];
    foreach ($files as $file) {
      $role = Yaml::parseFile($file);
      if (($role['is_admin'] ?? FALSE) === TRUE) {
        $adminRoles[] = $role['id'] ?? basename($file);
      }
    }

    $this->assertNotEmpty(
      $adminRoles,
      'With the super-user bypass off, config/sync MUST ship a role with is_admin: true '
      . '(core assigns it to user 1 at install) or uid 1 boots with zero permissions.',
    );
  }

  /**
   * The local-dev override restores the bypass (developer convenience).
   */
  public function testDevOverrideReenablesSuperUser(): void {
    $path = $this->repoRoot() . '/web/sites/default/services.dev.yml';
    $this->assertFileExists($path, 'The dev override file ships alongside services.yml.');
    $dev = Yaml::parseFile($path);
    $this->assertTrue(
      $dev['parameters']['security.enable_super_user'] ?? NULL,
      'services.dev.yml must restore security.enable_super_user: true for local DDEV.',
    );
  }

  /**
   * settings.php layers the dev override on top, gated to DDEV only.
   */
  public function testSettingsGatesDevOverrideToDdev(): void {
    $settings = (string) file_get_contents($this->repoRoot() . '/web/sites/default/settings.php');
    $this->assertMatchesRegularExpression(
      "/IS_DDEV_PROJECT.*\n.*services\.dev\.yml/U",
      $settings,
      'settings.php must append services.dev.yml only under the IS_DDEV_PROJECT gate.',
    );
  }

  /**
   * The appliance install never loads the dev override (production stays hardened).
   */
  public function testApplianceNeverLoadsDevOverride(): void {
    $appliance = (string) file_get_contents($this->repoRoot() . '/docker/settings.appliance.php');
    $this->assertStringNotContainsString(
      'services.dev.yml',
      $appliance,
      'The appliance settings must never reference the dev super-user override.',
    );
  }

}
