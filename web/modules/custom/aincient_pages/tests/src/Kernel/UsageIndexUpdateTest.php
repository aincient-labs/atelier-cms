<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\PageStore;
use Drupal\aincient_pages\UsageIndex;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * aincient_pages_update_10017 on an upgraded appliance (DECISIONS 0455).
 *
 * The appliance runs updatedb BEFORE config:import. A field the update creates
 * with a fresh UUID is a different entity to the import, which deletes it —
 * purging the backfill — and recreates it empty. The update must create the
 * fields with the UUIDs config/sync ships, so the import has nothing to do.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class UsageIndexUpdateTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  private const NAMES = [
    'field.storage.node.field_component_usage',
    'field.storage.media.field_component_usage',
    'field.field.node.aincient_page.field_component_usage',
    'field.field.media.block.field_component_usage',
  ];

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    // Saving a media entity updates its thumbnail (a file field) → file_usage.
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'media']);

    // The page node bundle + its layering field storage. A global block is a
    // `block` MEDIA entity (DECISIONS 0138) that shares the SAME field names on
    // the media entity type, so the block reuses the page layering machinery.
    if (!NodeType::load('aincient_page')) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Aincient page'])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      // Node storage (page) + media storage (block) — one field name per entity type.
      foreach (['node' => 'aincient_page', 'media' => 'block'] as $entityType => $bundle) {
        if (!FieldStorageConfig::loadByName($entityType, $name)) {
          FieldStorageConfig::create([
            'field_name' => $name,
            'entity_type' => $entityType,
            'type' => 'string_long',
            'translatable' => TRUE,
          ])->save();
        }
      }
    }
    // The block media type: source is the authored fragment (field_page_content).
    if (!MediaType::load('block')) {
      MediaType::create([
        'id' => 'block',
        'label' => 'Global block',
        'source' => 'aincient_block_fragment',
        'source_configuration' => ['source_field' => 'field_page_content'],
      ])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (!FieldConfig::loadByName('node', 'aincient_page', $name)) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => $name, 'translatable' => TRUE])->save();
      }
      if (!FieldConfig::loadByName('media', 'block', $name)) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => 'media', 'bundle' => 'block', 'label' => $name, 'translatable' => TRUE])->save();
      }
    }
    $this->setUpEditorialWorkflow(['aincient_page'], ['block']);
    // Exist BEFORE the first compile: core invalidates a config's cache tag on
    // an UPDATE save only, so a constraint first created mid-test would leave
    // the compiled catalog cached (the live site always has this config).
    $this->config('aincient_pages.site_constraint')->set('components', [])->save();
  }

  public function testUpdateKeepsTheShippedUuidsAndTheBackfill(): void {
    // A page saved by the released code: no usage field yet.
    $nid = $this->store()->store(['type' => 'landing', 'title' => 'Home', 'sections' => [
      ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hi']],
    ]]);
    $this->assertNull(FieldStorageConfig::loadByName('node', UsageIndex::FIELD));

    // The shipped config/sync copies of the four field configs.
    $shipped = new FileStorage(DRUPAL_ROOT . '/../config/sync');
    $sync = $this->container->get('config.storage.sync');
    foreach (self::NAMES as $name) {
      $data = $shipped->read($name);
      $this->assertNotFalse($data, "config/sync ships $name");
      $sync->write($name, $data);
    }

    \Drupal::moduleHandler()->loadInclude('aincient_pages', 'install');
    $sandbox = [];
    do {
      aincient_pages_update_10017($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);

    $active = $this->container->get('config.storage');
    foreach (self::NAMES as $name) {
      $this->assertSame($sync->read($name)['uuid'], $active->read($name)['uuid'], "$name keeps the shipped UUID");
    }

    // config:import would neither delete nor recreate them.
    $comparer = new StorageComparer($sync, $active);
    $comparer->createChangelist();
    $this->assertSame([], array_intersect(self::NAMES, $comparer->getChangelist('delete')));
    $this->assertSame([], array_intersect(self::NAMES, $comparer->getChangelist('create')));

    $node = Node::load($nid);
    $keys = array_column($node->get(UsageIndex::FIELD)->getValue(), 'value');
    $this->assertContains('c:hero', $keys, 'the backfill wrote the page');
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

}
