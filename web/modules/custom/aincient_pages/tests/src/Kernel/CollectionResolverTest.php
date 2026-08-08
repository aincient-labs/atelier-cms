<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\CollectionInventory;
use Drupal\aincient_pages\CollectionResolver;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The collection listing query — the one code path behind live + exported lists.
 *
 * Proves the resolver runs a REAL entity query (filters by the derived type
 * axis, sorts by the `created` projection, bounds by limit) rather than loading
 * every node, and that a listing carries the cache metadata that makes a publish
 * invalidate it. Also covers the inventory's discovery of index-mode placements.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class CollectionResolverTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'aincient_page', 'name' => 'AIncient page'])->save();
    FieldStorageConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'type' => 'string_long', 'translatable' => TRUE])->save();
    FieldConfig::create(['field_name' => 'field_page_structure', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Structure', 'translatable' => TRUE])->save();
    FieldStorageConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'type' => 'string', 'translatable' => FALSE])->save();
    FieldConfig::create(['field_name' => 'field_page_type', 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => 'Page type', 'translatable' => FALSE])->save();
    foreach (['field_teaser_title' => 'string', 'field_teaser_description' => 'string_long', 'field_teaser_image' => 'string'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type, 'translatable' => TRUE])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => $name, 'translatable' => TRUE])->save();
    }

    // A public listing resolves with access-checking on; give the running user
    // plain content-view access so published posts are visible (and unpublished
    // ones still aren't).
    $this->setUpCurrentUser(permissions: ['access content']);
  }

  private function resolver(): CollectionResolver {
    return $this->container->get('aincient_pages.collection_resolver');
  }

  /**
   * Create a published aincient_page with a derived type + teaser + created.
   */
  private function post(string $type, string $title, int $created, string $teaserTitle = '', string $teaserDesc = ''): Node {
    $node = Node::create([
      'type' => 'aincient_page',
      'title' => $title,
      'status' => 1,
      'created' => $created,
      'field_page_type' => $type,
      'field_teaser_title' => $teaserTitle,
      'field_teaser_description' => $teaserDesc,
    ]);
    $node->save();
    return $node;
  }

  public function testResolvesOnlyTheSourceTypeNewestFirst(): void {
    $this->post('landing', 'A landing page', 1000, 'Landing card', 'Landing teaser');
    $older = $this->post('blog', 'Older post', 2000, 'Older card', 'Older teaser');
    $newer = $this->post('blog', 'Newer post', 3000, 'Newer card', 'Newer teaser');

    $result = $this->resolver()->resolve(['source' => 'blog', 'sort' => 'newest']);

    $this->assertSame(2, $result['total'], 'The landing page is not part of the blog source.');
    $this->assertCount(2, $result['records']);
    // Newest first; title from the dedicated teaser title.
    $this->assertSame('Newer card', $result['records'][0]['title']);
    $this->assertSame('Older card', $result['records'][1]['title']);
    // Teaser description + a real formatted date from `created`; no image token set.
    $this->assertSame('Newer teaser', $result['records'][0]['teaser']);
    $this->assertNotSame('', $result['records'][0]['date']);
    $this->assertSame('', $result['records'][0]['image']);
    // The read-more link points back at the post.
    $props = CollectionResolver::teaserProps($result['records'][0]);
    $this->assertSame($result['records'][0]['url'], $props['more_url']);
    $this->assertSame((int) $newer->id(), $result['records'][0]['id']);
    $this->assertSame((int) $older->id(), $result['records'][1]['id']);
  }

  public function testOldestSortAndLimitBounds(): void {
    $this->post('blog', 'First', 2000);
    $this->post('blog', 'Second', 3000);

    $result = $this->resolver()->resolve(['source' => 'blog', 'sort' => 'oldest'], NULL, 1);

    $this->assertSame(2, $result['total'], 'total is unbounded by the limit.');
    $this->assertCount(1, $result['records'], 'limit bounds the returned records.');
    $this->assertSame('First', $result['records'][0]['title'], 'oldest first.');
  }

  public function testUnpublishedPostsAreNeverListed(): void {
    $this->post('blog', 'Published', 2000);
    $hidden = Node::create(['type' => 'aincient_page', 'title' => 'Draft', 'status' => 0, 'created' => 3000, 'field_page_type' => 'blog']);
    $hidden->save();

    $result = $this->resolver()->resolve(['source' => 'blog']);
    $this->assertSame(1, $result['total']);
    $this->assertSame('Published', $result['records'][0]['title']);
  }

  public function testTeaserTitleFallsBackToNodeLabel(): void {
    $this->post('blog', 'The node label', 2000, '');
    $result = $this->resolver()->resolve(['source' => 'blog']);
    $this->assertSame('The node label', $result['records'][0]['title']);
  }

  public function testCacheMetadataInvalidatesWithPosts(): void {
    $node = $this->post('blog', 'Cached', 2000);
    $result = $this->resolver()->resolve(['source' => 'blog']);
    $tags = $result['cacheability']->getCacheTags();
    $this->assertContains('node_list', $tags, 'A publish must invalidate the listing.');
    $this->assertContains('node:' . $node->id(), $tags, 'Editing a listed post must invalidate it too.');
  }

  public function testInventoryDiscoversOnlyIndexModeCollections(): void {
    /** @var \Drupal\aincient_pages\CollectionInventory $inventory */
    $inventory = $this->container->get('aincient_pages.collection_inventory');

    // A landing page carrying one index-mode collection and one strip.
    $structure = [
      'type' => 'landing',
      'slots' => [
        ['id' => 'aaaa', 'component' => 'collection', 'mode' => 'index', 'source' => 'blog', 'sort' => 'newest'],
        ['id' => 'bbbb', 'component' => 'collection', 'mode' => 'strip', 'source' => 'blog', 'sort' => 'newest'],
        ['id' => 'cccc', 'component' => 'hero'],
      ],
    ];
    Node::create([
      'type' => 'aincient_page',
      'title' => 'Blog index',
      'status' => 1,
      'field_page_type' => 'landing',
      'field_page_structure' => json_encode($structure),
    ])->save();

    $found = $inventory->indexCollections();
    $this->assertCount(1, $found, 'Only the index-mode collection is enumerated (strips carry no JSON).');
    $hash = $inventory->specHash(['source' => 'blog', 'sort' => 'newest']);
    $this->assertArrayHasKey($hash, $found);
    $this->assertSame(['source' => 'blog', 'sort' => 'newest', 'filter' => []], $found[$hash]);
  }

  public function testSourcesConstantMatchesTheGrammar(): void {
    // Guard against the grammar and the resolver drifting apart.
    $this->assertContains(CollectionInventory::DEFAULT_SOURCE, CollectionInventory::SOURCES);
  }

}
