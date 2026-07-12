<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Check;

/**
 * Builds one finding record — shared by every {@see CheckInterface}.
 */
trait FindingTrait {

  /**
   * Build one finding record.
   *
   * `$dimension` (Phase 2) names the axis the finding touches — `meta` /
   * `content` / `structure` — and `$remediation` is the declarative field-target
   * descriptor the repair agent + the manual editors read (see the check that
   * builds it). Both are optional and purely ADDITIVE: omit them and the finding
   * is byte-identical to the Phase-1 shape (the {@see \Drupal\aincient_audit\PolicyEvaluator}
   * byte-identity guarantee). When present they are appended after `location`, in
   * that order.
   *
   * @param string|null $dimension
   *   The axis this finding touches (meta|content|structure), or NULL.
   * @param array<string, mixed>|null $remediation
   *   The declarative remediation descriptor, or NULL when there is nothing to
   *   fix (every `pass` finding, and any finding with no known repair path).
   *
   * @return array{id: string, severity: string, title: string, detail: string, location: string, dimension?: string, remediation?: array<string, mixed>}
   */
  protected function finding(string $id, string $severity, string $title, string $detail, string $location, ?string $dimension = NULL, ?array $remediation = NULL): array {
    $finding = [
      'id' => $id,
      'severity' => $severity,
      'title' => $title,
      'detail' => $detail,
      'location' => $location,
    ];
    if ($dimension !== NULL) {
      $finding['dimension'] = $dimension;
    }
    if ($remediation !== NULL) {
      $finding['remediation'] = $remediation;
    }
    return $finding;
  }

}
