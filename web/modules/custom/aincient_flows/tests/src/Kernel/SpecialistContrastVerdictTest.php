<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandApplySlices;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ValidateSlice;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Brand orchestrator reads a MEASURED contrast verdict before it replies.
 *
 * Live repro (2026-10, pipeline 307): "Make the primary colour a bit darker."
 * The colour specialist returned brand_primary var(--color-cyan-600) with a
 * near-white on-colour; the end-of-turn apply graded both primary pairs 3.4:1
 * — a WCAG fail — yet the reply said the on-colour was "adjusted to keep
 * contrast". The reply is written by the one reasoning node BEFORE the apply
 * runs (reason → gateway FALSE → brand_apply_slices → chat_output), so the
 * only thing it can read is the specialist's tool result. The slice validator
 * inside each specialist now grades the pairs the slice moves with the SAME
 * code the apply uses (BrandPreviewApplier over ColorContrast, draft over
 * saved, var() followed) and writes one factual line per pair into the slice.
 *
 * Tested at the seam plus the tool boundary: the specialist's own model call
 * (simple_chat → the final ChatCompleter) cannot be scripted in a kernel
 * container, so the brand_studio loop is not driven end to end. What the
 * reasoner reads is reconstructed exactly as the job trail shows it — the
 * executor envelope {slice: "<data_to_json string>", status} as a `tool`
 * message — and the same message list is fed to the merge node to prove the
 * two verdicts agree and the new key is inert for the apply.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class SpecialistContrastVerdictTest extends KernelTestBase {

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
   * The live repro's slice: cyan-600 fill, near-white on-colour.
   */
  private const FAILING = ['brand_primary' => 'var(--color-cyan-600)', 'brand_primary_foreground' => 'oklch(0.98 0 0)'];

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
   * The validator as the specialist graph builds it (through create()).
   */
  private function validate(array $slice): array {
    $node = ValidateSlice::create($this->container, [], 'aincient_flows:brand_validate_slice', []);
    return $node->process(new ParameterBag(['slice' => $slice]))['slice'];
  }

  /**
   * The tool message the reasoner reads: data_to_json → executor envelope.
   */
  private function toolMessage(array $validated): array {
    return [
      'role' => 'tool',
      'tool_call_id' => 'toolu_colour',
      'content' => (string) json_encode(['slice' => (string) json_encode($validated), 'status' => 'success']),
    ];
  }

  /**
   * The live repro: the reasoner's input now carries the FAIL, in words.
   */
  public function testFailingPairReachesTheReasonerAsFail(): void {
    $validated = $this->validate(['tokens_json' => self::FAILING]);

    $this->assertSame(self::FAILING, $validated['tokens_json'], 'Valid tokens pass through untouched.');
    $this->assertContains('brand_primary_foreground on brand_primary: 3.4:1 — FAILS WCAG AA for body text (needs 4.5:1)', $validated['contrast']);
    // brand_primary moves the semantic pair through its var() reference.
    $this->assertContains('primary_foreground on primary: 3.4:1 — FAILS WCAG AA for body text (needs 4.5:1)', $validated['contrast']);
    foreach ($validated['contrast'] as $line) {
      $this->assertStringNotContainsString('brand_primary: 3.4:1 — passes', $line);
    }

    // Across the tool boundary: the content string reason.2 reads, decoded the
    // way the model sees it, states the failure.
    $message = $this->toolMessage($validated);
    $inner = json_decode((string) json_decode($message['content'], TRUE)['slice'], TRUE);
    $this->assertContains('brand_primary_foreground on brand_primary: 3.4:1 — FAILS WCAG AA for body text (needs 4.5:1)', $inner['contrast']);

    // The end-of-turn apply reads the same message: it applies exactly the
    // slice's tokens (the contrast key is inert) and grades the same pairs at
    // the same ratio — the reply and the warning chip can no longer disagree.
    $merge = new BrandApplySlices([], 'aincient_flows:brand_apply_slices', [], $this->container->get('aincient_pages.preview_applier'));
    $result = $merge->process(new ParameterBag([
      'messages' => [
      ['role' => 'user', 'content' => 'Make the primary colour a bit darker.'],
        $this->toolMessage($validated),
      ],
    ]));
    $this->assertSame(2, $result['applied']);
    $envelope = json_decode($result['widget'], TRUE);
    $warnings = [];
    foreach ($envelope['payload']['contrast_warnings'] as $w) {
      $warnings[$w['surface'] . '/' . $w['on']] = $w['ratio'];
    }
    $this->assertSame(['brand_primary/brand_primary_foreground' => 3.4, 'primary/primary_foreground' => 3.4], $warnings);
    // And its advisory no longer tells a "make it darker" turn not to darken.
    $this->assertStringNotContainsString('do not darken', $envelope['summary']);
  }

  /**
   * A passing pair is stated as passing — the only licence to claim AA.
   */
  public function testPassingPairIsStatedAsPassing(): void {
    $validated = $this->validate([
      'tokens_json' => [
        'brand_primary' => 'var(--color-cyan-600)',
        'brand_primary_foreground' => 'oklch(0.15 0 0)',
      ],
    ]);
    $lines = implode("\n", $validated['contrast']);
    $this->assertMatchesRegularExpression('/^brand_primary_foreground on brand_primary: [\d.]+:1 — passes WCAG AA/m', $lines);
    $this->assertStringNotContainsString('brand_primary_foreground on brand_primary: 3.4', $lines);
  }

  /**
   * An on-colour the slice leaves alone is graded from the studio draft.
   *
   * The specialist often sets only the fill; the on-colour on screen is the
   * staged draft's, so the verdict must be draft-over-saved like the apply.
   */
  public function testOnColourFromTheDraftIsGraded(): void {
    $this->container->get('aincient_pages.preview_applier')
      ->setDraftBaseline(['brand-primary-foreground' => 'oklch(0.98 0 0)']);
    $validated = $this->validate(['tokens_json' => ['brand_primary' => 'var(--color-cyan-600)']]);
    $this->assertContains('brand_primary_foreground on brand_primary: 3.4:1 — FAILS WCAG AA for body text (needs 4.5:1)', $validated['contrast']);
  }

  /**
   * A slice that moves no colour carries no contrast claim either way.
   */
  public function testNonColourSliceHasNoContrastKey(): void {
    $validated = $this->validate(['presets_json' => ['roundness' => 'rounded']]);
    $this->assertArrayNotHasKey('contrast', $validated);
    $this->assertArrayNotHasKey('contrast', $this->validate([]));
  }

}
