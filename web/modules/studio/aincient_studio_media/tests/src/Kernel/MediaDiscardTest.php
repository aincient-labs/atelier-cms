<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_media\Kernel;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\aincient_studio_media\Controller\MediaEditController;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Discarding a generated image unpublishes it, reversibly (DECISIONS 0512).
 *
 * B4: the Pages agent reused an image the owner had rejected, because every
 * generation is published at once and find_reference searched newest first
 * with no status filter. Discard unpublishes; find_reference skips unpublished
 * images; and an image still placed where visitors see it can't be discarded.
 */
#[RunTestsInSeparateProcesses]
final class MediaDiscardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'key', 'file', 'image', 'media',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_media',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'media', 'node']);

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
      'settings' => ['alt_field' => TRUE, 'file_extensions' => 'png', 'uri_scheme' => 'public'],
    ])->save();
    ImageStyle::create(['name' => 'media_library', 'label' => 'Media Library'])->save();

    // aincient_pages may already provide the page type and its fields.
    if (NodeType::load('aincient_page') === NULL) {
      NodeType::create(['type' => 'aincient_page', 'name' => 'Page'])->save();
    }
    foreach (['field_page_structure', 'field_page_content'] as $name) {
      if (FieldStorageConfig::loadByName('node', $name) === NULL) {
        FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'string_long'])->save();
      }
      if (FieldConfig::loadByName('node', 'aincient_page', $name) === NULL) {
        FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'aincient_page', 'label' => $name])->save();
      }
    }

    // uid 1 is the superuser; the person under test is an ordinary editor.
    $this->createUser([], NULL, FALSE, ['uid' => 1]);
    $this->setUpCurrentUser([], [
      'access content',
      'administer media',
      'view any unpublished content',
      'administer aincient pages',
      'use aincient studio media',
    ]);
  }

  private function makeImage(string $name): MediaInterface {
    $path = 'public://' . $name . '.png';
    file_put_contents($path, base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => $path, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => $name,
      'field_media_image' => ['target_id' => $file->id(), 'alt' => $name],
    ]);
    $media->save();
    return $media;
  }

  private function controller(): MediaEditController {
    return MediaEditController::create($this->container);
  }

  private function reload(MediaInterface $media): MediaInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$media->id()]);
    return $storage->load($media->id());
  }

  private function findReference(string $query): string {
    $tool = $this->container->get('plugin.manager.aincient.capabilities')->createInstance('aincient_pages:find_reference');
    $tool->setContextValue('types', 'media');
    $tool->setContextValue('query', $query);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Discard unpublishes, Restore publishes again; both report the new status.
   */
  public function testDiscardAndRestore(): void {
    $media = $this->makeImage('Rejected hero');

    $response = $this->controller()->discard($media);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('unpublished', json_decode((string) $response->getContent(), TRUE)['item']['status']);
    $this->assertFalse($this->reload($media)->isPublished());

    $response = $this->controller()->restore($this->reload($media));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertTrue($this->reload($media)->isPublished());
  }

  /**
   * The Pages agent never offers a discarded image (B4).
   */
  public function testFindReferenceSkipsDiscardedImages(): void {
    $kept = $this->makeImage('Hero kept');
    $rejected = $this->makeImage('Hero rejected');

    $before = $this->findReference('Hero');
    $this->assertStringContainsString('media:' . $rejected->id(), $before);

    $this->controller()->discard($rejected);
    $after = $this->findReference('Hero');
    $this->assertStringContainsString('media:' . $kept->id(), $after);
    $this->assertStringNotContainsString('media:' . $rejected->id(), $after);

    // All matches discarded → the usual "nothing found" answer, no tokens.
    $this->controller()->discard($this->reload($kept));
    $this->assertStringContainsString('No content', $this->findReference('Hero'));
  }

  /**
   * An image still placed on a page can't be discarded; an unplaced one can.
   */
  public function testDiscardRefusedWhileUsedOnAPage(): void {
    $used = $this->makeImage('Used');
    $other = $this->makeImage('Other');
    Node::create([
      'type' => 'aincient_page',
      'title' => 'Home',
      'field_page_content' => json_encode(['hero' => ['image' => 'media:' . $used->id()]]),
    ])->save();

    $response = $this->controller()->discard($used);
    $this->assertSame(409, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['Page: Home'], $body['placements']);
    $this->assertTrue($this->reload($used)->isPublished());

    $this->assertSame(200, $this->controller()->discard($other)->getStatusCode());
  }

  /**
   * The site logo counts as a placement too.
   */
  public function testDiscardRefusedWhileItIsTheLogo(): void {
    $logo = $this->makeImage('Logo');
    $this->config(SiteIdentity::CONFIG)->set('logo', 'media:' . $logo->id())->save();

    $response = $this->controller()->discard($logo);
    $this->assertSame(409, $response->getStatusCode());
    $this->assertContains('Site identity', json_decode((string) $response->getContent(), TRUE)['placements']);
  }

}
