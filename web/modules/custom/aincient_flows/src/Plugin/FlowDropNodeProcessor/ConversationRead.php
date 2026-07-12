<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ExecutionContextAwareInterface;
use Drupal\flowdrop_memory\Plugin\FlowDropNodeProcessor\ScopeResolutionTrait;
use Drupal\flowdrop_memory\Service\MemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the agent conversation buffer — the loop's visible re-entry point.
 *
 * The read half of the {@see ConversationAppend} pair. On the operator graph
 * it sits between the appenders and the Reason node, and it is the TARGET of
 * the loop-back edges: a stategraph loop iteration re-executes from the
 * loopback target forward, so every iteration re-reads the buffer fresh from
 * storage — Reason then re-infers over a conversation that already contains
 * the just-appended tool results (or declined-approval results). With the
 * `entity` backend the read also survives the HITL approve → resume request
 * boundary, which is why the operator needs NO stategraph checkpointer.
 *
 * Why not upstream `memory_read`: it has no loopback-capable port, and a
 * loop-back edge must land on a port flagged `is_loopback` or the canvas
 * rejects the cycle.
 *
 * Self-heal: tool results are deduped by tool_call_id on the way out so Reason
 * never infers over a malformed buffer. The {@see ConversationAppend} writer is
 * idempotent, so new buffers can't carry a duplicate — but a buffer corrupted
 * BEFORE that guard landed would otherwise wedge a thread permanently (a
 * provider rejects an unpaired tool turn). Reading defensively recovers such a
 * thread on its next turn without losing history; it does not rewrite storage.
 *
 * @see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationAppend
 * @see \Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\Reason
 */
#[FlowDropNodeProcessor(
  id: 'aincient_conversation_read',
  label: new TranslatableMarkup('Conversation read'),
  description: 'Read the conversation buffer; loop-back edges land here so each iteration re-reads it fresh.',
  version: '0.1.0',
)]
class ConversationRead extends AbstractFlowDropNodeProcessor implements ExecutionContextAwareInterface {

  use ScopeResolutionTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly MemoryManager $memoryManager,
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
      $container->get('flowdrop_memory.manager'),
    );
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
  public function process(ParameterBagInterface $params): array {
    $scope = $params->getString('scope', 'session');
    $key = $params->getString('key', 'conversation');
    $backend = $params->getString('backend', 'entity');

    $resolvedScopeId = $this->resolveScopeId($scope);
    $messages = $this->memoryManager->get($scope, $resolvedScopeId, $key, [], $backend);
    if (!is_array($messages)) {
      $messages = [];
    }
    $messages = $this->dedupeToolResults($messages);

    return [
      'messages' => $messages,
      'count' => count($messages),
      'key' => $key,
      'scope' => $scope,
      'resolved_scope_id' => $resolvedScopeId,
    ];
  }

  /**
   * Drop tool turns whose tool_call_id already appeared earlier in the buffer.
   *
   * A tool result is uniquely keyed by tool_call_id, so a second one is always
   * a duplicate (a pre-guard wave re-fire / resume artefact). Keeping the first
   * preserves the tool_use → tool_result pairing the provider validates; the
   * extra copy is what an unpaired-tool error trips on. Non-tool turns and tool
   * turns missing an id pass through untouched.
   *
   * @param array<int, mixed> $messages
   *   The stored buffer.
   *
   * @return array<int, mixed>
   *   The buffer with duplicate tool results removed, re-indexed.
   */
  private function dedupeToolResults(array $messages): array {
    $seen = [];
    $out = [];
    foreach ($messages as $message) {
      if (is_array($message)
        && ($message['role'] ?? '') === 'tool'
        && !empty($message['tool_call_id'])) {
        $id = (string) $message['tool_call_id'];
        if (isset($seen[$id])) {
          continue;
        }
        $seen[$id] = TRUE;
      }
      $out[] = $message;
    }
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'key' => [
          'type' => 'string',
          'title' => 'Key',
          'description' => 'Memory key of the conversation buffer; must match the appenders\' key. (Sequencing is NOT this port\'s job — wire the writer\'s trigger output to this node\'s trigger input so the read runs after the write.)',
          'default' => 'conversation',
          'required' => FALSE,
        ],
        'scope' => [
          'type' => 'string',
          'title' => 'Scope',
          'description' => 'The memory scope (scope ID auto-resolves from the execution context).',
          'enum' => ['global', 'workflow', 'pipeline', 'execution', 'session'],
          'default' => 'session',
        ],
        'backend' => [
          'type' => 'string',
          'title' => 'Memory backend',
          'description' => 'Storage backend; must match the appenders.',
          'enum' => ['static', 'cached', 'entity'],
          'default' => 'entity',
        ],
        'loop_back' => [
          'type' => 'any',
          'title' => 'Loop Back',
          'description' => 'Loopback trigger — the loop body (tool results / declined approvals appended) re-enters here, so the next iteration reads the refreshed buffer.',
          'required' => FALSE,
          'is_loopback' => TRUE,
          'loopback_metadata' => [
            'edge_style' => 'dotted',
            'skip_cycle_detection' => TRUE,
            'label' => 'Loop Back',
          ],
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
        'messages' => [
          'type' => 'array',
          'description' => 'The conversation buffer ([{role, content, …}]) — wire to Reason\'s messages port.',
        ],
        'count' => [
          'type' => 'integer',
          'description' => 'Messages in the buffer.',
        ],
        'key' => [
          'type' => 'string',
          'description' => 'The memory key used.',
        ],
        'scope' => [
          'type' => 'string',
          'description' => 'The scope used.',
        ],
        'resolved_scope_id' => [
          'type' => 'string',
          'description' => 'The scope ID after auto-resolution.',
        ],
      ],
    ];
  }

}
