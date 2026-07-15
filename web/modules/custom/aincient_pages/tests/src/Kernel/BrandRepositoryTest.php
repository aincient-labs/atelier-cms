<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BrandRepository;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the brand store: registry-validated token updates + CSS var output.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BrandRepositoryTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function brand(): BrandRepository {
    return $this->container->get('aincient_pages.brand');
  }

  public function testUpdateValidatesPerTypeAndIgnoresJunk(): void {
    $applied = $this->brand()->update([
      'brand_primary' => 'oklch(0.55 0.2 280)',     // Tier 1: a raw colour is allowed.
      'primary' => 'var(--brand-accent)',            // Tier 2 colour: reference only.
      'button_radius' => '0.25rem',
      'card_shadow' => 'var(--shadow-lg)',
      'accent' => 'teal',                            // raw colour below Tier 1 → dropped.
      'bogus_token' => '#ffffff',                    // unknown token → dropped.
      'primary_foreground' => 'white; } body{display:none}', // injection → dropped.
    ]);
    $tokens = $this->brand()->tokens();
    // Any valid CSS-of-type is stored verbatim (no lowercasing of oklch/var).
    $this->assertSame('oklch(0.55 0.2 280)', $tokens['brand_primary']);
    $this->assertSame('var(--brand-accent)', $tokens['primary']);
    $this->assertSame('0.25rem', $tokens['button_radius']);
    $this->assertSame('var(--shadow-lg)', $tokens['card_shadow']);
    // A raw colour below Tier 1, an unknown token, and an injection are all
    // dropped (the install seeds no token overrides, so they're simply absent).
    $this->assertArrayNotHasKey('accent', $tokens);
    $this->assertArrayNotHasKey('bogus_token', $tokens);
    $this->assertArrayNotHasKey('primary_foreground', $tokens);
    $this->assertContains('button_radius → 0.25rem', $applied);
  }

  public function testCssVariablesEmitsSafeRootOverride(): void {
    $this->brand()->update([
      'brand_primary' => 'oklch(0.55 0.2 280)',       // Tier 1 raw colour.
      'foreground' => 'red; } html{display:none}',     // injection → dropped.
    ]);
    $css = $this->brand()->cssVariables();
    $this->assertStringStartsWith(':root{', $css);
    $this->assertStringContainsString('--brand-primary:oklch(0.55 0.2 280)', $css);
    // The injection attempt never reaches the inline <style>.
    $this->assertStringNotContainsString('display:none', $css);
    $this->assertSame(1, substr_count($css, '{'), 'Exactly one rule — no breakout.');
  }

  public function testVisualBriefResolvesTokensToReadableText(): void {
    // A neo-brutalist-ish brand: concrete colours, a Tailwind-referenced accent,
    // square corners (via a resolved radius chain), and bold borders.
    $this->brand()->update([
      'brand_primary' => '#b3fff0',
      'brand_accent' => 'var(--color-fuchsia-300)',
      'neutral_surface' => '#FFFFFF',
      'neutral_ink' => '#1a1a1a',
      'radius_2xl' => '0px',
      'border_width' => '2px',
      'font_family_display' => '"Playfair Display", Georgia, serif',
      'font_family_base' => '"Inter", ui-sans-serif, sans-serif',
    ]);
    $brief = $this->brand()->visualBrief();
    // Concrete colours pass through; a Tailwind reference is named, not raw CSS.
    $this->assertStringContainsString('Primary colour: #b3fff0', $brief);
    $this->assertStringContainsString('Accent colour: fuchsia 300', $brief);
    $this->assertStringNotContainsString('var(', $brief);
    // Font stacks collapse to the lead family name (dequoted, fallbacks dropped).
    $this->assertStringContainsString('Heading font: Playfair Display', $brief);
    $this->assertStringContainsString('Body font: Inter', $brief);
    // background/foreground resolve through the default semantic → palette chain.
    $this->assertStringContainsString('Background: #FFFFFF', $brief);
    $this->assertStringContainsString('Text colour: #1a1a1a', $brief);
    // card_radius (default var(--radius-2xl)) resolves to the 0px override → sharp.
    $this->assertStringContainsString('Corners: sharp, square corners', $brief);
    $this->assertStringContainsString('Borders: bold, heavy borders (2px)', $brief);
  }

  public function testVisualBriefDescribesRoundedAndHairlineDefaults(): void {
    // The shipped defaults (no overrides): the Atelier pairing (Fraunces display
    // + Schibsted Grotesk body), rounded card corners, and a 1px hairline.
    $brief = $this->brand()->visualBrief();
    $this->assertStringContainsString('Heading font: Fraunces', $brief);
    $this->assertStringContainsString('Body font: Schibsted Grotesk', $brief);
    $this->assertStringContainsString('Corners: rounded corners (1rem)', $brief);
    $this->assertStringContainsString('Borders: thin hairline borders (1px)', $brief);
  }

  public function testWebFontsLoadAndSanitise(): void {
    // Valid family names load; an unsafe one is dropped; URL is built by us.
    $this->brand()->update([], ['Playfair Display', 'evil"/><script>']);
    $this->assertSame(['Playfair Display'], $this->brand()->fontFamilies());
    $href = $this->brand()->fontLinkHref();
    $this->assertStringStartsWith('https://fonts.googleapis.com/css2?', $href);
    $this->assertStringContainsString('family=Playfair+Display', $href);
    $this->assertStringNotContainsString('<', $href);
    // The always-on emoji font is self-hosted (DECISIONS 0058) — it must NEVER be
    // requested from Google, so a brand on a system-font stack stays GDPR-clean.
    $this->assertStringNotContainsString('Noto+Emoji', $href);
  }

  public function testFontDeliveryDefaultsToGoogle(): void {
    // Absent config → Google (consent-gated), so an un-migrated site renders as
    // before with only a consent gate added in front.
    $this->assertSame(BrandRepository::DELIVERY_GOOGLE, $this->brand()->fontDelivery());
  }

  public function testStatusDefaultsToIdeatingUnlocked(): void {
    // A fresh site has never set a status — a new brand should diverge freely.
    $status = $this->brand()->status();
    $this->assertSame(BrandRepository::STAGE_IDEATING, $status['stage']);
    $this->assertFalse($status['locked']);
  }

  public function testSetStatusRoundTripsAndCoercesUnknownStage(): void {
    $saved = $this->brand()->setStatus(BrandRepository::STAGE_POLISH, TRUE);
    $this->assertSame(['stage' => 'polish', 'locked' => TRUE], $saved);
    // Read back through a fresh config read.
    $read = $this->brand()->status();
    $this->assertSame('polish', $read['stage']);
    $this->assertTrue($read['locked']);

    // An unknown stage is coerced to the default rather than persisted verbatim.
    $coerced = $this->brand()->setStatus('nonsense', FALSE);
    $this->assertSame('ideating', $coerced['stage']);
    $this->assertSame('ideating', $this->brand()->status()['stage']);
  }

  public function testWebFontDescriptorPerDeliveryMode(): void {
    $this->brand()->update([], ['Playfair Display']);

    // Google delivery: descriptor points at the Google URL, to be consent-gated.
    $google = $this->brand()->webFont();
    $this->assertSame('google', $google['mode']);
    $this->assertStringStartsWith('https://fonts.googleapis.com/css2?', $google['href']);

    // Self-host delivery: the invariant is that we NEVER hot-link Google. The
    // descriptor is either 'selfhost' (woff2 vendored to our origin — the save
    // subscriber fetched them) or 'none' (fetch unavailable → system stack), but
    // never 'google'. Asserting the invariant keeps the test network-independent.
    $this->brand()->update([], NULL, BrandRepository::DELIVERY_SELFHOST);
    $this->assertSame(BrandRepository::DELIVERY_SELFHOST, $this->brand()->fontDelivery());
    $selfhost = $this->brand()->webFont();
    $this->assertNotSame('google', $selfhost['mode']);
    if ($selfhost['mode'] === 'selfhost') {
      $this->assertStringNotContainsString('googleapis', $selfhost['href']);
    }
  }

}
