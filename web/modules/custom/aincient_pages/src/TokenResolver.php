<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Follows a design-token value down to the concrete value behind it — the
 * SINGLE point of resolution, as {@see TokenValue} is the single point of trust.
 *
 * A token value is not necessarily a value. 46 of the registry's tokens default
 * to a `var()` reference — that is how the tiers compose (palette → semantic →
 * component, plus the Tier-0 Tailwind `--color-*` palette) and how the studio's
 * swatch grid records a picked colour. So `card_radius` may literally read
 * `var(--radius-2xl)`, and `brand_primary` may read `var(--color-yellow-100)`.
 *
 * That is fine for CSS, which resolves references itself, and wrong for every
 * consumer that needs the VALUE: a contrast ratio, a human descriptor, or a
 * model being asked to make something "one step darker". Before this class
 * existed each of those grew its own partial walker and they diverged — the
 * weakest one sat on the agent's prompt path and echoed `var(--color-yellow-100)`
 * bare, so "make primary darker" had no number to work from and the model
 * darkened the saved palette instead (DECISIONS 0408).
 */
final class TokenResolver {

  /** Recursion guard for var() chains. */
  public const MAX_DEPTH = 12;

  public function __construct(private readonly DesignTokens $tokens) {}

  /**
   * The concrete value behind a token value, or NULL when it does not land on
   * one.
   *
   * Follows a `var(--x)` reference through the registry (against $overrides
   * where given, else the registry defaults) and then the Tier-0 Tailwind
   * palette. A value that is already concrete is returned trimmed. A stack that
   * merely LEADS with a reference (`var(--font-family-base), "Noto Emoji"`) has
   * its head resolved and its tail preserved.
   *
   * @param string $value
   *   The value to resolve.
   * @param array<string, string> $overrides
   *   Token overrides to resolve against (a studio draft or the saved brand),
   *   keyed by token name OR css_var — both shapes are in circulation.
   */
  public function resolve(string $value, array $overrides = [], int $depth = 0): ?string {
    $value = trim($value);
    if ($value === '' || $depth > self::MAX_DEPTH) {
      return NULL;
    }
    if (stripos($value, 'var(') !== 0) {
      return $value;
    }
    // A stack whose head is a reference: resolve the head, keep the tail.
    if (preg_match('/^var\(\s*(--[a-z0-9-]+)\s*(?:,\s*([^)]*))?\)\s*(,\s*.+)?$/i', $value, $m) !== 1) {
      return NULL;
    }
    $cssVar = ltrim($m[1], '-');
    $fallback = trim($m[2] ?? '');
    $tail = trim($m[3] ?? '');

    $head = $this->target($cssVar, $overrides);
    if ($head === NULL) {
      // An unknown reference falls back to the var()'s own fallback, if it
      // declared one — never to a guess.
      $head = $fallback !== '' ? $this->resolve($fallback, $overrides, $depth + 1) : NULL;
    }
    else {
      $head = $this->resolve($head, $overrides, $depth + 1);
    }
    if ($head === NULL) {
      return NULL;
    }
    return $tail === '' ? $head : $head . $tail;
  }

  /**
   * As {@see resolve}, but STOPS at a Tier-0 Tailwind reference and returns it
   * verbatim instead of following it.
   *
   * Deliberately shallower, for the one caller that wants the swatch's NAME:
   * `visualBrief()` tells an image agent "fuchsia 300", which is a better
   * prompt ingredient than the oklch behind it. Callers that want the value as
   * well resolve a second time with {@see resolve}.
   *
   * @param array<string, string> $overrides
   *   Token overrides, keyed by token name OR css_var.
   */
  public function resolveInRegistry(string $value, array $overrides = [], int $depth = 0): string {
    $value = trim($value);
    if ($depth > self::MAX_DEPTH || preg_match('/^var\(\s*(--[a-z0-9-]+)\s*\)$/i', $value, $m) !== 1) {
      return $value;
    }
    $cssVar = ltrim($m[1], '-');
    $byCssVar = $this->cssVarToName();
    if (!isset($byCssVar[$cssVar])) {
      // Not a registry token (the Tier-0 Tailwind palette): leave it named.
      return $value;
    }
    $effective = $this->effective($overrides);
    return $this->resolveInRegistry((string) ($effective[$byCssVar[$cssVar]] ?? ''), $overrides, $depth + 1);
  }

  /**
   * TRUE when a value is (or leads with) a `var()` reference — i.e. resolving it
   * tells the reader something they could not already see.
   */
  public function isReference(string $value): bool {
    return stripos(trim($value), 'var(') === 0;
  }

  /**
   * The effective value of every token: a known override, else its default.
   *
   * @param array<string, string> $overrides
   *   Overrides keyed by token name OR css_var.
   *
   * @return array<string, string>
   *   Token name => value.
   */
  public function effective(array $overrides = []): array {
    $values = array_map('trim', $this->tokens->defaults());
    foreach ($this->byName($overrides) as $name => $value) {
      if (array_key_exists($name, $values)) {
        $values[$name] = $value;
      }
    }
    return $values;
  }

  /**
   * Re-key overrides to token names.
   *
   * The studio draft rides the wire keyed by css_var (`brand-primary`); the
   * saved brand config is keyed by token name (`brand_primary`). Passing the
   * wrong shape used to resolve silently against the defaults instead of the
   * draft, so accept both.
   *
   * @param array<string, string> $overrides
   *
   * @return array<string, string>
   */
  public function byName(array $overrides): array {
    if ($overrides === []) {
      return [];
    }
    $byCssVar = $this->cssVarToName();
    $out = [];
    foreach ($overrides as $key => $value) {
      if (is_string($value)) {
        $out[$byCssVar[(string) $key] ?? (string) $key] = trim($value);
      }
    }
    return $out;
  }

  /** @return array<string, string> css_var => token name. */
  public function cssVarToName(): array {
    $map = [];
    foreach ($this->tokens->all() as $name => $def) {
      $map[$def['css_var']] = $name;
    }
    return $map;
  }

  /**
   * The value a css_var points at — a registry token's effective value, else a
   * Tier-0 Tailwind swatch — or NULL when the name is unknown.
   *
   * @param array<string, string> $overrides
   */
  private function target(string $cssVar, array $overrides): ?string {
    $byCssVar = $this->cssVarToName();
    if (isset($byCssVar[$cssVar])) {
      $effective = $this->effective($overrides);
      return $effective[$byCssVar[$cssVar]] ?? NULL;
    }
    $tw = $this->tokens->tailwindValues();
    return $tw[$cssVar] ?? NULL;
  }

}
