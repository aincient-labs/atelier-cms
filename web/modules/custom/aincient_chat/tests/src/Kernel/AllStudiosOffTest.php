<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Studio\StudioManager;
use Drupal\aincient_chat\Studio\StudioSwitch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * "With every studio off, the site still installs, renders and exports."
 *
 * The folder rule's acceptance test (plans/studio-modules.md "Guards",
 * DECISIONS 0430): `custom/` is exactly what the product needs with every studio
 * switched off, so switching them all off must leave the engine standing.
 *
 * WHAT THIS PROVES, AND WHAT IT DOES NOT. It proves that the switch reaches
 * every studio the manager knows (built-ins and the fixture), that core config
 * still installs with the switch fully thrown, that a page node still renders
 * through the entity view builder, and that the static-export path inventory
 * still enumerates. It does NOT prove the front-end shell degrades gracefully or
 * that a real studio MODULE can be uninstalled — no studio module exists yet,
 * and the on/off switch is config, not uninstall. When studio modules land, the
 * set it walks grows with them and the test keeps its meaning.
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Studio\StudioSwitch
 */
#[RunTestsInSeparateProcesses]
final class AllStudiosOffTest extends KernelTestBase {

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
    'file',
    'image',
    'path_alias',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
    'aincient_export',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'node', 'filter']);
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }

    $manager = $this->container->get('plugin.manager.aincient.studios');
    $this->assertInstanceOf(StudioManager::class, $manager);
    $switch = $this->container->get('aincient_chat.studio_switch');
    $this->assertInstanceOf(StudioSwitch::class, $switch);
    // Every studio that CAN be switched off. General and Settings are pinned
    // on (StudioSwitch::ALWAYS_ON): the landing workspace + fallback, and the
    // room the switches live in — so "all studios off" means "everything but
    // the floor" (DECISIONS 0439).
    foreach ($manager->keys() as $id) {
      if (!in_array($id, StudioSwitch::ALWAYS_ON, TRUE)) {
        $switch->setEnabled($id, FALSE);
      }
    }
  }

  public function testEveryStudioIsSwitchedOff(): void {
    $keys = $this->container->get('plugin.manager.aincient.studios')->keys();
    $this->assertNotEmpty($keys);
    $switch = $this->container->get('aincient_chat.studio_switch');
    foreach ($keys as $id) {
      if (in_array($id, StudioSwitch::ALWAYS_ON, TRUE)) {
        $this->assertTrue($switch->isEnabled($id), "Studio $id is pinned on.");
        continue;
      }
      $this->assertFalse($switch->isEnabled($id), "Studio $id should be off.");
    }
  }

  public function testPagesStillRender(): void {
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Still here', 'status' => 1]);
    $node->save();

    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'full');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertNotSame('', trim($html));
    // No display config is installed in a kernel site, so the title is not
    // shown; the node template's own wrapper is what proves a render happened.
    $this->assertStringContainsString('<article', $html);
  }

  public function testExportInventoryStillEnumerates(): void {
    $node = Node::create(['type' => 'aincient_page', 'title' => 'Exported', 'status' => 1]);
    $node->save();

    $paths = $this->container->get('aincient_export.path_inventory')->collect();
    $this->assertContains('/', $paths);
    $this->assertContains('/node/' . $node->id(), $paths);
  }

}
