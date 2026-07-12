<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Builds a validated chrome-preview widget envelope from raw preview args.
 *
 * The chrome parallel of {@see BrandPreviewApplier}: the single source of truth
 * for turning a `preview_chrome`-shaped slice (`identity_json` / `layout_json` /
 * `reset`) into the client-ready `chrome_preview` widget envelope the Globals
 * studio's draft store applies. All the validation lives here once — identity
 * guidelines whitelisted against {@see SiteIdentity::GUIDELINE_KEYS}, header /
 * footer layout coerced against {@see ChromeRepository::REGISTRY} — so only
 * legal values ever reach the client (and, through Publish, the strict SDC enum
 * props that would otherwise 500).
 *
 * The chrome agent (like brand) writes NOTHING to the live site: this stages an
 * unsaved draft the studio rail shows and the live preview re-renders; the one
 * deliberate global write stays the studio's Publish button. Menus are NOT in
 * the agent's scope — they stay editor-only (the inline menu editor), so this
 * applier never touches `menus`.
 *
 * The `payload` is the client-ready partial the `chrome_preview` widget merges
 * into the current chrome draft (`identity` + `layout`, each only the keys the
 * agent actually set) — NOT the raw `*_json` arg shape.
 */
final class ChromePreviewApplier {

  /**
   * Build a chrome-preview widget envelope from raw preview args.
   *
   * @param array{identity_json?: string|null, layout_json?: string|null, reset?: bool|string|null} $args
   *   Raw preview args (string values keep parity with FunctionCall
   *   getContextValue / node-param reads): a JSON object of identity guidelines
   *   + an optional `footer_note` for `identity_json`, a JSON object of
   *   `{header: {...}, footer: {...}}` layout settings for `layout_json`, and a
   *   truthy `reset`.
   *
   * @return array
   *   On success, a widget envelope:
   *   `['__widget__' => 'chrome_preview', 'payload' => […], 'summary' => '…']`.
   *   On a hard input error (malformed JSON, or nothing valid to apply), a
   *   single-key `['error' => '…']`.
   */
  public function apply(array $args): array {
    $reset = $this->truthy($args['reset'] ?? FALSE);

    // ── 1. Identity guidelines + footer note (whitelisted text fields) ───────
    $identity = [];
    $rejected = [];
    $identityRaw = trim((string) ($args['identity_json'] ?? ''));
    if ($identityRaw !== '') {
      $decoded = json_decode($identityRaw, TRUE);
      if (!is_array($decoded)) {
        return ['error' => 'Error: identity must be a JSON object of {name?, tagline?, description?, tone?, imagery_style?, imagery_avoid?, footer_note?}.'];
      }
      $guidelines = [];
      foreach ($decoded as $key => $value) {
        if (!is_string($value)) {
          continue;
        }
        if (in_array($key, SiteIdentity::GUIDELINE_KEYS, TRUE)) {
          $guidelines[$key] = trim($value);
        }
        elseif ($key === 'footer_note') {
          $identity['footer_note'] = trim($value);
        }
        else {
          $rejected[] = 'identity.' . (string) $key;
        }
      }
      if ($guidelines !== []) {
        $identity['guidelines'] = $guidelines;
      }
    }

    // ── 2. Header / footer layout (coerced against the registry) ─────────────
    // A true PARTIAL: we keep only the keys the agent actually sent (so the
    // widget merge doesn't clobber settings it didn't mention), validating each
    // value directly against the registry — an unknown key OR an invalid value
    // is rejected (not silently defaulted).
    $layout = [];
    $layoutRaw = trim((string) ($args['layout_json'] ?? ''));
    if ($layoutRaw !== '') {
      $decoded = json_decode($layoutRaw, TRUE);
      if (!is_array($decoded)) {
        return ['error' => 'Error: layout must be a JSON object of {header?: {...}, footer?: {...}}.'];
      }
      foreach ($decoded as $section => $settings) {
        if (!in_array($section, ['header', 'footer'], TRUE) || !is_array($settings)) {
          $rejected[] = 'layout.' . (string) $section;
          continue;
        }
        foreach ($settings as $key => $value) {
          $def = ChromeRepository::REGISTRY[$section][$key] ?? NULL;
          $coerced = $def === NULL ? NULL : $this->coerceSetting($def, $value);
          if ($coerced === NULL) {
            $rejected[] = $section . '.' . (string) $key;
            continue;
          }
          $layout[$section][$key] = $coerced;
        }
      }
    }

    if (!$reset && $identity === [] && $layout === []) {
      return ['error' => 'Error: provide at least one identity field or layout setting to preview, or set reset=true.'
        . ($rejected ? ' Rejected (unknown key or invalid value): ' . implode(', ', $rejected) . '.' : '')];
    }

    $count = $this->countChanges($identity) + $this->countChanges($layout);
    $summary = $reset
      ? 'Reverted the preview to the saved chrome.'
      : 'Previewing ' . $count . ' chrome ' . ($count === 1 ? 'change' : 'changes')
        . ' — watch the live preview, then Publish to apply.';
    if ($rejected) {
      $summary .= ' (Skipped invalid: ' . implode(', ', $rejected) . '.)';
    }

    return [
      '__widget__' => 'chrome_preview',
      'payload' => [
        'identity' => $identity,
        'layout' => $layout,
        'reset' => $reset,
        'rejected' => $rejected,
      ],
      'summary' => $summary,
    ];
  }

  /**
   * Coerce one layout setting to a valid value, or NULL if invalid — mirrors
   * {@see ChromeRepository::coerce} (enum membership; bool + JSON-ish strings).
   *
   * @param array{enum?: list<string>, default: mixed} $def
   *   The registry definition for the setting.
   */
  private function coerceSetting(array $def, mixed $value): mixed {
    if ($value === NULL) {
      return NULL;
    }
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

  /**
   * Count the leaf changes in a payload section (guidelines + footer note, or
   * header + footer settings) for the human-readable summary.
   */
  private function countChanges(array $section): int {
    $n = 0;
    foreach ($section as $value) {
      $n += is_array($value) ? count($value) : 1;
    }
    return $n;
  }

  /**
   * Coerce a raw reset value (bool or "true"/"1" string) to bool.
   */
  private function truthy(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
    }
    return (bool) $value;
  }

}
