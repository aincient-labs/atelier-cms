<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\aincient_pages\ComponentCatalog;
use Drupal\aincient_pages\Entity\PageKindInterface;

/**
 * Pure compile step: SDC definitions ∩ site-constraint ∩ kind → an
 * {@see EffectiveCatalog}.
 *
 * Deliberately container-free (static, no services) so the grammar lints run
 * as plain unit tests over parsed YAML and so W4's `drush atelier:pack-validate`
 * can reuse the exact same admission logic. The catalog SERVICE
 * ({@see AtelierComponentCatalog}) owns discovery + caching and delegates here.
 *
 * Discovery is a source BEHIND the interface, never the interface (study steal
 * S2): a def needs no backing file — {@see VIRTUAL_REFERENCE} carries `block`,
 * whose render is the referenced global block's own sections expanded inline.
 *
 * NEVER FATAL (ratified decision #4): a kind or constraint naming an unknown
 * component/variant/tone degrades — the entry is skipped and a warning is
 * collected on the catalog — so a config typo cannot 500 the studio or blank
 * the agent manifest.
 */
final class CatalogCompiler {

  /**
   * Placeable tiers — defs the agent may place as top-level blocks.
   */
  private const PLACEABLE_TIERS = ['section', 'layout', 'reference'];

  /**
   * Reference placeables with no SDC of their own. `block` is expanded by the
   * renderer into the referenced global block's sections; there is nothing to
   * discover, so its def lives here (provider '' = virtual).
   */
  private const VIRTUAL_REFERENCE = [
    'block' => [
      'order' => 20,
      'icon' => '▣',
      'use' => 'Place a reusable GLOBAL BLOCK — a saved fragment (a shared CTA, banner, or footer note). Edit the block once and every page that uses it updates. Reach for it when the SAME content must appear on many pages.',
      'props' => [
        'ref' => '',
      ],
    ],
  ];

  /**
   * Compile the effective palette for one kind.
   *
   * @param array<string, array> $definitions
   *   SDC plugin definitions (or unit-test stand-ins: parsed .component.yml
   *   arrays that carry 'provider' + 'machineName' + 'thirdPartySettings').
   * @param \Drupal\aincient_pages\Entity\PageKindInterface|null $kind
   *   The kind entity, or NULL for the bare discovered palette (an unknown
   *   kind id degrades to landing semantics — never fatal).
   * @param array $constraint
   *   The aincient_pages.site_constraint payload:
   *   {components?: string[], tones?: string[], variants?: {name: string[]}} —
   *   each list REMOVES site-wide (narrowing-only; empty = no-op).
   * @param string $kindId
   *   The kind id to stamp on the catalog (used when $kind is NULL).
   * @param array $fallback
   *   Kind semantics when $kind is NULL: {mode?, collection_source?, hint?} —
   *   the service's code floor for the built-in regimes.
   */
  public static function compile(array $definitions, ?PageKindInterface $kind, array $constraint = [], string $kindId = 'landing', array $fallback = []): EffectiveCatalog {
    $warnings = [];

    // 1. Discover: every definition carrying the atelier contract, by tier —
    // AFTER the admission gate (§3.3, the hard floor): a rejected def is
    // EXCLUDED and surfaced as a warning, never fatal (a bad pack must not
    // take a client site down).
    $verdicts = AdmissionGate::check($definitions, array_keys(self::VIRTUAL_REFERENCE));
    $tiers = ['section' => [], 'layout' => [], 'reference' => [], 'chrome' => [], 'content' => []];
    foreach ($definitions as $definition) {
      $atelier = $definition['thirdPartySettings']['atelier'] ?? NULL;
      if (!is_array($atelier)) {
        continue;
      }
      $name = (string) ($definition['machineName'] ?? '');
      $tier = (string) ($atelier['tier'] ?? '');
      if ($name === '' || !isset($tiers[$tier])) {
        continue;
      }
      if (($verdicts[$name]['errors'] ?? []) !== []) {
        $warnings[] = sprintf('component "%s:%s" rejected by the admission gate: %s', (string) ($definition['provider'] ?? ''), $name, implode(' ', $verdicts[$name]['errors']));
        continue;
      }
      $tiers[$tier][$name] = [
        'provider' => (string) ($definition['provider'] ?? ''),
        'tier' => $tier,
        'order' => (int) ($atelier['order'] ?? 0),
        'icon' => (string) ($atelier['icon'] ?? ''),
        'use' => (string) ($atelier['use'] ?? ''),
        'props' => is_array($atelier['props'] ?? NULL) ? $atelier['props'] : [],
        // Renderer maps (W3): per-image-prop tuning
        // {style?: core image style, view_mode?: rift view mode, container_query?: bool}
        // and per-repeatable row pictures {rows-prop: {image, view_mode}}.
        'image_props' => is_array($atelier['image_props'] ?? NULL) ? $atelier['image_props'] : [],
        'rows' => is_array($atelier['rows'] ?? NULL) ? $atelier['rows'] : [],
        // W5: a pack component's pre-compiled stylesheet, module-relative.
        'stylesheet' => (string) ($atelier['stylesheet'] ?? ''),
        // Phase 5: declared render fixtures double as the agent's few-shot
        // fragments — inlined into the manifest for PACK components only
        // (a client's names carry no model prior; ours do).
        'examples' => is_array($atelier['examples'] ?? NULL) ? $atelier['examples'] : [],
      ];
    }
    foreach (self::VIRTUAL_REFERENCE as $name => $def) {
      $tiers['reference'][$name] ??= $def + ['provider' => '', 'tier' => 'reference', 'icon' => '', 'image_props' => [], 'rows' => [], 'examples' => []];
    }
    foreach ($tiers as &$defs) {
      $sortable = [];
      foreach ($defs as $name => $def) {
        $sortable[$name] = [$def['order'], $name];
      }
      asort($sortable);
      $defs = array_replace($sortable, $defs);
    }
    unset($defs);

    // 2. Site constraint — removals only (narrowing can never widen).
    $tones = ComponentCatalog::TONES;
    if (!empty($constraint['tones']) && is_array($constraint['tones'])) {
      $kept = array_values(array_diff($tones, $constraint['tones']));
      // Removing every tone would leave the grammar unusable — degrade.
      if ($kept === []) {
        $warnings[] = 'site_constraint removes every tone — ignored.';
      }
      else {
        $tones = $kept;
      }
    }
    foreach ((array) ($constraint['components'] ?? []) as $removed) {
      $found = FALSE;
      foreach (self::PLACEABLE_TIERS as $tier) {
        if (isset($tiers[$tier][$removed])) {
          unset($tiers[$tier][$removed]);
          $found = TRUE;
        }
      }
      if (!$found) {
        $warnings[] = sprintf('site_constraint removes unknown component "%s" — ignored.', $removed);
      }
    }
    $variantRemovals = is_array($constraint['variants'] ?? NULL) ? $constraint['variants'] : [];

    // 3. Kind narrowing — an allow-list when non-empty; empty = whole palette.
    $allow = $kind?->components() ?? [];
    if ($allow !== []) {
      foreach (self::PLACEABLE_TIERS as $tier) {
        foreach (array_keys($tiers[$tier]) as $name) {
          if (!array_key_exists($name, $allow)) {
            unset($tiers[$tier][$name]);
          }
        }
      }
      foreach (array_keys($allow) as $name) {
        if (!isset($tiers['section'][$name]) && !isset($tiers['layout'][$name]) && !isset($tiers['reference'][$name])) {
          $warnings[] = sprintf('kind "%s" allows unknown component "%s" — skipped.', $kind?->id() ?? $kindId, $name);
        }
      }
    }

    // 4. Per-component variant/tone narrowing + the derived variant map.
    $variants = [];
    foreach (self::PLACEABLE_TIERS as $tier) {
      foreach ($tiers[$tier] as $name => $def) {
        $declared = self::variantEnum($def['props']['variant'] ?? '');
        $narrowed = $declared;
        $removed = $variantRemovals[$name] ?? NULL;
        if (is_array($removed) && $narrowed !== NULL) {
          $kept = array_values(array_diff($narrowed, $removed));
          if ($kept === []) {
            $warnings[] = sprintf('every variant of "%s" removed — ignored.', $name);
          }
          else {
            $narrowed = $kept;
          }
        }
        $subset = $allow[$name]['variants'] ?? NULL;
        if (is_array($subset) && $subset !== [] && $narrowed !== NULL) {
          $kept = array_values(array_intersect($narrowed, $subset));
          if ($kept === []) {
            $warnings[] = sprintf('kind variant subset for "%s" matches nothing — ignored.', $name);
          }
          else {
            $narrowed = $kept;
          }
        }
        if ($narrowed !== NULL) {
          $variants[$name] = $narrowed;
          if ($narrowed !== $declared) {
            $tiers[$tier][$name]['props']['variant'] = implode('|', $narrowed);
          }
        }
        // Per-component tone subset (kind narrowing; same never-fatal floor).
        $toneSubset = $allow[$name]['tones'] ?? NULL;
        if (is_array($toneSubset) && $toneSubset !== []) {
          $kept = array_values(array_intersect($tones, $toneSubset));
          if ($kept === []) {
            $warnings[] = sprintf('kind tone subset for "%s" matches nothing — ignored.', $name);
          }
          elseif ($kept !== $tones) {
            $tiers[$tier][$name]['tones'] = $kept;
          }
        }
      }
    }

    // 5. Opener + limits, never-fatal.
    $opener = $kind?->opener();
    if ($opener !== NULL && !isset($tiers['section'][$opener])) {
      $warnings[] = sprintf('kind "%s" opener "%s" is not an available section — ignored.', $kind?->id() ?? $kindId, $opener);
      $opener = NULL;
    }
    $limits = [];
    foreach ($kind?->limits() ?? [] as $name => $max) {
      if (isset($tiers['section'][$name]) || isset($tiers['layout'][$name]) || isset($tiers['reference'][$name])) {
        $limits[$name] = max(1, (int) $max);
      }
      else {
        $warnings[] = sprintf('kind limit for unknown component "%s" — ignored.', $name);
      }
    }

    return new EffectiveCatalog(
      kind: $kind?->id() ?? $kindId,
      mode: $kind?->mode() ?? (($fallback['mode'] ?? '') === PageKindInterface::MODE_RECIPE ? PageKindInterface::MODE_RECIPE : PageKindInterface::MODE_COMPOSITION),
      sections: $tiers['section'],
      layout: $tiers['layout'],
      reference: $tiers['reference'],
      chrome: $tiers['chrome'],
      contentAtoms: $tiers['content'],
      tones: $tones,
      variants: $variants,
      opener: $opener,
      limits: $limits,
      collectionSource: $kind?->isCollectionSource() ?? (bool) ($fallback['collection_source'] ?? FALSE),
      hint: $kind?->hint() ?? (string) ($fallback['hint'] ?? ''),
      warnings: $warnings,
    );
  }

  /**
   * Parse a `variant` prop hint ('centered|split') into its enum, first value
   * = clamp default. NULL when the component declares no variant prop.
   */
  private static function variantEnum(string $hint): ?array {
    if ($hint === '') {
      return NULL;
    }
    $values = array_values(array_filter(array_map('trim', explode('|', $hint)), fn(string $v) => $v !== ''));
    return $values === [] ? NULL : $values;
  }

}
