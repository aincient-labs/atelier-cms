<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_export\Unit;

use Drupal\aincient_export\ExportResult;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\aincient_export\ExportResult
 * @group aincient_export
 */
class ExportResultTest extends UnitTestCase {

  /**
   * A clean run is ok; skipped paths alone do not fail it.
   */
  public function testCleanRunIsOk(): void {
    $result = new ExportResult('/tmp/out');
    $result->skipped['/draft'] = 403;
    $this->assertTrue($result->ok());
  }

  /**
   * A referenced asset the kernel could not produce (e.g. a derivative the
   * toolkit failed to build) fails the run — the link checker only walks
   * a[href], so this is the only place it surfaces.
   */
  public function testMissingAssetFailsTheRun(): void {
    $result = new ExportResult('/tmp/out');
    $result->missingAssets['/sites/default/files/styles/1280w960h-webp-80/public/x.png.webp'] = 500;
    $this->assertFalse($result->ok());
  }

  public function testFailuresAndBrokenLinksStillFailTheRun(): void {
    $failed = new ExportResult('/tmp/out');
    $failed->failures['/boom'] = 'exception';
    $this->assertFalse($failed->ok());

    $broken = new ExportResult('/tmp/out');
    $broken->brokenLinks[] = ['file' => 'index.html', 'ref' => '/nowhere'];
    $this->assertFalse($broken->ok());
  }

}
