<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_studio_components\Kernel;

use Drupal\aincient_studio_components\Plugin\FlowDropNodeProcessor\ComponentsState;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Components agent's runtime context node (DECISIONS 0455, P2).
 *
 * The {@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandState}
 * pattern: the brief is built server-side every turn from the studio's own
 * controllers, so a saved constraint shows up in the very next prompt, and the
 * incoming template variables pass through enriched, never clobbered.
 *
 * The node is built via its static create() with the kernel container (the
 * BrandStateTest approach) rather than through the FlowDrop plugin manager, so
 * the test does not have to enable the FlowDrop module stack.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class ComponentsStateTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'key',
    'workflows', 'content_moderation', 'aincient_core', 'aincient_pages',
    'aincient_chat', 'aincient_studio_components',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('aincient_brand_revision');
    $this->installConfig(['system', 'workflows', 'content_moderation', 'aincient_pages']);
  }

  private function node(): ComponentsState {
    return ComponentsState::create($this->container, [], 'aincient_studio_components:components_state', []);
  }

  private function componentLine(string $brief, string $name): string {
    $this->assertSame(1, preg_match('/^- ' . preg_quote($name, '/') . ' — .*$/m', $brief, $m), "No COMPONENTS line for $name.");
    return $m[0];
  }

  public function testBriefListsScopesAndComponents(): void {
    $brief = $this->node()->brief();
    $this->assertStringContainsString('SCOPES: site (everywhere)', $brief);
    $this->assertStringContainsString('landing "Landing page"', $brief);
    $this->assertStringContainsString('COMPONENTS (', $brief);
    $hero = $this->componentLine($brief, 'hero');
    $this->assertStringContainsString('; on;', $hero);
    $this->assertStringContainsString('variants centered, split', $hero);
    $this->assertStringContainsString('TONES EVERYWHERE: off: none', $brief);
    $this->assertStringContainsString('PAGE TYPE RULES:', $brief);
    $this->assertStringNotContainsString('- blog:', $brief, 'A recipe kind has no rules to show.');
  }

  /**
   * A saved site constraint shows up in the next brief.
   */
  public function testSavedConstraintIsReflected(): void {
    $this->assertStringContainsString('; on;', $this->componentLine($this->node()->brief(), 'newsletter'));

    $this->config('aincient_pages.site_constraint')->set('components', ['newsletter'])->save();
    $this->container->get('aincient_pages.catalog')->reset();

    $brief = $this->node()->brief();
    $this->assertStringContainsString('OFF everywhere', $this->componentLine($brief, 'newsletter'));
    $this->assertStringContainsString('; on;', $this->componentLine($brief, 'hero'));
  }

  /**
   * Each tone's contrast under the published brand.
   */
  public function testToneContrastSection(): void {
    $brief = $this->node()->brief();
    $this->assertStringContainsString('TONE CONTRAST under the published brand', $brief);
    foreach (['default', 'muted', 'brand', 'inverted'] as $tone) {
      $this->assertMatchesRegularExpression('/^- ' . $tone . ': \d+\.\d \((passes|FAILS)\)$/m', $brief, $tone);
    }
  }

  /**
   * process() passes incoming variables through and adds components_state.
   */
  public function testProcessEnrichesVariables(): void {
    $out = $this->node()->process(new ParameterBag(['variables' => ['live_state' => 'draft']]));
    $this->assertSame('draft', $out['variables']['live_state']);
    $this->assertArrayHasKey('components_state', $out['variables']);
    $this->assertSame($out['components_state'], $out['variables']['components_state']);
    $this->assertStringContainsString('SCOPES:', $out['components_state']);
  }

}
