<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Unit;

use Drupal\aincient_pages\TokenValue;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the per-type token-value validator — the security gate.
 *
 * @group aincient
 * @coversDefaultClass \Drupal\aincient_pages\TokenValue
 */
final class TokenValueTest extends UnitTestCase {

  /** css_var names a var() reference may target, for these cases. */
  private const KNOWN = ['primary', 'shadow-lg', 'radius-full'];

  /**
   * @dataProvider cases
   */
  public function testIsValid(string $type, string $value, bool $expected): void {
    $this->assertSame($expected, TokenValue::isValid($type, $value, self::KNOWN, ['compact', 'roomy']));
  }

  public static function cases(): array {
    return [
      // Valid values per type.
      'hex' => ['color', '#1f7a4d', TRUE],
      'oklch' => ['color', 'oklch(0.63 0.25 350)', TRUE],
      'rgb-slash' => ['color', 'rgb(0 0 0 / 0.5)', TRUE],
      'named' => ['color', 'transparent', TRUE],
      'var-ref' => ['color', 'var(--primary)', TRUE],
      'length-rem' => ['length', '0.75rem', TRUE],
      'length-zero' => ['length', '0', TRUE],
      'length-calc' => ['length', 'calc(2rem * 1.5)', TRUE],
      'number' => ['number', '0.85', TRUE],
      'font' => ['font-family', '"Playfair Display", serif', TRUE],
      'shadow' => ['shadow', '0 1px 2px 0 rgb(0 0 0 / 0.05)', TRUE],
      'shadow-none' => ['shadow', 'none', TRUE],
      'enum-ok' => ['enum', 'compact', TRUE],

      // Injection / structural breakout — rejected regardless of plausibility.
      'decl-breakout' => ['color', 'red; }', FALSE],
      'rule-breakout' => ['color', 'red; } body{display:none}', FALSE],
      'style-breakout' => ['color', 'red</style>', FALSE],
      'url-exfil' => ['color', 'url(//evil)', FALSE],
      'expression' => ['color', 'expression(alert(1))', FALSE],
      'at-import' => ['font-family', 'Inter; @import url(x)', FALSE],
      'comment' => ['color', '#fff/*x*/', FALSE],
      'semicolon-len' => ['length', '1rem;', FALSE],
      'brace-shadow' => ['shadow', '0 0 0 #000 } body{x:y}', FALSE],

      // Wrong type / unknown enum / dangling var.
      'not-a-color' => ['color', 'teal', FALSE],
      'enum-bad' => ['enum', 'cozy', FALSE],
      'var-unknown' => ['color', 'var(--not-a-token)', FALSE],
      'empty' => ['color', '', FALSE],
    ];
  }

  /**
   * @dataProvider normalizeCases
   */
  public function testNormalize(string $type, string $value, string $expected): void {
    $this->assertSame($expected, TokenValue::normalize($type, $value));
  }

  public static function normalizeCases(): array {
    return [
      // A bare length zero gains a unit so it stays a <length> through the
      // derived rungs' calc(var(--shadow-blur) * f) (a unitless 0 would make
      // the product a <number> and collapse the whole box-shadow to none).
      'len-zero-gets-unit' => ['length', '0', '0px'],
      'len-zero-trimmed' => ['length', ' 0 ', '0px'],
      // Everything else passes through (trimmed) untouched.
      'len-with-unit' => ['length', '8px', '8px'],
      'len-calc' => ['length', 'calc(2rem * 1.5)', 'calc(2rem * 1.5)'],
      'len-var' => ['length', 'var(--shadow-distance)', 'var(--shadow-distance)'],
      'number-zero-kept' => ['number', '0', '0'],
      'color-untouched' => ['color', 'oklch(0.8 0.1 340)', 'oklch(0.8 0.1 340)'],
    ];
  }

}
