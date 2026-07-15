<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_brand\Kernel;

use Drupal\aincient_pages\BrandRepository;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the branding agent layer: presets + the brand preview capabilities.
 *
 * The agent never writes the brand server-side — brand direction only ever goes
 * live through the Brand studio's Publish button (see BrandController::save).
 * Its tools are read/preview-only: brand_picker emits a generative-UI widget
 * envelope, and preview_brand emits a CSS-var draft envelope that persists
 * nothing. Presets are proven applyable through the same registry-validated
 * BrandRepository::update path the studio's Publish endpoint uses, so a preset
 * really does reskin the site when the user publishes it.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BrandAgentTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'workflows',
    'content_moderation',
    'aincient_core',
    'aincient_pages',
    'aincient_brand',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // BrandRepository::update records an attributed revision on every write.
    $this->installEntitySchema('aincient_brand_revision');
    $this->installConfig(['system', 'workflows', 'content_moderation', 'aincient_pages']);
    // Uid 1 is created first and is the superuser; create a real user so the
    // permission check in the capabilities is exercised honestly.
    $this->setCurrentUser($this->createUser(['administer aincient pages']));
  }

  private function manager(): FunctionCallPluginManager {
    return $this->container->get('plugin.manager.ai.function_calls');
  }

  private function brand(): BrandRepository {
    return $this->container->get('aincient_pages.brand');
  }

  /**
   * Run a capability and return its readable output.
   */
  private function runCapability(string $id, array $values = []): string {
    /** @var \Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface $tool */
    $tool = $this->manager()->createInstance($id);
    foreach ($values as $key => $value) {
      $tool->setContextValue($key, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  public function testPresetsAreRegistryValidAndApplyable(): void {
    /** @var \Drupal\aincient_brand\BrandPresets $presets */
    $presets = $this->container->get('aincient_brand.presets');
    $summaries = $presets->summaries();
    $this->assertCount(3, $summaries);

    foreach (['saas', 'playful', 'editorial'] as $id) {
      $preset = $presets->get($id);
      $this->assertNotNull($preset);
      $this->brand()->update($preset['tokens'], $preset['fonts']);
      // Every token in the preset must survive registry validation (none
      // dropped) and persist verbatim — proving the preset really reskins.
      $tokens = $this->brand()->tokens();
      foreach ($preset['tokens'] as $name => $value) {
        $this->assertSame($value, $tokens[$name] ?? NULL, "Preset {$id} token {$name} applied.");
      }
    }
    // The last preset (editorial) is now the live brand.
    $this->assertSame('var(--color-stone-800)', $this->brand()->tokens()['brand_primary']);
  }

  public function testBrandPickerEmitsWidgetEnvelope(): void {
    $out = $this->runCapability('aincient_brand:brand_picker');
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('brand_picker', $envelope['__widget__']);
    $this->assertNotEmpty($envelope['summary']);
    $payload = $envelope['payload'];
    $this->assertCount(3, $payload['presets']);
    $this->assertArrayHasKey('manifestUrl', $payload);
    $this->assertArrayHasKey('current', $payload);
    // The widget stages a draft for the studio to publish — it is not handed a
    // direct apply URL, so a pick can't write to the live site.
    $this->assertArrayNotHasKey('applyUrl', $payload);
    // Each preset carries its full token map + fonts so the picker can stage
    // the whole look as a draft (not just the two swatch colours).
    $this->assertArrayHasKey('tokens', $payload['presets'][0]);
    $this->assertArrayHasKey('fonts', $payload['presets'][0]);
  }

  public function testBrandPickerRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runCapability('aincient_brand:brand_picker');
    $this->assertStringStartsWith('Error:', $out);
    // Not a widget envelope — nothing renders.
    $this->assertStringNotContainsString('__widget__', $out);
  }

  public function testPreviewBrandEmitsCssVarEnvelopeAndPersistsNothing(): void {
    $before = $this->brand()->tokens();
    $out = $this->runCapability('aincient_brand:preview_brand', [
      // Valid token (name) + an invalid one (bogus value) + a junk name.
      'tokens_json' => json_encode([
        'neutral_surface' => 'oklch(0.15 0.02 270)',
        'brand_primary' => 'not-a-colour',
        'no_such_token' => '#fff',
      ]),
      'fonts' => 'Poppins, not a real font!!!',
    ]);
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('brand_preview', $envelope['__widget__']);
    $payload = $envelope['payload'];
    // Valid token is keyed by CSS-VAR (what the client preview store expects),
    // not the underscore token name.
    $this->assertSame(['neutral-surface' => 'oklch(0.15 0.02 270)'], $payload['tokens']);
    // Invalid value + unknown name are rejected and named back.
    $this->assertContains('brand_primary', $payload['rejected']);
    $this->assertContains('no_such_token', $payload['rejected']);
    // Only the legal font survives (the validator drops the junk one).
    $this->assertSame(['Poppins'], $payload['fonts']);
    $this->assertFalse($payload['reset']);
    // Preview persists NOTHING — the saved brand is byte-for-byte unchanged.
    $this->assertSame($before, $this->brand()->tokens());
  }

  public function testResetPreviewEmitsResetEnvelopeAndPersistsNothing(): void {
    $before = $this->brand()->tokens();
    $out = $this->runCapability('aincient_brand:reset_preview');
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('brand_preview', $envelope['__widget__']);
    // A reset frame: the flag is set, no tokens/fonts forwarded.
    $this->assertTrue($envelope['payload']['reset']);
    $this->assertSame([], $envelope['payload']['tokens']);
    $this->assertSame([], $envelope['payload']['fonts']);
    $this->assertStringContainsString('Reverted', $envelope['summary']);
    // Draft-only — the saved brand is untouched.
    $this->assertSame($before, $this->brand()->tokens());
  }

  public function testResetPreviewRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runCapability('aincient_brand:reset_preview');
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

  public function testPreviewBrandReportsLowContrastPairToAgent(): void {
    // Set a surface + its on-colour that fail AA together — the preview applies,
    // but the result must warn the agent so it can self-correct next turn. Use
    // PALETTE tokens, the one tier where a raw colour is a legal value.
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'tokens_json' => json_encode([
        'brand_primary' => '#ffffff',
        'brand_primary_foreground' => '#f2f2f2',
      ]),
    ]);
    $envelope = json_decode($out, TRUE);
    $this->assertSame('brand_preview', $envelope['__widget__']);
    // Machine-readable warning in the payload …
    $surfaces = array_column($envelope['payload']['contrast_warnings'], 'surface');
    $this->assertContains('brand_primary', $surfaces);
    // … and a human/agent-readable hint in the summary.
    $this->assertStringContainsString('Low contrast', $envelope['summary']);
  }

  public function testPreviewBrandNoContrastWarningForGoodPair(): void {
    // A primary that reads BOTH as a fill (vs. its on-colour) AND as text on the
    // light neutral surfaces — blue-700 clears AA on white and on its white
    // on-colour. No warning of either family.
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'tokens_json' => json_encode([
        'brand_primary' => '#1d4ed8',
        'brand_primary_foreground' => '#ffffff',
      ]),
    ]);
    $envelope = json_decode($out, TRUE);
    $this->assertSame([], $envelope['payload']['contrast_warnings']);
    $this->assertSame([], $envelope['payload']['accent_warnings']);
    $this->assertStringNotContainsString('Low contrast', $envelope['summary']);
  }

  public function testPreviewBrandNoLongerWarnsAccentAsText(): void {
    // A pale primary is a fine FILL (passes its on-pair vs. a dark on-colour) and
    // used to also trip an accent-as-text warning because templates painted raw
    // `primary` as eyebrows/links. DECISIONS 0067 decommissioned that advisory:
    // accent text now uses the derived `primary_on_surface` token (legible on any
    // neutral surface by construction), so no token declares `legible_on` and the
    // agent is no longer nudged to darken the primary. Both warning families stay
    // clean and the summary carries no contrast note.
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'tokens_json' => json_encode([
        'brand_primary' => '#cdddff',
        'brand_primary_foreground' => '#111111',
      ]),
    ]);
    $envelope = json_decode($out, TRUE);
    $this->assertSame('brand_preview', $envelope['__widget__']);
    $this->assertSame([], $envelope['payload']['contrast_warnings']);
    $this->assertSame([], $envelope['payload']['accent_warnings']);
    $this->assertStringNotContainsString('Low contrast', $envelope['summary']);
  }

  public function testPreviewBrandExpandsPresets(): void {
    // A {group: option} preset choice expands to its coherent token set + web
    // fonts — the agent's preferred, low-error path.
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'presets_json' => json_encode(['pairing' => 'editorial', 'roundness' => 'soft']),
    ]);
    $payload = json_decode($out, TRUE)['payload'];
    // Tokens are keyed by css_var (what the preview store expects): the pairing
    // set the two font families, roundness set the radius scale + button knob.
    $this->assertSame('"Playfair Display", Georgia, "Times New Roman", serif', $payload['tokens']['font-family-display'] ?? NULL);
    $this->assertArrayHasKey('font-family-base', $payload['tokens']);
    $this->assertSame('var(--radius-md)', $payload['tokens']['button-radius'] ?? NULL);
    $this->assertSame('0.375rem', $payload['tokens']['radius-md'] ?? NULL);
    // The pairing's web fonts are staged for the preview to load.
    $this->assertContains('Playfair Display', $payload['fonts']);
    $this->assertContains('Lora', $payload['fonts']);
    $this->assertEmpty($payload['rejected_presets']);
  }

  public function testExplicitTokenWinsOverPreset(): void {
    // presets_json + tokens_json together: the explicit font family overrides the
    // pairing's display stack (fine control a preset can't express).
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'presets_json' => json_encode(['pairing' => 'editorial']),
      'tokens_json' => json_encode(['font_family_display' => '"Roboto", sans-serif']),
    ]);
    $payload = json_decode($out, TRUE)['payload'];
    $this->assertSame('"Roboto", sans-serif', $payload['tokens']['font-family-display']);
    // The pairing's OTHER token (base family) is untouched by the override.
    $this->assertArrayHasKey('font-family-base', $payload['tokens']);
  }

  public function testUnknownPresetReportedButValidOnesApply(): void {
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'presets_json' => json_encode(['density' => 'roomy', 'roundness' => 'nope', 'bogus' => 'x']),
    ]);
    $envelope = json_decode($out, TRUE);
    $payload = $envelope['payload'];
    // The valid preset applied (density:roomy sets the density token)…
    $this->assertSame('1.15', $payload['tokens']['density'] ?? NULL);
    // … and the unknown group/option pairs are named back, not silently dropped.
    $this->assertContains('roundness:nope', $payload['rejected_presets']);
    $this->assertContains('bogus:x', $payload['rejected_presets']);
    $this->assertStringContainsString('Unknown presets', $envelope['summary']);
  }

  public function testPreviewBrandResetEmitsResetFlag(): void {
    $out = $this->runCapability('aincient_brand:preview_brand', ['reset' => TRUE]);
    $envelope = json_decode($out, TRUE);
    $this->assertSame('brand_preview', $envelope['__widget__']);
    $this->assertTrue($envelope['payload']['reset']);
  }

  public function testPreviewBrandRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runCapability('aincient_brand:preview_brand', [
      'tokens_json' => json_encode(['neutral_surface' => 'oklch(0.15 0.02 270)']),
    ]);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

  public function testProposeBrandStatusEmitsProposalEnvelopeAndPersistsNothing(): void {
    $before = $this->brand()->status();
    $this->assertSame(['stage' => 'ideating', 'locked' => FALSE], $before);
    $out = $this->runCapability('aincient_brand:propose_brand_status', [
      'stage' => 'polish',
      'locked' => TRUE,
      'rationale' => 'The palette and type look settled.',
    ]);
    $envelope = json_decode($out, TRUE);
    $this->assertIsArray($envelope);
    $this->assertSame('brand_status_proposal', $envelope['__widget__']);
    $payload = $envelope['payload'];
    $this->assertSame('polish', $payload['stage']);
    $this->assertTrue($payload['locked']);
    $this->assertSame('The palette and type look settled.', $payload['rationale']);
    // The card shows what it would change FROM (the live status).
    $this->assertSame(['stage' => 'ideating', 'locked' => FALSE], $payload['current']);
    $this->assertStringContainsString('polish', $envelope['summary']);
    // Proposing persists NOTHING — the status is unchanged until the human
    // confirms via POST /atelier/brand/status.
    $this->assertSame($before, $this->brand()->status());
  }

  public function testProposeBrandStatusDefaultsLockToCurrent(): void {
    // Seed a locked brand, then propose a stage-only change: `locked` must
    // default to the current lock, not silently flip to false.
    $this->brand()->setStatus('guided', TRUE);
    $out = $this->runCapability('aincient_brand:propose_brand_status', ['stage' => 'polish']);
    $payload = json_decode($out, TRUE)['payload'];
    $this->assertSame('polish', $payload['stage']);
    $this->assertTrue($payload['locked']);
  }

  public function testProposeBrandStatusRejectsUnknownStage(): void {
    $out = $this->runCapability('aincient_brand:propose_brand_status', ['stage' => 'nonsense']);
    $this->assertStringStartsWith('Error:', $out);
    // Not a widget envelope — nothing renders; the legal set is named for the model.
    $this->assertStringNotContainsString('__widget__', $out);
    $this->assertStringContainsString('ideating', $out);
  }

  public function testProposeBrandStatusRefusesWithoutPermission(): void {
    $this->setCurrentUser($this->createUser());
    $out = $this->runCapability('aincient_brand:propose_brand_status', ['stage' => 'polish']);
    $this->assertStringStartsWith('Error:', $out);
    $this->assertStringNotContainsString('__widget__', $out);
  }

}
