<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BlockStore;
use Drupal\aincient_pages\PageStore;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests reusable global blocks (Phase 5) + the reference-placeable clamps.
 *
 * Covers the data layer that makes "edit once, propagate everywhere" work: the
 * validator clamps an `embed` token and a `block` ref, and BlockStore round-trips
 * a fragment, exposes its sections for inline expansion, and refuses to nest a
 * block inside a block (so render-time expansion is always one level deep).
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\BlockStore
 * @covers \Drupal\aincient_pages\PageStore::validate
 */
#[RunTestsInSeparateProcesses]
final class BlockStoreTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_pages',
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
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  private function blocks(): BlockStore {
    return $this->container->get('aincient_pages.block_store');
  }

  /**
   * validate() clamps the reference placeables: a malformed embed token is
   * dropped, a well-formed one is kept (trimmed); a block ref is normalised to a
   * `block:<id>` token (a bare integer is accepted as sugar), an explicit token is
   * kept, and a junk / zero ref is dropped.
   */
  public function testValidateClampsReferenceProps(): void {
    $clean = $this->store()->validate([
      'type' => 'landing',
      'title' => 'Refs',
      'sections' => [
        ['component' => 'embed', 'props' => ['entity' => '  entity:node:15@teaser ']],
        ['component' => 'embed', 'props' => ['entity' => 'not a token']],
        ['component' => 'block', 'props' => ['ref' => '7']],
        ['component' => 'block', 'props' => ['ref' => 'nope']],
        ['component' => 'block', 'props' => ['ref' => 'block:42']],
        ['component' => 'block', 'props' => ['ref' => '0']],
      ],
    ]);

    $this->assertSame('entity:node:15@teaser', $clean['sections'][0]['props']['entity']);
    $this->assertArrayNotHasKey('entity', $clean['sections'][1]['props']);
    // Bare integer → normalised to the block:<id> token scheme.
    $this->assertSame('block:7', $clean['sections'][2]['props']['ref']);
    $this->assertArrayNotHasKey('ref', $clean['sections'][3]['props']);
    // An explicit token is kept as-is; a zero id is dropped.
    $this->assertSame('block:42', $clean['sections'][4]['props']['ref']);
    $this->assertArrayNotHasKey('ref', $clean['sections'][5]['props']);
  }

  /**
   * A block round-trips through store + load, and resolveSections exposes its
   * sections for inline expansion on a host page.
   */
  public function testBlockRoundTripAndResolveSections(): void {
    $id = $this->blocks()->store([
      'title' => 'Footer CTA',
      'sections' => [
        ['component' => 'cta', 'props' => ['heading' => 'Join us', 'cta_label' => 'Sign up', 'cta_url' => '/signup']],
      ],
    ]);
    $this->assertNotSame('', $id);

    $loaded = $this->blocks()->load($id);
    $this->assertSame('Footer CTA', $loaded['title']);
    $this->assertCount(1, $loaded['sections']);
    $this->assertSame('cta', $loaded['sections'][0]['component']);

    $sections = $this->blocks()->resolveSections($id);
    $this->assertCount(1, $sections);
    $this->assertSame('Join us', $sections[0]['props']['heading']);
  }

  /**
   * A block may not contain a `block` slot — nesting is stripped on write, so
   * render-time expansion can never recurse.
   */
  public function testBlockStripsNestedBlocks(): void {
    $id = $this->blocks()->store([
      'title' => 'Nested?',
      'sections' => [
        ['component' => 'hero', 'props' => ['heading' => 'Top']],
        ['component' => 'block', 'props' => ['ref' => '999']],
      ],
    ]);
    $sections = $this->blocks()->resolveSections($id);
    $this->assertCount(1, $sections);
    $this->assertSame('hero', $sections[0]['component']);
  }

  /**
   * load()/resolveSections refuse a non-block node; resolveSections of a missing
   * block is an empty list (a dangling reference renders nothing).
   */
  public function testBlockStoreRejectsForeignAndMissing(): void {
    $pageId = $this->store()->store(['type' => 'landing', 'title' => 'A page', 'sections' => []]);
    $this->assertNull($this->blocks()->load($pageId));
    $this->assertSame([], $this->blocks()->resolveSections($pageId));
    $this->assertSame([], $this->blocks()->resolveSections('999999'));
  }

  /**
   * The block directory lists saved blocks (newest first) for the picker.
   */
  public function testListReturnsSavedBlocks(): void {
    $a = $this->blocks()->store(['title' => 'Alpha', 'sections' => []]);
    $b = $this->blocks()->store(['title' => 'Beta', 'sections' => []]);
    $list = $this->blocks()->list();
    $ids = array_column($list, 'id');
    $this->assertContains($a, $ids);
    $this->assertContains($b, $ids);
    $titles = array_column($list, 'title');
    $this->assertContains('Alpha', $titles);
  }

}
