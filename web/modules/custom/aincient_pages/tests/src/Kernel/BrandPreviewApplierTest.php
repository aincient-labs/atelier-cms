<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the brand-preview applier: JSON value shapes + the envelope contract.
 *
 * The applier is the single source of truth for turning a preview_brand slice
 * (presets_json / tokens_json / fonts / reset) into the `brand_preview` widget
 * envelope the studio's draft store applies — shared by the legacy LLM tool,
 * the Brand orchestrator's merge node, and the live slice streamer.
 *
 * The value-shape cases here cover the "brand studio sometimes fails to apply
 * preview" bug: the writers are language models, so a `number`-typed token
 * arrives unquoted often enough to matter, and the applier used to require
 * is_string() and drop it WITHOUT reporting it. When the dropped token was the
 * turn's only one, apply() returned an error, the merge node emitted no widget,
 * and the agent's closing prose claimed a change the preview never made. Colour
 * work was unaffected (colours are always quoted strings), which is exactly why
 * it read as intermittent.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BrandPreviewApplierTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'link',
    'menu_link_content',
    'workflows', 'content_moderation', 'aincient_pages',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['workflows', 'content_moderation', 'aincient_pages']);
  }

  private function applier(): BrandPreviewApplier {
    return $this->container->get('aincient_pages.preview_applier');
  }

  /**
   * A `number`-typed token sent unquoted applies exactly as its quoted twin.
   *
   * `density` and `shadow_strength` are `type: number` in the registry, so
   * `0.9` and `"0.9"` are the same CSS value and must reach the preview
   * identically.
   */
  public function testUnquotedNumberTokensApply(): void {
    $env = $this->applier()->apply([
      'tokens_json' => json_encode(['density' => 0.9, 'shadow_strength' => 0.25]),
    ]);

    $this->assertSame('brand_preview', $env['__widget__'] ?? NULL, (string) ($env['error'] ?? ''));
    $this->assertSame('0.9', $env['payload']['tokens']['density']);
    $this->assertSame('0.25', $env['payload']['tokens']['shadow-strength']);
    $this->assertSame([], $env['payload']['rejected']);
  }

  /**
   * The quoted and unquoted forms produce byte-identical payloads.
   */
  public function testQuotedAndUnquotedNumbersAgree(): void {
    $unquoted = $this->applier()->apply([
      'tokens_json' => json_encode(['density' => 1, 'heading_weight' => 700, 'shadow_strength' => 0.5]),
    ]);
    $quoted = $this->applier()->apply([
      'tokens_json' => json_encode(['density' => '1', 'heading_weight' => '700', 'shadow_strength' => '0.5']),
    ]);

    $this->assertSame($quoted['payload'], $unquoted['payload']);
  }

  /**
   * An unquoted number as the turn's ONLY token still yields a widget.
   *
   * The regression that surfaced as "the agent said it applied, nothing moved":
   * a single dropped token left apply() with nothing to do, so it returned an
   * `error` and the merge node emitted no widget at all.
   */
  public function testLoneUnquotedNumberIsNotAnError(): void {
    $env = $this->applier()->apply(['tokens_json' => json_encode(['shadow_strength' => 0])]);

    $this->assertArrayNotHasKey('error', $env);
    $this->assertSame('brand_preview', $env['__widget__']);
    // Normalised by the token registry, present, and not silently dropped.
    $this->assertArrayHasKey('shadow-strength', $env['payload']['tokens']);
  }

  /**
   * A value that CANNOT be a CSS token value is named in `rejected`, not dropped.
   *
   * The point of the coercion is to stop silent losses, so the one shape that
   * genuinely can't be coerced (an object/array) must still be reported — that
   * is what lets the agent tell the user the truth.
   */
  public function testUncastableValueIsReportedNotDropped(): void {
    $env = $this->applier()->apply([
      'tokens_json' => json_encode([
        'density' => 0.9,
        'brand_primary' => ['oklch(0.6 0.2 30)'],
      ]),
    ]);

    $this->assertSame('brand_preview', $env['__widget__']);
    $this->assertContains('brand_primary', $env['payload']['rejected']);
    $this->assertArrayNotHasKey('brand-primary', $env['payload']['tokens']);
    // The valid sibling still applied.
    $this->assertSame('0.9', $env['payload']['tokens']['density']);
    $this->assertStringContainsString('brand_primary', $env['summary']);
  }

  /**
   * decodeSlice() un-stringifies a nested `tokens_json` object.
   *
   * The slice contract says these keys are objects, but the tool-arg contract
   * they're named after declares them as JSON strings, so specialists
   * stringify them. Every consumer gates on is_array(), so the string shape was
   * dropped as silently as the unquoted number.
   */
  public function testDecodeSliceUnStringifiesNestedJson(): void {
    $slice = $this->applier()->decodeSlice((string) json_encode([
      'tokens_json' => json_encode(['brand_primary' => 'oklch(0.6 0.2 30)']),
      'presets_json' => json_encode(['roundness' => 'soft']),
    ]));

    $this->assertIsArray($slice);
    $this->assertSame(['brand_primary' => 'oklch(0.6 0.2 30)'], $slice['tokens_json']);
    $this->assertSame(['roundness' => 'soft'], $slice['presets_json']);
  }

  /**
   * The object shape (what a well-behaved model emits) is untouched, fenced or
   * bare, and through the workflow-executor envelope.
   */
  public function testDecodeSliceKeepsObjectShape(): void {
    $bare = $this->applier()->decodeSlice('{"tokens_json":{"brand_primary":"oklch(0.48 0.18 50)"}}');
    $this->assertSame(['brand_primary' => 'oklch(0.48 0.18 50)'], $bare['tokens_json']);

    $wrapped = $this->applier()->decodeSlice((string) json_encode([
      'slice' => "```json\n{\"tokens_json\":{\"shadow_strength\":0.4}}\n```",
      'status' => 'success',
    ]));
    // Numbers survive the decode as numbers; apply() is what coerces them.
    $this->assertSame(['shadow_strength' => 0.4], $wrapped['tokens_json']);
  }

  /**
   * A non-slice payload is still NULL, so callers can cheaply skip it.
   */
  public function testDecodeSliceRejectsNonSlices(): void {
    $this->assertNull($this->applier()->decodeSlice(''));
    $this->assertNull($this->applier()->decodeSlice('I have updated the palette.'));
    $this->assertNull($this->applier()->decodeSlice('{"__widget__":"brand_picker","payload":{}}'));
    // A slice whose every token was already stripped upstream carries only a
    // rejection report — nothing to apply.
    $this->assertNull($this->applier()->decodeSlice('{"rejected":[{"token":"radius_rounded"}]}'));
  }

}
