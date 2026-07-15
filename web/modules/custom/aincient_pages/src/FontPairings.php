<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * A curated library of popular display + body font pairings.
 *
 * Typography is the hardest part of a brand to get right by hand, so the
 * studio offers a short, opinionated set of well-known Google-Font pairings as
 * one-click presets — pick one and both the display and base font-family tokens
 * are staged at once. Each entry carries the two CSS font-family *stacks* (the
 * leading web font + safe system fallbacks) plus the Google families to load.
 *
 * This is NOT exhaustive: the studio still exposes raw font-family inputs, so
 * any custom font can be injected. The stacks here are pre-validated against
 * {@see TokenValue::isFontFamily} and the family names against
 * {@see BrandRepository::isFontName}, so a pairing is always a legal write.
 */
final class FontPairings {

  private const SANS_FALLBACK = 'ui-sans-serif, system-ui, sans-serif';
  private const SERIF_FALLBACK = 'Georgia, "Times New Roman", serif';

  /**
   * The pairing library, keyed by id.
   *
   * @var array<string, array{label: string, blurb: string, display: string, base: string, fonts: list<string>}>
   */
  private const PAIRINGS = [
    'inter' => [
      'label' => 'Inter',
      'blurb' => 'Clean, neutral, unmistakably modern.',
      'display' => '"Inter Tight", ' . self::SANS_FALLBACK,
      'base' => '"Inter", ' . self::SANS_FALLBACK,
      'fonts' => ['Inter', 'Inter Tight'],
    ],
    'editorial' => [
      'label' => 'Editorial',
      'blurb' => 'Playfair headlines over a warm Lora body.',
      'display' => '"Playfair Display", ' . self::SERIF_FALLBACK,
      'base' => '"Lora", ' . self::SERIF_FALLBACK,
      'fonts' => ['Playfair Display', 'Lora'],
    ],
    'geometric' => [
      'label' => 'Geometric',
      'blurb' => 'Friendly Poppins display, readable Inter text.',
      'display' => '"Poppins", ' . self::SANS_FALLBACK,
      'base' => '"Inter", ' . self::SANS_FALLBACK,
      'fonts' => ['Poppins', 'Inter'],
    ],
    'technical' => [
      'label' => 'Technical',
      'blurb' => 'Sharp Space Grotesk over a neutral body.',
      'display' => '"Space Grotesk", ' . self::SANS_FALLBACK,
      'base' => '"Inter", ' . self::SANS_FALLBACK,
      'fonts' => ['Space Grotesk', 'Inter'],
    ],
    'warm' => [
      'label' => 'Warm',
      'blurb' => 'Expressive Fraunces display over a clean Schibsted Grotesk body — Atelier\'s own pairing.',
      'display' => '"Fraunces", ' . self::SERIF_FALLBACK,
      'base' => '"Schibsted Grotesk", ' . self::SANS_FALLBACK,
      'fonts' => ['Fraunces', 'Schibsted Grotesk'],
    ],
    'corporate' => [
      'label' => 'Corporate',
      'blurb' => 'Montserrat headings, classic Merriweather body.',
      'display' => '"Montserrat", ' . self::SANS_FALLBACK,
      'base' => '"Merriweather", ' . self::SERIF_FALLBACK,
      'fonts' => ['Montserrat', 'Merriweather'],
    ],
    'superfamily' => [
      'label' => 'DM',
      'blurb' => 'Matched DM Serif Display + DM Sans superfamily.',
      'display' => '"DM Serif Display", ' . self::SERIF_FALLBACK,
      'base' => '"DM Sans", ' . self::SANS_FALLBACK,
      'fonts' => ['DM Serif Display', 'DM Sans'],
    ],
  ];

  /**
   * The picker-facing list of pairings.
   *
   * Each entry carries id + label + blurb + the two font-family stacks + the
   * web fonts it loads. The studio stages `display`/`base` onto the matching
   * tokens and previews the fonts live (persisting on Publish).
   *
   * @return list<array{id: string, label: string, blurb: string, display: string, base: string, fonts: list<string>}>
   *   One entry per pairing.
   */
  public function summaries(): array {
    $out = [];
    foreach (self::PAIRINGS as $id => $pairing) {
      $out[] = ['id' => $id] + $pairing;
    }
    return $out;
  }

}
