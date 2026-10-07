<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ApprovalGate;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the engine's approval gate: any guarded call asks, an empty list never.
 *
 * @group aincient_flows
 */
#[CoversClass(ApprovalGate::class)]
final class ApprovalGateTest extends UnitTestCase {

  /**
   * Cases: [approval_tools CSV, tool call names, expected needs_approval].
   *
   * @return array<string, array{0: string, 1: array<int, string>, 2: bool}>
   *   The cases.
   */
  public static function cases(): array {
    return [
      'empty list never asks' => ['', ['capability_preview_page'], FALSE],
      'blank list never asks' => [' , ', ['capability_preview_page'], FALSE],
      'no calls' => ['capability_preview_page', [], FALSE],
      'unlisted call' => ['capability_quick_brand_picker', ['capability_list_pages'], FALSE],
      'listed call' => ['capability_preview_page', ['capability_preview_page'], TRUE],
      'guarded call after a safe one' => ['capability_preview_page', ['capability_list_pages', 'capability_preview_page'], TRUE],
      'whitespace in the list' => [' capability_list_pages ,capability_preview_page ', ['capability_preview_page'], TRUE],
      'names match exactly' => ['capability_preview', ['capability_preview_page'], FALSE],
    ];
  }

  /**
   * The gate's verdict for each case.
   *
   * @param array<int, string> $names
   *   The tool call names in the step.
   */
  #[DataProvider('cases')]
  public function testVerdict(string $csv, array $names, bool $expected): void {
    $calls = array_map(
      static fn (string $name, int $i): array => ['name' => $name, 'args' => [], 'tool_call_id' => 'c' . $i],
      $names,
      array_keys($names),
    );
    $gate = new ApprovalGate([], 'approval_gate', []);
    $result = $gate->process(new ParameterBag(['tool_calls' => $calls, 'approval_tools' => $csv]));
    $this->assertSame(['needs_approval' => $expected], $result);
  }

  /**
   * A malformed call (no name) is skipped, not fatal.
   */
  public function testMalformedCallIsSkipped(): void {
    $gate = new ApprovalGate([], 'approval_gate', []);
    $result = $gate->process(new ParameterBag([
      'tool_calls' => ['junk', ['args' => []]],
      'approval_tools' => 'capability_preview_page',
    ]));
    $this->assertFalse($result['needs_approval']);
  }

}
