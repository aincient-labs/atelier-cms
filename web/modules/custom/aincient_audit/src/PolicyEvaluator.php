<?php

declare(strict_types=1);

namespace Drupal\aincient_audit;

use Drupal\aincient_audit\Check\CheckInterface;
use Drupal\aincient_audit\Entity\PolicyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\flowdrop_runtime\Service\Orchestrator\SynchronousOrchestrator;
use Drupal\flowdrop_workflow\WorkflowDefinitionInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the shipped default policies against a node and returns their findings.
 *
 * The seam DECISIONS 0129 introduces: a policy IS a FlowDrop workflow (params in
 * → findings-state out), and the deterministic defaults run **synchronously and
 * DB-free**. This evaluator is what {@see AuditEngine} delegates `audit()` to; it
 * owns the per-policy execution, the report grouping, and the additive `policyId`
 * stamp. Output is byte-identical to the old direct-check report — the machinery
 * changed, not the numbers.
 *
 * For each enabled policy it:
 *  1. runs a cheap PHP selector prefilter (bundle / moderation state / language)
 *     so only matching policies ever execute a workflow;
 *  2. runs the policy's workflow via {@see SynchronousOrchestrator} with the
 *     target node + the policy's tunable `parameters` passed as initial data —
 *     the sync path uses the in-memory pipeline, so nothing is written to the DB;
 *  3. reads the `findings` state back out of the response;
 *  4. validates the finding shape (the `brand_validate_slice` posture: trust
 *     nothing that came back over the workflow boundary);
 *  5. stamps `policyId` and aggregates into the report envelope.
 *
 * As of Phase 3 (DECISIONS 0134) the policy REGISTRY is the stored
 * {@see \Drupal\aincient_audit\Entity\Policy} config entity — id → workflow +
 * per-policy selector + tunable parameters + weight (report order) + kind
 * (execution mode) + enforcement (advisory in v1). The hardcoded const map and
 * bundle-only guard this class used through Phase 2 are gone; the shipped
 * defaults now live in `config/install` with parameters equal to the checks'
 * historical constants, so the report stays byte-identical until a user tunes or
 * disables one. Group labels come from the entity.
 */
final class PolicyEvaluator {

  /**
   * The valid finding severities (worst first) — the boundary validation gate.
   */
  private const SEVERITIES = [CheckInterface::FAIL, CheckInterface::WARN, CheckInterface::PASS];

  /**
   * The valid finding dimensions (Phase 2, DECISIONS 0133) — the axis a finding
   * touches. `structure` is reserved (no shipped check emits it yet).
   */
  private const DIMENSIONS = ['meta', 'content', 'structure'];

  /**
   * The valid remediation `action` verbs (Phase 2) — a declarative field-target.
   */
  private const REMEDIATION_ACTIONS = ['edit_field', 'edit_prop', 'none'];

  public function __construct(
    private readonly SynchronousOrchestrator $orchestrator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Evaluate a node against the applicable policies.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The resolved page revision (the caller has already loaded the draft head).
   *
   * @return array{summary: array{pass: int, warn: int, fail: int, total: int}, checks: list<array{key: string, label: string, findings: list<array<string, mixed>>}>}
   *   The report body — the check groups + the pass/warn/fail summary. Merged
   *   into the envelope by {@see AuditEngine::audit()}.
   */
  public function evaluate(NodeInterface $node): array {
    $checks = [];
    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0];

    foreach ($this->policies() as $policy) {
      // PHP selector prefilter — only matching policies run a workflow.
      if (!$this->applies($policy, $node)) {
        continue;
      }
      // v1 executes deterministic policies only; `judged` is stored
      // forward-compatibly (Phase 5) but not run here.
      if ($policy->getKind() !== PolicyInterface::KIND_DETERMINISTIC) {
        continue;
      }

      $policyId = $policy->id();
      $findings = $this->run($policy, $node);
      foreach ($findings as &$finding) {
        // `policyId` is purely additive; the check `key` below is the group's.
        $finding['policyId'] = $policyId;
        $severity = $finding['severity'];
        $summary[$severity]++;
        $summary['total']++;
      }
      unset($finding);

      $checks[] = [
        'key' => $policyId,
        'label' => $policy->label(),
        'findings' => $findings,
      ];
    }

    return ['summary' => $summary, 'checks' => $checks];
  }

  /**
   * The enabled policies, in report order (lowest weight first).
   *
   * @return list<\Drupal\aincient_audit\Entity\PolicyInterface>
   */
  private function policies(): array {
    $storage = $this->entityTypeManager->getStorage('aincient_policy');
    $ids = $storage->getQuery()
      ->condition('status', TRUE)
      ->sort('weight')
      ->execute();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * The cheap PHP selector prefilter: does this policy apply to this node? Each
   * axis (bundle / moderation state / language) matches when its list is empty
   * ("any") or contains the node's value. The shipped defaults set only
   * `bundles: [aincient_page]`.
   */
  private function applies(PolicyInterface $policy, NodeInterface $node): bool {
    $selector = $policy->getSelector();
    return $this->axisMatches($selector['bundles'], $node->bundle())
      && $this->axisMatches($selector['moderation_states'], $this->moderationState($node))
      && $this->axisMatches($selector['langcodes'], $node->language()->getId());
  }

  /**
   * One selector axis: empty list = any; otherwise the value must be listed.
   *
   * @param list<string> $allowed
   *   The allowed values for this axis (empty = any).
   */
  private function axisMatches(array $allowed, ?string $value): bool {
    return $allowed === [] || ($value !== NULL && in_array($value, $allowed, TRUE));
  }

  /**
   * The node's content-moderation state, or NULL when it isn't moderated.
   */
  private function moderationState(NodeInterface $node): ?string {
    return $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
      ? (string) $node->get('moderation_state')->value
      : NULL;
  }

  /**
   * Run one policy's workflow against the node and read its findings back.
   *
   * @return list<array<string, mixed>>
   *   The validated findings (unknown-shaped entries dropped). Empty on any
   *   failure — a missing workflow or a broken policy must not abort the others.
   */
  private function run(PolicyInterface $policy, NodeInterface $node): array {
    $workflowId = $policy->getWorkflow();
    $workflow = $this->loadWorkflow($workflowId);
    if ($workflow === NULL) {
      $this->logger->warning('Policy workflow @id is missing — skipping.', ['@id' => $workflowId]);
      return [];
    }

    try {
      $response = $this->orchestrator->executeWorkflow($workflow, [
        'node_id' => (string) $node->id(),
        'langcode' => $node->language()->getId(),
        // The tunable knobs; the policy_check node forwards them to the check.
        'parameters' => $policy->getParameters(),
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Policy workflow @id failed: @msg', ['@id' => $workflowId, '@msg' => $e->getMessage()]);
      return [];
    }

    return $this->validate($this->extractFindings($response));
  }

  /**
   * Load a policy workflow config entity, or NULL if it doesn't exist.
   */
  private function loadWorkflow(string $workflowId): ?WorkflowDefinitionInterface {
    $workflow = $this->entityTypeManager->getStorage('flowdrop_workflow')->load($workflowId);
    return $workflow instanceof WorkflowDefinitionInterface ? $workflow : NULL;
  }

  /**
   * Pull the `findings` state out of an orchestration response. The workflow
   * emits it from its single policy_check node; we find that node's output
   * without hard-coding the node id (any node exposing `findings` wins).
   *
   * @return mixed
   *   The raw findings value (validated by {@see self::validate}).
   */
  private function extractFindings(object $response): mixed {
    // @phpstan-ignore-next-line — OrchestrationResponse::getResults().
    $results = method_exists($response, 'getResults') ? $response->getResults() : [];
    foreach ((array) $results as $result) {
      $output = $this->outputArray($result);
      if (array_key_exists('findings', $output)) {
        return $output['findings'];
      }
    }
    return [];
  }

  /**
   * Normalise a single node result to its output array, tolerating either a
   * result DTO (`->getOutput()->toArray()`) or a already-flat array.
   *
   * @return array<string, mixed>
   */
  private function outputArray(mixed $result): array {
    if (is_array($result)) {
      return $result;
    }
    if (is_object($result) && method_exists($result, 'getOutput')) {
      $output = $result->getOutput();
      if (is_object($output) && method_exists($output, 'toArray')) {
        return $output->toArray();
      }
      if (is_array($output)) {
        return $output;
      }
    }
    return [];
  }

  /**
   * Validate findings that crossed the workflow boundary: keep only well-formed
   * entries (the five string fields + a known severity), coercing to strings.
   *
   * The Phase-2 additive fields — `dimension` + the declarative `remediation`
   * descriptor — are validate-and-PASS-THROUGH: a valid one is copied verbatim
   * (so the report stays byte-identical to the check's own output — the
   * {@see PolicyEvaluatorTest} guarantee), a malformed one is dropped entirely.
   * They are appended after `location`, matching the {@see FindingTrait::finding}
   * key order.
   *
   * @return list<array<string, mixed>>
   */
  private function validate(mixed $findings): array {
    if (!is_array($findings)) {
      return [];
    }
    $valid = [];
    foreach ($findings as $finding) {
      if (!is_array($finding)) {
        continue;
      }
      $severity = $finding['severity'] ?? NULL;
      if (!in_array($severity, self::SEVERITIES, TRUE)) {
        continue;
      }
      $entry = [
        'id' => (string) ($finding['id'] ?? ''),
        'severity' => $severity,
        'title' => (string) ($finding['title'] ?? ''),
        'detail' => (string) ($finding['detail'] ?? ''),
        'location' => (string) ($finding['location'] ?? ''),
      ];
      $dimension = $finding['dimension'] ?? NULL;
      if (in_array($dimension, self::DIMENSIONS, TRUE)) {
        $entry['dimension'] = $dimension;
      }
      if ($this->validRemediation($finding['remediation'] ?? NULL)) {
        $entry['remediation'] = $finding['remediation'];
      }
      $valid[] = $entry;
    }
    return $valid;
  }

  /**
   * Is this a well-formed remediation descriptor (Phase 2)? A known `action`, a
   * boolean `aiFixable`, and — per action — the field-target keys it needs. We
   * validate the SHAPE and pass the value through unchanged (never rebuild), so
   * a well-formed descriptor stays byte-identical; anything malformed is dropped.
   */
  private function validRemediation(mixed $remediation): bool {
    if (!is_array($remediation)) {
      return FALSE;
    }
    if (!in_array($remediation['action'] ?? NULL, self::REMEDIATION_ACTIONS, TRUE)) {
      return FALSE;
    }
    if (!is_bool($remediation['aiFixable'] ?? NULL)) {
      return FALSE;
    }
    return match ($remediation['action']) {
      // A meta/field edit names the target field.
      'edit_field' => is_string($remediation['field'] ?? NULL),
      // A content/prop edit carries a target locator.
      'edit_prop' => is_array($remediation['target'] ?? NULL),
      // A bare marker (no structured target) — nothing more to require.
      default => TRUE,
    };
  }

}
