<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

/**
 * Concrete colour literal → OKLCH [L, C, H], for the brand eval's assertions.
 *
 * The eval asks perceptual questions the WCAG maths in ColorContrast does not:
 * "did the hue survive?" (#42: khaki from a yellow) and "did chroma follow
 * lightness down?" (#42 again). Those need L/C/H, not luminance. Pure, no
 * Drupal — the `var()` chain is resolved by TokenResolver BEFORE this is
 * called, so only literals arrive here.
 *
 * Accepts `oklch(L C H)` (L as 0..1 or %), `#rgb`/`#rrggbb`, `rgb()`. Anything
 * else → NULL. Hue is undefined at zero chroma; callers treat a NULL hue as
 * "achromatic, do not compare".
 */
final class OklchMath {

  /**
   * @return array{0: float, 1: float, 2: float|null}|null
   *   [L (0..1), C, H (deg, NULL when achromatic)] or NULL if unparseable.
   */
  public static function toOklch(string $value): ?array {
    $value = trim($value);

    if (preg_match('/^oklch\(([^)]+)\)$/i', $value, $m)) {
      $parts = preg_split('/[\s,]+/', trim(preg_replace('#/.*$#', '', $m[1]) ?? '')) ?: [];
      if (count($parts) < 3) {
        return NULL;
      }
      $l = rtrim($parts[0], '%');
      $c = $parts[1];
      $h = rtrim($parts[2], 'deg');
      if (!is_numeric($l) || !is_numeric($c) || !is_numeric($h)) {
        return NULL;
      }
      $lightness = str_ends_with($parts[0], '%') ? (float) $l / 100 : (float) $l;
      $chroma = (float) $c;
      return [$lightness, $chroma, $chroma < 1e-4 ? NULL : fmod((float) $h + 360.0, 360.0)];
    }

    $rgb = self::parseSrgb($value);
    if ($rgb === NULL) {
      return NULL;
    }
    [$r, $g, $b] = array_map([self::class, 'srgbToLinear'], $rgb);

    // Linear sRGB → LMS (Björn Ottosson's OKLab, D65).
    $l_ = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
    $m_ = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
    $s_ = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
    $l_ = self::cbrt($l_);
    $m_ = self::cbrt($m_);
    $s_ = self::cbrt($s_);
    $L = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
    $a = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
    $bb = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

    $C = sqrt($a * $a + $bb * $bb);
    $H = $C < 1e-4 ? NULL : fmod(rad2deg(atan2($bb, $a)) + 360.0, 360.0);
    return [$L, $C, $H];
  }

  /**
   * The shortest angular distance between two hues, in degrees (0..180).
   */
  public static function hueDelta(float $a, float $b): float {
    $d = fmod(abs($a - $b), 360.0);
    return $d > 180.0 ? 360.0 - $d : $d;
  }

  /**
   * @return array{0: float, 1: float, 2: float}|null
   *   sRGB channels 0..1.
   */
  private static function parseSrgb(string $value): ?array {
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m)) {
      $hex = $m[1];
      if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
      }
      return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
      ];
    }
    if (preg_match('/^rgba?\(([^)]+)\)$/i', $value, $m)) {
      $parts = preg_split('/[\s,\/]+/', trim($m[1])) ?: [];
      if (count($parts) < 3) {
        return NULL;
      }
      $out = [];
      foreach (array_slice($parts, 0, 3) as $p) {
        if (!is_numeric(rtrim($p, '%'))) {
          return NULL;
        }
        $n = (float) rtrim($p, '%');
        $out[] = str_ends_with($p, '%') ? $n / 100 : $n / 255;
      }
      return [$out[0], $out[1], $out[2]];
    }
    return NULL;
  }

  private static function srgbToLinear(float $c): float {
    return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
  }

  private static function cbrt(float $x): float {
    return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
  }

}
