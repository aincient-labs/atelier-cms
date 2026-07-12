<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandState;
use Drupal\aincient_pages\BrandRepository;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Brand state runtime node.
 *
 * The node reads the saved brand design-intent status server-side each turn and
 * shapes the orchestrator's system prompt: it emits the effective mode, the
 * rendered behaviour directive, a compact saved-brand brief, and a merged
 * `variables` object that carries the incoming template vars (the studio's
 * live_preview_state draft) PLUS the two status-derived vars. We assert: the
 * default stage is the permissive `ideating` directive; each stage renders its
 * own directive; `locked` overrides the stage; the brief summarises the saved
 * brand; and the incoming variables pass through enriched, not clobbered.
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandState
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class BrandStateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_flows',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aincient_brand_revision');
    $this->installConfig(['system', 'aincient_pages']);
  }

  /**
   * The node wired to the real brand + identity services.
   */
  private function node(): BrandState {
    return new BrandState(
      [],
      'aincient_flows:brand_state',
      [],
      $this->container->get('aincient_pages.brand'),
      $this->container->get('aincient_pages.site_identity'),
    );
  }

  /**
   * Run the node with the given incoming template variables.
   */
  private function emit(array $variables = []): array {
    return $this->node()->process(new ParameterBag(['variables' => $variables]));
  }

  /**
   * The brand repository (to drive status).
   */
  private function brand(): BrandRepository {
    return $this->container->get('aincient_pages.brand');
  }

  /**
   * @covers ::process
   *
   * A fresh brand defaults to the permissive IDEATING directive.
   */
  public function testDefaultsToIdeating(): void {
    $out = $this->emit();
    $this->assertSame('ideating', $out['effective_mode']);
    $this->assertStringContainsString('IDEATING', $out['status_directive']);
    // The directive is also folded into the merged template variables.
    $this->assertSame($out['status_directive'], $out['variables']['stage_directive']);
    $this->assertSame('ideating', $out['variables']['brand_status']);
  }

  /**
   * @covers ::process
   *
   * Each stage renders its own behaviour directive.
   */
  public function testStageSelectsDirective(): void {
    $this->brand()->setStatus(BrandRepository::STAGE_GUIDED, FALSE);
    $out = $this->emit();
    $this->assertSame('guided', $out['effective_mode']);
    $this->assertStringContainsString('GUIDED', $out['status_directive']);

    $this->brand()->setStatus(BrandRepository::STAGE_POLISH, FALSE);
    $out = $this->emit();
    $this->assertSame('polish', $out['effective_mode']);
    $this->assertStringContainsString('POLISH', $out['status_directive']);
    $this->assertStringContainsString('minimal', strtolower($out['status_directive']));
  }

  /**
   * @covers ::process
   *
   * `locked` overrides the stage — the effective mode is `locked` and the
   * directive tells the agent it can be unlocked in the studio.
   */
  public function testLockedOverridesStage(): void {
    $this->brand()->setStatus(BrandRepository::STAGE_IDEATING, TRUE);
    $out = $this->emit();
    $this->assertSame('locked', $out['effective_mode']);
    $this->assertStringContainsString('LOCKED', $out['status_directive']);
    $this->assertStringContainsString('unlock', strtolower($out['status_directive']));
  }

  /**
   * @covers ::process
   *
   * The brief summarises the saved brand (palette tokens + fonts).
   */
  public function testBriefSummarisesSavedBrand(): void {
    // A fresh kernel install carries no saved tokens, so seed the palette the
    // brief reads (the live site always has these).
    $this->config('aincient_pages.brand')
      ->set('tokens', [
        'brand_primary' => 'oklch(0.5 0.2 260)',
        'brand_accent' => 'oklch(0.7 0.15 30)',
        'neutral_surface' => 'oklch(0.98 0 0)',
        'neutral_ink' => 'oklch(0.2 0 0)',
      ])
      ->save();

    $out = $this->emit();
    $brief = $out['brand_brief'];
    $this->assertNotSame('', $brief, 'A brief was produced.');
    $this->assertStringContainsString('saved palette', strtolower($brief));
    $this->assertStringContainsString('primary', strtolower($brief));
    $this->assertStringContainsString('fonts', strtolower($brief));
    // Same brief is exposed on the merged variables for the template.
    $this->assertSame($brief, $out['variables']['brand_brief']);
  }

  /**
   * @covers ::process
   *
   * Incoming template variables (the studio's live_preview_state draft) pass
   * through enriched, never clobbered.
   */
  public function testIncomingVariablesPassThroughEnriched(): void {
    $out = $this->emit(['live_preview_state' => 'primary = hotpink']);
    $this->assertSame('primary = hotpink', $out['variables']['live_preview_state']);
    $this->assertArrayHasKey('stage_directive', $out['variables']);
    $this->assertArrayHasKey('brand_brief', $out['variables']);
  }

}
