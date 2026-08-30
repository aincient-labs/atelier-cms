<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit\Eval;

use Drupal\aincient_flows\Eval\OklchMath;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * sRGB → OKLCH agrees with the Tailwind v4 palette, which is authored in oklch.
 */
#[Group('aincient_flows')]
final class OklchMathTest extends UnitTestCase {

  public function testOklchLiteralPassesThrough(): void {
    [$l, $c, $h] = OklchMath::toOklch('oklch(0.973 0.071 103.193)');
    $this->assertEqualsWithDelta(0.973, $l, 1e-9);
    $this->assertEqualsWithDelta(0.071, $c, 1e-9);
    $this->assertEqualsWithDelta(103.193, $h, 1e-9);
    [$l] = OklchMath::toOklch('oklch(97.3% 0.071 103.193)');
    $this->assertEqualsWithDelta(0.973, $l, 1e-9);
    $this->assertNull(OklchMath::toOklch('oklch(0.5 0 0)')[2], 'zero chroma has no hue');
  }

  public function testHexMatchesTailwindAuthoredValues(): void {
    // yellow-100 #FEF9C2 is authored as oklch(97.3% 0.071 103.193).
    [$l, $c, $h] = OklchMath::toOklch('#FEF9C2');
    $this->assertEqualsWithDelta(0.973, $l, 0.01);
    $this->assertEqualsWithDelta(0.071, $c, 0.01);
    $this->assertEqualsWithDelta(103.2, $h, 3.0);
    // blue-600 #155DFC is authored as oklch(54.6% 0.245 262.881).
    [$l, $c, $h] = OklchMath::toOklch('#155DFC');
    $this->assertEqualsWithDelta(0.546, $l, 0.01);
    $this->assertEqualsWithDelta(0.245, $c, 0.01);
    $this->assertEqualsWithDelta(262.9, $h, 3.0);
  }

  public function testAchromaticAndGarbage(): void {
    [$l, $c, $h] = OklchMath::toOklch('#ffffff');
    $this->assertEqualsWithDelta(1.0, $l, 0.01);
    $this->assertLessThan(0.001, $c);
    $this->assertNull($h);
    $this->assertNull(OklchMath::toOklch('var(--color-yellow-100)'));
    $this->assertNull(OklchMath::toOklch('12px'));
  }

  public function testHueDeltaWraps(): void {
    $this->assertEqualsWithDelta(20.0, OklchMath::hueDelta(350.0, 10.0), 1e-9);
    $this->assertEqualsWithDelta(180.0, OklchMath::hueDelta(0.0, 180.0), 1e-9);
    $this->assertEqualsWithDelta(27.0, OklchMath::hueDelta(103.0, 76.0), 1e-9);
  }

}
