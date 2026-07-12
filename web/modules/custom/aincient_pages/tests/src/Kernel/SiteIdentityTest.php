<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\SiteIdentity;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the brand-identity store: guidelines, prompt brief, and footer note.
 *
 * Identity (name/tagline/voice/logo/footer note) is the "Brand" layer, kept
 * separate from the design-token Foundations layer (BrandRepository).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class SiteIdentityTest extends KernelTestBase {

  protected static $modules = [
    'system', 'field', 'text', 'file', 'image', 'media', 'user', 'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['field', 'media', 'workflows', 'content_moderation', 'aincient_pages']);

    // A minimal `image` media type — the bundle the unified picker (and now the
    // logo/favicon tokens) reference.
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
      'settings' => ['alt_field' => TRUE, 'file_extensions' => 'png jpg jpeg ico', 'uri_scheme' => 'public'],
    ])->save();
  }

  private function identity(): SiteIdentity {
    return $this->container->get('aincient_pages.site_identity');
  }

  /** Create an image-media entity from a file uri + mime; return its token. */
  private function makeMediaToken(string $uri, string $mime): string {
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'filemime' => $mime, 'status' => 1]);
    $file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => basename($uri),
      'field_media_image' => ['target_id' => $file->id(), 'alt' => 'x'],
    ]);
    $media->save();
    return 'media:' . $media->id();
  }

  public function testGuidelinesPersistAndExposeName(): void {
    $applied = $this->identity()->update(['name' => 'Lumen', 'tone' => 'warm and clear']);
    $this->assertContains('name', $applied);
    $this->assertSame('Lumen', $this->identity()->name());
  }

  public function testPromptBriefReflectsGuidelines(): void {
    $this->identity()->update(['name' => 'Lumen', 'tone' => 'warm and clear']);
    $brief = $this->identity()->promptBrief();
    $this->assertStringContainsString('Lumen', $brief);
    $this->assertStringContainsString('warm and clear', $brief);
  }

  public function testPromptBriefIncludesImageryDirection(): void {
    // Imagery style + avoid are whitelisted guideline keys and surface in the
    // brief so the image agent has art direction, not just palette/voice.
    $applied = $this->identity()->update([
      'imagery_style' => 'soft natural light, muted tones',
      'imagery_avoid' => 'generic stock photos',
    ]);
    $this->assertContains('imagery_style', $applied);
    $this->assertContains('imagery_avoid', $applied);
    $brief = $this->identity()->promptBrief();
    $this->assertStringContainsString('Imagery style: soft natural light, muted tones', $brief);
    $this->assertStringContainsString('Imagery to avoid: generic stock photos', $brief);
  }

  public function testUnknownGuidelineKeysAreIgnored(): void {
    $this->identity()->update(['name' => 'Lumen', 'bogus' => 'x']);
    $this->assertArrayNotHasKey('bogus', $this->identity()->guidelines());
  }

  public function testFooterNotePersists(): void {
    $this->identity()->update([], '© Acme');
    $this->assertSame('© Acme', $this->identity()->footerNote());
  }

  public function testFaviconDefaultsToNone(): void {
    $this->assertNull($this->identity()->faviconLink());
    $this->assertSame('', $this->identity()->faviconUrl());
  }

  public function testFaviconLinkCarriesRawUrlAndMime(): void {
    $token = $this->makeMediaToken('public://aincient/brand/favicon.ico', 'image/vnd.microsoft.icon');
    $this->identity()->setFavicon($token);
    $link = $this->identity()->faviconLink();

    $this->assertIsArray($link);
    $this->assertSame($token, $this->identity()->favicon());
    $this->assertSame('image/vnd.microsoft.icon', $link['type']);
    // Served raw (no image-style derivative), unlike the logo.
    $this->assertStringContainsString('aincient/brand/favicon.ico', $link['href']);
    $this->assertSame($link['href'], $this->identity()->faviconUrl());
  }

  public function testFaviconCleared(): void {
    $token = $this->makeMediaToken('public://aincient/brand/favicon.png', 'image/png');
    $this->identity()->setFavicon($token);
    $this->assertNotNull($this->identity()->faviconLink());

    // An empty token clears it.
    $this->identity()->setFavicon('');
    $this->assertNull($this->identity()->faviconLink());
    $this->assertSame('', $this->identity()->favicon());
  }

}
