<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Named, opinionated PRESET groups — the high-level vocabulary the brand studio
 * rail leads with and the brand agent picks from.
 *
 * A preset is a one-click bundle that expands to a coherent set of design-token
 * overrides: "Soft" corners, "Lifted" shadows, the "Editorial" font pairing. It
 * is the layer ABOVE raw tokens — fewer, safer degrees of freedom that always
 * produce an internally-consistent result (a graded radius scale, a matched
 * font pair) where hand-setting each token could drift.
 *
 * ONE source of truth, two consumers: the brand manifest serialises
 * {@see groups()} so the React rail can render preset chips and apply them
 * through the same override store; {@see expand()} lets the `preview_brand`
 * agent tool turn a {group: option} choice into that same token map. The token
 * VALUES here are all legal writes (validated against {@see DesignTokens} like
 * any other token), so a preset can never smuggle in an illegal value.
 *
 * The typography PAIRING group is sourced from {@see FontPairings} so the
 * curated pairings stay defined in one place; the scale groups
 * (roundness/direction/density/text_size/heading_weight) are defined inline.
 * The shadow is NOT a preset: it is dialled directly via its decoupled AXES
 * (distance / blur / strength / colour), with `direction` the one enum that maps
 * a named compass point to the two internal offset multipliers the derived rungs
 * consume — there is no monolithic "shadow style" bundle to relapse into. Colour
 * "quick brand" starting points remain the agent-layer
 * {@see \Drupal\aincient_brand\BrandPresets} (a different concept: a whole
 * palette to start FROM, not a single-axis dial).
 *
 * Each scale group ships one option whose token values equal the registry
 * DEFAULTS, so a freshly-installed brand reads as that option rather than
 * "Custom": roundness=pill, direction=bottom, density=comfortable,
 * text_size=default, heading_weight=regular. If a default in design-tokens.yml
 * changes, move the matching option here so the two stay in lockstep.
 */
final class PresetCatalog {

  /**
   * The synthetic group id for the typography font pairing (sourced from
   * FontPairings rather than the inline SCALE_GROUPS below).
   */
  public const PAIRING_GROUP = 'pairing';

  public function __construct(private readonly FontPairings $fontPairings) {}

  /**
   * The scale-preset groups (everything bar the font pairing). Shape per group:
   * label + description + an `options` map of id => {label, blurb, tokens}.
   *
   * Each option's `tokens` map is keyed by token NAME (not css_var); values are
   * legal CSS for that token's type (a raw scalar, or a var() reference to a
   * lower tier). Roundness/depth write the raw Tier-1 scales AND the two
   * component knobs (button/tabs) that escape the scale by pointing at
   * radius_full — so the dial is coherent everywhere, not just on cards.
   */
  private const SCALE_GROUPS = [
    'roundness' => [
      'label' => 'Corner roundness',
      'description' => 'How rounded corners are across the whole site.',
      'options' => [
        'sharp' => [
          'label' => 'Sharp',
          'blurb' => 'Square corners, zero radius.',
          'tokens' => [
            'radius_sm' => '0', 'radius_md' => '0', 'radius_lg' => '0',
            'radius_xl' => '0', 'radius_2xl' => '0',
            'button_radius' => 'var(--radius-none)', 'tabs_radius' => 'var(--radius-none)',
          ],
        ],
        'soft' => [
          'label' => 'Soft',
          'blurb' => 'Gently rounded rectangles.',
          'tokens' => [
            'radius_sm' => '0.25rem', 'radius_md' => '0.375rem', 'radius_lg' => '0.5rem',
            'radius_xl' => '0.75rem', 'radius_2xl' => '1rem',
            'button_radius' => 'var(--radius-md)', 'tabs_radius' => 'var(--radius-md)',
          ],
        ],
        'rounded' => [
          'label' => 'Rounded',
          'blurb' => 'Generous, friendly curves.',
          'tokens' => [
            'radius_sm' => '0.375rem', 'radius_md' => '0.5rem', 'radius_lg' => '0.75rem',
            'radius_xl' => '1rem', 'radius_2xl' => '1.5rem',
            'button_radius' => 'var(--radius-xl)', 'tabs_radius' => 'var(--radius-xl)',
          ],
        ],
        'pill' => [
          'label' => 'Pill',
          'blurb' => 'Fully-round buttons and tabs.',
          'tokens' => [
            'radius_sm' => '0.25rem', 'radius_md' => '0.375rem', 'radius_lg' => '0.5rem',
            'radius_xl' => '0.75rem', 'radius_2xl' => '1rem',
            'button_radius' => 'var(--radius-full)', 'tabs_radius' => 'var(--radius-full)',
          ],
        ],
      ],
    ],
    // Shadow DIRECTION: a named 9-way enum (the agent never computes an angle) that
    // expands to the two unitless offset multipliers the rung calc() consumes.
    // `bottom` (light from above) equals the registry default.
    'direction' => [
      'label' => 'Shadow direction',
      'description' => 'Where the shadow falls (opposite the light). Use center for a glow/halo.',
      'options' => [
        'top_left'     => ['label' => 'Top-left',     'blurb' => 'Light from the lower-right.', 'tokens' => ['shadow_dir_x' => '-0.71', 'shadow_dir_y' => '-0.71']],
        'top'          => ['label' => 'Top',          'blurb' => 'Light from below.',           'tokens' => ['shadow_dir_x' => '0',     'shadow_dir_y' => '-1']],
        'top_right'    => ['label' => 'Top-right',    'blurb' => 'Light from the lower-left.',  'tokens' => ['shadow_dir_x' => '0.71',  'shadow_dir_y' => '-0.71']],
        'left'         => ['label' => 'Left',         'blurb' => 'Light from the right.',       'tokens' => ['shadow_dir_x' => '-1',    'shadow_dir_y' => '0']],
        'center'       => ['label' => 'Center',       'blurb' => 'No offset — a halo/glow.',    'tokens' => ['shadow_dir_x' => '0',     'shadow_dir_y' => '0']],
        'right'        => ['label' => 'Right',        'blurb' => 'Light from the left.',        'tokens' => ['shadow_dir_x' => '1',     'shadow_dir_y' => '0']],
        'bottom_left'  => ['label' => 'Bottom-left',  'blurb' => 'Light from the upper-right.', 'tokens' => ['shadow_dir_x' => '-0.71', 'shadow_dir_y' => '0.71']],
        'bottom'       => ['label' => 'Bottom',       'blurb' => 'Light from above (default).', 'tokens' => ['shadow_dir_x' => '0',     'shadow_dir_y' => '1']],
        'bottom_right' => ['label' => 'Bottom-right', 'blurb' => 'Light from the upper-left.',  'tokens' => ['shadow_dir_x' => '0.71',  'shadow_dir_y' => '0.71']],
      ],
    ],
    'density' => [
      'label' => 'Spacing density',
      'description' => 'How tight or roomy component padding feels.',
      'options' => [
        'tight' => ['label' => 'Tight', 'blurb' => 'Compact padding.', 'tokens' => ['density' => '0.85']],
        'comfortable' => ['label' => 'Comfortable', 'blurb' => 'Balanced spacing.', 'tokens' => ['density' => '1']],
        'roomy' => ['label' => 'Roomy', 'blurb' => 'Generous breathing room.', 'tokens' => ['density' => '1.15']],
      ],
    ],
    'text_size' => [
      'label' => 'Body text size',
      'description' => 'The base reading size for body copy.',
      'options' => [
        'compact' => ['label' => 'Compact', 'blurb' => 'Smaller body text.', 'tokens' => ['body_size' => 'var(--size-sm)']],
        'default' => ['label' => 'Default', 'blurb' => 'Standard reading size.', 'tokens' => ['body_size' => 'var(--size-base)']],
        'large' => ['label' => 'Large', 'blurb' => 'Bigger, more legible text.', 'tokens' => ['body_size' => 'var(--size-lg)']],
      ],
    ],
    'heading_weight' => [
      'label' => 'Heading weight',
      'description' => 'How heavy headings and display type are.',
      'options' => [
        'regular' => [
          'label' => 'Regular', 'blurb' => 'Light, understated headings.',
          'tokens' => ['heading_weight' => 'var(--weight-medium)', 'display_weight' => 'var(--weight-semibold)', 'subheading_weight' => 'var(--weight-medium)'],
        ],
        'medium' => [
          'label' => 'Medium', 'blurb' => 'Moderately emphasised headings.',
          'tokens' => ['heading_weight' => 'var(--weight-semibold)', 'display_weight' => 'var(--weight-bold)', 'subheading_weight' => 'var(--weight-medium)'],
        ],
        'bold' => [
          'label' => 'Bold', 'blurb' => 'Strong, confident headings.',
          'tokens' => ['heading_weight' => 'var(--weight-bold)', 'display_weight' => 'var(--weight-extrabold)', 'subheading_weight' => 'var(--weight-semibold)'],
        ],
        'heavy' => [
          'label' => 'Heavy', 'blurb' => 'Maximum-impact headings.',
          'tokens' => ['heading_weight' => 'var(--weight-extrabold)', 'display_weight' => 'var(--weight-extrabold)', 'subheading_weight' => 'var(--weight-bold)'],
        ],
      ],
    ],
  ];

  /**
   * Every preset group for the manifest — the pairing group (from FontPairings)
   * first, then the scale groups. Each entry:
   *   {id, label, description, options: [{id, label, blurb, tokens, fonts}]}.
   *
   * `tokens` is keyed by token name; `fonts` lists the web families the option
   * stages (only the pairing group loads fonts — scale options carry []).
   *
   * @return list<array{id: string, label: string, description: string, options: list<array{id: string, label: string, blurb: string, tokens: array<string, string>, fonts: list<string>}>}>
   */
  public function groups(): array {
    $pairingOptions = [];
    foreach ($this->fontPairings->summaries() as $p) {
      $expanded = $this->expandPairing($p['id']);
      $pairingOptions[] = [
        'id' => $p['id'],
        'label' => $p['label'],
        'blurb' => $p['blurb'],
        'tokens' => $expanded['tokens'],
        'fonts' => $expanded['fonts'],
      ];
    }
    $groups = [[
      'id' => self::PAIRING_GROUP,
      'label' => 'Font pairing',
      'description' => 'A curated display + body typeface pairing.',
      'options' => $pairingOptions,
    ]];

    foreach (self::SCALE_GROUPS as $id => $group) {
      $options = [];
      foreach ($group['options'] as $oid => $opt) {
        $options[] = [
          'id' => $oid,
          'label' => $opt['label'],
          'blurb' => $opt['blurb'],
          'tokens' => $opt['tokens'],
          'fonts' => [],
        ];
      }
      $groups[] = [
        'id' => $id,
        'label' => $group['label'],
        'description' => $group['description'],
        'options' => $options,
      ];
    }
    return $groups;
  }

  /** Whether a group + option id pair is known. */
  public function has(string $group, string $option): bool {
    return $this->expand($group, $option) !== NULL;
  }

  /**
   * Expand one {group: option} choice to its token map + web fonts, or NULL
   * when the group or option id is unknown.
   *
   * @return array{tokens: array<string, string>, fonts: list<string>}|null
   */
  public function expand(string $group, string $option): ?array {
    if ($group === self::PAIRING_GROUP) {
      return $this->expandPairing($option);
    }
    $opt = self::SCALE_GROUPS[$group]['options'][$option] ?? NULL;
    return $opt === NULL ? NULL : ['tokens' => $opt['tokens'], 'fonts' => []];
  }

  /**
   * Expand a font-pairing id to the two font-family token writes + its web
   * fonts, or NULL when the pairing is unknown.
   *
   * @return array{tokens: array<string, string>, fonts: list<string>}|null
   */
  private function expandPairing(string $id): ?array {
    foreach ($this->fontPairings->summaries() as $p) {
      if ($p['id'] === $id) {
        return [
          'tokens' => [
            'font_family_display' => $p['display'],
            'font_family_base' => $p['base'],
          ],
          'fonts' => $p['fonts'],
        ];
      }
    }
    return NULL;
  }

}
