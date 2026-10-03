<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Chat\WorkflowCatalog;
use Drupal\aincient_chat\Controller\ConsoleController;
use Drupal\aincient_chat\Studio\StudioSwitch;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A switched-off studio is OFF everywhere the server decides, not just hidden.
 *
 * DECISIONS 0430 (plans/studio-modules.md "On / off"): a studio is switched
 * off by config, never by uninstall, so its module, routes, permission and
 * capability plugins all still exist. Four places must each say no:
 *
 * 1. its routes 403, even for a user who holds the studio's permission;
 * 2. its OWNED capabilities are refused at dispatch — the one that matters
 *    most, because a turn in another studio could otherwise still call them;
 * 3. the console shell omits it (catalog, access list);
 * 4. the workflow catalog drops its flows.
 *
 * Exercised through the fixture `aincient_studio_test` module: a
 * manifest-only studio `studio_test` owning `aincient_studio_test:ping` and
 * the route `aincient_studio_test.ping`, gated by its derived permission only.
 * And the regression the other way: with the switch absent, every built-in
 * studio is still on.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Access\StudioEnabledAccessCheck
 * @covers \Drupal\aincient_chat\Routing\StudioRouteSubscriber
 * @covers \Drupal\aincient_chat\Studio\StudioCapabilityGate
 * @covers \Drupal\aincient_core\Capability\CapabilityGates
 * @covers \Drupal\aincient_chat\Chat\WorkflowCatalog
 * @covers \Drupal\aincient_chat\Controller\ConsoleController
 */
#[RunTestsInSeparateProcesses]
final class StudioDisabledTest extends KernelTestBase {

  use UserCreationTrait;

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
    // `design_system`, `media`, `globals` and `content` are studio MODULES now
    // (Phase E, DECISIONS 0432 / 0434 / 0435 / 0436); the built-in list below
    // still names them, so all four must be on.
    'aincient_brand',
    'aincient_studio_content',
    'aincient_studio_media',
    'aincient_studio_site',
    'aincient_studio_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The initial build only: the enabled requirement is static and the check
    // reads config, so flipping the switch must NOT need another rebuild.
    $this->container->get('router.builder')->rebuild();
  }

  private function switch(): StudioSwitch {
    return $this->container->get('aincient_chat.studio_switch');
  }

  /**
   * The studio's route 403s while it is off — permission held or not.
   */
  public function testRouteIsForbiddenWhileDisabled(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('aincient_studio_test.ping');
    $this->assertSame('studio_test', $route->getRequirement('_aincient_studio_enabled'), 'The subscriber hangs the switch off the derived permission.');
    $this->assertSame('use aincient studio studio_test', $route->getRequirement('_permission'), 'The permission requirement is left as it was.');

    $member = $this->createUser(['use aincient studio studio_test']);
    $outsider = $this->createUser([]);
    $access = $this->container->get('access_manager');

    $this->assertTrue($access->checkNamedRoute('aincient_studio_test.ping', [], $member));
    $this->assertFalse($access->checkNamedRoute('aincient_studio_test.ping', [], $outsider));

    $this->switch()->setEnabled('studio_test', FALSE);
    $this->assertFalse($access->checkNamedRoute('aincient_studio_test.ping', [], $member), 'Permission AND enabled: off wins over a held permission.');
    $this->assertFalse($access->checkNamedRoute('aincient_studio_test.ping', [], $outsider));

    $result = $access->checkNamedRoute('aincient_studio_test.ping', [], $member, TRUE);
    $this->assertContains('config:aincient_chat.settings', $result->getCacheTags(), 'Flipping the switch invalidates a cached access result.');

    $this->switch()->setEnabled('studio_test', TRUE);
    $this->assertTrue($access->checkNamedRoute('aincient_studio_test.ping', [], $member), 'Back on, without a router rebuild.');
  }

  /**
   * The studio's OWNED capability is refused at dispatch; core verbs never are.
   */
  public function testOwnedCapabilityIsRefusedWhileDisabled(): void {
    $gates = $this->container->get('aincient_core.capability_gates');

    $this->assertNull($gates->refusal('aincient_studio_test:ping'));
    $this->assertNull($gates->refusal('aincient_pages:list_pages'), 'A core verb is in no manifest.');

    $this->switch()->setEnabled('studio_test', FALSE);
    // Same gate instance: ownership is mapped once per request, but the switch
    // is read on every call, so a flip mid-request is honoured.
    $refusal = $gates->refusal('aincient_studio_test:ping');
    $this->assertIsString($refusal);
    $this->assertStringContainsString('Studio test', $refusal, 'The model is told WHICH studio is off.');
    $this->assertStringContainsString('aincient_studio_test:ping', $refusal);
    $this->assertNull($gates->refusal('aincient_pages:list_pages'), 'Switching a studio off never refuses a shared core verb.');
    $this->assertNull($gates->refusal('aincient_nope:unknown'), 'An unowned id is not this gate\'s business.');
  }

  /**
   * The console shell omits a switched-off studio from its access list.
   */
  public function testShellOmitsDisabledStudio(): void {
    $this->setCurrentUser($this->createUser(['use aincient studio studio_test']));

    $this->assertContains('studio_test', $this->shell('studioAccess'));
    $this->switch()->setEnabled('studio_test', FALSE);
    $this->assertNotContains('studio_test', $this->shell('studioAccess'));
  }

  /**
   * The workflow catalog drops a switched-off studio's flows.
   */
  public function testWorkflowCatalogDropsDisabledStudio(): void {
    $this->config('aincient_chat.settings')
      ->set('studios', [
        'general' => ['agents' => ['op'], 'default' => 'op'],
        'studio_test' => ['agents' => ['ping_agent'], 'default' => 'ping_agent'],
      ])
      ->set('default_studio', 'studio_test')
      ->save();

    $catalog = $this->catalog(['op', 'ping_agent']);
    $this->assertSame(['general', 'studio_test'], array_keys($catalog->studios()));
    $this->assertSame('ping_agent', $catalog->resolve('ping_agent'));

    $this->switch()->setEnabled('studio_test', FALSE);
    $catalog = $this->catalog(['op', 'ping_agent']);
    $this->assertSame(['general'], array_keys($catalog->studios()));
    $this->assertSame('op', $catalog->resolve('ping_agent'), 'A disabled studio\'s flow no longer passes the POST gate.');
    $this->assertSame('general', $catalog->defaultStudio(), 'A disabled studio is never the default.');
  }

  /**
   * THE REGRESSION: with no switch config, every built-in studio stays on.
   */
  public function testBuiltInsStayOnWithFreshConfig(): void {
    $this->assertSame([], $this->switch()->disabled(), 'Fresh config switches nothing off.');

    $builtIn = ['general', 'design_system', 'globals', 'content', 'media'];
    $studios = [];
    foreach ($builtIn as $key) {
      $studios[$key] = ['agents' => [$key . '_agent'], 'default' => $key . '_agent'];
    }
    $this->config('aincient_chat.settings')->set('studios', $studios)->save();

    $catalog = $this->catalog(array_map(static fn(string $k): string => $k . '_agent', $builtIn));
    $this->assertSame($builtIn, array_keys($catalog->studios()));

    $gates = $this->container->get('aincient_core.capability_gates');
    foreach (array_keys($this->container->get('plugin.manager.aincient.capabilities')->getDefinitions()) as $id) {
      $this->assertNull($gates->refusal((string) $id), "$id is allowed while nothing is switched off.");
    }
  }

  /**
   * A workflow catalog over the real config, switch and studio set, with the
   * flowdrop_workflow storage stubbed (flowdrop is not installable in a kernel
   * test, and which flows exist is not what is under test).
   *
   * @param list<string> $ids
   *   The workflow ids that exist.
   */
  private function catalog(array $ids): WorkflowCatalog {
    $entities = [];
    foreach ($ids as $id) {
      $entity = $this->createMock(EntityInterface::class);
      $entity->method('id')->willReturn($id);
      $entity->method('label')->willReturn($id);
      $entities[$id] = $entity;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($entities);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('hasDefinition')->with('flowdrop_workflow')->willReturn(TRUE);
    $etm->method('getStorage')->with('flowdrop_workflow')->willReturn($storage);

    return new WorkflowCatalog(
      $this->container->get('config.factory'),
      $etm,
      $this->container->get('aincient_core.capability_verbs'),
      $this->container->get('plugin.manager.aincient.studios'),
      $this->container->get('aincient_chat.studio_switch'),
    );
  }

  /**
   * Invoke one of the console controller's private shell builders.
   *
   * @return list<string>
   */
  private function shell(string $method): array {
    $reflection = new \ReflectionMethod(ConsoleController::class, $method);
    return (array) $reflection->invoke(ConsoleController::create($this->container));
  }

}
