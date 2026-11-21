<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\Theme\OverrideAwareComponentNegotiator;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Packs written before 0.30.0 keep rendering (DECISIONS 0534, S5.1 + S5.4).
 *
 * `atelier_test_legacy_pack` still names the built-ins by their old
 * `aincient_pages:` ids: its `hero` replaces `aincient_pages:hero`, and its
 * `legacy-include` template includes `aincient_pages:divider`. Core alone
 * drops the first silently and fails the second; the negotiator bridges both
 * and remembers each old id.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class LegacyComponentIdBridgeTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'file', 'image', 'media', 'node', 'workflows', 'content_moderation',
    'aincient_core', 'atelier', 'aincient_pages', 'atelier_test_legacy_pack',
  ];

  private function negotiator(): OverrideAwareComponentNegotiator {
    $negotiator = $this->container->get('Drupal\Core\Theme\ComponentNegotiator');
    $negotiator->clearCache();
    return $negotiator;
  }

  private function renderComponent(string $component, array $props): string {
    $this->negotiator();
    $element = ['#type' => 'component', '#component' => $component, '#props' => $props];
    return (string) $this->container->get('renderer')->renderInIsolation($element);
  }

  private function legacy(): array {
    return $this->container->get('state')->get(OverrideAwareComponentNegotiator::LEGACY_STATE, []);
  }

  public function testOldReplacesStillOverridesTheBuiltIn(): void {
    $this->negotiator();
    $provider = $this->container->get('plugin.manager.sdc')->find('atelier:hero')->getPluginDefinition()['provider'];
    $this->assertSame('atelier_test_legacy_pack', $provider);
    $this->assertStringContainsString('data-atelier-legacy-override', $this->renderComponent('atelier:hero', ['heading' => 'Hi', 'variant' => 'centered', 'tone' => 'default']));

    $kinds = array_column($this->legacy(), 'kind', 'id');
    $this->assertSame('replaces', $kinds['aincient_pages:hero'] ?? NULL, 'The old replaces is remembered for pack-validate and Site health.');
  }

  public function testOldIdInATemplateRendersTheBuiltIn(): void {
    $html = $this->renderComponent('atelier_test_legacy_pack:legacy-include', ['label' => 'Section two']);
    $this->assertStringContainsString('data-legacy-include', $html);
    $this->assertStringContainsString('Section two', $html, 'The included built-in divider rendered its label.');
    $kinds = array_column($this->legacy(), 'kind', 'id');
    $this->assertSame('include', $kinds['aincient_pages:divider'] ?? NULL);
  }

  public function testOldIdRenderedDirectlyResolves(): void {
    $this->negotiator();
    $component = $this->container->get('plugin.manager.sdc')->find('aincient_pages:card');
    $this->assertSame('atelier:card', $component->getPluginId());
  }

  public function testOldIdInOverridesOffStillTurnsTheOverrideOff(): void {
    // Until the 0.30.0 update rewrites it, the stored id may be the old one.
    $this->config('aincient_pages.site_constraint')->set('overrides_off', ['aincient_pages:hero'])->save();
    $this->assertStringNotContainsString('data-atelier-legacy-override', $this->renderComponent('atelier:hero', ['heading' => 'Hi', 'variant' => 'centered', 'tone' => 'default']));
  }

  public function testAnUnknownOldIdIsNotInvented(): void {
    $this->negotiator();
    $this->expectException(\Drupal\Core\Render\Component\Exception\ComponentNotFoundException::class);
    $this->container->get('plugin.manager.sdc')->find('aincient_pages:no-such-thing');
  }

  /**
   * Core never checked a bridged override's props (its target was missing),
   * so the negotiator does: an incompatible one is not bridged.
   */
  public function testIncompatibleOldReplacesIsRefused(): void {
    $definitions = $this->container->get('plugin.manager.sdc')->getDefinitions();
    $definitions['atelier_test_legacy_pack:hero']['props'] = [
      'type' => 'object',
      'required' => ['nonsense'],
      'properties' => ['nonsense' => ['type' => 'integer']],
    ];
    $this->assertNull($this->negotiator()->negotiate('atelier:hero', $definitions), 'The original renders.');
    $row = array_values(array_filter($this->legacy(), fn(array $r) => $r['id'] === 'aincient_pages:hero'))[0] ?? [];
    $this->assertNotNull($row['refused'] ?? NULL, 'The refusal and its reason are remembered.');
  }

  /**
   * pack-validate names every old id with its fix, before 0.32.0 drops the
   * bridge — including ones no page renders yet.
   */
  public function testPackValidateWarnsAboutOldIds(): void {
    $report = $this->container->get('aincient_pages.pack_validator')->validate('atelier_test_legacy_pack');
    $this->assertSame([], $report['pack']['errors']);
    $warnings = implode("\n", $report['pack']['warnings']);
    $this->assertStringContainsString('components/hero/hero.component.yml names aincient_pages:hero, which is now atelier:hero', $warnings);
    $this->assertStringContainsString('components/legacy-include/legacy-include.twig names aincient_pages:divider, which is now atelier:divider', $warnings);
    $this->assertSame(0, $report['rejected'], 'A warning, not a rejection, while the bridge lasts.');
  }

}
