<?php

declare(strict_types=1);

namespace Drupal\aincient_brand;

/**
 * The branding agent's curated starting palettes ("quick brand" presets).
 *
 * Each preset is a small Tier-1 token map (the one tier where a colour is
 * chosen) plus any web fonts it needs loaded. Applying a preset is just a
 * {@see \Drupal\aincient_pages\BrandRepository::update()} call, so a preset
 * reskins every page with no rebuild and is snapshotted like any other brand
 * write. Colour values reference the immutable Tailwind base
 * (`var(--color-…)`, a legal Tier-1 → Tier-0 reference); `primary`/`accent`
 * carry a literal hex purely so the chat widget can paint a preview swatch
 * without resolving the var() chain.
 *
 * This is deliberately a SMALL, opinionated set — the in-chat picker exposes
 * only primary + accent + one of these presets, not the full token studio.
 */
final class BrandPresets {

  /**
   * The preset library, keyed by id.
   *
   * @var array<string, array{label: string, blurb: string, primary: string, accent: string, tokens: array<string, string>, fonts: list<string>}>
   */
  private const PRESETS = [
    'saas' => [
      'label' => 'SaaS',
      'blurb' => 'Cool indigo, crisp and modern.',
      'primary' => '#6366f1',
      'accent' => '#06b6d4',
      'tokens' => [
        'brand_primary' => 'var(--color-indigo-500)',
        'brand_accent' => 'var(--color-cyan-500)',
        'neutral_ink' => 'var(--color-slate-900)',
        'neutral_border' => 'var(--color-slate-200)',
        'font_family_display' => '"Inter Tight", "Inter", ui-sans-serif, system-ui, sans-serif',
      ],
      'fonts' => ['Inter', 'Inter Tight'],
    ],
    'playful' => [
      'label' => 'Playful',
      'blurb' => 'Warm fuchsia and amber, high energy.',
      'primary' => '#d946ef',
      'accent' => '#fbbf24',
      'tokens' => [
        'brand_primary' => 'var(--color-fuchsia-500)',
        'brand_accent' => 'var(--color-amber-400)',
        'neutral_ink' => 'var(--color-zinc-900)',
        'font_family_base' => '"Poppins", ui-sans-serif, system-ui, sans-serif',
        'font_family_display' => '"Poppins", ui-sans-serif, system-ui, sans-serif',
      ],
      'fonts' => ['Poppins'],
    ],
    'editorial' => [
      'label' => 'Editorial',
      'blurb' => 'Inky serif headlines, a single deep accent.',
      'primary' => '#292524',
      'accent' => '#b91c1c',
      'tokens' => [
        'brand_primary' => 'var(--color-stone-800)',
        'brand_accent' => 'var(--color-red-700)',
        'neutral_ink' => 'var(--color-stone-900)',
        'neutral_surface' => 'var(--color-stone-50)',
        'font_family_display' => '"Playfair Display", Georgia, serif',
      ],
      'fonts' => ['Playfair Display'],
    ],
  ];

  /**
   * Whether a preset id is known.
   */
  public function has(string $id): bool {
    return isset(self::PRESETS[$id]);
  }

  /**
   * A single preset definition, or NULL when the id is unknown.
   *
   * @return array{label: string, blurb: string, primary: string, accent: string, tokens: array<string, string>, fonts: list<string>}|null
   *   The preset definition, or NULL.
   */
  public function get(string $id): ?array {
    return self::PRESETS[$id] ?? NULL;
  }

  /**
   * The picker-facing list: id + label + blurb + preview swatches, plus the
   * full token map and web fonts so the picker can stage a whole preset as a
   * studio draft (preview now, persist on Publish).
   *
   * @return list<array{id: string, label: string, blurb: string, primary: string, accent: string, tokens: array<string, string>, fonts: list<string>}>
   *   One entry per preset.
   */
  public function summaries(): array {
    $out = [];
    foreach (self::PRESETS as $id => $preset) {
      $out[] = [
        'id' => $id,
        'label' => $preset['label'],
        'blurb' => $preset['blurb'],
        'primary' => $preset['primary'],
        'accent' => $preset['accent'],
        'tokens' => $preset['tokens'] ?? [],
        'fonts' => $preset['fonts'] ?? [],
      ];
    }
    return $out;
  }

}
