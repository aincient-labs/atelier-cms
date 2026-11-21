<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A pack's SDC override, and the way back to the original (DECISIONS 0455, P4a).
 *
 * `atelier_test_override_pack` replaces atelier:hero through core's
 * `replaces:`. Core negotiates every request for the hero to the pack; the
 * decorator lets the site's `overrides_off` and the studio's Compare
 * (withOriginals) reach the original through the same plugin manager every
 * render path uses.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class OverrideNegotiationTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation',
    'aincient_core', 'atelier', 'aincient_pages', 'atelier_test_override_pack',
  ];

  private function provider(): string {
    $manager = $this->container->get('plugin.manager.sdc');
    // A fresh request: core memoises a negotiation per theme within one.
    $this->container->get('Drupal\Core\Theme\ComponentNegotiator')->clearCache();
    return $manager->find('atelier:hero')->getPluginDefinition()['provider'];
  }

  private function renderHero(): string {
    $element = [
      '#type' => 'component',
      '#component' => 'atelier:hero',
      '#props' => ['heading' => 'Hello', 'variant' => 'centered', 'tone' => 'default'],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  public function testThePackOverridesTheBuiltIn(): void {
    $this->assertSame('atelier_test_override_pack', $this->provider());
    $this->assertStringContainsString('data-atelier-override', $this->renderHero());
  }

  public function testOverridesOffRendersTheOriginal(): void {
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['atelier:hero'])->save();
    $this->assertSame('atelier', $this->provider());
    $this->assertStringNotContainsString('data-atelier-override', $this->renderHero());
  }

  public function testWithOriginalsIsScoped(): void {
    $negotiator = $this->container->get('Drupal\Core\Theme\ComponentNegotiator');
    $inside = $negotiator->withOriginals(fn() => $this->provider());
    $this->assertSame('atelier', $inside);
    $this->assertSame('atelier_test_override_pack', $this->provider(), 'Outside the callback the override applies again.');
  }

  /**
   * An override never owns a name: stored ids qualify from the ORIGINAL, so
   * turning the override off still reverts every page (failure modes S2.2).
   */
  public function testStoredIdsQualifyFromTheOriginal(): void {
    $providers = \Aincient\PageModel\ComponentId::providers($this->container->get('aincient_pages.catalog')->discovered());
    $this->assertSame('atelier', $providers['hero']);
    $this->assertSame('atelier', $providers['block'], 'The virtual block belongs to the built-ins.');
  }

}
