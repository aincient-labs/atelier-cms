<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Per-type validation for design-token values — the SINGLE point of trust.
 *
 * Token values are concatenated verbatim into an inline `<style>html:root{
 * --x: VALUE }</style>` on every page (BrandRepository::cssVariables /
 * SiteChrome::brandStyle). The injection point is a dumb concatenator; ALL
 * trust lives here. The threat is a value that closes the declaration, the
 * rule, or the style element to inject arbitrary CSS/markup (e.g. CSS
 * exfiltration via url(), or `</style><script>`).
 *
 * Defense in depth: a universal structural gate rejects any dangerous
 * character first; then a strict per-type allow-list confirms the value really
 * is a CSS value of the expected kind. Values starting with `var(` are checked
 * as references to a known token (the only way tiers reference each other).
 */
final class TokenValue {

  /** Characters that could break out of the declaration/rule/style element. */
  private const FORBIDDEN = ['<', '>', '{', '}', ';', '@', '\\', '`', '/*', '*/', '//', 'url(', 'expression(', '&#'];

  private const MAX_LENGTH = 256;

  private const NAMED_COLORS = ['transparent', 'currentcolor', 'inherit', 'black', 'white'];

  /**
   * Validate a value for a given token type against a set of known token names
   * (so `var(--x)` references can be confined to real tokens).
   *
   * @param string $type
   *   One of: color, length, number, font-family, shadow, enum.
   * @param string[] $knownVars
   *   css_var names that a var() reference may target.
   * @param string[] $enum
   *   Allowed values when $type === 'enum'.
   */
  public static function isValid(string $type, string $value, array $knownVars = [], array $enum = []): bool {
    $value = trim($value);
    if ($value === '' || strlen($value) > self::MAX_LENGTH) {
      return FALSE;
    }
    // Universal structural gate — applies to EVERY type, before type checks.
    $lower = strtolower($value);
    foreach (self::FORBIDDEN as $needle) {
      if (str_contains($lower, $needle)) {
        return FALSE;
      }
    }
    if (preg_match('/[\x00-\x1f]/', $value)) {
      return FALSE;
    }
    // A bare var() reference is allowed for any type, but must target a known
    // token. A font-family is the one type that legitimately mixes a leading
    // var() (the inherited family) with literal fallbacks (e.g. the always-on
    // `"Noto Emoji"` tail) — that is NOT a single reference, so it falls through
    // to the per-segment font-family check below rather than short-circuiting.
    if (str_starts_with($lower, 'var(') && self::isVarReference($value, $knownVars)) {
      return TRUE;
    }
    return match ($type) {
      'color' => self::isColor($value),
      'length' => self::isLength($value),
      'number' => (bool) preg_match('/^-?\d*\.?\d+$/', $value),
      'font-family' => self::isFontFamily($value, $knownVars),
      'shadow' => self::isShadow($value),
      'enum' => in_array($value, $enum, TRUE),
      default => FALSE,
    };
  }

  /**
   * Canonicalise an already-valid value for EMISSION into CSS.
   *
   * Be liberal in what we accept, strict in what we emit: the agent is told
   * (and {@see isLength} blesses) a bare `0` for a length — but a unitless `0`
   * is a `<number>`, not a `<length>`, and the moment it flows into a derived
   * rung's `calc(var(--shadow-blur) * 0.3)` the product is type-invalid and the
   * whole `box-shadow` collapses to `none`. Emitting `0px` keeps zero a length
   * through every multiplication. var()/calc() values are passed through
   * untouched (the exact string `0` is the only unitless length isLength lets
   * past, so this is the only case to fix).
   */
  public static function normalize(string $type, string $value): string {
    $value = trim($value);
    if ($type === 'length' && $value === '0') {
      return '0px';
    }
    return $value;
  }

  /** `var(--known-token)` or `var(--known-token, <safe-fallback>)`. */
  private static function isVarReference(string $value, array $knownVars): bool {
    if (!preg_match('/^var\(\s*--([a-z0-9-]+)\s*(?:,\s*(.+))?\)$/i', $value, $m)) {
      return FALSE;
    }
    if (!in_array($m[1], $knownVars, TRUE)) {
      return FALSE;
    }
    // Optional fallback must itself be a safe scalar value (no nested var/fn).
    if (isset($m[2]) && $m[2] !== '') {
      $fallback = trim($m[2]);
      return self::isColor($fallback) || self::isLength($fallback)
        || (bool) preg_match('/^-?\d*\.?\d+$/', $fallback);
    }
    return TRUE;
  }

  private static function isColor(string $v): bool {
    $lower = strtolower($v);
    if (in_array($lower, self::NAMED_COLORS, TRUE)) {
      return TRUE;
    }
    // Hex.
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $v)) {
      return TRUE;
    }
    // Functional notation: only the recognised colour functions, and only
    // digits, dots, %, spaces, commas and slashes inside the single paren pair.
    return (bool) preg_match('/^(rgb|rgba|hsl|hsla|oklch|oklab|lab|lch|hwb)\([0-9.,%\/\s-]+\)$/i', $v);
  }

  private static function isLength(string $v): bool {
    if ($v === '0') {
      return TRUE;
    }
    if (preg_match('/^-?\d*\.?\d+(px|rem|em|%|vh|vw|vmin|vmax|ch|ex|pt|fr)$/i', $v)) {
      return TRUE;
    }
    // Restricted calc(): only numbers, units, operators and spaces (the
    // universal gate already forbids ; { } etc.).
    if (preg_match('/^calc\([0-9.+\-*\/()%\sa-z]+\)$/i', $v)) {
      return TRUE;
    }
    return FALSE;
  }

  private static function isFontFamily(string $v, array $knownVars = []): bool {
    foreach (explode(',', $v) as $item) {
      $item = trim($item);
      // A stack may lead with a var() reference to an inherited family token
      // (e.g. `var(--font-family-base), "Noto Emoji"`).
      if (str_starts_with(strtolower($item), 'var(')) {
        if (!self::isVarReference($item, $knownVars)) {
          return FALSE;
        }
        continue;
      }
      $isQuoted = preg_match('/^"[^"]+"$/', $item) || preg_match("/^'[^']+'$/", $item);
      $isBareword = preg_match('/^[a-z][a-z0-9 -]*$/i', $item);
      if (!$isQuoted && !$isBareword) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private static function isShadow(string $v): bool {
    if (strtolower($v) === 'none') {
      return TRUE;
    }
    // One or more comma-separated layers; each layer is lengths/colour/inset
    // only. The universal gate already removed structural characters.
    foreach (explode(',', $v) as $layer) {
      if (!preg_match('/^[\sa-z0-9.%#\/()-]+$/i', trim($layer))) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
