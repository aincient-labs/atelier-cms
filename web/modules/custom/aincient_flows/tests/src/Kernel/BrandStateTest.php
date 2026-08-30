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
      $this->container->get('aincient_pages.color_contrast'),
      $this->container->get('aincient_pages.token_grounding'),
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
    // A fresh kernel install carries no saved overrides, so seed the palette
    // the brief reads. `font_families` is the operator's OWN chosen web fonts
    // (empty by default — the bundled Fraunces/Schibsted defaults load via the
    // brand-fonts library, not as overrides); seed it too so the brief's fonts
    // branch is exercised.
    $this->config('aincient_pages.brand')
      ->set('tokens', [
        'brand_primary' => 'oklch(0.5 0.2 260)',
        'brand_accent' => 'oklch(0.7 0.15 30)',
        'neutral_surface' => 'oklch(0.98 0 0)',
        'neutral_ink' => 'oklch(0.2 0 0)',
      ])
      ->set('font_families', ['Inter', 'Inter Tight'])
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
   * A saved palette token held as a var() reference is grounded, not echoed
   * bare — the brown-instead-of-yellow bug.
   *
   * The studio's Tailwind swatch grid writes `var(--color-*)` into the saved
   * brand just as it does into a draft. Echoed without the literal behind it,
   * the specialist has no number for a relative request ("make primary
   * darker") and falls back to whatever concrete colour it can find.
   */
  public function testBriefGroundsVarReferencedPaletteTokens(): void {
    $this->config('aincient_pages.brand')
      ->set('tokens', ['brand_primary' => 'var(--color-yellow-100)'])
      ->save();

    $brief = $this->emit()['brand_brief'];
    $this->assertStringContainsString('var(--color-yellow-100)', $brief, 'The reference itself is still shown.');
    $this->assertStringContainsString('#FEF9C2', $brief, '…annotated with the colour it resolves to.');
    $this->assertStringContainsString('oklch(', $brief, '…and with the literal, so a relative edit has numbers.');
    $this->assertStringContainsString('[ramp yellow: ', $brief, '…and with the ramp it sits on, so a relative edit has a rung to step to (cms #44).');
    $this->assertStringContainsString('100 #FEF9C2 ◀', $brief, 'The current rung is marked.');
  }

  /**
   * @covers ::process
   *
   * The shape and typography specialists get a baseline for the axis they own.
   *
   * `brand_brief` carries the palette and the typefaces — all the COLOUR
   * specialist needs, and nothing about corners, shadows, weights or tracking.
   * So a relative request reaching the other two ("make the corners rounder",
   * "heavier headings") had nothing to step from and the model invented a
   * value: DECISIONS 0236 repeating on the axes it did not cover.
   */
  public function testAxisBriefsCoverShapeAndTypography(): void {
    $out = $this->emit();

    $shape = $out['shape_brief'];
    $this->assertNotSame('', $shape, 'The shape specialist has a baseline.');
    foreach (['corner', 'border width', 'shadow distance', 'shadow blur', 'density'] as $dial) {
      $this->assertStringContainsString($dial, $shape, "Shape baseline omits the $dial dial.");
    }

    $type = $out['type_brief'];
    $this->assertNotSame('', $type, 'The typography specialist has a baseline.');
    foreach (['display family', 'body family', 'body size', 'heading weight', 'heading tracking'] as $dial) {
      $this->assertStringContainsString($dial, $type, "Type baseline omits the $dial dial.");
    }

    // Both are exposed on the merged variables the specialist templates render.
    $this->assertSame($shape, $out['variables']['shape_brief']);
    $this->assertSame($type, $out['variables']['type_brief']);
  }

  /**
   * @covers ::process
   *
   * Every axis value is GROUNDED — a var() reference is followed, so a relative
   * request has a number and not an alias.
   */
  public function testAxisBriefsGroundReferencedValues(): void {
    $out = $this->emit();

    // card_radius ships as var(--radius-2xl); the brief must carry the length.
    $this->assertMatchesRegularExpression(
      '/card corners var\(--radius-2xl\) \(= 1rem ≈ 16px\)/',
      $out['shape_brief'],
    );
    // A font stack collapses to its lead family rather than four fallbacks.
    $this->assertStringContainsString('display family Fraunces', $out['type_brief']);
    $this->assertStringNotContainsString('ui-sans-serif', $out['type_brief']);
    // display_weight ships as var(--weight-semibold); the brief carries 600.
    $this->assertStringContainsString('(= 600)', $out['type_brief']);
  }

  /**
   * @covers ::process
   *
   * A token the open draft overrides is marked SUPERSEDED in the saved brief.
   *
   * Otherwise one prompt asserts two different "current" values for the same
   * token — and the stale one reads more assertively ("Current saved palette:
   * primary …") than the draft line does. Saying which is stale at the value
   * itself beats a header further up claiming the draft "wins", which the model
   * has to remember and then apply.
   */
  public function testSavedBriefMarksTokensTheDraftOverrides(): void {
    $this->config('aincient_pages.brand')
      ->set('tokens', [
        'brand_primary' => 'oklch(0.55 0.12 40)',
        'brand_accent' => 'oklch(0.12 0 0)',
      ])
      ->save();

    $brief = $this->emit(['draft_tokens' => 'brand-primary'])['brand_brief'];
    $this->assertMatchesRegularExpression(
      '/primary oklch\(0\.55 0\.12 40\)[^,]*\[SUPERSEDED/',
      $brief,
      'The overridden token is marked at its own value.',
    );
    // …and only that one: an untouched token keeps a clean entry.
    $this->assertDoesNotMatchRegularExpression('/accent [^,]*\[SUPERSEDED/', $brief);

    // No draft → nothing is marked.
    $this->assertStringNotContainsString('SUPERSEDED', $this->emit()['brand_brief']);
  }

  /**
   * @covers ::process
   *
   * The restrictive modes say what "surgical" does NOT constrain.
   *
   * Both carve-outs are regressions caught live on a Locked brand asked to
   * "make primary darker" over a pale yellow draft:
   *  - the orchestrator read "touch ONLY the token(s) named" as forbidding the
   *    paired on-colour and framed the ask as "…or any other token", so the
   *    pair went stale and the edit shipped a 2.94:1 WCAG Fail;
   *  - it generalised "don't change other TOKENS" into "don't change the other
   *    AXES of this token" and asked for "lower lightness only", so chroma was
   *    pinned at a pale tint's value and a yellow darkened into khaki.
   */
  public function testRestrictiveModesCarveOutPairsAndChroma(): void {
    foreach (['polish', 'locked'] as $mode) {
      $this->brand()->setStatus(BrandRepository::STAGE_POLISH, $mode === 'locked');
      $directive = $this->emit()['status_directive'];

      $this->assertStringContainsString('SURGICAL constrains', $directive, "$mode: no carve-out block.");
      // Chroma follows lightness; hue is what is held.
      $this->assertStringContainsString('does NOT freeze the other axes', $directive, "$mode");
      $this->assertStringContainsString('let chroma follow lightness', $directive, "$mode");
      $this->assertStringContainsString("lower lightness only", $directive, "$mode: the bad ask is not named.");
      // The paired on-colour travels with its surface.
      $this->assertStringContainsString('PAIRED ON-COLOUR travels with it', $directive, "$mode");
      $this->assertStringContainsString('4.5:1', $directive, "$mode: no AA floor stated.");
    }

    // The permissive modes are untouched — they already set complete palettes.
    $this->brand()->setStatus(BrandRepository::STAGE_IDEATING, FALSE);
    $this->assertStringNotContainsString('SURGICAL constrains', $this->emit()['status_directive']);
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
