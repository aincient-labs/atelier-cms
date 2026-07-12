<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\aincient_chat\Studio;
use Drupal\aincient_chat\StudioPermissions;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests per-studio access: the enum's permission map + accessibleBy gate and
 * the dynamically minted `use aincient studio <key>` permission set.
 *
 * @covers \Drupal\aincient_chat\Studio
 * @covers \Drupal\aincient_chat\StudioPermissions
 * @group aincient
 */
final class StudioAccessTest extends UnitTestCase {

  /**
   * General is the open default landing studio — no dedicated permission.
   */
  public function testGeneralIsOpen(): void {
    $this->assertNull(Studio::General->permission());

    // Even an account that holds NO permissions can enter General.
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->assertTrue(Studio::General->accessibleBy($account));
  }

  /**
   * Every specialised studio maps to `use aincient studio <key>`.
   */
  public function testSpecialisedPermissions(): void {
    $this->assertSame('use aincient studio design_system', Studio::DesignSystem->permission());
    $this->assertSame('use aincient studio globals', Studio::Globals->permission());
    $this->assertSame('use aincient studio content', Studio::Content->permission());
    $this->assertSame('use aincient studio library', Studio::Library->permission());
    $this->assertSame('use aincient studio media', Studio::Media->permission());
    $this->assertSame('use aincient studio checks', Studio::Checks->permission());
  }

  /**
   * accessibleBy is exactly a hasPermission() check on the studio's permission
   * — a content-only grant opens Content (and General) but not Globals.
   */
  public function testAccessibleByFollowsPermission(): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(
      static fn(string $permission): bool => $permission === 'use aincient studio content',
    );

    $this->assertTrue(Studio::Content->accessibleBy($account), 'Held permission ⇒ accessible.');
    $this->assertTrue(Studio::General->accessibleBy($account), 'General is always accessible.');
    $this->assertFalse(Studio::Globals->accessibleBy($account), 'Ungranted studio ⇒ not accessible.');
    $this->assertFalse(Studio::DesignSystem->accessibleBy($account));
    $this->assertFalse(Studio::Checks->accessibleBy($account));
  }

  /**
   * The permission set is minted from the enum: one per specialised studio,
   * General excluded, each restricted.
   */
  public function testPermissionsMintedFromEnum(): void {
    $permissions = (new StudioPermissions())
      ->setStringTranslation($this->getStringTranslationStub())
      ->permissions();

    $this->assertSame(
      [
        'use aincient studio design_system',
        'use aincient studio globals',
        'use aincient studio content',
        'use aincient studio library',
        'use aincient studio media',
        'use aincient studio checks',
      ],
      array_keys($permissions),
    );
    // General is open — it must never appear as a grantable permission.
    $this->assertArrayNotHasKey('use aincient studio general', $permissions);

    foreach ($permissions as $definition) {
      $this->assertTrue($definition['restrict access']);
    }
  }

}
