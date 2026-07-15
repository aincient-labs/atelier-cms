<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @coversDefaultClass \Drupal\aincient_export\PathInventory
 * @group aincient_export
 */
#[RunTestsInSeparateProcesses]
final class PathInventoryTest extends KernelTestBase {

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
    'image',
    'path_alias',
    'aincient_export',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['system', 'node', 'filter']);
    NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
  }

  /**
   * @covers ::collect
   */
  public function testCollect(): void {
    $published = Node::create(['type' => 'aincient_page', 'title' => 'Published', 'status' => 1]);
    $published->save();
    PathAlias::create(['path' => '/node/' . $published->id(), 'alias' => '/pages/published'])->save();

    $unpublished = Node::create(['type' => 'aincient_page', 'title' => 'Draft', 'status' => 0]);
    $unpublished->save();

    $unaliased = Node::create(['type' => 'aincient_page', 'title' => 'No alias', 'status' => 1]);
    $unaliased->save();

    $paths = $this->container->get('aincient_export.path_inventory')->collect();

    $this->assertContains('/', $paths);
    $this->assertContains('/pages/published', $paths);
    $this->assertContains('/node/' . $unaliased->id(), $paths);
    $this->assertNotContains('/node/' . $unpublished->id(), $paths);

    // The configured front page is deduplicated — it already exports as "/".
    $this->config('system.site')->set('page.front', '/pages/published')->save();
    $paths = $this->container->get('aincient_export.path_inventory')->collect();
    $this->assertContains('/', $paths);
    $this->assertNotContains('/pages/published', $paths);
  }

}
