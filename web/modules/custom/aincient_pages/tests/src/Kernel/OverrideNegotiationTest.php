<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A pack's SDC override, and the way back to the original (DECISIONS 0455, P4a).
 *
 * `atelier_test_override_pack` replaces aincient_pages:hero through core's
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
    'aincient_core', 'aincient_pages', 'atelier_test_override_pack',
  ];

  private function provider(): string {
    $manager = $this->container->get('plugin.manager.sdc');
    // A fresh request: core memoises a negotiation per theme within one.
    $this->container->get('Drupal\Core\Theme\ComponentNegotiator')->clearCache();
    return $manager->find('aincient_pages:hero')->getPluginDefinition()['provider'];
  }

  private function renderHero(): string {
    $element = [
      '#type' => 'component',
      '#component' => 'aincient_pages:hero',
      '#props' => ['heading' => 'Hello', 'variant' => 'centered', 'tone' => 'default'],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  public function testThePackOverridesTheBuiltIn(): void {
    $this->assertSame('atelier_test_override_pack', $this->provider());
    $this->assertStringContainsString('data-atelier-override', $this->renderHero());
  }

  public function testOverridesOffRendersTheOriginal(): void {
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['aincient_pages:hero'])->save();
    $this->assertSame('aincient_pages', $this->provider());
    $this->assertStringNotContainsString('data-atelier-override', $this->renderHero());
  }

  public function testWithOriginalsIsScoped(): void {
    $negotiator = $this->container->get('Drupal\Core\Theme\ComponentNegotiator');
    $inside = $negotiator->withOriginals(fn() => $this->provider());
    $this->assertSame('aincient_pages', $inside);
    $this->assertSame('atelier_test_override_pack', $this->provider(), 'Outside the callback the override applies again.');
  }

}
