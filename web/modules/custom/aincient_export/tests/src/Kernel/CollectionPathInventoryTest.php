<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The path inventory enumerates each index-mode collection's JSON + archive
 * routes (DECISIONS 0329) — the export's guarantee that every hash the route
 * serves is a hash the exporter writes.
 *
 * Cross-module by design (aincient_export ← aincient_pages, optional @?): this
 * is the integration the base PathInventoryTest can't cover because it doesn't
 * enable aincient_pages.
 *
 * @coversDefaultClass \Drupal\aincient_export\PathInventory
 * @group aincient_export
 */
#[RunTestsInSeparateProcesses]
final class CollectionPathInventoryTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'file', 'image',
    'path_alias', 'workflows', 'content_moderation', 'aincient_core',
    'aincient_pages', 'aincient_export',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installEntitySchema('path_alias');
    // Url::fromRoute() in the inventory needs the aincient_pages routes dumped.
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    FieldStorageConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'type' => 'string_long', 'translatable' => TRUE])->save();
    FieldConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Structure', 'translatable' => TRUE])->save();
    FieldStorageConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'type' => 'string', 'translatable' => FALSE])->save();
    FieldConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Page type', 'translatable' => FALSE])->save();
  }

  /**
   * @covers ::collect
   */
  public function testCollectEnumeratesCollectionJsonAndArchivePaths(): void {
    Node::create([
      'type' => 'aincient_page', 'title' => 'Blog index', 'status' => 1, 'field_page_type' => 'landing',
      'field_page_structure' => json_encode([
        'type' => 'landing',
        'slots' => [
          ['id' => 'aaaa', 'component' => 'collection', 'mode' => 'index', 'source' => 'blog', 'sort' => 'newest'],
          // A strip contributes NO JSON/archive path.
          ['id' => 'bbbb', 'component' => 'collection', 'mode' => 'strip', 'source' => 'blog', 'sort' => 'newest'],
        ],
      ]),
    ])->save();

    $hash = $this->container->get('aincient_pages.collection_inventory')
      ->specHash(['source' => 'blog', 'sort' => 'newest']);
    $paths = $this->container->get('aincient_export.path_inventory')->collect();

    $this->assertContains('/_data/collections/' . $hash . '.json', $paths);
    $this->assertContains('/collections/' . $hash . '/archive', $paths);
    // Exactly one of each — the strip is not enumerated, and the pair is deduped.
    $this->assertCount(1, array_filter($paths, static fn ($p) => str_contains($p, '/_data/collections/')));
    $this->assertCount(1, array_filter($paths, static fn ($p) => str_ends_with($p, '/archive')));
  }

}
