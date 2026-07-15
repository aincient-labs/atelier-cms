<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Unit;

use Drupal\aincient_export\AssetExtractor;
use Drupal\aincient_export\PathUtil;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aincient_export\AssetExtractor
 * @group aincient_export
 */
class AssetExtractorTest extends UnitTestCase {

  /**
   * @covers ::fromHtml
   */
  public function testFromHtml(): void {
    $html = <<<HTML
      <html><head>
        <link rel="stylesheet" href="/themes/custom/x/dist/style.css?v=123">
        <link rel="icon" href="/favicon.svg">
        <link rel="canonical" href="/pages/foo">
        <meta property="og:image" content="https://example.com/sites/default/files/og.png">
        <style>.hero { background: url('/sites/default/files/bg.jpg'); }</style>
      </head><body>
        <img src="/sites/default/files/a.png" srcset="/sites/default/files/a-320.webp 320w, /sites/default/files/a-640.webp 640w">
        <video poster="/sites/default/files/poster.jpg"><source src="/sites/default/files/clip.mp4"></video>
        <script src="/modules/custom/x/js/app.js"></script>
        <a href="/pages/bar">a page link, not an asset</a>
      </body></html>
      HTML;

    $urls = AssetExtractor::fromHtml($html);

    $expected = [
      '/themes/custom/x/dist/style.css?v=123',
      '/favicon.svg',
      'https://example.com/sites/default/files/og.png',
      '/sites/default/files/bg.jpg',
      '/sites/default/files/a.png',
      '/sites/default/files/a-320.webp',
      '/sites/default/files/a-640.webp',
      '/sites/default/files/poster.jpg',
      '/sites/default/files/clip.mp4',
      '/modules/custom/x/js/app.js',
    ];
    foreach ($expected as $url) {
      $this->assertContains($url, $urls);
    }
    // rel=canonical points at a page and a[href] is the link checker's job.
    $this->assertNotContains('/pages/foo', $urls);
    $this->assertNotContains('/pages/bar', $urls);
  }

  /**
   * @covers ::fromCss
   */
  public function testFromCss(): void {
    $css = '@import "base.css"; @font-face { src: url(../fonts/a.woff2) format("woff2"); } .x { background: url("img/b.png"); }';
    $urls = AssetExtractor::fromCss($css);
    $this->assertEqualsCanonicalizing(['base.css', '../fonts/a.woff2', 'img/b.png'], $urls);
  }

  /**
   * @covers \Drupal\aincient_export\PathUtil::destination
   * @covers \Drupal\aincient_export\PathUtil::normalize
   */
  public function testPathUtil(): void {
    $this->assertSame('index.html', PathUtil::destination('/'));
    $this->assertSame('pages/foo/index.html', PathUtil::destination('/pages/foo'));
    $this->assertSame('sitemap.xml', PathUtil::destination('/sitemap.xml'));
    $this->assertSame('a/b.css', PathUtil::destination('/a/b.css?v=1#x'));
    $this->assertSame('/fonts/a.woff2', PathUtil::normalize('/themes/x/css/../../../fonts/a.woff2'));
    $this->assertSame('/a/b', PathUtil::normalize('/a/./b'));
  }

}
