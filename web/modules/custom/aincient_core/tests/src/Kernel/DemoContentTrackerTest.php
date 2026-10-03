<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Kernel;

use Drupal\aincient_core\Demo\DemoContentTracker;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The demo-content tag: track, list, and clear only what is still there.
 *
 * Pins the "clear examples" contract (plans/studio-modules.md, DECISIONS 0430):
 * an entity the owner already deleted by hand is dropped from the table without
 * counting or erroring, every surviving tracked entity is deleted, and clearing
 * one studio leaves another studio's rows alone.
 *
 * @group aincient_core
 * @covers \Drupal\aincient_core\Demo\DemoContentTracker
 */
#[RunTestsInSeparateProcesses]
final class DemoContentTrackerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'file',
    'aincient_core',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('aincient_core', ['aincient_demo_content']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  private function tracker(): DemoContentTracker {
    return $this->container->get('aincient_core.demo_content');
  }

  private function node(string $title): Node {
    $node = Node::create(['type' => 'page', 'title' => $title]);
    $node->save();
    return $node;
  }

  public function testClearDeletesOnlyWhatStillExists(): void {
    $a = $this->node('A');
    $b = $this->node('B');
    $this->tracker()->track($a, 'a');
    $this->tracker()->track($b, 'b');

    $this->assertCount(1, $this->tracker()->tracked('a'));
    $this->assertCount(2, $this->tracker()->tracked());

    // The owner deletes B by hand; the row outlives the entity.
    $b->delete();

    $this->assertSame(1, $this->tracker()->clear());
    $this->assertSame([], $this->tracker()->tracked());
    $this->assertNull(Node::load($a->id()));
  }

  public function testClearOneStudioLeavesTheOtherAlone(): void {
    $a = $this->node('A');
    $b = $this->node('B');
    $this->tracker()->track($a, 'a');
    $this->tracker()->track($b, 'b');

    $this->assertSame(1, $this->tracker()->clear('a'));
    $this->assertNull(Node::load($a->id()));
    $this->assertNotNull(Node::load($b->id()));
    $this->assertCount(1, $this->tracker()->tracked('b'));
  }

  public function testTrackIsAnUpsert(): void {
    $a = $this->node('A');
    $this->tracker()->track($a, 'a');
    $this->tracker()->track($a, 'b');
    $this->assertSame([], $this->tracker()->tracked('a'));
    $this->assertCount(1, $this->tracker()->tracked('b'));
  }

}
