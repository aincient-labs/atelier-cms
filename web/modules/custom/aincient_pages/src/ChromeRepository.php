<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The site CHROME LAYOUT: how the header and footer are arranged.
 *
 * This is the layout/arrangement layer of the chrome — distinct from IDENTITY
 * (name/logo/tagline/footer note, {@see SiteIdentity}), the NAV (Drupal's core
 * `main`/`footer` menus, {@see SiteChrome::nav()}), and FOUNDATIONS (the design
 * tokens, {@see BrandRepository}). It stores a small, strictly-enumerated set of
 * layout choices (logo position, sticky header, nav alignment, footer layout)
 * that the `site-header`/`site-footer` SDCs read as variant props.
 *
 * Every setting is validated against {@see self::REGISTRY} on the way in and on
 * the way out, so the SDC enum can never receive an unknown value (which would
 * 500 the strict SDC prop validation). Unknown keys are dropped; bad values fall
 * back to the registry default.
 */
final class ChromeRepository {

  public const CONFIG = 'aincient_pages.chrome';

  /**
   * The chrome layout vocabulary: header + footer setting → allowed values +
   * default. The first listed value is the shipped default (matches the SDCs'
   * pre-variant look so a fresh site is unchanged).
   *
   * @var array<string, array<string, array{enum?: list<string>, default: mixed}>>
   */
  public const REGISTRY = [
    'header' => [
      // Where the wordmark/logo sits in the bar.
      'logo_position' => ['enum' => ['left', 'center'], 'default' => 'left'],
      // Whether the header sticks to the top on scroll (the current behaviour).
      'sticky' => ['default' => TRUE],
      // Where the nav links align (the current look is end / right).
      'nav_alignment' => ['enum' => ['end', 'center', 'start'], 'default' => 'end'],
    ],
    'footer' => [
      // `inline` = logo block + nav in one row (the current look); `stacked` =
      // centred logo block with the nav beneath.
      'layout' => ['enum' => ['inline', 'stacked'], 'default' => 'inline'],
      // Whether the tagline shows under the footer name.
      'show_tagline' => ['default' => TRUE],
    ],
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /** Validated header layout settings, every key present (defaults filled). */
  public function header(): array {
    return $this->section('header');
  }

  /** Validated footer layout settings, every key present (defaults filled). */
  public function footer(): array {
    return $this->section('footer');
  }

  /** Both sections: ['header' => […], 'footer' => […]]. */
  public function all(): array {
    return ['header' => $this->header(), 'footer' => $this->footer()];
  }

  /**
   * The validated `all()` AS IF the given partial changes were applied — WITHOUT
   * persisting. The studio preview seam: render the chrome with a draft layout.
   *
   * @param array<string, array<string, mixed>> $changes
   */
  public function applyDraft(array $changes): array {
    $out = [];
    foreach (['header', 'footer'] as $section) {
      $base = $this->section($section);
      $incoming = $changes[$section] ?? NULL;
      if (is_array($incoming)) {
        foreach ($incoming as $key => $value) {
          $coerced = $this->coerce($section, $key, $value);
          if ($coerced !== NULL) {
            $base[$key] = $coerced;
          }
        }
      }
      $out[$section] = $base;
    }
    return $out;
  }

  /**
   * A validated section, defaults filled for any missing/invalid key.
   */
  private function section(string $section): array {
    $stored = $this->configFactory->get(self::CONFIG)->get($section);
    $stored = is_array($stored) ? $stored : [];
    $out = [];
    foreach (self::REGISTRY[$section] as $key => $def) {
      $value = $stored[$key] ?? NULL;
      $out[$key] = $this->coerce($section, $key, $value) ?? $def['default'];
    }
    return $out;
  }

  /**
   * Merge + persist chrome changes. Each `[section][key]` is validated against
   * the registry; unknown keys and invalid values are dropped. Returns a
   * human-readable list of what changed (e.g. "header.sticky → false").
   *
   * @param array<string, array<string, mixed>> $changes
   *   Partial `['header' => […], 'footer' => […]]` map.
   *
   * @return string[]
   */
  public function update(array $changes): array {
    $config = $this->configFactory->getEditable(self::CONFIG);
    $applied = [];
    foreach (['header', 'footer'] as $section) {
      $incoming = $changes[$section] ?? NULL;
      if (!is_array($incoming)) {
        continue;
      }
      $current = $config->get($section);
      $current = is_array($current) ? $current : [];
      foreach ($incoming as $key => $value) {
        $coerced = $this->coerce($section, $key, $value);
        if ($coerced === NULL) {
          continue;
        }
        if (($current[$key] ?? NULL) !== $coerced) {
          $applied[] = $section . '.' . $key . ' → ' . $this->render($coerced);
        }
        $current[$key] = $coerced;
      }
      $config->set($section, $current);
    }
    if ($applied) {
      $config->save();
    }
    return $applied;
  }

  /**
   * Coerce one setting to a valid value, or NULL if the key is unknown or the
   * value is invalid (so the caller falls back to the default / drops it).
   */
  private function coerce(string $section, string $key, mixed $value): mixed {
    $def = self::REGISTRY[$section][$key] ?? NULL;
    if ($def === NULL || $value === NULL) {
      return NULL;
    }
    // Boolean settings (no enum) accept real bools and the JSON-ish strings.
    if (!isset($def['enum'])) {
      if (is_bool($value)) {
        return $value;
      }
      if ($value === 'true' || $value === 1 || $value === '1') {
        return TRUE;
      }
      if ($value === 'false' || $value === 0 || $value === '0') {
        return FALSE;
      }
      return NULL;
    }
    return (is_string($value) && in_array($value, $def['enum'], TRUE)) ? $value : NULL;
  }

  /** Render a coerced value for the human-readable change list. */
  private function render(mixed $value): string {
    return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
  }

}
