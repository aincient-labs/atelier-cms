<?php

declare(strict_types=1);

namespace Drupal\aincient_pages;

/**
 * Structural lint of an agent-emitted section against its component schema.
 *
 * The page agent's "ally" — the page analogue of the brand agent's contrast
 * warnings. The grammar validator ({@see PageStore}) CLAMPS a schema so it
 * always renders; this layer is ADVISORY: it explains, in the agent's own
 * feedback channel, WHY a section won't look right — a prop it doesn't
 * recognise, a content array it left empty, or a row field that's misnamed —
 * so the model self-corrects instead of silently shipping an empty band.
 *
 * It reasons purely from {@see ComponentCatalog} (the single source of the
 * prop vocabulary + the repeatable-row shapes), so the lint can never drift
 * from what the renderer actually consumes.
 *
 * Why this exists: an SDC tolerates unknown props (it just ignores them), so a
 * section whose data sits under the wrong key — e.g. `testimonials` instead of
 * `quotes` — renders a heading band with no cards and no error. The lint turns
 * that silent failure into a precise message the agent can act on.
 */
final class SchemaLinter {

  /**
   * Lint one section's props against its component schema.
   *
   * @param string $component
   *   The placeable component name (section or layout container).
   * @param array $props
   *   The emitted props.
   * @param bool $full
   *   TRUE for a whole new section (add_section) — also flags content arrays
   *   left empty. FALSE for a partial edit (update_section), where an absent
   *   array may already exist on the draft, so "renders empty" is suppressed.
   *
   * @return array<int, string>
   *   Human/agent-readable advisory messages (empty when the section is clean).
   */
  public static function lint(string $component, array $props, bool $full = TRUE): array {
    $def = ComponentCatalog::placeable($component);
    if ($def === NULL) {
      // Unknown components are handled by the allow-list, not here.
      return [];
    }
    $specs = $def['props'];
    $allowed = array_keys($specs);

    // Declared array props → their expected row fields ([] for a scalar list).
    $arrayProps = [];
    foreach ($specs as $prop => $hint) {
      if (str_starts_with($hint, '[')) {
        $arrayProps[$prop] = self::rowFields($hint);
      }
    }

    $issues = [];
    // Array props an unknown prop's rows were redirected to — so we don't ALSO
    // nag that they're empty (the agent already knows where the data belongs).
    $redirected = [];

    // 1) Unknown top-level props — the SDC silently ignores them, so flag each
    //    and (when the value looks like misplaced rows) point at the right prop.
    foreach ($props as $key => $value) {
      if (in_array($key, $allowed, TRUE)) {
        continue;
      }
      $hint = '';
      if (is_array($value)) {
        foreach ($arrayProps as $arrayProp => $fields) {
          if (empty($props[$arrayProp])) {
            $hint = sprintf(' Did you mean "%s"? Move these rows there.', $arrayProp);
            $redirected[$arrayProp] = TRUE;
            break;
          }
        }
      }
      $issues[] = sprintf('"%s" has no prop "%s" — it is ignored.%s', $component, $key, $hint);
    }

    // 2) Content arrays: empty → renders blank (full sections only); present →
    //    validate each row's fields against the declared shape.
    foreach ($arrayProps as $prop => $fields) {
      $value = $props[$prop] ?? NULL;
      $present = is_array($value) && $value !== [];
      if (!$present) {
        if ($full && !isset($redirected[$prop])) {
          $issues[] = sprintf(
            '"%s" has no "%s" yet, so it renders empty — add %s:%s.',
            $component, $prop, $prop, $specs[$prop],
          );
        }
        continue;
      }
      if ($fields === []) {
        // A list of scalars (no row shape to check).
        continue;
      }
      $badFields = [];
      foreach ($value as $row) {
        if (!is_array($row)) {
          continue;
        }
        foreach (array_keys($row) as $field) {
          if (!in_array($field, $fields, TRUE)) {
            $badFields[$field] = TRUE;
          }
        }
      }
      if ($badFields !== []) {
        $issues[] = sprintf(
          '"%s.%s" rows take {%s}; ignored unknown field(s): %s.',
          $component, $prop, implode(',', $fields), implode(', ', array_keys($badFields)),
        );
      }
    }

    return $issues;
  }

  /**
   * Parse the declared row fields out of a repeatable hint, e.g.
   * `[{quote,author,role,avatar}]` → `['quote','author','role','avatar']`.
   * Returns [] for a hint with no `{…}` (a list of scalars).
   */
  private static function rowFields(string $hint): array {
    if (!preg_match('/\{([^}]*)\}/', $hint, $m)) {
      return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $m[1])), static fn($s) => $s !== ''));
  }

}
