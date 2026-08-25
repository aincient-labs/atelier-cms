<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

/**
 * The machine-checkable slice of the pack CSS contract (W5).
 *
 * Brand and a11y laws are ours; a pack's markup is theirs — contrast rules are
 * unenforceable inside foreign CSS (plan §8), so this lints only what a regex
 * can honestly claim: hardcoded colours that a rebrand can never reach, and
 * fractional `opacity` (the muted-text anti-pattern that breaks WCAG AA — use
 * the muted-foreground token instead). ADVISORY, not the gate: these are
 * warnings in `atelier:pack-validate`, never admission errors — a pack that
 * looks wrong after a rebrand is the client's bruise, not a site-down.
 */
final class StylesheetLint {

  /**
   * Lint one stylesheet's source. Returns advisory messages (line-prefixed).
   *
   * @return string[]
   */
  public static function lint(string $css): array {
    $issues = [];
    // Strip comments so commented-out examples don't warn.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
    foreach (explode("\n", $css) as $i => $line) {
      $n = $i + 1;
      // Hardcoded colours: hex, rgb()/hsl() literals. A colour inside var(…, X)
      // is a FALLBACK and allowed — the token still wins when defined.
      $bare = (string) preg_replace('/var\([^)]*\)/', '', $line);
      if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $bare, $m)) {
        $issues[] = sprintf('line %d: hardcoded colour %s — route colours through the design tokens (e.g. var(--neutral-ink)) so a rebrand reaches this rule.', $n, $m[0]);
      }
      elseif (preg_match('/\b(rgba?|hsla?|oklch|oklab)\(/', $bare, $m)) {
        $issues[] = sprintf('line %d: literal %s(…) colour — route colours through the design tokens so a rebrand reaches this rule.', $n, $m[1]);
      }
      // Fractional opacity — the muted-text anti-pattern (memory:
      // never-mute-text-with-opacity). Opacity 0/1 (show/hide, transitions)
      // passes; anything between dims content and breaks contrast math.
      if (preg_match('/\bopacity\s*:\s*(0?\.\d+|[1-9]\d?%)/', $bare, $m)) {
        $issues[] = sprintf('line %d: opacity: %s dims content — dimmed TEXT breaks WCAG AA contrast; use var(--neutral-muted-foreground) for muted copy.', $n, $m[1]);
      }
    }
    return $issues;
  }

}
