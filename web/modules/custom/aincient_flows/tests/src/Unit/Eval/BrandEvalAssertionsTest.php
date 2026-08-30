<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit\Eval;

use Drupal\aincient_flows\Eval\BrandEvalAssertions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `expect:` grammar — every form a case file may use, deterministic.
 */
#[Group('aincient_flows')]
final class BrandEvalAssertionsTest extends UnitTestCase {

  public static function forms(): iterable {
    $o = [
      'slice.brand_primary' => 'var(--color-yellow-500)',
      'contrast.brand_primary' => 5.12,
      'chroma_delta.brand_primary' => 0.08,
      'lightness_delta.brand_primary' => -0.3,
      'delegations.colour' => 1,
      'rejected' => [],
      'prose' => 'Primary is now a darker yellow (yellow-500).',
      'empty' => '',
    ];
    yield 'regex match' => [['slice.brand_primary' => '/^var\(--color-yellow-(500|600)\)$/'], $o, TRUE];
    yield 'regex miss' => [['slice.brand_primary' => '/blue/'], $o, FALSE];
    yield 'negative regex' => [['prose' => '!/blue/i'], $o, TRUE];
    yield 'prose_not sugar' => [['prose_not' => '/blue/i'], $o, TRUE];
    yield 'prose_not hit fails' => [['prose_not' => '/yellow/i'], $o, FALSE];
    yield 'present' => [['slice.brand_primary' => 'present'], $o, TRUE];
    yield 'present on missing fails' => [['slice.brand_accent' => 'present'], $o, FALSE];
    yield 'present on empty string fails' => [['empty' => 'present'], $o, FALSE];
    yield 'absent on missing' => [['slice.brand_accent' => 'absent'], $o, TRUE];
    yield 'absent on present fails' => [['slice.brand_primary' => 'absent'], $o, FALSE];
    yield '>=' => [['contrast.brand_primary' => '>= 4.5'], $o, TRUE];
    yield '>= fails' => [['contrast.brand_primary' => '>= 7'], $o, FALSE];
    yield '==' => [['delegations.colour' => '== 1'], $o, TRUE];
    yield '!=' => [['delegations.colour' => '!= 1'], $o, FALSE];
    yield 'rises' => [['chroma_delta.brand_primary' => 'rises'], $o, TRUE];
    yield 'falls' => [['lightness_delta.brand_primary' => 'falls'], $o, TRUE];
    yield 'falls on a rise fails' => [['chroma_delta.brand_primary' => 'falls'], $o, FALSE];
    yield 'numeric compare on non-number fails' => [['prose' => '> 1'], $o, FALSE];
    yield 'empty list' => [['rejected' => []], $o, TRUE];
    yield 'list mismatch' => [['rejected' => ['brand_primary']], $o, FALSE];
    yield 'exact string' => [['slice.brand_primary' => 'var(--color-yellow-500)'], $o, TRUE];
    yield 'missing key fails a regex' => [['slice.nothing' => '/x/'], $o, FALSE];
  }

  #[DataProvider('forms')]
  public function testForms(array $expect, array $observed, bool $pass): void {
    $results = BrandEvalAssertions::evaluate($expect, $observed);
    $this->assertCount(1, $results);
    $this->assertSame($pass, $results[0]['pass'], $results[0]['key'] . ': expected ' . $results[0]['expected'] . ', got ' . $results[0]['actual']);
  }

  public function testMissingIsReportedNotInvented(): void {
    $r = BrandEvalAssertions::evaluate(['slice.brand_primary' => 'present'], [])[0];
    $this->assertFalse($r['pass']);
    $this->assertSame('(missing)', $r['actual']);
  }

  public function testValidExpectation(): void {
    $this->assertTrue(BrandEvalAssertions::validExpectation('a', '/x/'));
    $this->assertTrue(BrandEvalAssertions::validExpectation('a', []));
    $this->assertTrue(BrandEvalAssertions::validExpectation('a', 1));
    $this->assertFalse(BrandEvalAssertions::validExpectation('', '/x/'));
    $this->assertFalse(BrandEvalAssertions::validExpectation('a', ['k' => 'v']));
  }

}
