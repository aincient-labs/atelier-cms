<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Computes WCAG contrast for the design system's declared surface/on-colour
 * pairs (the tokens carrying an `on:` key in design-tokens.yml).
 *
 * The pairing convention guarantees every surface ships the text colour meant
 * to sit on it; this is the safety net that catches a low-contrast pairing
 * before it reaches a reader. It is ADVISORY — preview/publish surface the
 * ratio + pass/fail so the agent self-corrects and the human sees a warning,
 * but it does not hard-block (see BrandController::save / PreviewBrand).
 *
 * A colour value may be a raw `#hex`, `rgb()/rgba()`, `oklch()`, or a
 * `var(--token)` reference — so we resolve the var() chain through the
 * effective token map (overrides over registry defaults) and the Tier 0
 * Tailwind base palette, then convert to linear sRGB for the WCAG ratio.
 * Values we cannot parse (unknown function, alpha-only, etc.) are skipped
 * rather than guessed — a pair we can't assess is reported as `null` ratio.
 */
final class ColorContrast {

  /** WCAG AA for normal body text. */
  public const AA_NORMAL = 4.5;

  /** Recursion guard for var() chains. */
  private const MAX_DEPTH = 12;

  public function __construct(private readonly DesignTokens $tokens) {}

  /**
   * A contrast report for every declared surface/on pair, evaluated against the
   * given token overrides merged over the registry defaults (so it reflects
   * exactly what a preview/publish would render).
   *
   * @param array<string, string> $overrides
   *   Token name => value overrides (e.g. a studio draft or saved brand).
   *
   * @return list<array{surface: string, on: string, surfaceValue: string, onValue: string, ratio: float|null, passes: bool|null}>
   *   One entry per pair. `ratio`/`passes` are NULL when a colour could not be
   *   resolved to a concrete value.
   */
  public function pairReport(array $overrides = []): array {
    $effective = $this->effective($overrides);
    $byCssVar = $this->cssVarToName();
    $tw = $this->tokens->tailwindValues();

    $out = [];
    foreach ($this->tokens->pairs() as $pair) {
      $surface = $pair['surface'];
      $on = $pair['on'];
      $surfaceValue = (string) ($effective[$surface] ?? '');
      $onValue = (string) ($effective[$on] ?? '');

      $a = $this->toLinear($surfaceValue, $effective, $byCssVar, $tw);
      $b = $this->toLinear($onValue, $effective, $byCssVar, $tw);
      $ratio = ($a !== NULL && $b !== NULL) ? $this->ratio($a, $b) : NULL;

      $out[] = [
        'surface' => $surface,
        'on' => $on,
        'surfaceValue' => $surfaceValue,
        'onValue' => $onValue,
        'ratio' => $ratio,
        'passes' => $ratio === NULL ? NULL : $ratio >= self::AA_NORMAL,
      ];
    }
    return $out;
  }

  /**
   * The subset of {@see pairReport} entries that resolved to a concrete ratio
   * and fail AA — the warnings worth showing.
   *
   * @return list<array{surface: string, on: string, surfaceValue: string, onValue: string, ratio: float|null, passes: bool|null}>
   */
  public function failures(array $overrides = []): array {
    return array_values(array_filter(
      $this->pairReport($overrides),
      static fn (array $r) => $r['passes'] === FALSE,
    ));
  }

  /**
   * A contrast report for every accent text × surface combination templates
   * render that is NOT the surface's declared on-colour (the `legible_on`
   * combos — e.g. brand `primary` text on the neutral background/muted/card
   * surfaces). Same var()-resolution and AA threshold as {@see pairReport};
   * reported separately because the surface/on contract is the hard guarantee
   * while these are advisory — a brand that darkens a neutral surface can leave
   * its accent text unreadable there even though every `on:` pair passes.
   *
   * @param array<string, string> $overrides
   *   Token name => value overrides (a studio draft or saved brand).
   *
   * @return list<array{text: string, surface: string, textValue: string, surfaceValue: string, ratio: float|null, passes: bool|null}>
   */
  public function legibilityReport(array $overrides = []): array {
    $effective = $this->effective($overrides);
    $byCssVar = $this->cssVarToName();
    $tw = $this->tokens->tailwindValues();

    $out = [];
    foreach ($this->tokens->legiblePairs() as $combo) {
      $text = $combo['text'];
      $surface = $combo['surface'];
      $textValue = (string) ($effective[$text] ?? '');
      $surfaceValue = (string) ($effective[$surface] ?? '');

      $a = $this->toLinear($textValue, $effective, $byCssVar, $tw);
      $b = $this->toLinear($surfaceValue, $effective, $byCssVar, $tw);
      $ratio = ($a !== NULL && $b !== NULL) ? $this->ratio($a, $b) : NULL;

      $out[] = [
        'text' => $text,
        'surface' => $surface,
        'textValue' => $textValue,
        'surfaceValue' => $surfaceValue,
        'ratio' => $ratio,
        'passes' => $ratio === NULL ? NULL : $ratio >= self::AA_NORMAL,
      ];
    }
    return $out;
  }

  /**
   * The subset of {@see legibilityReport} entries that resolved to a concrete
   * ratio and fail AA — the advisory accent warnings worth showing.
   *
   * @return list<array{text: string, surface: string, textValue: string, surfaceValue: string, ratio: float|null, passes: bool|null}>
   */
  public function legibilityFailures(array $overrides = []): array {
    return array_values(array_filter(
      $this->legibilityReport($overrides),
      static fn (array $r) => $r['passes'] === FALSE,
    ));
  }

  /**
   * The WCAG contrast ratio between two linear-sRGB colours, 1.0–21.0.
   *
   * @param array{0: float, 1: float, 2: float} $a
   * @param array{0: float, 1: float, 2: float} $b
   */
  public function ratio(array $a, array $b): float {
    $la = $this->luminance($a);
    $lb = $this->luminance($b);
    [$hi, $lo] = $la >= $lb ? [$la, $lb] : [$lb, $la];
    return round(($hi + 0.05) / ($lo + 0.05), 2);
  }

  /**
   * The effective value of every token: a known override, else its default.
   *
   * @return array<string, string>
   */
  private function effective(array $overrides): array {
    $values = $this->tokens->defaults();
    foreach ($overrides as $name => $value) {
      if (is_string($value) && array_key_exists($name, $values)) {
        $values[$name] = trim($value);
      }
    }
    return $values;
  }

  /** @return array<string, string> css_var => token name. */
  private function cssVarToName(): array {
    $map = [];
    foreach ($this->tokens->all() as $name => $def) {
      $map[$def['css_var']] = $name;
    }
    return $map;
  }

  /**
   * Resolve a CSS colour value to linear sRGB [r, g, b] in 0..1, following any
   * var() reference through the token map then the Tailwind base palette.
   *
   * @return array{0: float, 1: float, 2: float}|null
   */
  private function toLinear(string $value, array $effective, array $byCssVar, array $tw, int $depth = 0): ?array {
    $value = trim($value);
    if ($value === '' || $depth > self::MAX_DEPTH) {
      return NULL;
    }
    if (stripos($value, 'var(') === 0) {
      if (!preg_match('/var\(\s*(--[a-z0-9-]+)/i', $value, $m)) {
        return NULL;
      }
      $cssVar = ltrim($m[1], '-');
      // A reference to another design token: resolve its effective value.
      if (isset($byCssVar[$cssVar])) {
        return $this->toLinear((string) ($effective[$byCssVar[$cssVar]] ?? ''), $effective, $byCssVar, $tw, $depth + 1);
      }
      // A reference to a Tier 0 Tailwind swatch (--color-*).
      if (isset($tw[$cssVar])) {
        return $this->toLinear($tw[$cssVar], $effective, $byCssVar, $tw, $depth + 1);
      }
      return NULL;
    }
    return $this->parse($value);
  }

  /**
   * Parse a concrete colour literal (#hex, rgb(), oklch()) to linear sRGB.
   *
   * @return array{0: float, 1: float, 2: float}|null
   */
  private function parse(string $value): ?array {
    $value = trim($value);

    // #rgb / #rrggbb
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m)) {
      $hex = $m[1];
      if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
      }
      return [
        $this->srgbToLinear(hexdec(substr($hex, 0, 2)) / 255),
        $this->srgbToLinear(hexdec(substr($hex, 2, 2)) / 255),
        $this->srgbToLinear(hexdec(substr($hex, 4, 2)) / 255),
      ];
    }

    // rgb()/rgba() — comma or space separated; alpha ignored (treated opaque).
    if (preg_match('/^rgba?\(([^)]+)\)$/i', $value, $m)) {
      $parts = preg_split('/[\s,\/]+/', trim($m[1])) ?: [];
      if (count($parts) < 3) {
        return NULL;
      }
      $chan = static function (string $p): ?float {
        if (!is_numeric(rtrim($p, '%'))) {
          return NULL;
        }
        $n = (float) rtrim($p, '%');
        return str_ends_with($p, '%') ? $n / 100 : $n / 255;
      };
      $r = $chan($parts[0]);
      $g = $chan($parts[1]);
      $b = $chan($parts[2]);
      if ($r === NULL || $g === NULL || $b === NULL) {
        return NULL;
      }
      return [$this->srgbToLinear($r), $this->srgbToLinear($g), $this->srgbToLinear($b)];
    }

    // oklch(L C H) — L as 0..1 or %, C absolute, H in degrees; alpha ignored.
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
      return $this->oklchToLinear($lightness, (float) $c, (float) $h);
    }

    return NULL;
  }

  /** sRGB channel (0..1) → linear-light. */
  private function srgbToLinear(float $c): float {
    return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
  }

  /**
   * OKLCH → linear sRGB [r, g, b], clamped to 0..1 (Björn Ottosson's matrices).
   *
   * @return array{0: float, 1: float, 2: float}
   */
  private function oklchToLinear(float $l, float $c, float $h): array {
    $hr = deg2rad($h);
    $a = $c * cos($hr);
    $b = $c * sin($hr);

    $l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
    $m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
    $s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;

    $l3 = $l_ ** 3;
    $m3 = $m_ ** 3;
    $s3 = $s_ ** 3;

    $r = 4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3;
    $g = -1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3;
    $bl = -0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3;

    $clamp = static fn (float $x): float => max(0.0, min(1.0, $x));
    return [$clamp($r), $clamp($g), $clamp($bl)];
  }

  /**
   * WCAG relative luminance from linear-sRGB [r, g, b].
   *
   * @param array{0: float, 1: float, 2: float} $rgb
   */
  private function luminance(array $rgb): float {
    return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
  }

}
