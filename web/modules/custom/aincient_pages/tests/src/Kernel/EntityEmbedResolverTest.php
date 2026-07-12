<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\EntityEmbedResolver;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the entity-general embed-reference resolver (Phase 4a media surface).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class EntityEmbedResolverTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation', 'aincient_pages',
  ];

  private int $mediaId;

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('node');
    $this->installEntitySchema('aincient_brand_revision');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'field', 'media']);

    // A standard image media type with an alt-bearing source field.
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
      'settings' => ['alt_field' => TRUE, 'alt_field_required' => TRUE],
    ])->save();

    // An image style so the styled-URL path is exercised.
    ImageStyle::create(['name' => 'thumbnail', 'label' => 'Thumbnail'])->save();

    // A real-enough file + media entity carrying alt text.
    file_put_contents('public://falcon.png', base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
    ));
    $file = File::create(['uri' => 'public://falcon.png', 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Falcon',
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'A peregrine falcon'],
    ]);
    $media->save();
    $this->mediaId = (int) $media->id();
  }

  private function resolver(): EntityEmbedResolver {
    return $this->container->get('aincient_pages.embed_resolver');
  }

  public function testParsesTokenForms(): void {
    $r = $this->resolver();
    $this->assertSame(['type' => 'media', 'id' => 7, 'view_mode' => NULL], $r->parse('media:7'));
    $this->assertSame(['type' => 'media', 'id' => 7, 'view_mode' => NULL], $r->parse('entity:media:7'));
    $this->assertSame(['type' => 'node', 'id' => 15, 'view_mode' => 'teaser'], $r->parse('entity:node:15@teaser'));
    // A global block — a logical ref type, parseable but never view-built.
    $this->assertSame(['type' => 'block', 'id' => 7, 'view_mode' => NULL], $r->parse('block:7'));
    // Non-tokens pass through as NULL so raw URLs survive.
    $this->assertNull($r->parse('https://example.com/x.jpg'));
    $this->assertNull($r->parse('/sites/default/files/x.jpg'));
    $this->assertNull($r->parse('media:'));
    $this->assertNull($r->parse('media:abc'));
    $this->assertFalse($r->isToken('https://example.com/x.jpg'));
    $this->assertTrue($r->isToken('media:7'));
  }

  public function testResolvesMediaTokenToFileUrl(): void {
    $url = $this->resolver()->url('media:' . $this->mediaId);
    $this->assertIsString($url);
    $this->assertStringContainsString('falcon.png', $url);
    // Sugar and explicit form agree.
    $this->assertSame($url, $this->resolver()->url('entity:media:' . $this->mediaId));
  }

  public function testResolvesMediaTokenThroughImageStyle(): void {
    $url = $this->resolver()->url('media:' . $this->mediaId, 'thumbnail');
    $this->assertIsString($url);
    $this->assertStringContainsString('styles/thumbnail', $url);
  }

  public function testResolvesAltText(): void {
    $this->assertSame('A peregrine falcon', $this->resolver()->alt('media:' . $this->mediaId));
    // Alt is meaningless for non-media / non-tokens.
    $this->assertNull($this->resolver()->alt('https://example.com/x.jpg'));
  }

  public function testDanglingAndNonTokensResolveToNull(): void {
    $r = $this->resolver();
    $this->assertNull($r->url('media:999999'));
    $this->assertNull($r->url('https://example.com/x.jpg'));
    $this->assertNull($r->alt('media:999999'));
  }

  public function testRenderReturnsViewBuilderArray(): void {
    $build = $this->resolver()->render('media:' . $this->mediaId);
    $this->assertIsArray($build);
    $this->assertArrayHasKey('#cache', $build);
  }

  /**
   * The entity-general path (Phase 4b): a NODE token resolves to a view-builder
   * render array in the requested view mode — the seam the `embed` section uses.
   */
  public function testRenderResolvesNodeInViewMode(): void {
    \Drupal::entityTypeManager()->getStorage('node_type')->create(['type' => 'article', 'name' => 'Article'])->save();
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'article',
      'title' => 'Embeddable article',
      'status' => 1,
    ]);
    $node->save();

    $build = $this->resolver()->render('entity:node:' . $node->id() . '@teaser');
    $this->assertIsArray($build);
    $this->assertSame('teaser', $build['#view_mode']);
    $this->assertSame((int) $node->id(), (int) $build['#node']->id());

    // A well-formed token is recognised statically (the PageStore clamp path).
    $this->assertTrue(EntityEmbedResolver::isWellFormed('entity:node:' . $node->id() . '@teaser'));
    $this->assertTrue(EntityEmbedResolver::isWellFormed('block:7'));
    $this->assertFalse(EntityEmbedResolver::isWellFormed('entity:node:'));
    $this->assertFalse(EntityEmbedResolver::isWellFormed('just text'));
  }

  /**
   * A discovery-only type (aincient_brand_revision — searchable/previewable but
   * not meant to be embedded) is never offered by the studio embed picker, but if
   * such a token ever lands in an embed prop it must degrade HARMLESSLY: core
   * gives every content entity the default EntityViewBuilder, so render() returns
   * a (mostly empty) array rather than throwing and fataling the page.
   */
  public function testRenderOfDiscoveryOnlyTypeIsHarmless(): void {
    $rev = \Drupal::entityTypeManager()->getStorage('aincient_brand_revision')->create([
      'summary' => 'Changed accent colour',
      'data' => '{}',
    ]);
    $rev->save();
    $token = 'entity:aincient_brand_revision:' . $rev->id();
    $this->assertNotNull($this->resolver()->parse($token));
    // No throw — a render array (the band would just show nothing of substance).
    $this->assertIsArray($this->resolver()->render($token));
  }

}
