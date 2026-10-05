<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

/**
 * The `expect:` grammar of a brand eval case, evaluated against what a turn
 * actually produced. Pure — the runner builds the `observed` map from the job
 * trail, this class only compares.
 *
 * OBSERVED KEYS (dotted; the runner fills them):
 *  - `status`                     completed | failed | awaiting_input | paused | unknown
 *  - `slice.<token>`              the specialist's written value, by token name
 *                                 (merged across the turn's specialist jobs)
 *  - `applied.<css_var>`          the merged brand_preview envelope's tokens
 *  - `applied_keys`               those css_vars, comma-joined (greppable)
 *  - `applied_nonzero`            the subset whose value is not 0/0px/none
 *  - `rejected`                   list of tokens the validator refused
 *  - `contrast.<surface>`         the pair ratio for that surface's declared on-colour
 *  - `hue_delta.<token>`          |Δhue| deg between before (draft, else saved) and after
 *  - `chroma_delta.<token>`       after.C − before.C
 *  - `lightness_delta.<token>`    after.L − before.L
 *  - `delegations.<axis>`         colour | shape | typography | total — specialist jobs
 *  - `prose`                      the orchestrator's final reply
 *  - `model`, `calls`, `cost_usd` informational
 *
 * EXPECTATION FORMS (the YAML value):
 *  - `/re/i`             regex must match the string form of the value
 *  - `!/re/i`            regex must NOT match
 *  - `present`/`absent`  the key exists with a non-empty value / does not
 *  - `>= 4.5`, `> 0`, `< 15`, `<= 1`, `== 1`, `!= 0`   numeric compare
 *  - `rises` / `falls`   sugar for `> 0` / `< 0` (deltas)
 *  - `[]` (a list)       the value must equal that list
 *  - anything else       exact string equality
 *  - `prose_not: /re/`   legacy spelling of `prose: !/re/`
 *  - `<spec> unless <key> <spec>`   CONDITIONAL: when the guard holds (its key
 *                        compared with its own spec, any form above), the
 *                        assertion is waived and passes; otherwise <spec> is
 *                        checked. For claims that are only false when a fact is:
 *                        `prose: "!/passes AA/i unless contrast.brand_primary >= 4.5"`.
 *                        A guard on a key that was never observed holds only
 *                        when its spec is `absent`.
 *
 * A key the runner never observed (e.g. `slice.brand_primary` on a turn that
 * delegated nothing) compares as missing: `absent` passes, everything else fails
 * with "(missing)" as the actual — silence is a finding, not a pass.
 */
final class BrandEvalAssertions {

  /**
   * Whether a YAML expectation is one this grammar understands.
   */
  public static function validExpectation(string $key, mixed $value): bool {
    if ($key === '') {
      return FALSE;
    }
    if (is_array($value)) {
      return array_is_list($value);
    }
    return is_scalar($value) || $value === NULL;
  }

  /**
   * @param array<string, mixed> $expect
   * @param array<string, mixed> $observed
   *
   * @return list<array{key: string, expected: string, actual: string, pass: bool}>
   */
  public static function evaluate(array $expect, array $observed): array {
    $results = [];
    foreach ($expect as $key => $expected) {
      $key = (string) $key;
      $lookup = $key;
      $shown = $expected;
      // `<spec> unless <key> <spec>` → waived when the guard holds.
      $guard = is_string($expected) ? self::splitGuard($expected) : NULL;
      if ($guard !== NULL) {
        [$expected, $guardKey, $guardSpec] = $guard;
        $guardHas = array_key_exists($guardKey, $observed) && $observed[$guardKey] !== NULL && $observed[$guardKey] !== '';
        if (self::compare($guardSpec, $guardHas ? $observed[$guardKey] : NULL, $guardHas)) {
          $results[] = [
            'key' => $key,
            'expected' => self::describe($shown),
            'actual' => sprintf('(waived: %s = %s)', $guardKey, $guardHas ? self::describe($observed[$guardKey]) : '(missing)'),
            'pass' => TRUE,
          ];
          continue;
        }
      }
      // `prose_not: /re/` → negative regex on `prose`.
      if (str_ends_with($key, '_not') && is_string($expected) && preg_match('#^/.*/[a-z]*$#s', $expected)) {
        $lookup = substr($key, 0, -4);
        $expected = '!' . $expected;
      }
      $has = array_key_exists($lookup, $observed) && $observed[$lookup] !== NULL && $observed[$lookup] !== '';
      $actual = $has ? $observed[$lookup] : NULL;
      $results[] = [
        'key' => $key,
        'expected' => self::describe($shown),
        'actual' => $has ? self::describe($actual) : '(missing)',
        'pass' => self::compare($expected, $actual, $has),
      ];
    }
    return $results;
  }

  /**
   * Split `<spec> unless <key> <spec>` into its parts, or NULL.
   *
   * @return array{0: string, 1: string, 2: string}|null
   *   [main spec, guard key, guard spec].
   */
  private static function splitGuard(string $expected): ?array {
    if (!preg_match('/^(.*\S)\s+unless\s+([A-Za-z0-9_.\-]+)\s+(\S.*)$/s', $expected, $m)) {
      return NULL;
    }
    return [$m[1], $m[2], trim($m[3])];
  }

  private static function compare(mixed $expected, mixed $actual, bool $has): bool {
    if (is_array($expected)) {
      return $has ? (is_array($actual) && array_values($actual) == $expected) : $expected === [];
    }
    if ($expected === NULL) {
      return !$has;
    }
    $spec = trim((string) $expected);

    if ($spec === 'present') {
      return $has;
    }
    if ($spec === 'absent') {
      return !$has;
    }
    if (!$has) {
      return FALSE;
    }
    $string = is_array($actual) ? (string) json_encode($actual) : (string) $actual;

    if (preg_match('#^(!?)(/.*/[a-z]*)$#s', $spec, $m)) {
      $matched = @preg_match($m[2], $string);
      if ($matched === FALSE) {
        return FALSE;
      }
      return $m[1] === '!' ? $matched === 0 : $matched === 1;
    }
    if ($spec === 'rises') {
      $spec = '> 0';
    }
    elseif ($spec === 'falls') {
      $spec = '< 0';
    }
    if (preg_match('/^(>=|<=|==|!=|>|<)\s*(-?\d+(?:\.\d+)?)$/', $spec, $m)) {
      if (!is_numeric($string)) {
        return FALSE;
      }
      $a = (float) $string;
      $b = (float) $m[2];
      return match ($m[1]) {
        '>=' => $a >= $b,
        '<=' => $a <= $b,
        '>' => $a > $b,
        '<' => $a < $b,
        '==' => abs($a - $b) < 1e-9,
        '!=' => abs($a - $b) >= 1e-9,
      };
    }
    return $string === $spec;
  }

  private static function describe(mixed $value): string {
    if (is_array($value)) {
      return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    if (is_float($value)) {
      return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }
    $s = (string) $value;
    return mb_strlen($s) > 160 ? mb_substr($s, 0, 157) . '…' : $s;
  }

}
