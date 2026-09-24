<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\StudioPermissions;
use Drupal\aincient_chat\Studio\StudioManager;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Session\AccountInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * The studio SET and its access gate, now that both are discovered.
 *
 * Three things are asserted here, and the first is the one that matters most:
 *
 * 1. THE BUILT-IN IDS ARE FROZEN. A studio id is the shared key between the
 *    plugin, `aincient_chat.settings`, the `use aincient studio <id>`
 *    permission, the front-end registry and every stored console deep link
 *    (memory/console-open-doc-url-reflection). Renaming one is a silent break
 *    for saved links, so this test fails on any rename — an ADDITION, which is
 *    the safe direction, is a one-line edit here.
 * 2. Access is exactly `hasPermission()` on the derived permission, with
 *    General open.
 * 3. The permissions page mints one permission per gated studio, from the same
 *    rule — which is what gives a pack studio a permission for free.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Studio\StudioManager
 * @covers \Drupal\aincient_chat\Studio\StudioBase
 * @covers \Drupal\aincient_chat\StudioPermissions
 */
#[RunTestsInSeparateProcesses]
final class StudioAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * The built-in studios, in display order. Append-only: see the class doc.
   */
  private const BUILT_IN = [
    'general',
    'design_system',
    'globals',
    'settings',
    'components',
    'content',
    'library',
    'media',
    'checks',
  ];

  private function manager(): StudioManager {
    $manager = $this->container->get('plugin.manager.aincient.studios');
    $this->assertInstanceOf(StudioManager::class, $manager);
    return $manager;
  }

  /**
   * Discovery finds every built-in studio, in weight order, and nothing else.
   */
  public function testBuiltInStudiosAreDiscoveredInOrder(): void {
    $this->assertSame(self::BUILT_IN, $this->manager()->keys());
  }

  /**
   * General is the open default landing studio — no dedicated permission.
   */
  public function testGeneralIsOpen(): void {
    $general = $this->manager()->get('general');
    $this->assertNotNull($general);
    $this->assertTrue($general->isOpen());
    $this->assertNull($general->permission());
    $this->assertSame('general', $this->manager()->defaultId());

    // Even an account that holds NO permissions can enter General.
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->assertTrue($general->accessibleBy($account));
  }

  /**
   * Every specialised studio maps to `use aincient studio <id>`.
   */
  public function testSpecialisedPermissionsAreDerived(): void {
    foreach ($this->manager()->studios() as $id => $studio) {
      $this->assertSame(
        $id === 'general' ? NULL : "use aincient studio $id",
        $studio->permission(),
      );
    }
  }

  /**
   * An unknown key resolves to NULL rather than fataling — a stale deep link
   * or a config row naming a studio this install no longer has.
   */
  public function testUnknownKeyResolvesToNull(): void {
    $this->assertNull($this->manager()->get('no_such_studio'));
    $this->assertNull($this->manager()->get(NULL));
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

    $manager = $this->manager();
    $this->assertTrue($manager->get('content')->accessibleBy($account), 'Held permission ⇒ accessible.');
    $this->assertTrue($manager->get('general')->accessibleBy($account), 'General is always accessible.');
    $this->assertFalse($manager->get('globals')->accessibleBy($account), 'Ungranted studio ⇒ not accessible.');
    $this->assertFalse($manager->get('design_system')->accessibleBy($account));
    $this->assertFalse($manager->get('checks')->accessibleBy($account));
  }

  /**
   * The permission set is minted from the plugins: one per gated studio,
   * General excluded, each restricted.
   */
  public function testPermissionsMintedFromPlugins(): void {
    $permissions = StudioPermissions::create($this->container)->permissions();

    $expected = [];
    foreach (self::BUILT_IN as $id) {
      if ($id !== 'general') {
        $expected[] = "use aincient studio $id";
      }
    }
    $this->assertSame($expected, array_keys($permissions));
    // General is open — it must never appear as a grantable permission.
    $this->assertArrayNotHasKey('use aincient studio general', $permissions);

    foreach ($permissions as $definition) {
      $this->assertTrue($definition['restrict access']);
    }
  }

  /**
   * A studio plugin that cannot be instantiated is dropped, not thrown.
   *
   * The console shell, the settings form and the permissions page all read
   * `studios()`, so an exception out of it is a 500 on every one of them — for
   * every user of a client's site, because of one bad class in a pack. A
   * malformed ID is already dropped-and-logged; discovery only proves a class
   * carries the attribute, so the class that does not implement
   * StudioInterface has to be dropped in the same way.
   */
  public function testUninstantiableStudioIsDroppedNotThrown(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('could not be instantiated'),
        $this->callback(static fn(array $context): bool => ($context['%id'] ?? '') === 'acme_pack'),
      );

    // A pack shipping `#[Studio(id: 'acme_pack')] class Reports {}` — a valid
    // id on a class that implements nothing. NullBackend keeps this double's
    // discovery out of the real manager's cache bin.
    $manager = new class(
      $this->container->get('container.namespaces'),
      new NullBackend('test'),
      $this->container->get('module_handler'),
      $logger,
    ) extends StudioManager {

      protected function findDefinitions(): array {
        $definitions = parent::findDefinitions();
        $definitions['acme_pack'] = [
          'id' => 'acme_pack',
          'label' => 'Reports',
          'weight' => 99,
          'open' => FALSE,
          'class' => \stdClass::class,
          'provider' => 'acme_pack',
        ];
        return $definitions;
      }

    };

    // The console still has every studio it shipped with, and not the bad one.
    $this->assertSame(self::BUILT_IN, $manager->keys());
    $this->assertNull($manager->get('acme_pack'));
    $this->assertSame('general', $manager->defaultId());
  }

}
