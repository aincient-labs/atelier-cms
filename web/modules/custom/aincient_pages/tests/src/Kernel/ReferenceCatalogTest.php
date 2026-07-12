<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BlockStore;
use Drupal\aincient_pages\Reference\ReferenceCatalog;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the unified reference catalog — the one SEARCH + DESCRIBE over media,
 * nodes and blocks behind the studio's ReferenceField and the agent find tool.
 *
 * @group aincient
 * @covers \Drupal\aincient_pages\Reference\ReferenceCatalog
 */
#[RunTestsInSeparateProcesses]
final class ReferenceCatalogTest extends KernelTestBase {

  use EditorialWorkflowTestTrait;

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_pages',
  ];

  private int $mediaId;
  private int $nodeId;
  private string $blockId;
  private int $userId;
  private int $brandRevId;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installEntitySchema('aincient_brand_revision');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'media']);

    // Image media type + a published media entity with alt text.
    MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    FieldStorageConfig::create(['field_name' => 'field_media_image', 'entity_type' => 'media', 'type' => 'image'])->save();
    FieldConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'bundle' => 'image',
      'label' => 'Image',
      'settings' => ['alt_field' => TRUE],
    ])->save();
    ImageStyle::create(['name' => 'media_library', 'label' => 'Media library'])->save();

    file_put_contents('public://falcon.png', base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => 'public://falcon.png', 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Falcon',
      'status' => 1,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'A peregrine falcon'],
    ]);
    $media->save();
    $this->mediaId = (int) $media->id();

    // An embeddable article node.
    if (!NodeType::load('article')) {
      NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    }
    // The global block is a `block` MEDIA entity (DECISIONS 0138) carrying the
    // page layering fields on the media entity type; its source is the authored
    // content fragment.
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (!FieldStorageConfig::loadByName('media', $name)) {
        FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'media', 'type' => 'string_long', 'translatable' => TRUE])->save();
      }
    }
    if (!MediaType::load('block')) {
      MediaType::create([
        'id' => 'block',
        'label' => 'Global block',
        'source' => 'aincient_block_fragment',
        'source_configuration' => ['source_field' => 'field_page_content'],
      ])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (!FieldConfig::loadByName('media', 'block', $name)) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => 'media', 'bundle' => 'block', 'label' => $name, 'translatable' => TRUE])->save();
      }
    }
    // The block store creates a moderated draft, so bind the workflow to the
    // block media bundle (the article + image bundles stay unmoderated).
    $this->setUpEditorialWorkflow([], ['block']);

    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'article',
      'title' => 'Pricing',
      'status' => 1,
    ]);
    $node->save();
    $this->nodeId = (int) $node->id();

    $this->blockId = $this->container->get('aincient_pages.block_store')->store([
      'title' => 'Footer CTA',
      'sections' => [
        ['component' => 'cta', 'props' => ['heading' => 'Join', 'cta_label' => 'Go', 'cta_url' => '/x']],
      ],
    ]);
    // Blocks now start as drafts (editorial workflow); a reusable block you
    // reference is a published one, so take it live for the reference tests.
    // Set the state directly in the fixture (the permission gate lives in the
    // store's API path, not on a raw entity save).
    $block = \Drupal::entityTypeManager()->getStorage('media')->load($this->blockId);
    $block->set('moderation_state', 'published')->save();

    // A real person (uid > 0) — the first non-page-composition reference type.
    $user = \Drupal::entityTypeManager()->getStorage('user')->create([
      'name' => 'nefertiti',
      'mail' => 'nefertiti@example.com',
      'status' => 1,
    ]);
    $user->save();
    $this->userId = (int) $user->id();

    // A brand revision — a DISCOVERY-only type (searchable/previewable, not
    // page-embeddable). Owned by the user above so the byline resolves.
    $rev = \Drupal::entityTypeManager()->getStorage('aincient_brand_revision')->create([
      'uid' => $this->userId,
      'summary' => 'Changed accent colour',
      'data' => '{"tokens":{}}',
      'created' => 1718000000,
    ]);
    $rev->save();
    $this->brandRevId = (int) $rev->id();
  }

  private function catalog(): ReferenceCatalog {
    return $this->container->get('aincient_pages.reference_catalog');
  }

  public function testRegistersEveryProvider(): void {
    $this->assertEqualsCanonicalizing(
      ['media', 'node', 'block', 'user', 'aincient_brand_revision'],
      $this->catalog()->types(),
    );
  }

  public function testMediaSearchAndDescribe(): void {
    $rows = $this->catalog()->search(['media'], NULL);
    $this->assertCount(1, $rows);
    $this->assertSame('media:' . $this->mediaId, $rows[0]['token']);
    $this->assertSame('media', $rows[0]['type']);
    $this->assertSame('Falcon', $rows[0]['label']);
    $this->assertSame('A peregrine falcon', $rows[0]['description']);
    $this->assertIsString($rows[0]['thumb']);
    $this->assertSame('published', $rows[0]['status']);

    $d = $this->catalog()->describe('media:' . $this->mediaId);
    $this->assertSame($rows[0]['token'], $d['token']);
    $this->assertNull($this->catalog()->describe('media:999999'));
  }

  public function testNodeSearchExcludesBlocksAndDescribes(): void {
    $tokens = array_column($this->catalog()->search(['node'], NULL), 'token');
    $this->assertContains('entity:node:' . $this->nodeId, $tokens);
    // The global block must NOT surface as an embeddable node.
    $this->assertNotContains('entity:node:' . $this->blockId, $tokens);

    $d = $this->catalog()->describe('entity:node:' . $this->nodeId);
    $this->assertSame('node', $d['type']);
    $this->assertSame('Pricing', $d['label']);
    $this->assertSame('published', $d['status']);
    $this->assertStringContainsString('/node/' . $this->nodeId, (string) $d['edit_url']);
  }

  public function testBlockSearchAndDescribe(): void {
    $tokens = array_column($this->catalog()->search(['block'], NULL), 'token');
    $this->assertContains('block:' . $this->blockId, $tokens);

    $d = $this->catalog()->describe('block:' . $this->blockId);
    $this->assertSame('block', $d['type']);
    $this->assertSame('Footer CTA', $d['label']);
    $this->assertSame('1 section', $d['description']);
    $this->assertSame('published', $d['status']);
    // Blocks edit in-studio — no canonical edit URL.
    $this->assertNull($d['edit_url']);
  }

  public function testUserSearchAndDescribe(): void {
    $tokens = array_column($this->catalog()->search(['user'], NULL), 'token');
    $this->assertContains('entity:user:' . $this->userId, $tokens);
    // Anonymous (uid 0) is never referenceable.
    $this->assertNotContains('entity:user:0', $tokens);

    $d = $this->catalog()->describe('entity:user:' . $this->userId);
    $this->assertSame('user', $d['type']);
    $this->assertSame('nefertiti', $d['label']);
    $this->assertSame('nefertiti@example.com', $d['description']);
    $this->assertSame('published', $d['status']);
    $this->assertStringContainsString('/user/' . $this->userId, (string) $d['edit_url']);

    $this->assertNull($this->catalog()->describe('entity:user:0'));
    $this->assertNull($this->catalog()->describe('entity:user:999999'));
  }

  public function testBrandRevisionSearchAndDescribe(): void {
    $token = 'entity:aincient_brand_revision:' . $this->brandRevId;
    $rows = $this->catalog()->search(['aincient_brand_revision'], NULL);
    $this->assertCount(1, $rows);
    $this->assertSame($token, $rows[0]['token']);
    $this->assertSame('aincient_brand_revision', $rows[0]['type']);
    // Label = "Brand v<id> · <date>"; description = summary — by <author>.
    $this->assertStringStartsWith('Brand v' . $this->brandRevId . ' · ', $rows[0]['label']);
    $this->assertStringContainsString('Changed accent colour', $rows[0]['description']);
    $this->assertStringContainsString('by nefertiti', $rows[0]['description']);
    // Discovery-only: no status, no thumb, no edit URL.
    $this->assertNull($rows[0]['status']);
    $this->assertNull($rows[0]['thumb']);
    $this->assertNull($rows[0]['edit_url']);
    $this->assertSame($this->brandRevId, $rows[0]['meta']['version']);

    $d = $this->catalog()->describe($token);
    $this->assertSame($token, $d['token']);
    $this->assertNull($this->catalog()->describe('entity:aincient_brand_revision:999999'));
  }

  public function testCrossTypeMergeAndBadTokens(): void {
    // Empty types = every provider; merges across types.
    $all = $this->catalog()->search([], NULL);
    $types = array_unique(array_column($all, 'type'));
    $this->assertEqualsCanonicalizing(
      ['media', 'node', 'block', 'user', 'aincient_brand_revision'],
      $types,
    );

    $this->assertNull($this->catalog()->describe('not a token'));
    $this->assertNull($this->catalog()->describe('block:999999'));
    $this->assertNull($this->catalog()->describe(''));
  }

}
