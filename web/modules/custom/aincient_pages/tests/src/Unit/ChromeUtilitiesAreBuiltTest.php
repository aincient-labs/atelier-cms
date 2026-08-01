<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards that every utility the chrome Twig names exists in BOTH built bundles.
 *
 * The chrome components write their height utilities as Twig *literal maps*
 * precisely so Tailwind v4's content scanner can see them — but that only helps
 * if the CSS is actually rebuilt. Two independent Tailwind builds consume the
 * same components:
 *
 *   - web/themes/custom/aincient_theme/css/aincient-theme.css  (public front end)
 *   - web/modules/custom/aincient_pages/assets/aincient-pages.css  (preview iframe)
 *
 * Both are committed build artifacts, and nothing about editing a Twig template
 * forces either to be regenerated. That has now failed twice: DECISIONS 0271
 * shipped `logo_size` with `h-5`/`h-6`/`max-h-full` missing from the theme
 * bundle (caught pre-ship, rebuilt), and the module bundle was left stale
 * anyway — so `logo_size: small` rendered the logo at the 480x480 derivative's
 * intrinsic size in the Globals studio preview while the front end was correct.
 *
 * A missing utility is invisible in review (the Twig is right, the props are
 * right, the tests pass) and only shows up as a visually broken preview, which
 * is why this is asserted mechanically rather than trusted to a rebuild habit.
 *
 * @group aincient_pages
 */
final class ChromeUtilitiesAreBuiltTest extends TestCase {

  /**
   * Built stylesheets that must contain every chrome utility, keyed by label.
   */
  private const BUNDLES = [
    'front end (aincient_theme)' => 'web/themes/custom/aincient_theme/css/aincient-theme.css',
    'preview iframe (aincient_pages)' => 'web/modules/custom/aincient_pages/assets/aincient-pages.css',
  ];

  /**
   * Chrome components whose class attributes drive the assertions.
   */
  private const COMPONENTS = [
    'web/modules/custom/aincient_pages/components/site-header/site-header.twig',
    'web/modules/custom/aincient_pages/components/site-footer/site-footer.twig',
  ];

  /**
   * The repository root, found by walking up to the composer.json that owns it.
   *
   * Walked rather than counted with dirname($x, N): the depth is easy to get
   * wrong by one and the resulting failure ("component missing") reads like the
   * real defect this test hunts for.
   */
  private function repoRoot(): string {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
      if (is_file("$dir/composer.json") && is_dir("$dir/web/modules/custom")) {
        return $dir;
      }
      $dir = dirname($dir);
    }
    self::fail('Could not locate the repository root from ' . __DIR__);
  }

  /**
   * Utilities named in the chrome Twig that Tailwind must have emitted.
   *
   * Deliberately narrow: sizing utilities chosen by a prop value, which are the
   * ones that silently vanish from a stale bundle and visibly break layout. A
   * broader sweep of every class in the templates would drag in variants whose
   * selector spelling (escaping, nesting) makes a literal match unreliable.
   */
  private function expectedUtilities(): array {
    $root = $this->repoRoot();
    $found = [];
    foreach (self::COMPONENTS as $relative) {
      $path = "$root/$relative";
      self::assertFileExists($path, "Chrome component missing: $relative");
      $twig = (string) file_get_contents($path);

      // Height steps from the literal maps, e.g. {'small': 'h-6', ...}.
      preg_match_all("/'(h-\d+)'/", $twig, $m);
      $found = array_merge($found, $m[1]);

      // Constraints written directly onto the logo img.
      foreach (['max-h-full'] as $utility) {
        if (str_contains($twig, $utility)) {
          $found[] = $utility;
        }
      }
    }
    $found = array_values(array_unique($found));
    sort($found);

    // If this ever comes back empty the regex has drifted and the test would
    // pass vacuously — the exact failure mode it exists to prevent.
    self::assertNotEmpty($found, 'Parsed no utilities from the chrome components.');

    return $found;
  }

  /**
   * Every utility the chrome names must be present in every built bundle.
   */
  public function testChromeUtilitiesArePresentInEveryBuiltBundle(): void {
    $root = $this->repoRoot();
    $utilities = $this->expectedUtilities();

    foreach (self::BUNDLES as $label => $relative) {
      $path = "$root/$relative";
      self::assertFileExists($path, "Built stylesheet missing: $relative");
      $css = (string) file_get_contents($path);

      $missing = [];
      foreach ($utilities as $utility) {
        // Minified Tailwind emits `.h-6{height:...}`; the brace anchors the
        // match so `.h-6` cannot be satisfied by `.h-60` or `.max-h-6`.
        if (!str_contains($css, ".$utility{")) {
          $missing[] = $utility;
        }
      }

      self::assertSame([], $missing, sprintf(
        "%s is stale — missing %s.\nThe chrome Twig names these utilities but %s does not define them, so the affected sizes render unstyled.\nRebuild it: npm run build in %s",
        $label,
        implode(', ', $missing),
        $relative,
        str_starts_with($relative, 'web/themes')
          ? 'web/themes/custom/aincient_theme'
          : 'web/modules/custom/aincient_pages',
      ));
    }
  }

}
