<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\Core\Render\Component\Exception\ComponentNotFoundException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The 0.30.0 bridge for pack code ended in 0.32.0 (DECISIONS 0534).
 *
 * `atelier_test_legacy_pack` still names the built-ins by their old
 * `aincient_pages:` ids: its `hero` replaces `aincient_pages:hero`, and its
 * `legacy-include` template includes `aincient_pages:divider`. Since 0.32.0
 * neither is read as the new id: the replace overrides nothing (the original
 * renders, the site keeps working) and pack-validate rejects the pack. Stored
 * config from before 0.30.0 is still read as the new id while the 0.30.0
 * rewrite ships.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class LegacyComponentIdRemovedTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation',
    'aincient_core', 'atelier', 'aincient_pages', 'atelier_test_legacy_pack',
  ];

  private function renderComponent(string $component, array $props): string {
    $this->container->get('Drupal\Core\Theme\ComponentNegotiator')->clearCache();
    $element = ['#type' => 'component', '#component' => $component, '#props' => $props];
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  public function testOldReplacesNoLongerOverrides(): void {
    $provider = $this->container->get('plugin.manager.sdc')->find('atelier:hero')->getPluginDefinition()['provider'];
    $this->assertSame('atelier', $provider);
    $this->assertStringNotContainsString('data-atelier-legacy-override', $this->renderComponent('atelier:hero', ['heading' => 'Hi', 'variant' => 'centered', 'tone' => 'default']));
  }

  public function testOldIdNoLongerResolves(): void {
    $this->expectException(ComponentNotFoundException::class);
    $this->container->get('plugin.manager.sdc')->find('aincient_pages:card');
  }

  public function testNothingIsRememberedInState(): void {
    $this->renderComponent('atelier:hero', ['heading' => 'Hi', 'variant' => 'centered', 'tone' => 'default']);
    $this->assertNull($this->container->get('state')->get('aincient_pages.legacy_component_ids'));
  }

  /**
   * Stored config is data, not pack code: an old id in overrides_off (a site
   * the 0.30.0 rewrite has not reached yet) still means the new id.
   */
  public function testOldIdInStoredOverridesOffStillReads(): void {
    $definitions = $this->container->get('plugin.manager.sdc')->getDefinitions();
    $definitions['override:hero'] = ['id' => 'override:hero', 'replaces' => 'atelier:hero'] + $definitions['atelier:hero'];
    $negotiator = $this->container->get('Drupal\Core\Theme\ComponentNegotiator');
    $this->assertSame('override:hero', $negotiator->negotiate('atelier:hero', $definitions), 'The override negotiates.');
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['aincient_pages:hero'])->save();
    $this->assertNull($negotiator->negotiate('atelier:hero', $definitions), 'Switched off by its old stored id.');
  }

  /**
   * pack-validate rejects every old id with its fix — including ones no page
   * renders yet.
   */
  public function testPackValidateRejectsOldIds(): void {
    $report = $this->container->get('aincient_pages.pack_validator')->validate('atelier_test_legacy_pack');
    $errors = implode("\n", $report['pack']['errors']);
    $this->assertStringContainsString('components/hero/hero.component.yml names aincient_pages:hero, which is atelier:hero since Atelier 0.30.0', $errors);
    $this->assertStringContainsString('components/legacy-include/legacy-include.twig names aincient_pages:divider, which is atelier:divider since Atelier 0.30.0', $errors);
    $this->assertSame(1, $report['rejected']);
  }

}
