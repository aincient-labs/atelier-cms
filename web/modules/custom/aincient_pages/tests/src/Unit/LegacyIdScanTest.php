<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\Catalog\LegacyIdScan;
use Drupal\Tests\UnitTestCase;

/**
 * pack-validate's scan for ids written before 0.30.0 (refused since 0.32.0).
 *
 * @group aincient
 */
final class LegacyIdScanTest extends UnitTestCase {

  private string $dir;

  protected function setUp(): void {
    parent::setUp();
    $this->dir = sys_get_temp_dir() . '/legacy-id-scan-' . bin2hex(random_bytes(4));
    mkdir($this->dir . '/config/install', 0777, TRUE);
    mkdir($this->dir . '/components/promo', 0777, TRUE);
  }

  protected function tearDown(): void {
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
      $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($this->dir);
    parent::tearDown();
  }

  public function testOldIdInAShippedKindIsReported(): void {
    file_put_contents($this->dir . '/config/install/aincient_pages.page_kind.promo.yml', "id: promo\nmode: composition\ncomponents:\n  - aincient_pages:hero\n  - 'atelier:cta'\nopener: aincient_pages:hero\n");
    $errors = LegacyIdScan::scan($this->dir);
    $this->assertCount(1, $errors, implode("\n", $errors));
    $this->assertStringContainsString('aincient_pages.page_kind.promo.yml names aincient_pages:hero, which is atelier:hero', $errors[0]);
  }

  public function testOldIdInTemplateAndReplacesIsReported(): void {
    file_put_contents($this->dir . '/components/promo/promo.twig', "{{ include('aincient_pages:card', {}) }}");
    file_put_contents($this->dir . '/components/promo/promo.component.yml', "name: Promo\nreplaces: aincient_pages:hero\n");
    $errors = implode("\n", LegacyIdScan::scan($this->dir));
    $this->assertStringContainsString('promo.twig names aincient_pages:card', $errors);
    $this->assertStringContainsString('promo.component.yml names aincient_pages:hero', $errors);
  }

  public function testNewIdsPass(): void {
    file_put_contents($this->dir . '/config/install/aincient_pages.page_kind.promo.yml', "id: promo\nmode: composition\ncomponents:\n  - atelier:hero\n");
    file_put_contents($this->dir . '/components/promo/promo.twig', "{{ include('atelier:card', {}) }}");
    $this->assertSame([], LegacyIdScan::scan($this->dir));
  }

}
