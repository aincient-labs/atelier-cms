<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Renders a design-token value into something a MODEL can reason about — the
 * one grounding layer every agent-facing echo of brand state goes through.
 *
 * Every brand specialist's system prompt makes the same promise:
 *
 *   RELATIVE CHANGES ("darker", "lighter", "bigger", "heavier", "one step up")
 *   are always relative to the CURRENT LOOK values below
 *
 * That promise is only kept if the value we show is a value. Two things break
 * it, and this class fixes both:
 *
 * 1. A `var()` REFERENCE carries no number. Echoed bare, a relative request has
 *    nothing to subtract from and the model anchors on whatever concrete value
 *    it can find elsewhere in the prompt — which is how "make primary darker"
 *    over a yellow draft returned a darker BROWN: the only concrete primary in
 *    the prompt was the saved palette (DECISIONS 0408).
 * 2. A LITERAL can still be unreadable. `oklch(0.98 0.01 0)` reads as "white" to
 *    a model and renders `#FFF6F8` — pink. `0.75rem` reads as a fraction and
 *    renders 12px.
 *
 * So: follow the reference, then annotate with what the type actually needs.
 * Add nothing rather than guess — an unresolvable value is echoed as-is, which
 * is honest, where a fabricated one is not.
 */
final class TokenGrounding {

  /** Root font size assumed when expressing a rem length in px. */
  private const ROOT_PX = 16.0;

  /** Longest resolved value worth pasting into a prompt. */
  private const MAX_ECHO = 120;

  public function __construct(
    private readonly DesignTokens $tokens,
    private readonly TokenResolver $resolver,
    private readonly ColorContrast $contrast,
    private readonly BrandRepository $brand,
  ) {}

  /**
   * The live resolution stack: registry defaults → SAVED brand → the caller's
   * overrides (a studio draft), each layer winning over the one before.
   *
   * The saved layer is the one a caller forgets, and forgetting it is silent:
   * a draft that sets `card_radius: var(--radius-sm)` resolves `radius_sm` to
   * the registry's 0.25rem unless the site's own saved value (0px on a square
   * brand) is layered underneath — so the same reference reads 4px on the draft
   * line and 0px in the saved brief, in ONE prompt. Owning the stack here means
   * no caller can assemble it wrong.
   *
   * @param array<string, string> $overrides
   *   The caller's overrides, keyed by token name or css_var.
   *
   * @return array<string, string>
   *   Token name => value.
   */
  private function stack(array $overrides): array {
    return $this->resolver->byName($overrides) + $this->resolver->byName($this->brand->tokens());
  }

  /**
   * The parenthesised suffix to append when echoing a token value into a
   * prompt — '' when there is nothing useful to add.
   *
   * Examples:
   *   brand-primary = var(--color-yellow-100) (= oklch(97.3% 0.071 103.193) ≈ #FEF9C2)
   *   brand-primary = oklch(0.55 0.12 40) (≈ #AB5637)
   *   card-radius   = var(--radius-2xl) (= 1rem ≈ 16px)
   *   font-display  = var(--font-family-display), "Noto Emoji" (= Fraunces)
   *
   * @param string $token
   *   The token's name or css_var — either shape is accepted, because the
   *   studio draft is keyed by css_var and the saved brand by name.
   * @param string $value
   *   The value being echoed.
   * @param array<string, string> $overrides
   *   Token overrides to resolve a reference against (a studio draft or the
   *   saved brand), keyed by token name OR css_var.
   */
  public function echoFor(string $token, string $value, array $overrides = []): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    $type = $this->typeOf($token);
    // Colour has its own renderer: it needs the sRGB maths, and it is the only
    // type whose annotation (hex) is worth showing for a literal too.
    if ($type === 'color') {
      $stack = $this->stack($overrides);
      $ramp = $this->rampFor($value, $stack);
      return $this->contrast->colorEcho($value, $stack) . ($ramp === '' ? '' : ' [' . $ramp . ']');
    }

    $isRef = $this->resolver->isReference($value);
    $resolved = $this->resolver->resolve($value, $this->stack($overrides));
    if ($resolved === NULL) {
      return '';
    }

    $parts = [];
    // Only worth restating the literal when the reference hid it AND the
    // literal is more legible than the reference was. A composed rung like
    // `shadow_lg` resolves to a nest of calc()/color-mix() over five other
    // tokens — pasting that in costs tokens and tells the model less than the
    // rung's own name already did, so leave it named.
    if ($isRef && $resolved !== $value && $this->isLegible($resolved)) {
      $parts[] = '= ' . $this->readable($type, $resolved);
    }
    $extra = $this->annotate($type, $resolved);
    if ($extra !== '' && $extra !== ($parts[0] ?? '')) {
      $parts[] = $extra;
    }
    return $parts === [] ? '' : ' (' . implode(' ', $parts) . ')';
  }

  /**
   * The Tier-0 ramp a colour value sits on, with the current rung marked — or
   * '' when the value is not anchored to a Tailwind swatch.
   *
   *   ramp yellow: 50 #FEFCE8 · 100 #FEF9C2 ◀ · 200 #FEF08A · … · 950 #422006
   *
   * A swatch is a TOKEN, and until this the agent only ever read it as one: the
   * echo above gave it a number to subtract from, so it subtracted and wrote a
   * literal back — the swatch identity gone on the first touch, the studio's
   * swatch grid unlit, the imagery brief left with an oklch triple instead of
   * "yellow 500" (cms #44). Showing the ramp gives a relative ask a discrete
   * neighbour to step TO, so the specialist can write `var(--color-yellow-500)`
   * instead of computing one. It also settles the hue question by
   * construction: Tailwind's yellow swings toward amber as it darkens, and that
   * swing IS the designed darker yellow — a rule that "holds the hue" from a
   * tint produces an olive no rung on the ramp would.
   *
   * @param string $value
   *   The value being echoed (a literal, a swatch, or a reference to one).
   * @param array<string, string> $stack
   *   The resolution stack, by token name.
   */
  public function rampFor(string $value, array $stack = []): string {
    $anchor = $this->resolver->resolveInRegistry(trim($value), $stack);
    if (preg_match('/^var\(--(color-([a-z]+)-(\d+))\)$/i', $anchor, $m) !== 1) {
      return '';
    }
    [, $current, $hue] = $m;
    foreach ($this->tokens->palette() as $group) {
      if (($group['hue'] ?? '') !== $hue) {
        continue;
      }
      $rungs = [];
      foreach ($group['swatches'] ?? [] as $s) {
        $hex = $this->contrast->hexApproximation((string) ($s['value'] ?? ''));
        // A hex value has nothing to approximate; use it as it is.
        $hex ??= (str_starts_with((string) ($s['value'] ?? ''), '#') ? strtoupper((string) $s['value']) : NULL);
        if ($hex === NULL) {
          continue;
        }
        $rungs[] = $s['step'] . ' ' . $hex . (($s['css_var'] ?? '') === $current ? ' ◀' : '');
      }
      return $rungs === [] ? '' : 'ramp ' . $hue . ': ' . implode(' · ', $rungs);
    }
    return '';
  }

  /**
   * A token rendered whole for a BRIEF — the value plus its grounding.
   *
   * Differs from {@see echoFor} in what it does with a font stack: a brief
   * wants the lead family (`Lora`), not the four-deep fallback chain, which is
   * unreadable and whose commas collide with the brief's own separators. A
   * per-token draft line, by contrast, echoes the stored value verbatim because
   * that is literally what the draft holds.
   *
   * @param string $token
   *   The token's name or css_var.
   * @param string $value
   *   The value being rendered.
   * @param array<string, string> $overrides
   *   Token overrides to resolve a reference against.
   */
  public function describe(string $token, string $value, array $overrides = []): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }
    if ($this->typeOf($token) === 'font-family') {
      $resolved = $this->resolver->resolve($value, $this->stack($overrides)) ?? $value;
      return $this->readable('font-family', $resolved);
    }
    return $value . $this->echoFor($token, $value, $overrides);
  }

  /**
   * The css_var for a token name — the shape a studio draft is keyed by.
   *
   * The registry is the authority (a token may declare its own `css_var`), so
   * callers matching draft keys against token names go through this rather than
   * assuming underscore → hyphen.
   */
  public function cssVarFor(string $name): string {
    return $this->tokens->cssVar($name);
  }

  /**
   * The declared type of a token, given its name or css_var ('' if unknown).
   */
  public function typeOf(string $token): string {
    $def = $this->tokens->get($token);
    if ($def === NULL) {
      $byCssVar = $this->resolver->cssVarToName();
      $def = isset($byCssVar[$token]) ? $this->tokens->get($byCssVar[$token]) : NULL;
    }
    return (string) ($def['type'] ?? '');
  }

  /**
   * Whether a resolved value is worth showing: a concrete value, not a formula.
   *
   * A value still carrying calc(), color-mix() or an unresolved var() is a
   * recipe, not a number — a model cannot step "one rung darker" from it any
   * better than from the reference it came from.
   */
  private function isLegible(string $resolved): bool {
    if (mb_strlen($resolved) > self::MAX_ECHO) {
      return FALSE;
    }
    foreach (['calc(', 'color-mix(', 'var('] as $formula) {
      if (stripos($resolved, $formula) !== FALSE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The resolved value as the model should read it: a font stack collapses to
   * its lead family (the rest is fallback plumbing, and the always-on
   * "Noto Emoji" tail carries no brand signal); everything else passes through.
   */
  private function readable(string $type, string $resolved): string {
    if ($type !== 'font-family') {
      return $resolved;
    }
    $lead = trim(explode(',', $resolved)[0]);
    $lead = trim($lead, "\"'");
    return $lead !== '' ? $lead : $resolved;
  }

  /**
   * The type-specific annotation for an already-resolved literal, or ''.
   *
   * Only lengths earn one: a rem is the unit our scale is authored in and the
   * unit a model reasons about worst. Numbers, enums and shadows are already
   * concrete and self-describing once the reference is followed.
   */
  private function annotate(string $type, string $resolved): string {
    if ($type !== 'length') {
      return '';
    }
    if (preg_match('/^(-?\d*\.?\d+)rem$/i', $resolved, $m) !== 1) {
      return '';
    }
    $px = (float) $m[1] * self::ROOT_PX;
    $formatted = rtrim(rtrim(number_format($px, 2, '.', ''), '0'), '.');
    return '≈ ' . $formatted . 'px';
  }

}
