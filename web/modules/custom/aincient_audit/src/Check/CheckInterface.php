<?php

declare(strict_types=1);

namespace Drupal\aincient_audit\Check;

use Drupal\node\NodeInterface;

/**
 * One deterministic page-health check — a shipped default policy's logic.
 *
 * The reusable unit behind both the current {@see \Drupal\aincient_audit\AuditEngine}
 * report and (Phase 1, DECISIONS 0129) the native FlowDrop node processor that
 * wraps it: a check takes a node and returns a flat list of findings. The
 * `{key,label,findings}` grouping + the pass/warn/fail summary are assembled by
 * the caller. Read-only by contract — a check computes, it never writes.
 */
interface CheckInterface {

  /**
   * Severity levels (worst first) — the string values the Checks UI keys on.
   */
  public const FAIL = 'fail';
  public const WARN = 'warn';
  public const PASS = 'pass';

  /**
   * The check id — also the report's check `key` and the finding `policyId`.
   */
  public function id(): string;

  /**
   * The human label for the check group (e.g. "SEO & meta tags").
   */
  public function label(): string;

  /**
   * Evaluate the node → a flat list of findings.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check (the caller has already resolved the right revision).
   * @param array<string, mixed> $params
   *   The policy's tunable knobs (Phase 3, DECISIONS 0134) — e.g. `title_min`.
   *   A check reads only the keys it understands and falls back to its built-in
   *   defaults for the rest, so an empty map reproduces the pre-Phase-3 output
   *   byte-for-byte. The shipped default policies pass params equal to those
   *   defaults, so the report is unchanged until a user tunes one.
   *
   * @return list<array{id: string, severity: string, title: string, detail: string, location: string, dimension?: string, remediation?: array<string, mixed>}>
   *   Findings, in report order. `dimension` (meta|content|structure) + the
   *   declarative `remediation` descriptor are ADDITIVE (Phase 2, DECISIONS
   *   0133) — the five base fields stay byte-identical.
   */
  public function evaluate(NodeInterface $node, array $params = []): array;

}
