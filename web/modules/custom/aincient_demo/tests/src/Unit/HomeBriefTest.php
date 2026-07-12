<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_demo\Unit;

use Drupal\aincient_pages\ComponentCatalog;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Guards the demo homepage brief against component drift.
 *
 * The brief is shipped content that the install hook composes verbatim. If a
 * component is renamed or retired in ComponentCatalog, a stale brief would emit
 * a section the renderer drops — the exact failure mode that left testimonials
 * blank. This test fails the build the moment the brief references anything the
 * catalog doesn't place.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class HomeBriefTest extends UnitTestCase {

  /**
   * Decode the shipped brief.
   */
  private function brief(): array {
    $path = dirname(__DIR__, 3) . '/briefs/home.json';
    $this->assertFileExists($path, 'The demo homepage brief is shipped.');
    $schema = json_decode((string) file_get_contents($path), TRUE);
    $this->assertIsArray($schema, 'The brief is valid JSON.');
    return $schema;
  }

  /**
   * The brief is a landing page with at least one section.
   */
  public function testBriefShape(): void {
    $schema = $this->brief();
    $this->assertSame('landing', $schema['type'] ?? NULL);
    $this->assertNotEmpty($schema['title'] ?? '');
    $this->assertNotEmpty($schema['sections'] ?? []);
  }

  /**
   * Every section uses a component the catalog actually places (no drift).
   */
  public function testEverySectionIsPlaceable(): void {
    $placeable = ComponentCatalog::placeableNames();
    foreach ($this->brief()['sections'] as $i => $section) {
      $this->assertContains(
        $section['component'] ?? '',
        $placeable,
        sprintf('Demo homepage section %d ("%s") must be a placeable component.', $i, $section['component'] ?? ''),
      );
    }
  }

  /**
   * The page drives visitors to log in (the homepage's whole job).
   */
  public function testHomepageLinksToLogin(): void {
    $urls = [];
    foreach ($this->brief()['sections'] as $section) {
      if (isset($section['props']['cta_url'])) {
        $urls[] = $section['props']['cta_url'];
      }
    }
    $this->assertContains('/user/login', $urls, 'At least one homepage CTA points at the login screen.');
  }

}
