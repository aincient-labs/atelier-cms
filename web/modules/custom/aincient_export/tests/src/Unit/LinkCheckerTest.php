<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Unit;

use Drupal\aincient_export\LinkChecker;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aincient_export\LinkChecker
 * @group aincient_export
 */
class LinkCheckerTest extends UnitTestCase {

  private string $dir;

  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/aincient-export-test-' . getmypid();
    foreach (['', '/about', '/css', '/fonts'] as $sub) {
      mkdir($this->dir . $sub, 0777, TRUE);
    }
    file_put_contents($this->dir . '/about/index.html', '<html></html>');
    file_put_contents($this->dir . '/fonts/a.woff2', 'x');
    file_put_contents($this->dir . '/css/style.css', '@font-face { src: url(../fonts/a.woff2); } .x { background: url(../fonts/missing.woff2); }');
    file_put_contents($this->dir . '/index.html', <<<HTML
      <html><body>
        <link rel="stylesheet" href="/css/style.css">
        <a href="/about">ok: directory with index.html</a>
        <a href="https://example.com/about">ok: same-host absolute</a>
        <a href="https://elsewhere.example/x">external, skipped</a>
        <a href="/missing-page">broken</a>
        <a href="/user/login">ignored by pattern</a>
        <a href="#fragment">skipped</a>
      </body></html>
      HTML);
  }

  protected function tearDown(): void {
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file_info) {
      $file_info->isDir() ? rmdir($file_info->getPathname()) : unlink($file_info->getPathname());
    }
    rmdir($this->dir);
    parent::tearDown();
  }

  /**
   * @covers ::check
   */
  public function testCheck(): void {
    $broken = (new LinkChecker())->check($this->dir, 'https://example.com', ['/user/*']);

    $refs = array_column($broken, 'ref');
    $this->assertContains('/missing-page', $refs);
    // Relative CSS url() resolves against the stylesheet's directory.
    $this->assertContains('../fonts/missing.woff2', $refs);
    $this->assertNotContains('/about', $refs);
    $this->assertNotContains('https://example.com/about', $refs);
    $this->assertNotContains('https://elsewhere.example/x', $refs);
    $this->assertNotContains('/user/login', $refs);
    $this->assertNotContains('../fonts/a.woff2', $refs);
    $this->assertCount(2, $broken);
  }

}
