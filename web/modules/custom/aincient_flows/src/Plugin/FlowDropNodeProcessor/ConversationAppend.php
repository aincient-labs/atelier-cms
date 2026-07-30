<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_flows\Conversation\BufferNormalizer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ExecutionContextAwareInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\HasSideEffectsInterface;
use Drupal\flowdrop_memory\Plugin\FlowDropNodeProcessor\ScopeResolutionTrait;
use Drupal\flowdrop_memory\Service\MemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Appends AGENT conversation messages to a memory-backed buffer — visibly.
 *
 * The operator loop's conversation lives in a flowdrop_memory buffer
 * (scope=session, backend=entity by default) instead of a hidden stategraph
 * state channel: every mutation of the conversation is a node execution on
 * the pipeline trail, and the stored conversation is a queryable MemoryRecord
 * entity. This node is the single writer; reads go through the upstream
 * `memory_read` node.
 *
 * Why not upstream's `conversation_buffer`: an agent conversation needs FULL
 * message objects — assistant turns carry `tool_calls`, tool turns carry a
 * `tool_call_id` (providers reject unpaired tool-use) — and the upstream node
 * only stores {role ∈ user|assistant|system, content}. Upstream ask #10 is to
 * extend it; this node retires when that lands.
 *
 * One placed instance per writer in the loop, each using ONE input style:
 * - `content` (+ configured role)  — chat_input.message → the user turn.
 * - `message`                      — reason.assistant_message (incl. tool_calls).
 * - `messages`                     — invoke.tool_messages (tool-role results).
 * - `declined_tool_calls`          — reason.tool_calls on the guardrail's
 *   decline branch: synthesizes one "Not executed: the user declined…"
 *   tool-role message per call, so the buffer stays well-formed BY TOPOLOGY
 *   (no in-node CONTENT healing anywhere).
 *
 * Message CONTENT is still trusted to topology, but the writer is idempotent
 * on tool results: a tool_call_id already present in the buffer is never
 * appended twice, so a wave-scheduler re-fire or a pipeline resume on the
 * loop_back cycle cannot duplicate a tool turn (which a provider would reject).
 *
 * STRUCTURE, however, is now the writer's job. Topology alone guarantees a
 * well-formed buffer only when the loop RUNS TO COMPLETION; a provider blip
 * mid-loop skips the closing assistant turn and leaves the tail an open
 * tool-use block, which the next user turn then makes permanently unsendable
 * (DECISIONS 0269). So an append that starts a NEW turn closes an open block
 * first, via {@see BufferNormalizer::closeOpenTurn()}. Mid-loop appends leave
 * the tail alone — open is the correct state there.
 *
 * GATING THE LOOP. Idempotency alone only makes the re-fire harmless to the
 * BUFFER — the re-fired append still triggered the loop_back edge, spawning an
 * agent step whose conversation read raced the current wave's writes. It read a
 * buffer that was missing the tool call the agent had just made, concluded
 * nothing had happened, and re-did the work: a second complete page, sections
 * and all. So this node publishes `appended_any` (FALSE when it wrote nothing),
 * and every agent loop wires that port into a Boolean Gateway whose True branch
 * is the loop_back edge. An append that appended nothing is a dead end: the
 * loop only advances on real progress.
 *
 * The termination rule is a NODE, deliberately. It used to be
 * `condition: state.data.conversation_stalled != true` on the loop_back edge —
 * correct, but invisible on the canvas, absent from the editor, and honoured by
 * exactly one orchestrator. FlowDrop 2.x gates loop re-entry on
 * `active_branches` (StateGraphOrchestrator::isLoopbackBranchActive), which
 * lets the rule be a gateway anyone can see and rewire.
 *
 * The sliding window (`max_messages`) trims only at user-turn boundaries so a
 * tool_use is never split from its tool_result.
 *
 * @see \Drupal\flowdrop_memory\Plugin\FlowDropNodeProcessor\ConversationBuffer
 * @see \Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\Reason
 * @see \Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\ToolInvoke
 */
#[FlowDropNodeProcessor(
  id: 'aincient_conversation_append',
  label: new TranslatableMarkup('Conversation append'),
  description: 'Append full agent messages (incl. tool calls/results) to the memory-backed conversation buffer.',
  version: '0.1.0',
)]
class ConversationAppend extends AbstractFlowDropNodeProcessor implements ExecutionContextAwareInterface, HasSideEffectsInterface {

  use ScopeResolutionTrait;

  /**
   * Roles a stored conversation message may carry.
   */
  private const ALLOWED_ROLES = ['user', 'assistant', 'system', 'tool'];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly MemoryManager $memoryManager,
    private readonly TimeInterface $time,
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
      $container->get('datetime.time'),
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
    $maxMessages = $params->getInt('max_messages', 0);

    $append = [];
    // Input styles in deterministic order; a placed instance normally wires
    // exactly one of them.
    $content = $params->get('content');
    if (is_string($content) && trim($content) !== '') {
      $append[] = [
        'role' => $params->getString('role', 'user'),
        'content' => $content,
      ];
    }
    $message = $params->get('message');
    if (is_array($message)) {
      $append[] = $message;
    }
    foreach ($params->getArray('messages', []) as $m) {
      if (is_array($m)) {
        $append[] = $m;
      }
    }
    foreach ($params->getArray('declined_tool_calls', []) as $call) {
      if (is_array($call) && !empty($call['tool_call_id'])) {
        $name = (string) ($call['name'] ?? 'the tool');
        $append[] = [
          'role' => 'tool',
          'content' => "Not executed: the user declined the approval for {$name}. Do not retry; acknowledge the refusal.",
          'tool_call_id' => (string) $call['tool_call_id'],
        ];
      }
    }
    $append = array_values(array_filter(array_map($this->normalizeMessage(...), $append)));

    $resolvedScopeId = $this->resolveScopeId($scope);
    $messages = $this->memoryManager->get($scope, $resolvedScopeId, $key, [], $backend);
    if (!is_array($messages)) {
      $messages = [];
    }

    // Idempotency guard (NOT content healing): a tool result is uniquely keyed
    // by its tool_call_id — there can only ever be one per call. The operator
    // loop runs on a wave-scheduled stategraph with a loop_back cycle, so a
    // resume or a re-evaluated wave can re-fire this node against the SAME
    // upstream tool_messages, which would otherwise append a byte-identical
    // tool turn twice. A provider then rejects the buffer on the next inference
    // ("tool_call_id … not found in tool_calls of previous message", because
    // the duplicate's predecessor is a tool turn, not the assistant tool_use).
    // Drop any tool-role message whose tool_call_id is already buffered.
    $seenToolCallIds = [];
    foreach ($messages as $existing) {
      if (($existing['role'] ?? '') === 'tool' && !empty($existing['tool_call_id'])) {
        $seenToolCallIds[(string) $existing['tool_call_id']] = TRUE;
      }
    }
    $append = array_values(array_filter($append, static function (array $m) use (&$seenToolCallIds): bool {
      if (($m['role'] ?? '') !== 'tool') {
        return TRUE;
      }
      $id = (string) ($m['tool_call_id'] ?? '');
      if ($id === '' || isset($seenToolCallIds[$id])) {
        return FALSE;
      }
      $seenToolCallIds[$id] = TRUE;
      return TRUE;
    }));

    // THE 0269 INVARIANT: never persist an unterminated tool loop. A new
    // user/system turn means the previous turn is over — but if the agent loop
    // died mid-flight (a transient provider 503 between the tool results and
    // the closing assistant turn), the buffer's tail is still an OPEN tool-use
    // block. Appending the user's next message straight onto it produces a
    // shape providers reject, and since the failure also prevents the closing
    // turn, every retry appends another orphan user message: the thread is
    // bricked for good and gets worse with each attempt. Close the open block
    // first, so the damage cannot be written down. (Only on a new turn — a
    // mid-loop append of the assistant turn or its tool results is exactly what
    // legitimately leaves the tail open.)
    if ($this->startsNewTurn($append)) {
      $messages = BufferNormalizer::closeOpenTurn($messages);
    }

    $messages = array_merge($messages, $append);
    $messages = $this->trimToWindow($messages, $maxMessages);

    if ($append !== []) {
      $this->memoryManager->set($scope, $resolvedScopeId, $key, $messages, NULL, $backend);
    }

    return [
      'messages' => $messages,
      'count' => count($messages),
      'appended' => count($append),
      'key' => $key,
      'scope' => $scope,
      'resolved_scope_id' => $resolvedScopeId,
      // "Did this write anything" as a first-class boolean OUTPUT PORT, so the
      // loop's termination rule can be a Boolean Gateway on the canvas rather
      // than an expression hidden in an edge. See the class docblock
      // ("Gating the loop"). `appended` carries the same signal as a count;
      // this port exists so the wire into a boolean gateway input is typed.
      'appended_any' => $append !== [],
    ];
  }

  /**
   * Whether this append opens a new conversation turn.
   *
   * A user (or system) message is the start of a turn; an assistant message and
   * tool results belong to the turn already in flight. Only the former means
   * the previous turn must be closed first — see the 0269 note in process().
   *
   * @param array<int, array<string, mixed>> $append
   *   The messages about to be appended.
   *
   * @return bool
   *   TRUE when any of them starts a new turn.
   */
  private function startsNewTurn(array $append): bool {
    foreach ($append as $message) {
      if (in_array($message['role'] ?? '', ['user', 'system'], TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Normalize a message to the stored shape, or NULL to drop it.
   *
   * Stored keys: role, content, tool_calls (assistant only), tool_call_id
   * (tool only), timestamp. An assistant turn with tool_calls may have empty
   * content (the model chose tools silently) — that is valid and required for
   * pairing; any other empty-content message is dropped.
   */
  private function normalizeMessage(array $message): ?array {
    $role = (string) ($message['role'] ?? '');
    if (!in_array($role, self::ALLOWED_ROLES, TRUE)) {
      return NULL;
    }
    $out = [
      'role' => $role,
      'content' => (string) ($message['content'] ?? ''),
      'timestamp' => $this->time->getRequestTime(),
    ];
    if ($role === 'assistant' && !empty($message['tool_calls']) && is_array($message['tool_calls'])) {
      $out['tool_calls'] = array_values(array_filter($message['tool_calls'], 'is_array'));
    }
    if ($role === 'tool') {
      $id = (string) ($message['tool_call_id'] ?? '');
      if ($id === '') {
        return NULL;
      }
      $out['tool_call_id'] = $id;
    }
    if ($out['content'] === '' && empty($out['tool_calls'])) {
      return NULL;
    }
    return $out;
  }

  /**
   * Trim the buffer to the window without splitting tool-use pairs.
   *
   * Cuts only at a user-turn boundary at or after the naive window start, so
   * an assistant tool_calls turn is never separated from its tool results.
   * If no user turn exists at/after the boundary the buffer is kept whole.
   *
   * @param array<int, array<string, mixed>> $messages
   *   The buffer.
   * @param int $max
   *   The window size; 0 disables trimming.
   *
   * @return array<int, array<string, mixed>>
   *   The (possibly trimmed) buffer.
   */
  private function trimToWindow(array $messages, int $max): array {
    $count = count($messages);
    if ($max <= 0 || $count <= $max) {
      return $messages;
    }
    for ($i = $count - $max; $i < $count; $i++) {
      if (($messages[$i]['role'] ?? '') === 'user') {
        return array_values(array_slice($messages, $i));
      }
    }
    return $messages;
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'content' => [
          'type' => 'string',
          'title' => 'Content',
          'description' => 'Plain text appended as one message with the configured role (wire chat_input.message here).',
          'required' => FALSE,
        ],
        'role' => [
          'type' => 'string',
          'title' => 'Role',
          'description' => 'Role for the plain-text `content` input.',
          'enum' => ['user', 'system'],
          'default' => 'user',
        ],
        'message' => [
          'type' => 'object',
          'title' => 'Message',
          'description' => 'One full message object, e.g. a Reason node\'s assistant_message (tool_calls preserved).',
          'required' => FALSE,
        ],
        'messages' => [
          'type' => 'array',
          'title' => 'Messages',
          'description' => 'Full message objects, e.g. an Invoke node\'s tool_messages.',
          'required' => FALSE,
        ],
        'declined_tool_calls' => [
          'type' => 'array',
          'title' => 'Declined tool calls',
          'description' => 'A Reason node\'s tool_calls on the guardrail\'s DECLINE branch: each becomes a "Not executed: declined" tool result, keeping the buffer well-formed.',
          'required' => FALSE,
        ],
        'trigger' => [
          'type' => 'any',
          'title' => 'Trigger',
          'description' => 'Run gate — wire a branch output (e.g. a confirmation\'s Decline) here so this append only runs on that branch.',
          'required' => FALSE,
        ],
        'scope' => [
          'type' => 'string',
          'title' => 'Scope',
          'description' => 'The memory scope (scope ID auto-resolves from the execution context).',
          'enum' => ['global', 'workflow', 'pipeline', 'execution', 'session'],
          'default' => 'session',
        ],
        'key' => [
          'type' => 'string',
          'title' => 'Key',
          'description' => 'Memory key of the conversation buffer.',
          'default' => 'conversation',
        ],
        'backend' => [
          'type' => 'string',
          'title' => 'Memory backend',
          'description' => 'Storage backend. `entity` persists across requests/turns (a queryable MemoryRecord).',
          'enum' => ['static', 'cached', 'entity'],
          'default' => 'entity',
        ],
        'max_messages' => [
          'type' => 'integer',
          'title' => 'Max messages',
          'description' => 'Sliding window size; trims only at user-turn boundaries. 0 = unlimited.',
          'default' => 0,
          'minimum' => 0,
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
          'description' => 'The full conversation buffer after the append.',
        ],
        'count' => [
          'type' => 'integer',
          'description' => 'Messages in the buffer.',
        ],
        'appended' => [
          'type' => 'integer',
          'description' => 'Messages appended by this execution.',
        ],
        'appended_any' => [
          'type' => 'boolean',
          'description' => 'Whether this execution wrote anything. Wire it into a Boolean Gateway to terminate an agent loop that stopped making progress.',
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
          'description' => 'The scope ID after auto-resolution (the session id on the console path).',
        ],
      ],
    ];
  }

}
