<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_audit\Check\CheckRegistry;
use Drupal\aincient_pages\NodeModeration;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ExecutionContextAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one deterministic page-health check as a native FlowDrop node.
 *
 * The building block of the shipped default POLICY workflows (DECISIONS 0129): a
 * policy IS a FlowDrop workflow whose contract is "params in (the target node) →
 * findings-state out". Each default workflow (`aincient_policy_seo`,
 * `aincient_policy_links`) is a single instance of this node with its `check`
 * pinned, so {@see \Drupal\aincient_audit\PolicyEvaluator} can run it via the
 * SynchronousOrchestrator with NO LLM, NO HTTP, and — because the sync path uses
 * the in-memory pipeline — NO pipeline/job/session entity in the DB.
 *
 * Inputs:
 *  - `check` (node config, pinned per workflow): which check to run — `seo` or
 *    `links`, i.e. a {@see \Drupal\aincient_audit\Check\CheckInterface} id.
 *  - `node_id` / `langcode`: the target page. Read from the workflow's initial
 *    data via {@see ExecutionContextAwareInterface} (so the workflow needs no
 *    wiring node), or overridden by an explicit param when one is connected.
 *
 * Output:
 *  - `findings`: the flat finding list the check produced — the "state as
 *    output" the evaluator reads back. Same `{id,severity,title,detail,location}`
 *    shape the checks have always emitted; the evaluator stamps `policyId`.
 *
 * Revision contract: it re-resolves the LATEST revision (the editable draft head)
 * via {@see NodeModeration::loadLatestRevision} — the exact resolver both current
 * audit callers use — so the workflow-backed report is byte-identical to the old
 * direct-call one. A misconfiguration (unknown check id, missing/gone node)
 * returns empty findings rather than throwing, so one broken policy can't abort
 * the whole evaluation.
 */
#[FlowDropNodeProcessor(
  id: "policy_check",
  label: new TranslatableMarkup("Policy check"),
  description: "Run a deterministic page-health check (seo / links) against a node and return its findings. Pure PHP — no LLM, no HTTP; safe to run synchronously and DB-free.",
  version: "0.1.0",
)]
class PolicyCheck extends AbstractFlowDropNodeProcessor implements ExecutionContextAwareInterface {

  /**
   * The bundle these checks apply to — mirrors the audit callers' guard.
   */
  private const BUNDLE = 'aincient_page';

  /**
   * The workflow's execution context (carries the initial data), when running
   * inside an orchestration; NULL if the node is executed standalone.
   */
  private ?ExecutionContextDTO $executionContext = NULL;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly CheckRegistry $checks,
    private readonly NodeModeration $moderation,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aincient_audit.check_registry'),
      $container->get('aincient_pages.moderation'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setExecutionContext(ExecutionContextDTO $context): void {
    $this->executionContext = $context;
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $check = $this->checks->get($params->getString('check', ''));
    $nodeId = $this->resolve($params, 'node_id');
    if ($check === NULL || $nodeId === '') {
      return ['findings' => []];
    }

    $langcode = $this->resolve($params, 'langcode');
    $node = $this->moderation->loadLatestRevision($nodeId, self::BUNDLE, $langcode !== '' ? $langcode : NULL);
    if ($node === NULL) {
      return ['findings' => []];
    }

    // The policy's tunable knobs (Phase 3) — the evaluator passes the entity's
    // `parameters` map through the workflow initial data; the check reads only
    // the keys it understands (empty = its built-in defaults).
    return ['findings' => $check->evaluate($node, $this->resolveArray($params, 'parameters'))];
  }

  /**
   * Resolve a target-node value: a connected/pinned param wins, else the
   * workflow's initial data (how the evaluator passes node_id/langcode).
   */
  private function resolve(ParameterBagInterface $params, string $key): string {
    if ($params->has($key)) {
      $value = $params->get($key);
      if (is_scalar($value) && (string) $value !== '') {
        return (string) $value;
      }
    }
    $fromContext = $this->executionContext?->getInitialDataValue($key);
    return is_scalar($fromContext) ? (string) $fromContext : '';
  }

  /**
   * Resolve a map-valued input (the policy `parameters`): a connected/pinned
   * param wins, else the workflow's initial data. Non-array values → empty.
   *
   * @return array<string, mixed>
   */
  private function resolveArray(ParameterBagInterface $params, string $key): array {
    if ($params->has($key)) {
      $value = $params->get($key);
      if (is_array($value)) {
        return $value;
      }
    }
    $fromContext = $this->executionContext?->getInitialDataValue($key);
    return is_array($fromContext) ? $fromContext : [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'check' => [
          'type' => 'string',
          'title' => 'Check',
          'description' => 'Which deterministic check to run: a check id such as "seo" or "links". Pinned per policy workflow.',
          'default' => '',
          'required' => TRUE,
        ],
        'node_id' => [
          'type' => 'string',
          'title' => 'Node ID',
          'description' => 'The target page node id. Usually left unset — taken from the workflow initial data.',
          'default' => '',
        ],
        'langcode' => [
          'type' => 'string',
          'title' => 'Language',
          'description' => 'The translation to check. Usually left unset — taken from the workflow initial data.',
          'default' => '',
        ],
        'parameters' => [
          'type' => 'object',
          'title' => 'Parameters',
          'description' => 'The policy\'s tunable knobs (e.g. title_min). Usually left unset — taken from the workflow initial data. The check reads only the keys it understands.',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'findings' => [
          'type' => 'array',
          'description' => 'The check findings: a flat list of {id, severity, title, detail, location}.',
        ],
      ],
    ];
  }

}
