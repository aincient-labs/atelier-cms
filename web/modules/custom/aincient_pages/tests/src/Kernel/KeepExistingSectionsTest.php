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
 * Narrowing the catalog keeps existing sections (DECISIONS 0455, P0).
 *
 * A component, variant or tone the site or a kind stops offering is refused as
 * a NEW placement, but a slot that already uses it survives every later save
 * (studio save, agent ops) and still renders — on pages and in blocks.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\PageStore::validate
 * @covers \Drupal\aincient_pages\PageStore::applyOps
 */
#[RunTestsInSeparateProcesses]
final class KeepExistingSectionsTest extends KernelTestBase {

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
  }

  private function store(): PageStore {
    return $this->container->get('aincient_pages.store');
  }

  private function blocks(): BlockStore {
    return $this->container->get('aincient_pages.block_store');
  }

  /**
   * Re-compile the catalog after a constraint/kind change.
   */
  private function narrowSite(array $constraint): void {
    $config = $this->config('aincient_pages.site_constraint');
    foreach ($constraint as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
    $this->container->get('aincient_pages.catalog')->reset();
  }

  private function page(): string {
    return $this->store()->store([
      'type' => 'landing',
      'title' => 'Home',
      'sections' => [
        ['component' => 'hero', 'props' => ['variant' => 'split', 'tone' => 'inverted', 'heading' => 'Hi']],
        ['component' => 'newsletter', 'props' => ['heading' => 'Stay in touch']],
      ],
    ]);
  }

  /**
   * Site narrowing: the stored newsletter slot survives a studio save, keeps
   * its id + copy, and still reaches the renderer; a NEW newsletter is dropped.
   */
  public function testSiteNarrowedComponentSurvivesSave(): void {
    $id = $this->page();
    $this->narrowSite(['components' => ['newsletter']]);

    $schema = $this->store()->load($id);
    $slot = $schema['sections'][1]['id'];
    $schema['sections'][1]['props']['heading'] = 'Still here';
    // A fresh newsletter (no stored slot id) is a new placement — refused.
    $schema['sections'][] = ['component' => 'newsletter', 'props' => ['heading' => 'New']];
    $this->assertTrue($this->store()->update($id, $schema));

    $saved = $this->store()->load($id);
    $this->assertCount(2, $saved['sections']);
    $this->assertSame($slot, $saved['sections'][1]['id']);
    $this->assertSame('newsletter', $saved['sections'][1]['component']);
    $this->assertSame('Still here', $saved['sections'][1]['props']['heading']);
  }

  /**
   * The studio PREVIEW of an open page validates against its stored head's
   * keep set, so a kept slot previews exactly as it will save — without it the
   * preview silently dropped the section (seen live).
   */
  public function testPreviewKeepSetOfTheStoredHead(): void {
    $id = $this->page();
    $this->narrowSite(['components' => ['newsletter']]);
    $schema = $this->store()->load($id);

    $this->assertCount(1, $this->store()->validate($schema)['sections'], 'Without a keep set the kept slot is a new placement.');
    $head = $this->container->get('aincient_pages.moderation')->loadHead($id, 'aincient_page');
    $kept = $this->store()->validate($schema, $this->store()->keepSetOf($head));
    $this->assertSame(['hero', 'newsletter'], array_column($kept['sections'], 'component'));
  }

  /**
   * A kept slot cannot be smuggled into a NEW slot id, and a conversion of a
   * kept slot to another retired component is refused like any new placement.
   */
  public function testKeptSlotIsNotANewPlacementLicence(): void {
    $id = $this->page();
    $this->narrowSite(['components' => ['newsletter', 'pricing']]);
    $schema = $this->store()->load($id);
    // Same id, different (retired) component: a conversion → refused.
    $schema['sections'][1]['component'] = 'pricing';
    $this->store()->update($id, $schema);
    $saved = $this->store()->load($id);
    $this->assertCount(1, $saved['sections']);
    $this->assertSame('hero', $saved['sections'][0]['component']);
  }

  /**
   * Variant + tone: a stored value the site no longer offers is kept on its
   * slot across a save; the same value on a NEW slot is clamped.
   */
  public function testNarrowedVariantAndToneAreKept(): void {
    $id = $this->page();
    $this->narrowSite(['variants' => ['hero' => ['split']], 'tones' => ['inverted']]);

    $schema = $this->store()->load($id);
    $schema['sections'][] = ['component' => 'hero', 'props' => ['variant' => 'split', 'tone' => 'inverted', 'heading' => 'New']];
    $this->store()->update($id, $schema);

    $saved = $this->store()->load($id);
    $this->assertSame('split', $saved['sections'][0]['props']['variant']);
    $this->assertSame('inverted', $saved['sections'][0]['props']['tone']);
    $this->assertSame('centered', $saved['sections'][2]['props']['variant']);
    $this->assertArrayNotHasKey('tone', $saved['sections'][2]['props']);
  }

  /**
   * Kind narrowing (a composition kind that allows only hero + cta): a stored
   * newsletter slot on a page of that kind is kept; add_section of it refused.
   */
  public function testKindNarrowedComponentSurvivesOps(): void {
    $this->container->get('entity_type.manager')->getStorage('page_kind')->create([
      'id' => 'campaign',
      'label' => 'Campaign page',
      'mode' => 'composition',
      'hint' => 'Promo.',
      'components' => ['hero' => [], 'newsletter' => []],
    ])->save();
    $this->container->get('aincient_pages.catalog')->reset();
    $id = $this->store()->store([
      'type' => 'campaign',
      'title' => 'Promo',
      'sections' => [
        ['component' => 'hero', 'props' => ['heading' => 'Hi']],
        ['component' => 'newsletter', 'props' => ['heading' => 'Join']],
      ],
    ]);

    $kind = $this->container->get('entity_type.manager')->getStorage('page_kind')->load('campaign');
    $kind->set('components', ['hero' => []])->save();
    $this->container->get('aincient_pages.catalog')->reset();

    $schema = $this->store()->load($id);
    $slot = $schema['sections'][1]['id'];
    $result = $this->store()->applyOps($schema, [
      ['op' => 'update_section', 'id' => $slot, 'props' => ['heading' => 'Edited']],
      ['op' => 'add_section', 'component' => 'newsletter', 'props' => ['heading' => 'Again']],
    ]);
    $this->assertCount(1, $result['rejected']);
    $this->assertSame('add_section', $result['rejected'][0]['op']);
    $this->assertCount(2, $result['schema']['sections']);
    $this->assertSame('Edited', $result['schema']['sections'][1]['props']['heading']);

    $this->store()->update($id, $result['schema']);
    $saved = $this->store()->load($id);
    $this->assertSame('newsletter', $saved['sections'][1]['component']);
  }

  /**
   * A block's stored slot survives a save after its component is narrowed out.
   */
  public function testBlockKeepsNarrowedSlot(): void {
    $id = $this->blocks()->store([
      'title' => 'Footer',
      'sections' => [
        ['component' => 'newsletter', 'props' => ['heading' => 'Join']],
      ],
    ]);
    $this->narrowSite(['components' => ['newsletter']]);
    $schema = $this->blocks()->load($id);
    $schema['sections'][0]['props']['heading'] = 'Join us';
    $this->assertTrue($this->blocks()->update($id, $schema));
    $sections = $this->blocks()->resolveSections($id);
    $this->assertCount(1, $sections);
    $this->assertSame('newsletter', $sections[0]['component']);
  }

  /**
   * The built-in block kind (DECISIONS 0455, P1b): a block validates under it
   * whatever its schema says, a page can never be born as one, and narrowing
   * the block kind governs block contents (not the host page's kind).
   */
  public function testBlockKindGovernsBlocksAndIsNeverAPageType(): void {
    $page = $this->store()->store(['type' => 'block', 'title' => 'Sneaky', 'sections' => []]);
    $this->assertSame('landing', $this->store()->load($page)['type']);

    $id = $this->blocks()->store(['type' => 'landing', 'title' => 'Footer', 'sections' => [
      ['component' => 'cta', 'props' => ['heading' => 'Join']],
    ]]);
    $this->assertSame('block', $this->blocks()->load($id)['type']);

    // The block kind denies newsletter: a NEW newsletter in a block is
    // refused, while a landing page still takes one.
    $this->container->get('entity_type.manager')->getStorage('page_kind')->create([
      'id' => 'block', 'label' => 'Block', 'mode' => 'composition', 'fragment' => TRUE, 'removed' => ['newsletter'],
    ])->save();
    $this->container->get('aincient_pages.catalog')->reset();
    $schema = $this->blocks()->load($id);
    $schema['sections'][] = ['component' => 'newsletter', 'props' => ['heading' => 'News']];
    $this->blocks()->update($id, $schema);
    $this->assertCount(1, $this->blocks()->resolveSections($id));
    $this->assertContains('newsletter', $this->container->get('aincient_pages.catalog')->for('landing')->placeableNames());
  }

}
