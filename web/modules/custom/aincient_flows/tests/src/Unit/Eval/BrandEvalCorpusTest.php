<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit\Eval;

use Drupal\aincient_flows\Eval\BrandEvalCase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The shipped corpus parses, and the case format rejects what it should.
 *
 * The corpus is code: a case that fails to parse would fail at run time, on a
 * command nobody runs in CI. This is the build-time half — no model calls.
 */
#[Group('aincient_flows')]
final class BrandEvalCorpusTest extends UnitTestCase {

  private const DIR = __DIR__ . '/../../../../evals/brand';

  public function testShippedCorpusParses(): void {
    $cases = BrandEvalCase::loadDirectory(self::DIR);
    $this->assertGreaterThanOrEqual(10, count($cases), 'one case per defect fixed (cms #36–#44), the two open asks, and the eval-caught pair defect');
    $names = array_map(static fn (BrandEvalCase $c) => $c->name, $cases);
    $this->assertSame($names, array_unique($names), 'case names are unique');
    foreach ($cases as $case) {
      $this->assertSame($case->name . '.yml', preg_replace('/^\d+-/', '', basename($case->file)), "{$case->file}: file stem (minus its order prefix) must equal `name`");
      $this->assertNotSame([], $case->expect, "{$case->name} asserts nothing");
    }
    // The KNOWN-OPEN set, by name: a defect the live eval has documented and
    // plans/brand-eval.md Phase 2 decides from data. Adding to it is a
    // conscious act (say why in the case file); removing from it means fixed.
    $expectedFail = array_values(array_map(
      static fn (BrandEvalCase $c) => $c->name,
      array_filter($cases, static fn (BrandEvalCase $c) => $c->expectedFail),
    ));
    // Empty since 2026-08-30: all four first-generation known-opens (step size,
    // absolute ask → swatch, #43 narration, Locked pair 4.4:1) were closed from
    // ≥ 3 runs each after the reword recorded in their case files.
    $this->assertSame([], $expectedFail, 'the known-open cases marked expected_fail');
  }

  public function testRejectsMalformedCases(): void {
    $bad = [
      'no ask' => ['name' => 'x'],
      'bad name' => ['name' => 'Has Spaces', 'ask' => 'a'],
      'bad status' => ['name' => 'x', 'ask' => 'a', 'status' => 'frozen'],
      'bad history role' => ['name' => 'x', 'ask' => 'a', 'history' => [['system' => 'hi']]],
      'two-key history turn' => ['name' => 'x', 'ask' => 'a', 'history' => [['user' => 'hi', 'assistant' => 'yo']]],
      'expect not a map' => ['name' => 'x', 'ask' => 'a', 'expect' => 'present'],
      'expect assoc value' => ['name' => 'x', 'ask' => 'a', 'expect' => ['k' => ['a' => 'b']]],
      'draft not a map' => ['name' => 'x', 'ask' => 'a', 'draft' => 'blue'],
    ];
    foreach ($bad as $label => $raw) {
      try {
        BrandEvalCase::fromArray($raw);
        $this->fail("$label should have been rejected");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function testDefaultsAndLockedStatus(): void {
    $c = BrandEvalCase::fromArray(['name' => 'x', 'ask' => 'a', 'saved_tokens' => 'default', 'status' => 'locked']);
    $this->assertSame([], $c->savedTokens);
    $this->assertSame('locked', $c->status);
    $this->assertFalse($c->expectedFail);
  }

}
