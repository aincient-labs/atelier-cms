<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Studio\StudioDemoContent;
use Drupal\aincient_chat\Studio\StudioSwitch;
use Drupal\aincient_core\Demo\DemoContentTracker;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A studio's demo content: imported ONCE on first enable, cleared on demand.
 *
 * The fixture studio `studio_demo_test` ships two pages under `content/demo/`.
 * What is asserted is the contract an owner feels: switching the studio on
 * seeds its examples and tags them; switching it off and on again does NOT
 * bring back examples that were cleared; a config sync never seeds; a file
 * that fails rolls the whole studio back; and the install hook seeds a studio
 * that ships on (plans/studio-modules.md "Demo content", DECISIONS 0441).
 *
 * @group aincient
 * @covers \Drupal\aincient_chat\Studio\StudioDemoContent
 * @covers \Drupal\aincient_chat\EventSubscriber\StudioDemoSubscriber
 */
#[RunTestsInSeparateProcesses]
final class StudioDemoContentTest extends KernelTestBase {

  private const ID = 'studio_demo_test';

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
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
    'aincient_studio_demo_test',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('aincient_core', ['aincient_demo_content']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  private function demo(): StudioDemoContent {
    return $this->container->get('aincient_chat.studio_demo_content');
  }

  private function tracker(): DemoContentTracker {
    return $this->container->get('aincient_core.demo_content');
  }

  private function switch(): StudioSwitch {
    return $this->container->get('aincient_chat.studio_switch');
  }

  /**
   * @return list<string>
   */
  private function titles(): array {
    $titles = array_map(fn(Node $n) => $n->label(), Node::loadMultiple());
    sort($titles);
    return array_values($titles);
  }

  /**
   * The manifest's `demo:` is what makes a studio ship examples.
   */
  public function testTheManifestSaysWhoShips(): void {
    $this->assertTrue($this->demo()->ships(self::ID));
    $this->assertFalse($this->demo()->ships('general'));
    $this->assertFalse($this->demo()->ships('no_such_studio'));
    $this->assertSame(0, $this->demo()->import('general'), 'a studio without demo content imports nothing');
  }

  /**
   * Switching the studio on for the first time seeds and tags its examples.
   */
  public function testFirstEnableImportsAndTags(): void {
    $this->switch()->setEnabled(self::ID, FALSE);
    $this->assertSame([], Node::loadMultiple(), 'switching OFF seeds nothing');

    $this->switch()->setEnabled(self::ID, TRUE);
    $this->assertSame(['Example: the first page', 'Example: the second page'], $this->titles());
    $this->assertTrue($this->demo()->imported(self::ID));
    $tracked = $this->tracker()->tracked(self::ID);
    $this->assertCount(2, $tracked);
    $this->assertSame('node', $tracked[0]['entity_type']);
  }

  /**
   * Once means once: cleared examples do not return on the next enable, and
   * an explicit import without --force is a no-op; --force seeds again.
   */
  public function testOnceMeansOnce(): void {
    $this->assertSame(2, $this->demo()->import(self::ID));
    $this->assertSame(0, $this->demo()->import(self::ID), 'a second import is skipped');
    $this->assertCount(2, Node::loadMultiple());

    $this->assertSame(2, $this->tracker()->clear(self::ID));
    $this->assertSame([], Node::loadMultiple());
    $this->assertTrue($this->demo()->imported(self::ID), 'clearing does not re-arm the import');

    $this->switch()->setEnabled(self::ID, FALSE);
    $this->switch()->setEnabled(self::ID, TRUE);
    $this->assertSame([], Node::loadMultiple(), 'off → on after a clear brings nothing back');

    $this->assertSame(2, $this->demo()->import(self::ID, force: TRUE));
    $this->assertCount(2, Node::loadMultiple());

    $this->demo()->forget(self::ID);
    $this->assertFalse($this->demo()->imported(self::ID));
  }

  /**
   * A config sync is the appliance converging, not an operator opening a
   * room: the switch flipping on during one seeds nothing.
   */
  public function testConfigSyncNeverSeeds(): void {
    $this->switch()->setEnabled(self::ID, FALSE);
    $installer = $this->container->get('config.installer');
    $installer->setSyncing(TRUE);
    try {
      $this->switch()->setEnabled(self::ID, TRUE);
    }
    finally {
      $installer->setSyncing(FALSE);
    }
    $this->assertSame([], Node::loadMultiple());
    $this->assertFalse($this->demo()->imported(self::ID));
  }

  /**
   * The install hook is the first enable of a studio that ships ON.
   */
  public function testInstallHookSeedsAStudioThatShipsOn(): void {
    $this->container->get('module_handler')->invoke('aincient_chat', 'modules_installed', [['aincient_studio_demo_test'], FALSE]);
    $this->assertCount(2, Node::loadMultiple());
    $this->assertTrue($this->switch()->isEnabled(self::ID));

    // Running it again (a reinstall) seeds nothing more.
    $this->container->get('module_handler')->invoke('aincient_chat', 'modules_installed', [['aincient_studio_demo_test'], FALSE]);
    $this->assertCount(2, Node::loadMultiple());
  }

  /**
   * A file that fails rolls the studio's whole import back and leaves it
   * un-imported, so nothing is half-seeded and a retry starts clean.
   */
  public function testAFailingFileRollsTheStudioBack(): void {
    $this->enableModules(['aincient_studio_demo_bad_test']);
    $this->container->get('plugin.manager.aincient.studios')->clearCachedDefinitions();
    $id = 'studio_demo_bad_test';
    $this->assertTrue($this->demo()->ships($id));
    $this->assertSame(0, $this->demo()->import($id));
    $this->assertSame([], Node::loadMultiple(), 'the good file before the bad one was rolled back');
    $this->assertSame([], $this->tracker()->tracked($id));
    $this->assertFalse($this->demo()->imported($id));
  }

}
