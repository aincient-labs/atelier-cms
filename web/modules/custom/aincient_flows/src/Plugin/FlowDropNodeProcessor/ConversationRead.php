<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_flows\Conversation\BufferNormalizer;
use Drupal\aincient_flows\Conversation\Scratchpad;
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
 * Reads the agent's context — the loop's visible re-entry point.
 *
 * Returns TWO stores concatenated, transcript first (DECISIONS 0379):
 *
 * - the durable transcript at this node's configured `scope`/`key` — prose
 *   only, what the user said and what the agent said;
 * - this turn's scratchpad at {@see Scratchpad}'s fixed address — the turn's
 *   `tool_calls` and tool results, which vanish with the turn.
 *
 * The configured params still mean exactly what they always meant: the store
 * this node reads. The scratchpad is not configurable because it is not a
 * choice — it is the current turn, resolved in code.
 *
 * Why the split: a state snapshot in a durable message outlives the state it
 * described. `update_section` on a repeatable prop must resend the whole array,
 * so the old single session-scoped buffer accumulated full page-state
 * snapshots; a later turn then carried two contradicting copies of the same
 * content and the model anchored on the stale one, silently reverting user
 * edits (cms#29). Live state is injected exactly once, in the system prompt,
 * rebuilt every turn — so no conversation message may carry a snapshot.
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
 * Self-heal: the buffer is run through {@see BufferNormalizer::forInference()}
 * on the way out, so Reason never infers over a malformed buffer — duplicate
 * tool results dropped, tool-use blocks paired and terminated, consecutive user
 * turns merged. The {@see ConversationAppend} writer maintains the same
 * invariant on the way in, so new buffers arrive clean; but a buffer corrupted
 * before those guards landed would otherwise wedge a thread PERMANENTLY (see
 * DECISIONS 0269 — one transient 503 was enough). Reading defensively recovers
 * such a thread on its next turn without losing history; it does not rewrite
 * storage. An open tool-use block at the tail is preserved on purpose: mid-loop,
 * that is exactly the state Reason continues from.
 *
 * @see \Drupal\aincient_flows\Conversation\BufferNormalizer
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
    $scope = (string) ($params['scope'] ?? 'session');
    $key = (string) ($params['key'] ?? 'conversation');
    if (Scratchpad::isScratchpadAddress($scope, $key)) {
      return ValidationResult::error(
        'key',
        sprintf(
          'These are the per-turn scratchpad\'s own coordinates (scope "%s", key "%s"), which this node already reads on top of the transcript. Point it at the durable transcript instead — normally scope "session", key "conversation".',
          Scratchpad::SCOPE,
          Scratchpad::KEY,
        ),
        'scratchpad_address_as_transcript',
      );
    }
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
    $transcript = $this->readStore($scope, $resolvedScopeId, $key, $backend);
    $scratchpad = $this->readScratchpad($scope, $key, $backend);

    // Transcript first, scratchpad last, and never interleaved: the current
    // turn's assistant `tool_calls` message and its tool results have to stay
    // adjacent or the provider rejects the sequence. Appending the whole
    // scratchpad after the whole transcript keeps that pairing intact for free,
    // because the scratchpad is stored in the order the turn produced it.
    $messages = BufferNormalizer::forInference([...$transcript, ...$scratchpad]);

    return [
      'messages' => $messages,
      'count' => count($messages),
      'key' => $key,
      'scope' => $scope,
      'resolved_scope_id' => $resolvedScopeId,
      'scratchpad_count' => count($scratchpad),
    ];
  }

  /**
   * Reads one memory store, tolerating a non-array value.
   *
   * @return array
   *   The stored messages, or an empty list.
   */
  private function readStore(string $scope, string $scopeId, string $key, string $backend): array {
    $messages = $this->memoryManager->get($scope, $scopeId, $key, [], $backend);
    return is_array($messages) ? $messages : [];
  }

  /**
   * This turn's tool traffic (tier B), or nothing.
   *
   * The ADDRESS is not configurable, by design (DECISIONS 0379): the
   * transcript's is a choice a workflow author makes, the scratchpad's is an
   * invariant of the turn, resolved in code from one shared constant — see
   * {@see Scratchpad}. The BACKEND is not part of the address, so it follows
   * this node's configuration: a workflow storing its transcript in `static`
   * writes its scratchpad there too, and a read that reached past it into
   * `entity` would look in a store nothing wrote to. Production uses `entity`
   * throughout ({@see Scratchpad::BACKEND}) so a paused turn survives resume.
   *
   * A workflow that never appends tool traffic simply reads an empty store.
   *
   * @return array
   *   The turn's tool messages in production order, or an empty list.
   */
  private function readScratchpad(string $transcriptScope, string $transcriptKey, string $backend): array {
    // A read node already pointed AT the scratchpad would otherwise concatenate
    // the turn's tool traffic onto itself. validateParams() refuses that
    // config, but a hand-edited workflow can reach process() unvalidated.
    if (Scratchpad::isScratchpadAddress($transcriptScope, $transcriptKey)) {
      return [];
    }
    return $this->readStore(
      Scratchpad::SCOPE,
      $this->resolveScopeId(Scratchpad::SCOPE),
      Scratchpad::KEY,
      $backend,
    );
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
          'description' => 'Memory key of the durable TRANSCRIPT (prose); must match the prose appenders\' key. This node also reads the current turn\'s tool traffic from the per-turn scratchpad automatically — do not point this at the scratchpad. (Sequencing is NOT this port\'s job — wire the writer\'s trigger output to this node\'s trigger input so the read runs after the write.)',
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
          'description' => 'Transcript + this turn\'s tool traffic ([{role, content, …}]) — wire to Reason\'s messages port.',
        ],
        'count' => [
          'type' => 'integer',
          'description' => 'Messages handed to Reason (both tiers, after normalisation).',
        ],
        'scratchpad_count' => [
          'type' => 'integer',
          'description' => 'How many of them came from this turn\'s scratchpad. 0 on the first pass of a turn.',
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
