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
   * The page drives visitors to log in (the homepage's whole job). The CTA may
   * carry a `?destination=…` so a logged-in operator lands in the console.
   */
  public function testHomepageLinksToLogin(): void {
    $urls = [];
    foreach ($this->brief()['sections'] as $section) {
      if (isset($section['props']['cta_url'])) {
        $urls[] = $section['props']['cta_url'];
      }
    }
    $login = array_filter($urls, static fn (string $url): bool => str_starts_with($url, '/user/login'));
    $this->assertNotEmpty($login, 'At least one homepage CTA points at the login screen.');
  }

  /**
   * Every `@<slug>` image placeholder in the brief has a shipped-image entry in
   * the install manifest — so install can resolve it to a real media token
   * instead of leaking a literal "@slug" onto the page.
   */
  public function testImagePlaceholdersAreDeclared(): void {
    // The manifest lives at file scope in the (non-autoloaded) .install; require
    // it once so AINCIENT_DEMO_IMAGES is defined. It only declares a const +
    // functions — no side effects at include time.
    require_once dirname(__DIR__, 3) . '/aincient_demo.install';
    $declared = array_map(static fn (string $slug): string => '@' . $slug, array_keys(AINCIENT_DEMO_IMAGES));

    $placeholders = [];
    array_walk_recursive($this->brief(), static function ($value) use (&$placeholders): void {
      if (is_string($value) && preg_match('/^@[a-z0-9_-]+$/', $value)) {
        $placeholders[] = $value;
      }
    });

    foreach (array_unique($placeholders) as $token) {
      $this->assertContains($token, $declared, sprintf('Image placeholder "%s" must have an entry in AINCIENT_DEMO_IMAGES.', $token));
    }
  }

}
