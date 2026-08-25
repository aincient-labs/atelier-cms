<?php

declare(strict_types=1);

namespace Drupal\aincient_pages\Catalog;

use Drupal\aincient_pages\ComponentCatalog;

/**
 * The admission gate — the HARD floor a component must pass to enter the
 * catalog (plans/byo-components.md §3.3). This is code, not policy: a page
 * kind or the site constraint may narrow what the gate admitted, never re-admit
 * what it rejected (memory/security-floor-vs-governance-policy).
 *
 * Pure static (like {@see CatalogCompiler}) so `drush atelier:pack-validate`
 * runs the EXACT same checks a boot does, and unit tests need no container.
 * The 28 built-ins pass this gate permanently — they are its fixtures.
 *
 * NEVER FATAL at runtime: the compiler EXCLUDES a rejected def and surfaces
 * the rejection as a catalog warning — a bad pack must not take a client site
 * down. `pack-validate` renders the same rejections as errors so a client CI
 * fails before an image ever ships.
 */
final class AdmissionGate {

  /**
   * The atelier contract majors this build understands.
   */
  public const KNOWN_API_MAJORS = [1];

  /**
   * The tiers whose defs the page agent may place (must carry `use` + props).
   */
  private const PLACEABLE_TIERS = ['section', 'layout', 'reference'];

  private const TIERS = ['section', 'layout', 'reference', 'content', 'chrome'];

  /**
   * Check every atelier-carrying definition. Keyed by component name; each
   * verdict: ['tier' => ?string, 'errors' => string[], 'warnings' => string[]].
   * A def with errors must be excluded from the catalog.
   *
   * @param array<string, array> $definitions
   *   SDC plugin definitions (or parsed .component.yml stand-ins carrying
   *   'provider' + 'machineName' + 'thirdPartySettings').
   * @param string[] $virtualNames
   *   Names reserved by virtual (non-SDC) defs — e.g. 'block'.
   */
  public static function check(array $definitions, array $virtualNames = ['block']): array {
    $verdicts = [];
    $seen = [];
    foreach ($virtualNames as $name) {
      $seen[$name] = '(virtual)';
    }

    foreach ($definitions as $definition) {
      $atelier = $definition['thirdPartySettings']['atelier'] ?? NULL;
      if (!is_array($atelier)) {
        continue;
      }
      $name = (string) ($definition['machineName'] ?? '');
      $provider = (string) ($definition['provider'] ?? '');
      if ($name === '') {
        continue;
      }
      $errors = [];
      $warnings = [];
      $tier = (string) ($atelier['tier'] ?? '');

      // api: the contract is versioned; refuse an unknown major outright —
      // guessing at a future contract is worse than skipping the component.
      $api = $atelier['api'] ?? NULL;
      if (!is_int($api) || !in_array($api, self::KNOWN_API_MAJORS, TRUE)) {
        $errors[] = sprintf('unknown or missing atelier api version (%s) — this build understands api: %s.', var_export($api, TRUE), implode('|', self::KNOWN_API_MAJORS));
      }

      // tier: must be one of the five.
      if (!in_array($tier, self::TIERS, TRUE)) {
        $errors[] = sprintf('unknown tier "%s" (section|layout|reference|content|chrome).', $tier);
      }

      // Rule 1 — globally unique names; reserved layout words stay reserved.
      if (isset($seen[$name])) {
        $errors[] = sprintf('name collides with %s — component names are globally unique (one word, one concept).', $seen[$name]);
      }
      if (in_array($name, ComponentCatalog::LAYOUT_RESERVED, TRUE) && !in_array($tier, ['layout', 'content'], TRUE)) {
        $errors[] = sprintf('"%s" is a reserved layout word — it cannot name a %s component.', $name, $tier ?: 'pack');
      }

      $isPlaceable = in_array($tier, self::PLACEABLE_TIERS, TRUE);
      $props = is_array($atelier['props'] ?? NULL) ? $atelier['props'] : [];
      $localVocab = is_array($atelier['prop_vocab'] ?? NULL) ? $atelier['prop_vocab'] : [];

      if ($isPlaceable) {
        // `use` is mandatory: a pack component has no pretraining prior, so the
        // selection hint is the only reason the agent will ever place it.
        if (trim((string) ($atelier['use'] ?? '')) === '') {
          $errors[] = 'missing "use" — the one-line selection hint the agent picks by is mandatory for a placeable component.';
        }

        // Rule 2 — the locked prop vocabulary, or an explicit pack-local
        // meaning. A prop word without a meaning is a prop the agent will
        // misuse.
        foreach (array_keys($props) as $prop) {
          if (!isset(ComponentCatalog::PROP_VOCAB[$prop]) && trim((string) ($localVocab[$prop] ?? '')) === '') {
            $errors[] = sprintf('prop "%s" is not in the locked vocabulary and declares no pack-local meaning (thirdPartySettings.atelier.prop_vocab.%s).', $prop, $prop);
          }
        }

        // Enums draw from the declared vocabulary: `tone` always uses the
        // shared enum (a bespoke hint would drift from the clamp), and the
        // `variant` hint must be a subset of the SDC schema's own enum, or the
        // clamp writes values the SDC 500s on.
        if (($props['tone'] ?? '') !== '' && isset($props['tone'])) {
          $errors[] = 'the "tone" prop hint must be empty — the tone enum is shared and injected by the catalog.';
        }
        $variantHint = (string) ($props['variant'] ?? '');
        if ($variantHint !== '') {
          $declared = $definition['props']['properties']['variant']['enum'] ?? NULL;
          $hinted = array_filter(array_map('trim', explode('|', $variantHint)));
          if (is_array($declared)) {
            foreach ($hinted as $value) {
              if (!in_array($value, $declared, TRUE)) {
                $errors[] = sprintf('variant "%s" is hinted to the agent but absent from the SDC schema enum (%s) — the clamp would 500 the render.', $value, implode('|', $declared));
              }
            }
          }
          elseif (isset($definition['props'])) {
            $errors[] = 'a variant hint is declared but the SDC schema has no variant enum.';
          }
        }

        // Every image-word SDC SLOT must be fillable by the renderer: the
        // renderer fills a slot only when image_props.<slot>.view_mode maps it.
        $imageProps = is_array($atelier['image_props'] ?? NULL) ? $atelier['image_props'] : [];
        foreach (array_keys($definition['slots'] ?? []) as $slot) {
          if (ComponentCatalog::isImageProp((string) $slot) && trim((string) ($imageProps[$slot]['view_mode'] ?? '')) === '') {
            $errors[] = sprintf('SDC slot "%s" is an image slot but declares no image_props.%s.view_mode — the renderer could never fill it.', $slot, $slot);
          }
        }
        foreach (array_keys($definition['slots'] ?? []) as $slot) {
          if (!ComponentCatalog::isImageProp((string) $slot) && !in_array($name, ['embed'], TRUE)) {
            $warnings[] = sprintf('SDC slot "%s" is not an image slot — the generic renderer will leave it empty.', $slot);
          }
        }
      }

      // `examples` (Phase 4): declared render fixtures — the gallery's input,
      // Phase 5's few-shots, the visual-regression baseline. Shape errors
      // reject (a malformed example would 500 the gallery); a pack placeable
      // WITHOUT examples warns — our built-ins predate the key, so absence is
      // only advisory, and only for non-built-in providers.
      $examples = $atelier['examples'] ?? NULL;
      if ($examples !== NULL) {
        if (!is_array($examples) || $examples !== array_values($examples)) {
          $errors[] = '"examples" must be a list of { props: {...} } entries.';
        }
        else {
          foreach ($examples as $i => $example) {
            if (!is_array($example) || !is_array($example['props'] ?? NULL)) {
              $errors[] = sprintf('examples[%d] must be a mapping with a "props" map (story-shaped: name?, props, slots?).', $i);
            }
          }
        }
      }
      elseif ($isPlaceable && $provider !== 'aincient_pages' && $provider !== '') {
        $warnings[] = 'no "examples" declared — the gallery cannot render this component and the agent gets no few-shot for it.';
      }

      // W5: a declared stylesheet must be a module-relative .css path — an
      // absolute path or traversal would let a pack link arbitrary files into
      // every rendered page (floor, not policy).
      $stylesheet = (string) ($atelier['stylesheet'] ?? '');
      if ($stylesheet !== '' && (!str_ends_with($stylesheet, '.css') || str_starts_with($stylesheet, '/') || str_contains($stylesheet, '..'))) {
        $errors[] = sprintf('stylesheet "%s" must be a module-relative .css path (no leading /, no ..).', $stylesheet);
      }

      $seen[$name] = sprintf('"%s:%s"', $provider, $name);
      $verdicts[$name] = ['tier' => $tier ?: NULL, 'provider' => $provider, 'errors' => $errors, 'warnings' => $warnings];
    }
    return $verdicts;
  }

  /**
   * Only the rejected verdicts (errors non-empty).
   */
  public static function rejections(array $definitions, array $virtualNames = ['block']): array {
    return array_filter(self::check($definitions, $virtualNames), fn(array $v) => $v['errors'] !== []);
  }

}
