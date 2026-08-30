<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Eval;

/**
 * A content fingerprint of "the brand agent as the eval last saw it".
 *
 * `drush aincient:brand-eval` writes it into `evals/brand/.last-run`; the
 * build-time guard (BrandEvalFreshnessGuardTest) recomputes it and fails when
 * the two differ: the agent's behaviour-bearing sources changed and nobody
 * re-ran the live corpus. Content-hashed rather than git-hashed so it is
 * deterministic in a shallow CI checkout and cannot be defeated by an amend.
 *
 * WATCHED — deliberately tight (every widening costs a ~$0.75 eval run per
 * change): the four brand workflow YAMLs (orchestrator + specialist prompts),
 * BrandState (the status directive + saved-brand brief), and the corpus itself
 * (a changed case must be re-run). Widen here, in one place, when a defect
 * proves another file behaviour-bearing.
 */
final class BrandEvalFingerprint {

  /**
   * Repo-relative globs, resolved against the cms root.
   */
  public const WATCHED = [
    'config/sync/flowdrop_workflow.flowdrop_workflow.brand_studio.yml',
    'config/sync/flowdrop_workflow.flowdrop_workflow.aincient_brand_specialist_*.yml',
    'web/modules/custom/aincient_flows/src/Plugin/FlowDropNodeProcessor/BrandState.php',
    'web/modules/custom/aincient_flows/evals/brand/*.yml',
  ];

  public const LAST_RUN = 'web/modules/custom/aincient_flows/evals/brand/.last-run';

  /**
   * The cms repository root (…/web/modules/custom/aincient_flows/src/Eval → root).
   */
  public static function cmsRoot(): string {
    return dirname(__DIR__, 6);
  }

  /**
   * The watched files, sorted, repo-relative.
   *
   * @return list<string>
   */
  public static function watchedFiles(?string $root = NULL): array {
    $root = rtrim($root ?? self::cmsRoot(), '/');
    $files = [];
    foreach (self::WATCHED as $glob) {
      foreach (glob($root . '/' . $glob) ?: [] as $path) {
        $files[] = substr($path, strlen($root) + 1);
      }
    }
    sort($files);
    return $files;
  }

  /**
   * sha256 over (path + content) of every watched file.
   */
  public static function compute(?string $root = NULL): string {
    $root = rtrim($root ?? self::cmsRoot(), '/');
    $h = hash_init('sha256');
    foreach (self::watchedFiles($root) as $rel) {
      hash_update($h, $rel . "\0" . (string) file_get_contents($root . '/' . $rel) . "\0");
    }
    return hash_final($h);
  }

  /**
   * The fingerprint recorded by the last run, or NULL when none/unparseable.
   *
   * `.last-run` is one tab-separated line: ISO time, cms HEAD, then
   * `key=value` fields (`cases=`, `green=`, `unexpected=`, `fingerprint=`).
   */
  public static function recorded(?string $root = NULL): ?string {
    $path = rtrim($root ?? self::cmsRoot(), '/') . '/' . self::LAST_RUN;
    if (!is_file($path)) {
      return NULL;
    }
    foreach (preg_split('/\s+/', trim((string) file_get_contents($path))) ?: [] as $field) {
      if (str_starts_with($field, 'fingerprint=')) {
        return substr($field, strlen('fingerprint=')) ?: NULL;
      }
    }
    return NULL;
  }

}
