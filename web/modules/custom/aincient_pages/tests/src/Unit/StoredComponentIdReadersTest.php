<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every reader of stored page layers goes through the one translator.
 *
 * From 0.30.0 stored component ids are full (`atelier:hero`) while code above
 * storage speaks short names (DECISIONS 0536). A class that decodes
 * `field_page_structure` / `field_page_content` itself and compares
 * `component` without {@see \Aincient\PageModel\ComponentId} silently
 * changes behaviour (failure modes R2: CollectionInventory lost every
 * collection from the static export).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class StoredComponentIdReadersTest extends UnitTestCase {

  public function testEveryReaderUsesTheTranslator(): void {
    // web/modules: our code lives in custom/ and studio/.
    $modules = dirname(__DIR__, 5);
    $offenders = [];
    foreach (['custom', 'studio'] as $dir) {
      $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$modules/$dir", \FilesystemIterator::SKIP_DOTS));
      foreach ($files as $file) {
        $this->check($file->getPathname(), substr($file->getPathname(), strlen($modules) + 1), $offenders);
      }
    }
    $this->assertSame([], $offenders, 'These read stored page layers without ComponentId — shorten the ids first (ComponentId::shortenStructure / shortenContent).');
  }

  private function check(string $path, string $relative, array &$offenders): void {
    if (!str_ends_with($path, '.php') || str_contains($relative, '/tests/') || str_contains($relative, '/node_modules/')) {
      return;
    }
    $code = (string) file_get_contents($path);
    if (preg_match("/->get\\(\\s*'field_page_(structure|content)'\\s*\\)/", $code) && !str_contains($code, 'ComponentId::')) {
      $offenders[] = $relative;
    }
  }

}
