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

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

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
   * The hex echo grounds an oklch literal — including the hue-0 pink trap.
   *
   * oklch(0.98 0.01 0) reads as "white" to a model but is #FFF6F8 (pink);
   * the approximation is what lets the agent see its own tint. Hex input has
   * nothing to add; an unparseable value is not guessed at.
   */
  public function testHexApproximationGroundsColourLiterals(): void {
    $c = $this->contrast();
    $this->assertSame('#FFF6F8', $c->hexApproximation('oklch(0.98 0.01 0)'));
    $this->assertSame('#FFFFFF', $c->hexApproximation('rgb(255 255 255)'));
    $this->assertNull($c->hexApproximation('#ffffff'));
    $this->assertNull($c->hexApproximation('not-a-colour'));
    $this->assertNull($c->hexApproximation(''));
  }

  /**
   * A var() reference is FOLLOWED, not skipped — the brown-instead-of-yellow bug.
   *
   * A swatch picked from the studio's Tailwind grid rides the wire as the
   * opaque literal `var(--color-yellow-100)`. Echoed bare into the colour
   * specialist's prompt it carried no number, so "make primary darker" had
   * nothing to subtract from and the model anchored on the only concrete
   * primary present — the SAVED palette — and darkened that instead.
   */
  public function testHexApproximationFollowsVarReferences(): void {
    $c = $this->contrast();

    // Tier 0: a Tailwind swatch reference resolves to its concrete colour.
    $this->assertSame('#FEF9C2', $c->hexApproximation('var(--color-yellow-100)'));

    // A registry token reference resolves through the effective token map.
    $this->assertNotNull($c->hexApproximation('var(--brand-primary)'));

    // Against a DRAFT: the reference must resolve to what is on screen, not to
    // the saved value. Overrides are accepted keyed by css_var (how the studio
    // draft rides the wire) as well as by token name.
    $draft = ['brand-primary' => 'var(--color-yellow-100)'];
    $this->assertSame('#FEF9C2', $c->hexApproximation('var(--brand-primary)', $draft));
    $this->assertSame('#FEF9C2', $c->hexApproximation('var(--brand-primary)', ['brand_primary' => 'var(--color-yellow-100)']));

    // An unknown reference is not guessed at.
    $this->assertNull($c->hexApproximation('var(--no-such-token)'));
  }

  /**
   * colorEcho is the ONE grounding renderer every model-facing echo uses.
   *
   * A literal gets its hex; a reference also gets the literal behind it,
   * because a relative edit ("darker", "warmer") needs the numbers and not
   * just the colour's identity.
   */
  public function testColorEchoRendersOneGroundingSuffix(): void {
    $c = $this->contrast();

    $this->assertSame(' (≈ #AB5637)', $c->colorEcho('oklch(0.55 0.12 40)'));
    $this->assertSame(
      ' (= oklch(97.3% 0.071 103.193) ≈ #FEF9C2)',
      $c->colorEcho('var(--color-yellow-100)'),
    );

    // Nothing to add: already hex, unparseable, empty, or a non-colour token.
    $this->assertSame('', $c->colorEcho('#ffffff'));
    $this->assertSame('', $c->colorEcho('not-a-colour'));
    $this->assertSame('', $c->colorEcho(''));
    $this->assertSame('', $c->colorEcho('var(--radius-lg)'));
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
