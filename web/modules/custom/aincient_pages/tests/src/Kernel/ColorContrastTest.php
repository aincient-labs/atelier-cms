<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\ColorContrast;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the WCAG contrast checker for the surface/on-colour pairs.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ColorContrastTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_pages'];

  private function contrast(): ColorContrast {
    return $this->container->get('aincient_pages.color_contrast');
  }

  public function testShippedDefaultsAllPassAa(): void {
    // The out-of-box brand (registry defaults, no overrides) must be accessible.
    $this->assertSame([], $this->contrast()->failures(), 'A shipped default pair fails WCAG AA.');
  }

  public function testEveryDeclaredPairIsReported(): void {
    $report = $this->contrast()->pairReport();
    $surfaces = array_column($report, 'surface');
    foreach (['background', 'primary', 'accent', 'muted', 'card', 'brand_primary', 'brand_accent'] as $s) {
      $this->assertContains($s, $surfaces, "Pair for $s not reported.");
    }
  }

  public function testBlackOnWhiteIsMaxRatio(): void {
    // Pure black/white is the WCAG ceiling, 21:1.
    $report = $this->contrast()->pairReport([
      'background' => '#ffffff',
      'foreground' => '#000000',
    ]);
    $bg = $this->pair($report, 'background');
    $this->assertEqualsWithDelta(21.0, $bg['ratio'], 0.1);
    $this->assertTrue($bg['passes']);
  }

  public function testLowContrastPairFailsAndIsReported(): void {
    // Near-white text on a white surface must be flagged.
    $failures = $this->contrast()->failures([
      'background' => '#ffffff',
      'foreground' => '#f2f2f2',
    ]);
    $surfaces = array_column($failures, 'surface');
    $this->assertContains('background', $surfaces);
  }

  public function testResolvesVarReferenceThroughTailwindPalette(): void {
    // A var(--color-*) reference resolves to a concrete swatch and computes.
    $report = $this->contrast()->pairReport([
      'background' => 'var(--color-slate-900)',
      'foreground' => 'var(--color-white)',
    ]);
    $bg = $this->pair($report, 'background');
    $this->assertNotNull($bg['ratio'], 'var() chain did not resolve to a colour.');
    $this->assertTrue($bg['passes'], 'White on slate-900 should pass AA.');
  }

  public function testParsesOklchValues(): void {
    // A dark oklch surface with light oklch text resolves and passes.
    $report = $this->contrast()->pairReport([
      'background' => 'oklch(0.15 0.02 270)',
      'foreground' => 'oklch(0.98 0.01 180)',
    ]);
    $bg = $this->pair($report, 'background');
    $this->assertNotNull($bg['ratio'], 'oklch() did not parse.');
    $this->assertTrue($bg['passes']);
  }

  public function testUnparseableColourReportsNullRatio(): void {
    // A value we cannot resolve is reported as null, never guessed.
    $report = $this->contrast()->pairReport([
      'background' => 'lab(50% 40 59.5)',
      'foreground' => '#000000',
    ]);
    $bg = $this->pair($report, 'background');
    $this->assertNull($bg['ratio']);
    $this->assertNull($bg['passes']);
  }

  public function testLegibilityMachineryIsInertUntilATokenOptsIn(): void {
    // DECISIONS 0067 decommissioned the `legible_on` advisory: accent TEXT now
    // uses the derived `primary_on_surface` token (primary blended toward the
    // page ink), which is legible on any neutral surface by construction, so no
    // token declares `legible_on` any more. The machinery is kept for a possible
    // first-class `link` token follow-up but reports nothing until a token opts
    // back in — so the default report is empty and there are no failures.
    $this->assertSame([], $this->contrast()->legibilityReport(), 'No token declares legible_on, so the report is empty.');
    $this->assertSame([], $this->contrast()->legibilityFailures(), 'An inert legibility check can never fail.');
  }

  /**
   * Pull a single pair from a report by surface name.
   */
  private function pair(array $report, string $surface): array {
    foreach ($report as $row) {
      if ($row['surface'] === $surface) {
        return $row;
      }
    }
    $this->fail("Pair $surface not in report.");
  }

}
