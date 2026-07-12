<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandApplySlices;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Brand deterministic merge node.
 *
 * The node reads the turn's conversation buffer, collects every specialist
 * slice (colour tokens_json, shape presets_json, typography fonts), deep-merges
 * them, and applies ONE authoritative brand_preview envelope via the shared
 * BrandPreviewApplier — the apply the LLM no longer makes. We assert: all three
 * dimensions land in one envelope (fenced + bare JSON both parse); only THIS
 * turn's slices count (anything before the last user message is ignored); a
 * re-delegated dimension is last-wins, not additive; an empty turn is a no-op.
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\BrandApplySlices
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class BrandApplySlicesTest extends KernelTestBase {

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
   * The merge node wired to the real applier.
   */
  private function node(): BrandApplySlices {
    return new BrandApplySlices(
      [],
      'aincient_flows:brand_apply_slices',
      [],
      $this->container->get('aincient_pages.preview_applier'),
    );
  }

  /**
   * Wrap a slice the way the workflow executor does: {slice, status}.
   */
  private function toolResult(string $id, string $slice): array {
    return [
      'role' => 'tool',
      'tool_call_id' => $id,
      'content' => (string) json_encode(['slice' => $slice, 'status' => 'success']),
    ];
  }

  /**
   * Decode the node's emitted widget envelope.
   */
  private function widget(array $result): ?array {
    $json = (string) ($result['widget'] ?? '');
    return $json !== '' ? json_decode($json, TRUE) : NULL;
  }

  /**
   * @covers ::process
   *
   * Three specialist slices (fenced + bare) merge into ONE envelope carrying
   * colour, font and shape tokens; pre-turn slices are excluded.
   */
  public function testMergesThreeDimensionsIntoOneEnvelope(): void {
    $messages = [
      // A PRIOR turn — must be ignored (it's before the last user message).
      ['role' => 'user', 'content' => 'earlier request'],
      $this->toolResult('old', '{"tokens_json":{"neutral_muted_foreground":"oklch(0.011 0 0)"}}'),
      // THIS turn.
      ['role' => 'user', 'content' => 'warm sunset palette, editorial fonts, rounded + lifted'],
      // Colour (fenced) — only brand_primary + neutral_surface so neutral_muted_foreground
      // can only come from the excluded prior slice.
      $this->toolResult('c1', "```json\n{\"tokens_json\":{\"brand_primary\":\"oklch(0.48 0.18 50)\",\"neutral_surface\":\"oklch(0.97 0.02 60)\"}}\n```"),
      // Typography (bare) — a pairing preset + fonts.
      $this->toolResult('c2', '{"presets_json":{"pairing":"editorial"},"fonts":"Playfair Display, Lora"}'),
      // Shape (fenced) — roundness + a shadow axis. (DECISIONS 0066 retired the
      // monolithic `depth` bundle; shadows are now dialled via decoupled axes
      // like `direction`, which writes the shadow_dir_* tokens.)
      $this->toolResult('c3', "```json\n{\"presets_json\":{\"roundness\":\"rounded\",\"direction\":\"bottom_right\"}}\n```"),
    ];

    $result = $this->node()->process(new ParameterBag(['messages' => $messages]));
    $env = $this->widget($result);

    $this->assertNotNull($env, 'A widget envelope was emitted.');
    $this->assertSame('brand_preview', $env['__widget__']);
    $tokens = $env['payload']['tokens'];
    $names = array_keys($tokens);

    // Colour landed.
    $this->assertArrayHasKey('brand-primary', $tokens);
    $this->assertSame('oklch(0.48 0.18 50)', $tokens['brand-primary']);
    // Typography landed (preset font tokens + the fonts list).
    $this->assertNotEmpty(array_filter($names, static fn(string $n): bool => str_contains($n, 'font')));
    $this->assertContains('Playfair Display', $env['payload']['fonts']);
    $this->assertContains('Lora', $env['payload']['fonts']);
    // Shape landed (radius + shadow tokens from the presets).
    $this->assertNotEmpty(array_filter($names, static fn(string $n): bool => str_contains($n, 'radius')));
    $this->assertNotEmpty(array_filter($names, static fn(string $n): bool => str_contains($n, 'shadow')));
    // The prior-turn slice was excluded.
    $this->assertArrayNotHasKey('neutral-muted-foreground', $tokens);
  }

  /**
   * @covers ::process
   *
   * A dimension delegated twice this turn is last-wins, not additive.
   */
  public function testReDelegatedDimensionIsLastWins(): void {
    $messages = [
      ['role' => 'user', 'content' => 'make the accent hotter'],
      $this->toolResult('c1', '{"tokens_json":{"brand_primary":"oklch(0.50 0.10 50)"}}'),
      $this->toolResult('c2', '{"tokens_json":{"brand_primary":"oklch(0.55 0.22 30)"}}'),
    ];

    $env = $this->widget($this->node()->process(new ParameterBag(['messages' => $messages])));
    $this->assertSame('oklch(0.55 0.22 30)', $env['payload']['tokens']['brand-primary']);
  }

  /**
   * @covers ::process
   *
   * A turn with no specialist slices emits no widget (no empty apply).
   */
  public function testEmptyTurnIsNoOp(): void {
    $messages = [
      ['role' => 'user', 'content' => 'thanks!'],
      ['role' => 'assistant', 'content' => "You're welcome!", 'tool_calls' => []],
    ];

    $result = $this->node()->process(new ParameterBag(['messages' => $messages]));
    $this->assertSame('', $result['widget']);
    $this->assertSame(0, $result['applied']);
  }

  /**
   * @covers ::process
   *
   * A brand_picker widget result (not a slice) is ignored by the merge.
   */
  public function testNonSliceToolResultIsIgnored(): void {
    $messages = [
      ['role' => 'user', 'content' => 'show me options'],
      $this->toolResult('c1', '{"__widget__":"brand_picker","payload":{"swatches":[]}}'),
    ];

    $result = $this->node()->process(new ParameterBag(['messages' => $messages]));
    $this->assertSame('', $result['widget']);
  }

}
