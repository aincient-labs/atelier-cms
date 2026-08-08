<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\CollectionDataController;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The content-addressed JSON index + archive controller (DECISIONS 0329).
 *
 * Guards the two invariants a later refactor breaks silently: the JSON MUST be
 * a CacheableJsonResponse (or the page cache refuses it — invariant 3) and an
 * unknown hash MUST be a 404 (so the export inventory and the route agree on
 * exactly which files exist).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class CollectionDataControllerTest extends KernelTestBase {

  use UserCreationTrait;

  protected static $modules = ['system', 'user', 'field', 'text', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  private string $hash;

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
    $this->setUpCurrentUser(permissions: ['access content']);

    // A published blog post, and a landing page placing an index collection.
    Node::create([
      'type' => 'aincient_page', 'title' => 'The Post', 'status' => 1, 'created' => 1000,
      'field_page_type' => 'blog', 'field_teaser_title' => 'The Post', 'field_teaser_description' => 'A summary',
    ])->save();
    Node::create([
      'type' => 'aincient_page', 'title' => 'Blog index', 'status' => 1, 'field_page_type' => 'landing',
      'field_page_structure' => json_encode([
        'type' => 'landing',
        'slots' => [['id' => 'aaaa', 'component' => 'collection', 'mode' => 'index', 'source' => 'blog', 'sort' => 'newest']],
      ]),
    ])->save();

    $this->hash = $this->container->get('aincient_pages.collection_inventory')
      ->specHash(['source' => 'blog', 'sort' => 'newest']);
  }

  private function controller(): CollectionDataController {
    return CollectionDataController::create($this->container);
  }

  public function testJsonIsCacheableAndCarriesTheResolvedItems(): void {
    $response = $this->controller()->data($this->hash . '.json');

    // Invariant 3: only a CacheableResponse survives the page cache.
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    $this->assertContains('node_list', $response->getCacheableMetadata()->getCacheTags());

    $payload = json_decode($response->getContent(), TRUE);
    $this->assertSame($this->hash, $payload['hash']);
    $this->assertSame(1, $payload['total']);
    $this->assertCount(1, $payload['items']);
    $this->assertSame('The Post', $payload['items'][0]['title']);
    $this->assertArrayHasKey('url', $payload['items'][0]);
    $this->assertArrayHasKey('tags', $payload['items'][0]);
  }

  public function testUnknownHashIs404(): void {
    $this->expectException(NotFoundHttpException::class);
    $this->controller()->data('0000000000000000.json');
  }

  public function testArchiveListsEveryPostAsARealLink(): void {
    $response = $this->controller()->archive($this->hash);
    $html = $response->getContent();
    $this->assertStringContainsString('<h1>Archive', $html);
    $this->assertStringContainsString('The Post', $html);
    // A real, crawlable anchor to the post.
    $this->assertMatchesRegularExpression('/<a href="\/[^"]*">The Post<\/a>/', $html);
  }

}
