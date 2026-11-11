<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BrandRepository;
use Drupal\aincient_pages\ConsentSettings;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the GDPR consent model: categories + when the banner is needed.
 *
 * The key property is that the banner is HONEST — it appears only when a
 * non-essential third party is actually loaded. Under Google font delivery the
 * "fonts" category is active and the banner shows; under self-host (nothing
 * leaves the origin) it does not.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ConsentSettingsTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function consent(): ConsentSettings {
    return $this->container->get('aincient_pages.consent');
  }

  private function brand(): BrandRepository {
    return $this->container->get('aincient_pages.brand');
  }

  public function testBannerSilentOnFreshInstall(): void {
    // Out of the box the default typefaces (Fraunces + Schibsted Grotesk) are
    // BUNDLED and self-hosted via the always-on brand-fonts library — nothing
    // leaves the origin, so no non-essential category is active and no banner
    // shows. The honest default is silence, not consent theatre.
    $this->assertFalse($this->consent()->isActive());
    $this->assertFalse($this->categoryById('fonts')['active']);
  }

  public function testBannerActiveUnderGoogleDelivery(): void {
    // The banner is honest: it appears only once a live third-party request
    // exists. The bundled defaults and curated pairings (0513) make none, so the
    // operator must add a custom Google-delivered font → fonts goes live → banner.
    $this->brand()->update([], ['Roboto'], BrandRepository::DELIVERY_GOOGLE);
    $this->assertTrue($this->consent()->isActive());

    $fonts = $this->categoryById('fonts');
    $this->assertTrue($fonts['active']);
    $this->assertFalse($fonts['required']);
  }

  public function testBannerSilentForACuratedPairing(): void {
    // The Editorial pairing ships with the module (0513): no Google request even
    // under Google delivery, so no banner.
    $this->brand()->update([], ['Playfair Display', 'Lora'], BrandRepository::DELIVERY_GOOGLE);
    $this->assertFalse($this->consent()->isActive());
  }

  public function testConsentCookieIsPerSite(): void {
    $this->config('system.site')->set('uuid', 'site-a')->save();
    $a = $this->consent()->cookieName();
    $this->config('system.site')->set('uuid', 'site-b')->save();
    $this->assertNotSame($a, $this->consent()->cookieName());
    $this->assertStringNotContainsString('site-b', $this->consent()->cookieName(), 'The uuid itself is not exposed.');
  }

  public function testBannerSilentUnderSelfHost(): void {
    // Self-host (with nothing vendored) makes no third-party request, so no
    // non-essential category is active → no banner theatre.
    $this->brand()->update([], NULL, BrandRepository::DELIVERY_SELFHOST);
    $this->assertFalse($this->consent()->isActive());
    $this->assertFalse($this->categoryById('fonts')['active']);
  }

  public function testNecessaryIsAlwaysRequiredAndPlaceholdersInactive(): void {
    $necessary = $this->categoryById('necessary');
    $this->assertTrue($necessary['required']);
    $this->assertTrue($necessary['active']);

    // Analytics/embeds are shown for transparency but inactive until built.
    $this->assertFalse($this->categoryById('analytics')['active']);
    $this->assertFalse($this->categoryById('embeds')['active']);
  }

  public function testConfigJsonCarriesCookieAndCategories(): void {
    $this->config('system.site')->set('uuid', 'site-under-test')->save();
    $config = json_decode($this->consent()->configJson(), TRUE);
    $this->assertSame($this->container->get('aincient_pages.consent')->cookieName(), $config['cookie']);
    $this->assertStringStartsWith(ConsentSettings::COOKIE . '_', $config['cookie'], 'Scoped per site (0513).');
    $ids = array_column($config['categories'], 'id');
    $this->assertSame(['necessary', 'fonts', 'analytics', 'embeds'], $ids);
  }

  /**
   * Fetch one category descriptor by id.
   */
  private function categoryById(string $id): array {
    foreach ($this->consent()->categories() as $category) {
      if ($category['id'] === $id) {
        return $category;
      }
    }
    $this->fail("No consent category '$id'");
  }

}
