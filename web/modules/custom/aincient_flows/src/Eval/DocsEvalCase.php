<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

use Symfony\Component\Yaml\Yaml;

/**
 * One docs-eval case: a question for the General assistant and what a good
 * answer must (not) do. Format → `evals/docs/README.md`.
 */
final class DocsEvalCase {

  /**
   * @param string $kind
   *   `covered` (the docs answer it) or `uncovered` (Atelier does not do it;
   *   the answer must say the docs don't cover it, and invent nothing).
   * @param list<string> $linksAny
   *   Docs paths (e.g. `/docs/quickstart`); the answer links at least one.
   * @param list<string> $mentionAll
   *   Regexes the answer must all match.
   * @param string $proseNot
   *   A regex the answer must not match ('' = none).
   */
  public function __construct(
    public readonly string $name,
    public readonly string $file,
    public readonly string $ask,
    public readonly string $kind,
    public readonly array $linksAny,
    public readonly array $mentionAll,
    public readonly string $proseNot,
  ) {}

  /**
   * Every `*.yml` case in a directory, sorted by file name.
   *
   * @return list<self>
   */
  public static function loadDirectory(string $dir): array {
    $cases = [];
    $files = glob(rtrim($dir, '/') . '/*.yml') ?: [];
    sort($files);
    foreach ($files as $file) {
      $data = Yaml::parseFile($file);
      if (!is_array($data) || !isset($data['ask'])) {
        throw new \InvalidArgumentException("$file: a case needs at least `ask`.");
      }
      $kind = (string) ($data['kind'] ?? 'covered');
      if (!in_array($kind, ['covered', 'uncovered'], TRUE)) {
        throw new \InvalidArgumentException("$file: kind must be covered or uncovered.");
      }
      $expect = (array) ($data['expect'] ?? []);
      $cases[] = new self(
        (string) ($data['name'] ?? preg_replace('/^\d+-/', '', basename($file, '.yml'))),
        $file,
        (string) $data['ask'],
        $kind,
        array_values(array_map('strval', (array) ($expect['links_any'] ?? []))),
        array_values(array_map('strval', (array) ($expect['mention_all'] ?? []))),
        (string) ($expect['prose_not'] ?? ''),
      );
    }
    return $cases;
  }

}
