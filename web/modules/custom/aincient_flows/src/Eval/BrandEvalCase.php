<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

use Drupal\Component\Serialization\Yaml;

/**
 * One live-turn eval case for the brand agent, parsed from `evals/brand/*.yml`.
 *
 * A case is a SCENARIO the agent must get right, not a unit of code: the saved
 * brand it starts from, the status overlay, the conversation so far, the
 * studio draft on screen, the ask — and what must be true of the job trail
 * afterwards. One case per defect fixed (cms #36–#44) so each stays fixed; a
 * case marked `expected_fail` documents a defect we KNOW is open (#43) and
 * reports XFAIL/XPASS without moving the exit code — the eval is how we
 * decide whether to tighten the prompt, from runs rather than from one turn.
 *
 * Format (all keys optional except name + ask):
 * @code
 * name: darker-from-swatch-after-hand-edit
 * issue: 44
 * status: locked            # ideating | guided | polish | locked
 * saved_tokens: {}          # token name → value; {} = registry defaults
 * history:                  # prior turns as the model sees them
 *   - user: make primary blue
 *   - assistant: Primary is now blue.
 * draft:                    # live_preview_state overrides, keyed by css_var
 *   brand-primary: var(--color-yellow-100)
 * ask: make primary darker
 * expect:                   # see BrandEvalAssertions for the grammar
 *   slice.brand_primary: /^var\(--color-yellow-(500|600)\)$/
 * expected_fail: false
 * @endcode
 *
 * Designed so `plans/model-eval.md`, if it ever starts, can absorb this as a
 * move: nothing here knows about drush or Drupal.
 */
final class BrandEvalCase {

  public const STATUSES = ['ideating', 'guided', 'polish', 'locked'];

  /**
   * @param list<array{role: string, content: string}> $history
   * @param array<string, string> $savedTokens
   * @param array<string, string> $draft
   * @param array<string, mixed> $expect
   */
  public function __construct(
    public readonly string $name,
    public readonly string $ask,
    public readonly string $status = 'ideating',
    public readonly array $savedTokens = [],
    public readonly array $history = [],
    public readonly array $draft = [],
    public readonly array $expect = [],
    public readonly bool $expectedFail = FALSE,
    public readonly ?int $issue = NULL,
    public readonly string $file = '',
  ) {}

  /**
   * @throws \InvalidArgumentException
   *   On a malformed case — the corpus is code, a bad case must not run.
   */
  public static function fromFile(string $path): self {
    $raw = Yaml::decode((string) file_get_contents($path));
    if (!is_array($raw)) {
      throw new \InvalidArgumentException("$path: not a YAML mapping.");
    }
    return self::fromArray($raw, $path);
  }

  /**
   * @param array<string, mixed> $raw
   */
  public static function fromArray(array $raw, string $file = ''): self {
    $where = $file !== '' ? basename($file) : '(inline case)';
    $name = trim((string) ($raw['name'] ?? ''));
    $ask = trim((string) ($raw['ask'] ?? ''));
    if ($name === '' || $ask === '') {
      throw new \InvalidArgumentException("$where: `name` and `ask` are required.");
    }
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
      throw new \InvalidArgumentException("$where: `name` must be kebab-case ([a-z0-9-]).");
    }
    $status = strtolower(trim((string) ($raw['status'] ?? 'ideating')));
    if (!in_array($status, self::STATUSES, TRUE)) {
      throw new \InvalidArgumentException("$where: `status` must be one of " . implode('|', self::STATUSES) . ".");
    }

    $history = [];
    foreach ((array) ($raw['history'] ?? []) as $i => $turn) {
      if (!is_array($turn) || count($turn) !== 1) {
        throw new \InvalidArgumentException("$where: history[$i] must be a one-key map `{user: …}` or `{assistant: …}`.");
      }
      $role = (string) array_key_first($turn);
      if (!in_array($role, ['user', 'assistant'], TRUE)) {
        throw new \InvalidArgumentException("$where: history[$i] role must be user or assistant.");
      }
      $content = trim((string) $turn[$role]);
      if ($content === '') {
        throw new \InvalidArgumentException("$where: history[$i] is empty.");
      }
      $history[] = ['role' => $role, 'content' => $content];
    }

    $expect = $raw['expect'] ?? [];
    if (!is_array($expect)) {
      throw new \InvalidArgumentException("$where: `expect` must be a mapping.");
    }
    foreach ($expect as $key => $value) {
      if (!BrandEvalAssertions::validExpectation((string) $key, $value)) {
        throw new \InvalidArgumentException("$where: expect.$key has an unsupported form (" . json_encode($value) . ").");
      }
    }

    return new self(
      name: $name,
      ask: $ask,
      status: $status,
      savedTokens: self::stringMap($raw['saved_tokens'] ?? [], "$where: saved_tokens"),
      history: $history,
      draft: self::stringMap($raw['draft'] ?? [], "$where: draft"),
      expect: $expect,
      expectedFail: (bool) ($raw['expected_fail'] ?? FALSE),
      issue: isset($raw['issue']) ? (int) $raw['issue'] : NULL,
      file: $file,
    );
  }

  /**
   * Every `*.yml` in a directory, sorted by file name.
   *
   * @return list<self>
   */
  public static function loadDirectory(string $dir): array {
    $files = glob(rtrim($dir, '/') . '/*.yml') ?: [];
    sort($files);
    return array_map([self::class, 'fromFile'], $files);
  }

  /**
   * @return array<string, string>
   */
  private static function stringMap(mixed $value, string $label): array {
    if ($value === NULL || $value === 'default') {
      return [];
    }
    if (!is_array($value)) {
      throw new \InvalidArgumentException("$label must be a mapping.");
    }
    $out = [];
    foreach ($value as $k => $v) {
      if (!is_scalar($v)) {
        throw new \InvalidArgumentException("$label.$k must be a scalar.");
      }
      $out[(string) $k] = trim((string) $v);
    }
    return $out;
  }

}
