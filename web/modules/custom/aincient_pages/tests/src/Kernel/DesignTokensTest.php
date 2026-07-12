<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\DesignTokens;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the design-token registry: manifest integrity + AI summary.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class DesignTokensTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_pages'];

  private function tokens(): DesignTokens {
    return $this->container->get('aincient_pages.design_tokens');
  }

  public function testManifestLoadsAndIsWellFormed(): void {
    $all = $this->tokens()->all();
    $this->assertNotEmpty($all);
    foreach ($all as $name => $def) {
      foreach (['tier', 'category', 'css_var', 'type', 'default', 'label'] as $field) {
        $this->assertArrayHasKey($field, $def, "$name missing $field");
      }
      $this->assertContains($def['tier'], DesignTokens::TIERS, "$name has an unknown tier");
      $this->assertContains($def['category'], DesignTokens::CATEGORIES, "$name has an unknown category");
    }
  }

  public function testVarDefaultsAreReferentiallyIntact(): void {
    // Every var(--x) default must resolve to a real token's css_var.
    foreach ($this->tokens()->all() as $name => $def) {
      if (str_starts_with((string) $def['default'], 'var(')) {
        $this->assertTrue(
          $this->tokens()->validate($name, $def['default']),
          "$name default {$def['default']} references an unknown token",
        );
      }
    }
  }

  public function testManifestSummaryListsTokensByTier(): void {
    $summary = $this->tokens()->manifestSummary();
    // Each tier header is prefaced with its purpose/reference rule (the
    // convention is carried by structure, not a tier prefix on every name).
    $this->assertStringContainsString('PALETTE tokens —', $summary);
    $this->assertStringContainsString('SEMANTIC tokens —', $summary);
    $this->assertStringContainsString('COMPONENT tokens —', $summary);
    // The reference contract is stated for the agent.
    $this->assertStringContainsString('May reference PALETTE only', $summary);
    $this->assertStringContainsString('button_radius', $summary);
  }

  public function testPairingConventionUsesForegroundSuffix(): void {
    $t = $this->tokens();
    // Standardised on-colour pairing: the palette on-colour is *_foreground,
    // never the old *_contrast spelling.
    $this->assertNotNull($t->get('brand_primary_foreground'));
    $this->assertNull($t->get('brand_primary_contrast'));
    // Component tabs token matches its component name ({component}_{property}).
    $this->assertNotNull($t->get('tabs_radius'));
    $this->assertNull($t->get('tab_radius'));
  }

  public function testSurfacesDeclareAnOnColourPair(): void {
    $t = $this->tokens();
    $pairs = [];
    foreach ($t->pairs() as $p) {
      $pairs[$p['surface']] = $p['on'];
    }
    // Every shadcn-style surface ships a foreground partner via `on:`.
    $this->assertSame('foreground', $pairs['background'] ?? NULL);
    $this->assertSame('primary_foreground', $pairs['primary'] ?? NULL);
    $this->assertSame('card_foreground', $pairs['card'] ?? NULL);
    // …and the palette brand fills are paired too (incl. the new accent pair).
    $this->assertSame('brand_primary_foreground', $pairs['brand_primary'] ?? NULL);
    $this->assertSame('brand_accent_foreground', $pairs['brand_accent'] ?? NULL);
    // Each `on:` target is itself a real token.
    foreach ($pairs as $surface => $on) {
      $this->assertNotNull($t->get($on), "on-colour $on (for $surface) is not a token");
    }
  }

  public function testLayeredReferenceContractIsEnforced(): void {
    $t = $this->tokens();
    // Semantic colour may reference a Tier 1 palette token …
    $this->assertTrue($t->validate('primary', 'var(--brand-primary)'));
    // … but NOT reach past it into the Tier 0 Tailwind base …
    $this->assertFalse($t->validate('primary', 'var(--color-indigo-500)'));
    // … and may NOT carry a raw colour (raw colours enter only at Tier 1).
    $this->assertFalse($t->validate('primary', '#ff0000'));

    // Tier 1 palette colour references Tier 0, and is the one place a raw
    // colour is allowed (the exact-match escape hatch).
    $this->assertTrue($t->validate('brand_primary', 'var(--color-sky-600)'));
    $this->assertTrue($t->validate('brand_primary', '#bada55'));
    // But a palette token may not reference a sibling/higher tier.
    $this->assertFalse($t->validate('brand_primary', 'var(--primary)'));

    // Component may reference Tier 1 or Tier 2, not Tier 0.
    $this->assertTrue($t->validate('button_bg', 'var(--primary)'));
    $this->assertTrue($t->validate('button_radius', 'var(--radius-full)'));
    $this->assertFalse($t->validate('button_bg', 'var(--color-red-500)'));
  }

}
