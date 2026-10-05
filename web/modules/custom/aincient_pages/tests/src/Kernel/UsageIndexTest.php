<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BlockStore;
use Drupal\aincient_pages\PageStore;
use Drupal\aincient_pages\UsageIndex;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The derived component-usage index (DECISIONS 0455, P1): PageStore writes
 * c:/v:/t: keys on every save of a page or block; UsageIndex counts the
 * LATEST revision per entity and lists where a key is used.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\UsageIndex
 */
#[RunTestsInSeparateProcesses]
final class UsageIndexTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

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
    // The derived usage index (DECISIONS 0455) — distribution config, built by hand.
    foreach (['node' => 'aincient_page', 'media' => 'block'] as $entityType => $bundle) {
      FieldStorageConfig::create([
        'field_name' => UsageIndex::FIELD,
        'entity_type' => $entityType,
        'type' => 'string',
        'cardinality' => -1,
        'translatable' => FALSE,
      ])->save();
      FieldConfig::create(['field_name' => UsageIndex::FIELD, 'entity_type' => $entityType, 'bundle' => $bundle, 'label' => 'Usage'])->save();
    }
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  private function blocks(): BlockStore {
    return $this->container->get('aincient_pages.block_store');
  }

  private function index(): UsageIndex {
    return $this->container->get('aincient_pages.usage_index');
  }

  public function testKeysAreDerivedFromTheSchema(): void {
    $keys = UsageIndex::keys(['sections' => [
      ['component' => 'hero', 'props' => ['variant' => 'split', 'tone' => 'inverted']],
      ['component' => 'hero', 'props' => ['variant' => 'split']],
      ['component' => 'cta', 'props' => []],
    ]]);
    sort($keys);
    $this->assertSame(['c:cta', 'c:hero', 't:hero:inverted', 'v:hero:split'], $keys);
  }

  public function testCountsPagesAndBlocksAtTheLatestRevision(): void {
    $a = $this->store()->store(['type' => 'landing', 'title' => 'Home', 'sections' => [
      ['component' => 'hero', 'props' => ['variant' => 'split', 'heading' => 'Hi']],
      ['component' => 'cta', 'props' => ['heading' => 'Go']],
    ]]);
    $this->store()->store(['type' => 'landing', 'title' => 'About', 'sections' => [
      ['component' => 'hero', 'props' => ['variant' => 'centered', 'heading' => 'About']],
    ]]);
    $this->blocks()->store(['title' => 'Footer CTA', 'sections' => [
      ['component' => 'cta', 'props' => ['heading' => 'Join']],
    ]]);

    $counts = $this->index()->counts();
    $this->assertSame(['pages' => 2, 'blocks' => 0], $counts['c:hero']);
    $this->assertSame(['pages' => 1, 'blocks' => 1], $counts['c:cta']);
    $this->assertSame(1, $counts['v:hero:split']['pages']);

    // A forward DRAFT that drops the cta: the latest revision counts.
    $schema = $this->store()->load($a);
    array_pop($schema['sections']);
    $this->store()->saveDraft($schema, $a);
    $counts = $this->index()->counts();
    $this->assertSame(['pages' => 0, 'blocks' => 1], $counts['c:cta'] ?? NULL);

    $where = $this->index()->where('c:hero');
    $this->assertSame(2, $where['total']);
    $titles = array_column($where['items'], 'title');
    sort($titles);
    $this->assertSame(['About', 'Home'], $titles);
    $this->assertSame('block', $this->index()->where('c:cta')['items'][0]['type']);
  }

}
