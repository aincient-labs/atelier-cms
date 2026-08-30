<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_pages\Kernel;

use Drupal\aincient_pages\TokenGrounding;
use Drupal\aincient_pages\TokenResolver;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shared token resolution + model-facing grounding layer.
 *
 * Roughly half the registry's tokens hold a `var()` reference; a reference is
 * not a value a model can step from. Echoed bare into a specialist's prompt,
 * "make primary darker" over a yellow draft returned a darker BROWN, because
 * the only concrete primary in the prompt was the saved palette
 * (DECISIONS 0408).
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class TokenGroundingTest extends KernelTestBase {

  protected static $modules = ['system', 'workflows', 'content_moderation', 'aincient_core', 'aincient_pages'];

  private function resolver(): TokenResolver {
    return $this->container->get('aincient_pages.token_resolver');
  }

  private function grounding(): TokenGrounding {
    return $this->container->get('aincient_pages.token_grounding');
  }

  /**
   * The resolver follows both reference tiers: registry, then Tier-0 Tailwind.
   */
  public function testResolveFollowsBothTiers(): void {
    $r = $this->resolver();

    // Tier 0 — a Tailwind swatch.
    $this->assertSame('oklch(97.3% 0.071 103.193)', $r->resolve('var(--color-yellow-100)'));
    // Registry — a multi-hop chain (card_radius → radius_2xl → a literal).
    $this->assertSame('1rem', $r->resolve('var(--radius-2xl)'));
    $this->assertSame('1rem', $r->resolve('var(--card-radius)'));
    // A literal passes through; an unknown reference is not guessed at.
    $this->assertSame('12px', $r->resolve('12px'));
    $this->assertNull($r->resolve('var(--no-such-token)'));
    $this->assertNull($r->resolve(''));
  }

  /**
   * A stack that merely LEADS with a reference keeps its fallback tail.
   */
  public function testResolvePreservesAFontStackTail(): void {
    $resolved = $this->resolver()->resolve('var(--font-family-display), "Noto Emoji"');
    $this->assertNotNull($resolved);
    $this->assertStringContainsString('Fraunces', $resolved);
    $this->assertStringEndsWith('"Noto Emoji"', $resolved);
  }

  /**
   * Overrides are accepted keyed by token name OR css_var.
   *
   * The studio draft rides the wire keyed by css_var, the saved brand by token
   * name; passing the wrong shape used to resolve silently against the defaults
   * instead of the draft.
   */
  public function testResolveAcceptsEitherOverrideKeying(): void {
    $r = $this->resolver();
    $this->assertSame('4px', $r->resolve('var(--radius-2xl)', ['radius-2xl' => '4px']));
    $this->assertSame('4px', $r->resolve('var(--radius-2xl)', ['radius_2xl' => '4px']));
    // …and the chain above it re-tracks the override.
    $this->assertSame('4px', $r->resolve('var(--card-radius)', ['radius_2xl' => '4px']));
  }

  /**
   * Grounding annotates each type with what that type actually needs.
   */
  public function testEchoGroundsEachTokenType(): void {
    $g = $this->grounding();

    // colour — the reference AND the hex, because "darker" needs the numbers;
    // a swatch also carries its ramp (see testEchoPrintsTheRampForASwatch).
    $this->assertStringStartsWith(
      ' (= oklch(97.3% 0.071 103.193) ≈ #FEF9C2) [ramp yellow: ',
      $g->echoFor('brand_primary', 'var(--color-yellow-100)'),
    );
    // length — the literal plus px, because a model reads rem badly.
    $this->assertSame(' (= 1rem ≈ 16px)', $g->echoFor('card_radius', 'var(--radius-2xl)'));
    $this->assertSame(' (≈ 18px)', $g->echoFor('body_size', '1.125rem'));
    // number / enum — the literal is enough.
    $this->assertSame(' (= 600)', $g->echoFor('display_weight', 'var(--weight-semibold)'));
    // font-family — the lead family; the fallback chain is plumbing.
    $this->assertSame(' (= Fraunces)', $g->echoFor('font_display', 'var(--font-family-display), "Noto Emoji"'));
  }

  /**
   * A composed rung is left NAMED rather than expanded.
   *
   * `shadow_lg` resolves to a nest of calc()/color-mix() over five other
   * tokens. Pasting that in costs tokens and tells the model less than the
   * rung's own name already did.
   */
  public function testEchoLeavesFormulaValuesNamed(): void {
    $g = $this->grounding();
    $this->assertSame('', $g->echoFor('card_shadow', 'var(--shadow-lg)'));
    // Nothing to add for an empty value, or for a literal of a type that earns
    // no annotation.
    $this->assertSame('', $g->echoFor('card_radius', ''));
    $this->assertSame('', $g->echoFor('border_width', '1px'));
    // An unregistered key still gets the reference followed — the caller loses
    // only the type-specific annotation (no px here), not the value.
    $this->assertSame(' (= 1rem)', $g->echoFor('no_such_token', 'var(--radius-2xl)'));
  }

  /**
   * Grounding resolves against a DRAFT, not the saved brand.
   *
   * This is the bug itself: the value on screen must win.
   */
  public function testEchoResolvesAgainstTheOpenDraft(): void {
    $draft = ['brand-primary' => 'var(--color-yellow-100)', 'card-radius' => '2px'];
    $g = $this->grounding();

    $this->assertStringContainsString('#FEF9C2', $g->echoFor('primary', 'var(--brand-primary)', $draft));
    $this->assertSame(' (= 2px)', $g->echoFor('card_radius', 'var(--card-radius)', $draft));
  }

  /**
   * Resolution layers the SAVED brand under the draft, not just the defaults.
   *
   * The saved layer is the one a caller forgets, and forgetting it is silent: a
   * draft setting `card_radius: var(--radius-sm)` resolved `radius_sm` to the
   * registry's 0.25rem, so the same reference read 4px on the draft line and
   * 0px in the saved brief — in ONE prompt, on a brand whose scale is
   * deliberately flat. Live-caught on a "make the corners rounder" turn.
   */
  public function testEchoLayersTheSavedBrandUnderTheDraft(): void {
    $this->config('aincient_pages.brand')
      ->set('tokens', ['radius_sm' => '0px'])
      ->save();

    $g = $this->grounding();
    $draft = ['card-radius' => 'var(--radius-sm)'];

    // The site's saved 0px, not the registry's 0.25rem.
    $this->assertSame(' (= 0px)', $g->echoFor('card_radius', 'var(--radius-sm)', $draft));
    // …and with no draft at all, the saved value still wins over the default.
    $this->assertSame(' (= 0px)', $g->echoFor('card_radius', 'var(--radius-sm)'));
    // The draft still wins over the saved value where it sets one.
    $this->assertSame(
      ' (= 3px)',
      $g->echoFor('card_radius', 'var(--radius-sm)', ['radius-sm' => '3px']),
    );
  }

  /**
   * A swatch-anchored colour echoes its whole Tailwind ramp, current rung marked.
   *
   * A swatch is a token the agent only ever READ as one: grounded to a number,
   * it subtracted and wrote a literal back, and the swatch identity was gone
   * on the first touch (cms #44). The ramp gives "darker" a discrete rung to
   * step TO — and one it can write back as var(--color-yellow-500). A literal
   * colour sits on no ramp and gets none.
   */
  public function testEchoPrintsTheRampForASwatch(): void {
    $g = $this->grounding();

    $echo = $g->echoFor('brand_primary', 'var(--color-yellow-100)');
    $this->assertStringContainsString('[ramp yellow: 50 #FEFCE8 · 100 #FEF9C2 ◀ · 200 ', $echo, 'The ramp is printed, the current rung marked.');
    $this->assertStringContainsString(' · 950 #', $echo, 'The whole ramp, down to 950.');
    $this->assertSame(1, substr_count($echo, '◀'), 'Exactly one rung is current.');

    // Through a registry reference resolving to a swatch in the DRAFT.
    $draft = ['brand-primary' => 'var(--color-blue-600)'];
    $this->assertStringContainsString('[ramp blue: ', $g->echoFor('primary', 'var(--brand-primary)', $draft));
    $this->assertStringContainsString('600 #155DFC ◀', $g->echoFor('primary', 'var(--brand-primary)', $draft));

    // A literal is on no ramp; a non-colour token never gets one.
    $this->assertStringNotContainsString('ramp', $g->echoFor('brand_primary', 'oklch(0.55 0.12 40)'));
    $this->assertStringNotContainsString('ramp', $g->echoFor('card_radius', 'var(--radius-2xl)'));
    $this->assertSame('', $g->rampFor('#B94430'));
  }

  /**
   * describe() collapses a font stack for a brief; echoFor() does not.
   *
   * A brief wants the lead family — the four-deep fallback chain is unreadable
   * and its commas collide with the brief's own separators. A per-token draft
   * line echoes the stored value verbatim, because that is what the draft holds.
   */
  public function testDescribeCollapsesFontStacksForBriefs(): void {
    $g = $this->grounding();
    $stack = '"Lora", Georgia, "Times New Roman", serif';
    $this->assertSame('Lora', $g->describe('font_family_base', $stack));
    $this->assertSame('1rem (≈ 16px)', $g->describe('radius_2xl', '1rem'));
  }

}
