<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Controller\PageSpikeController;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Gallery carousel + lightbox (DECISIONS 0523; plans/gallery-carousel.md).
 *
 * A plain grid stays hook-free (no island, no links); the carousel shows the
 * uncropped slide URL in a scroll-snap track; the lightbox links each image to
 * its full-size file and ships one viewer; the grammar clamps the new props.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class GalleryVariantsTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
  ];

  private const IMAGES = [
    ['image' => '/files/one.png', 'image_slide' => '/styles/wide/one.png', 'image_full' => '/full/one.png', 'image_alt' => 'Alt one', 'caption' => 'First'],
    ['image' => '/files/two.png', 'image_slide' => '/styles/wide/two.png', 'image_full' => '/full/two.png', 'caption' => 'Second'],
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function renderGallery(array $props): string {
    $catalog = $this->container->get('aincient_pages.catalog')->for('landing');
    $build = [
      '#type' => 'component',
      '#component' => $catalog->pluginId('gallery'),
      '#props' => $props + ['heading' => 'Works', 'images' => self::IMAGES],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  public function testPlainGridHasNoIslandHooks(): void {
    foreach ([['variant' => 'grid'], ['variant' => 'grid', 'lightbox' => FALSE]] as $props) {
      $html = $this->renderGallery($props);
      $this->assertStringNotContainsString('data-gallery', $html);
      $this->assertStringNotContainsString('<a ', $html);
      $this->assertStringNotContainsString('<dialog', $html);
      $this->assertStringContainsString('src="/files/one.png"', $html, 'Grid keeps the raw-URL fallback.');
    }
  }

  public function testCarouselShowsUncroppedSlides(): void {
    $html = $this->renderGallery(['variant' => 'carousel']);
    $this->assertStringContainsString('data-gallery="carousel"', $html);
    $this->assertStringContainsString('aria-roledescription="carousel"', $html);
    $this->assertStringContainsString('src="/styles/wide/one.png"', $html);
    $this->assertStringContainsString('object-contain', $html);
    $this->assertStringContainsString('alt="Alt one"', $html, 'Media alt wins over the caption.');
    $this->assertStringContainsString('alt="Second"', $html, 'Caption is the alt fallback.');
    $this->assertStringContainsString('aria-label="2 of 2"', $html);
    $this->assertStringContainsString('data-gallery-controls', $html);
    $this->assertStringNotContainsString('<dialog', $html, 'No viewer without lightbox.');
  }

  public function testLightboxLinksFullImagesAndShipsOneViewer(): void {
    foreach (['grid', 'carousel'] as $variant) {
      $html = $this->renderGallery(['variant' => $variant, 'lightbox' => TRUE]);
      $this->assertStringContainsString('data-gallery="' . $variant . '"', $html);
      $this->assertStringContainsString('href="/full/one.png"', $html);
      $this->assertStringContainsString('data-gallery-open="1"', $html);
      $this->assertStringContainsString('data-asset', $html, 'The static export copies the full-size file.');
      $this->assertSame(1, substr_count($html, '<dialog'), "$variant: one viewer.");
    }
  }

  public function testGrammarClampsVariantAndCoercesLightbox(): void {
    $store = $this->container->get('aincient_pages.store');
    $props = fn(array $p) => $store->validate([
      'type' => 'landing',
      'title' => 'T',
      'sections' => [['component' => 'gallery', 'props' => $p + ['images' => [['image' => '/x.png']]]]],
    ])['sections'][0]['props'];

    $this->assertSame('grid', $props([])['variant']);
    $this->assertSame('grid', $props(['variant' => 'slider'])['variant']);
    $this->assertSame('carousel', $props(['variant' => 'carousel'])['variant']);
    $this->assertTrue($props(['lightbox' => 'true'])['lightbox']);
    $this->assertFalse($props(['lightbox' => 'no'])['lightbox']);
  }

  /**
   * A gallery stored BEFORE `variant` existed has no variant in its slot. The
   * SDC would get "" and 500 the page; the renderer backfills `grid`.
   */
  public function testStoredSlotWithoutVariantStillRenders(): void {
    $controller = PageSpikeController::create($this->container);
    $html = (string) $controller->renderFragment([
      ['id' => 's1', 'component' => 'gallery', 'props' => ['heading' => 'Old', 'images' => [['image' => '/x.png', 'caption' => 'X']]]],
    ], 'T')->getContent();
    $this->assertStringContainsString('src="/x.png"', $html);
    $this->assertStringNotContainsString('data-gallery', $html);
    $this->assertStringNotContainsString('gallery.js', $html, 'No island for a plain grid.');
  }

  /**
   * The shell loads the island only when a carousel or lightbox rendered.
   */
  public function testShellLoadsIslandForCarousel(): void {
    $controller = PageSpikeController::create($this->container);
    $html = (string) $controller->renderFragment([
      ['id' => 's1', 'component' => 'gallery', 'props' => ['variant' => 'carousel', 'lightbox' => 'true', 'images' => [['image' => '/x.png']]]],
    ], 'T')->getContent();
    $this->assertMatchesRegularExpression('#<script src="[^"]*/js/gallery\.js\?v=\d+" defer></script>#', $html);
    $this->assertStringContainsString('<dialog', $html, 'String "true" is cast to a boolean before the SDC.');
  }

}
