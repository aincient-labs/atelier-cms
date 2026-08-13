<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ValidateSlice;
use Drupal\aincient_pages\DesignTokens;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests ValidateSlice as what it now is: a pure validator over decoded data.
 *
 * The node used to parse the model's raw response itself, with a fence-stripping
 * regex anchored at both ends of the string. A specialist that emitted fenced
 * JSON followed by a rationale — which they do about half the time — left the
 * closing fence mid-string, so the decode failed, the node passed the text
 * through as an "intentional no-op", the merge applied nothing, and the
 * orchestrator announced a brand change that never happened.
 *
 * Parsing now belongs to the graph (the engine's tolerant `json_to_data`), where
 * a failure is a recorded job with visible input and output rather than a NULL
 * returned inside PHP that leaves no trace. This node only validates.
 *
 * That it is a UNIT test is the point: with no formats to guess, the node needs
 * no container, no database, and no fixtures beyond the real token registry.
 *
 * @group aincient_flows
 */
#[Group('aincient_flows')]
final class ValidateSliceTest extends UnitTestCase {

  /**
   * The node, built with the real design-token registry off disk.
   */
  private function node(): ValidateSlice {
    // …/aincient_flows/tests/src/Unit → …/aincient_flows → …/custom.
    $custom = dirname(__DIR__, 4);
    $moduleList = $this->createMock(ModuleExtensionList::class);
    $moduleList->method('getPath')->willReturn($custom . '/aincient_pages');

    return new ValidateSlice(
      [],
      'aincient_flows:brand_validate_slice',
      [],
      new DesignTokens($moduleList),
    );
  }

  /**
   * Runs the node over a decoded slice.
   *
   * @param array<string, mixed> $slice
   *   The slice as the tolerant JSON parser hands it over.
   *
   * @return array<string, mixed>
   *   The node's output.
   */
  private function validate(array $slice): array {
    return $this->node()->process(new ParameterBag(['slice' => $slice]));
  }

  /**
   * A valid token survives, and the output stays structured (never a string).
   */
  public function testValidTokensPassThroughAsData(): void {
    $out = $this->validate([
      'tokens_json' => ['brand_primary' => 'oklch(0.62 0.22 40)'],
    ]);

    $this->assertIsArray($out['slice']);
    $this->assertSame(
      ['brand_primary' => 'oklch(0.62 0.22 40)'],
      $out['slice']['tokens_json'],
    );
    $this->assertArrayNotHasKey('rejected', $out['slice']);
  }

  /**
   * An invalid token is stripped AND reported — never silently dropped.
   *
   * The reason rides back to the orchestrator inside the slice, which is what
   * lets it tell the user the truth instead of claiming success.
   */
  public function testInvalidTokenIsStrippedAndReported(): void {
    $out = $this->validate([
      'tokens_json' => [
        'brand_primary' => 'oklch(0.62 0.22 40)',
        'radius_rounded' => '4px',
      ],
    ]);

    $this->assertSame(['brand_primary'], array_keys($out['slice']['tokens_json']));
    $this->assertCount(1, $out['slice']['rejected']);
    $this->assertSame('radius_rounded', $out['slice']['rejected'][0]['token']);
    $this->assertNotSame('', $out['slice']['rejected'][0]['reason']);
  }

  /**
   * A stringified tokens_json is repaired, not dropped.
   *
   * The tool-arg contract these keys are named after declares them as JSON
   * strings, so specialists nest them as strings often enough to matter.
   */
  public function testStringifiedTokensJsonIsRepaired(): void {
    $out = $this->validate([
      'tokens_json' => '{"brand_primary":"oklch(0.62 0.22 40)"}',
    ]);

    $this->assertSame(
      ['brand_primary' => 'oklch(0.62 0.22 40)'],
      $out['slice']['tokens_json'],
    );
  }

  /**
   * An empty slice is a deliberate no-op and stays one.
   */
  public function testEmptySliceStaysANoop(): void {
    $this->assertSame(['slice' => []], $this->validate([]));
  }

  /**
   * Given a non-slice, the node invents nothing.
   *
   * Prose should never reach here — the tolerant parser upstream reports
   * `success: false` for unparseable output, and routing that branch to a
   * rejection is the graph's job. This only pins that the node does not quietly
   * manufacture a slice out of something that isn't one.
   */
  public function testNonSliceDataYieldsNoSlice(): void {
    $out = $this->validate(['rationale' => 'I made the palette warmer.']);

    $this->assertSame([], $out['slice']);
  }

}
