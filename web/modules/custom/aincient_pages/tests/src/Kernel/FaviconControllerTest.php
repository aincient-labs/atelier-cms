<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\FaviconController;
use Drupal\aincient_pages\SiteIdentity;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the literal /favicon.ico route (operator upload vs bundled default).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class FaviconControllerTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'field', 'media']);

    // A standard image media type, as the Globals studio's favicon picker uses.
    MediaType::create([
      'id' => 'image',
      'label' => 'Image',
      'source' => 'image',
      'source_configuration' => ['source_field' => 'field_media_image'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'type' => 'image',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'media',
      'bundle' => 'image',
      'label' => 'Image',
    ])->save();
  }

  private function controller(): FaviconController {
    return FaviconController::create($this->container);
  }

  /** No upload → the bundled neutral default, cacheable, long-lived. */
  public function testServesBundledDefaultWhenNoFaviconIsSet(): void {
    $response = $this->controller()->icon();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('image/x-icon', $response->headers->get('Content-Type'));
    $default = $this->container->get('extension.list.module')->getPath('aincient_pages') . '/images/favicon.ico';
    $this->assertSame(file_get_contents($default), $response->getContent());
    $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));
    $this->assertContains('config:' . SiteIdentity::CONFIG, $response->getCacheableMetadata()->getCacheTags());
  }

  /** Upload set → the operator's raw file, with its own MIME type. */
  public function testServesOperatorFaviconWhenSet(): void {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    file_put_contents('public://operator-favicon.png', $png);
    $file = File::create(['uri' => 'public://operator-favicon.png', 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Operator favicon',
      'field_media_image' => [['target_id' => $file->id()]],
    ]);
    $media->save();
    $this->container->get('aincient_pages.site_identity')->setFavicon('media:' . $media->id());

    $response = $this->controller()->icon();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('image/png', $response->headers->get('Content-Type'));
    $this->assertSame($png, $response->getContent());
    $tags = $response->getCacheableMetadata()->getCacheTags();
    $this->assertContains('config:' . SiteIdentity::CONFIG, $tags);
    $this->assertContains('file:' . $file->id(), $tags);
  }

  /** A dangling media token degrades to the default, never a 404. */
  public function testDanglingTokenFallsBackToDefault(): void {
    $this->container->get('aincient_pages.site_identity')->setFavicon('media:9999');

    $response = $this->controller()->icon();

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('image/x-icon', $response->headers->get('Content-Type'));
  }

}
