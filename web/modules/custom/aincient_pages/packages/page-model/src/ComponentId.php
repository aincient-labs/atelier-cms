<?php

declare(strict_types=1);

namespace Aincient\PageModel;

use Aincient\PageModel\Catalog\EffectiveCatalog;

/**
 * Component ids: short names ↔ full `provider:name` ids (DECISIONS 0534–0536).
 *
 * The ONE translator (failure-modes plan R2). From 0.30.0 every store holds
 * full ids; in A0 everything above storage still speaks short names. So:
 *  - reads shorten ({@see shortenStructure()}, {@see shortenContent()}),
 *  - writes qualify ({@see qualifyStructure()}, {@see qualifyContent()}) from
 *    the provider of the ORIGINAL component (an override never owns a name),
 *  - a name no installed provider ships is stored as `unresolved:<name>`,
 *  - an old built-in id (`aincient_pages:<name>`) in STORED data is read as
 *    its new id ({@see bridge()}) for as long as the 0.30.0 rewrite ships;
 *    pack CODE lost that bridge in 0.32.0 (DECISIONS 0534).
 * Nothing else may read or write the `component` of a stored slot.
 */
final class ComponentId {

  /**
   * The provider of a stored name no installed provider ships.
   */
  public const UNRESOLVED = 'unresolved';

  /**
   * The provider built-ins had before 0.30.0.
   */
  public const LEGACY_PROVIDER = 'aincient_pages';

  /**
   * The built-in id an old `aincient_pages:<name>` id now means.
   *
   * @return string|null
   *   `atelier:<name>`, or NULL when $id is not an old built-in id.
   */
  public static function bridge(string $id): ?string {
    $prefix = self::LEGACY_PROVIDER . ':';
    if (!str_starts_with($id, $prefix)) {
      return NULL;
    }
    $name = substr($id, strlen($prefix));
    return $name === '' ? NULL : ComponentCatalog::BUILTIN_PROVIDER . ':' . $name;
  }


  /**
   * The short name of an id: `atelier:hero` → `hero`; a bare name as is.
   *
   * A bare name is still read (rows the 0.30.0 rewrite has not reached yet,
   * a pack's config/install written before it). An `unresolved:` name reads
   * as its name, so it renders again once its pack is back — as before 0.30.0.
   */
  public static function short(string $id): string {
    $at = strpos($id, ':');
    return $at === FALSE ? $id : substr($id, $at + 1);
  }

  /**
   * The full id of a name, given name → provider.
   *
   * An id that is already full is kept (an old built-in id is bridged); a
   * name missing from the map becomes `unresolved:<name>`. An empty name is
   * left empty — the validator decides what an empty slot means.
   *
   * @param array<string, string> $providers
   *   Short name → provider ({@see providers()}).
   */
  public static function qualify(string $name, array $providers): string {
    if ($name === '') {
      return '';
    }
    if (str_contains($name, ':')) {
      return self::bridge($name) ?? $name;
    }
    return (isset($providers[$name]) ? $providers[$name] : self::UNRESOLVED) . ':' . $name;
  }

  /**
   * Like {@see qualify()}, and an `unresolved:<name>` whose provider is now
   * installed becomes `<provider>:<name>`. What the stored-id rewrite and the
   * re-resolve after a pack install map with.
   *
   * @param array<string, string> $providers
   */
  public static function resolve(string $id, array $providers): string {
    $prefix = self::UNRESOLVED . ':';
    if (str_starts_with($id, $prefix)) {
      $name = substr($id, strlen($prefix));
      return isset($providers[$name]) ? $providers[$name] . ':' . $name : $id;
    }
    return self::qualify($id, $providers);
  }

  /**
   * Short name → provider of every component a site has, originals only.
   *
   * Built from the admitted catalog (all tiers), so a `replaces:` override —
   * which carries no catalog metadata — never claims a name. A virtual
   * component (`block`, no SDC) belongs to the built-ins.
   *
   * @return array<string, string>
   */
  public static function providers(EffectiveCatalog $discovered): array {
    $map = [];
    $names = array_merge($discovered->placeableNames(), $discovered->chrome(), $discovered->contentAtoms());
    foreach ($names as $name) {
      $provider = (string) ($discovered->def($name)['provider'] ?? '');
      $map[$name] = $provider === '' ? ComponentCatalog::BUILTIN_PROVIDER : $provider;
    }
    return $map;
  }

  /**
   * {@see providers()}, except that a short name keeps the provider it
   * already has in $stored — so re-saving a kind or the site constraint from
   * a short-name UI never moves `atelier:testimonials` onto a pack's
   * `testimonials` (DECISIONS 0539 review).
   *
   * @param array<string, string> $providers
   * @param iterable<string> $stored
   *   Full ids as stored now.
   *
   * @return array<string, string>
   */
  public static function providersPreferring(array $providers, iterable $stored): array {
    foreach ($stored as $id) {
      if (!is_string($id) || !str_contains($id, ':')) {
        continue;
      }
      $id = self::bridge($id) ?? $id;
      $provider = substr($id, 0, (int) strpos($id, ':'));
      if ($provider !== self::UNRESOLVED) {
        $providers[self::short($id)] = $provider;
      }
    }
    return $providers;
  }

  /**
   * Short name → provider for a name written BEFORE 0.30.0 (bare names).
   *
   * Until A1 a short name was globally unique and the built-in claimed it
   * first, so a bare `testimonial` in old data always meant the built-in —
   * even on a site where a pack's `testimonial` now wins the pair choice
   * (DECISIONS 0539). The 0.30.0 rewrite and a pack's bare-name kind map
   * with this, never with {@see providers()}, or a straight 0.29 → A1
   * upgrade would move built-in sections onto the pack's component.
   *
   * @return array<string, string>
   */
  public static function legacyProviders(EffectiveCatalog $discovered): array {
    $map = self::providers($discovered);
    $builtin = ComponentCatalog::BUILTIN_PROVIDER . ':';
    foreach ($discovered->hiddenIds() as $id) {
      if (str_starts_with($id, $builtin)) {
        $map[self::short($id)] = ComponentCatalog::BUILTIN_PROVIDER;
      }
    }
    return $map;
  }

  /**
   * A stored structure layer with short names (`slots[].component`).
   *
   * @param string[] $keep
   *   Full ids to keep as they are: the hidden one of a same-name pair
   *   (DECISIONS 0539) — shortening it would render the other one.
   */
  public static function shortenStructure(array $structure, array $keep = []): array {
    return self::mapStructure($structure, self::shortUnless($keep));
  }

  /**
   * A stored content layer with short names
   * (`slots.<id>.panels[].blocks[].component`, the accordion's children).
   *
   * @param string[] $keep
   *   As for {@see shortenStructure()}.
   */
  public static function shortenContent(array $content, array $keep = []): array {
    return self::mapContent($content, self::shortUnless($keep));
  }

  /**
   * short(), except for the ids in $keep.
   *
   * @param string[] $keep
   */
  private static function shortUnless(array $keep): \Closure {
    if ($keep === []) {
      return self::short(...);
    }
    $keep = array_flip($keep);
    return static fn(string $id): string => isset($keep[$id]) ? $id : self::short($id);
  }

  /**
   * A structure layer with full ids, ready to store.
   *
   * @param array<string, string> $providers
   */
  public static function qualifyStructure(array $structure, array $providers): array {
    return self::mapStructure($structure, static fn(string $name): string => self::qualify($name, $providers));
  }

  /**
   * A content layer with full ids, ready to store.
   *
   * @param array<string, string> $providers
   */
  public static function qualifyContent(array $content, array $providers): array {
    return self::mapContent($content, static fn(string $name): string => self::qualify($name, $providers));
  }

  /**
   * Apply $map to every `slots[].component` of a structure layer.
   *
   * Walks the known path only — never a text replace (plan R3): a prop value
   * that happens to equal a component name is not a component.
   */
  public static function mapStructure(array $structure, callable $map): array {
    if (!is_array($structure['slots'] ?? NULL)) {
      return $structure;
    }
    foreach ($structure['slots'] as $i => $slot) {
      if (is_array($slot) && is_string($slot['component'] ?? NULL)) {
        $structure['slots'][$i]['component'] = $map($slot['component']);
      }
    }
    return $structure;
  }

  /**
   * Apply $map to every `slots.<id>.panels[].blocks[].component`.
   */
  public static function mapContent(array $content, callable $map): array {
    if (!is_array($content['slots'] ?? NULL)) {
      return $content;
    }
    foreach ($content['slots'] as $id => $slot) {
      if (!is_array($slot) || !is_array($slot['panels'] ?? NULL)) {
        continue;
      }
      foreach ($slot['panels'] as $p => $panel) {
        if (!is_array($panel) || !is_array($panel['blocks'] ?? NULL)) {
          continue;
        }
        foreach ($panel['blocks'] as $b => $block) {
          if (is_array($block) && is_string($block['component'] ?? NULL)) {
            $content['slots'][$id]['panels'][$p]['blocks'][$b]['component'] = $map($block['component']);
          }
        }
      }
    }
    return $content;
  }

  /**
   * The site constraint with short names: `components[]`, and the keys of
   * `variants` and `component_tones`. Tones and `overrides_off` (SDC ids)
   * are left as they are.
   */
  public static function shortenConstraint(array $constraint): array {
    return self::mapConstraint($constraint, self::short(...));
  }

  /**
   * The site constraint with full ids, ready to store.
   *
   * @param array<string, string> $providers
   */
  public static function qualifyConstraint(array $constraint, array $providers): array {
    return self::mapConstraint($constraint, static fn(string $name): string => self::qualify($name, $providers));
  }

  /**
   * Page-kind values with short names: `components` and `limits` keys,
   * `opener`, `removed[]`. Other keys are left as they are.
   */
  public static function shortenKind(array $values): array {
    return self::mapKind($values, self::short(...));
  }

  /**
   * Page-kind values with full ids, ready to store.
   *
   * @param array<string, string> $providers
   */
  public static function qualifyKind(array $values, array $providers): array {
    return self::mapKind($values, static fn(string $name): string => self::qualify($name, $providers));
  }

  /**
   * The names in page-kind values that carry no provider.
   *
   * @return string[]
   */
  public static function bareNamesInKind(array $values): array {
    $bare = [];
    self::mapKind($values, static function (string $name) use (&$bare): string {
      if ($name !== '' && !str_contains($name, ':')) {
        $bare[] = $name;
      }
      return $name;
    });
    return array_values(array_unique($bare));
  }

  public static function mapConstraint(array $constraint, callable $map): array {
    if (is_array($constraint['components'] ?? NULL)) {
      $constraint['components'] = array_values(array_map(
        static fn(mixed $name): mixed => is_string($name) ? $map($name) : $name,
        $constraint['components'],
      ));
    }
    foreach (['variants', 'component_tones'] as $key) {
      if (is_array($constraint[$key] ?? NULL)) {
        $constraint[$key] = self::mapKeys($constraint[$key], $map);
      }
    }
    return $constraint;
  }

  public static function mapKind(array $values, callable $map): array {
    foreach (['components', 'limits'] as $key) {
      if (is_array($values[$key] ?? NULL)) {
        $values[$key] = self::mapKeys($values[$key], $map);
      }
    }
    if (is_string($values['opener'] ?? NULL) && $values['opener'] !== '') {
      $values['opener'] = $map($values['opener']);
    }
    if (is_array($values['removed'] ?? NULL)) {
      $values['removed'] = array_values(array_map(
        static fn(mixed $name): mixed => is_string($name) ? $map($name) : $name,
        $values['removed'],
      ));
    }
    return $values;
  }

  private static function mapKeys(array $map, callable $fn): array {
    $out = [];
    foreach ($map as $key => $value) {
      $out[is_string($key) ? $fn($key) : $key] = $value;
    }
    return $out;
  }

}
