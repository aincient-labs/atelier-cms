<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\AincientReasonerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\Constants\ReservedName;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\Reason\ReasonMessage;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ToolPairing;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ToolsAwareInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ToolsAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Atelier's own agent-step reasoning node.
 *
 * A drop-in replacement for the engine's `flowdrop_node_processor:reason` — same
 * inputs, same four load-bearing outputs, so every agent that places the
 * `aincient_reason` node type is served unchanged — but returning three more
 * ports the engine's fixed {@see \Drupal\flowdrop\DTO\Reason\ReasonResult} DTO
 * cannot carry: `raw_result`, `codec` and `error_detail`. Owning the node is
 * what lets the trust-the-wire codec (Phase 4) and the structured error contract
 * (Phase 3) ship entirely in our code with no upstream FlowDrop change
 * (ADR 0365). The engine `Reason` processor is `@internal`; the supported
 * extension path is a fresh {@see ToolsAwareInterface} node, not a fork of it —
 * tool injection is interface-driven (`NodeRuntimeService` calls `setTools()` on
 * any `ToolsAwareInterface` node), and the reasoning contract
 * ({@see AincientReasonerInterface}, which extends FlowDrop's `@api`
 * `ChatReasonerInterface`) is ours.
 *
 * Everything the engine node does that is NOT about the result shape is
 * preserved verbatim below, each with the rationale it was given upstream:
 * ToolPairing healing of a crashed-mid-loop buffer, the trailing-assistant
 * no-op that defends against an interrupt-resume double-fire, and populating the
 * model/role dropdowns from the backend's advertised choices.
 *
 * EXPOSURE = TOPOLOGY: the model is shown exactly the tools wired into this node
 * (`getTools()->getDefinitions()`), so there is no PHP allow-list and the tool
 * manifest cannot drift from the wired capabilities' real signatures.
 *
 * @see \Drupal\aincient_core\Inference\AincientReasonerInterface
 * @see \Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\Reason
 */
#[FlowDropNodeProcessor(
  id: "aincient_reason",
  label: new TranslatableMarkup("Reason (agent step)"),
  description: "One tool-aware LLM inference: emits the next tool_calls or a final answer (executes nothing), plus the raw body and detected codec.",
  version: "1.0.0",
)]
class AincientReason extends AbstractFlowDropNodeProcessor implements ToolsAwareInterface {
  use ToolsAwareTrait;

  /**
   * Constructs an AincientReason plugin instance.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\aincient_core\Inference\AincientReasonerInterface $reasoner
   *   Atelier's reasoning backend — the concrete service, not the rebound
   *   `flowdrop.chat_reasoner` seam, because this node needs `reasonRich()`.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected AincientReasonerInterface $reasoner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('aincient_core.inference.reasoner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    // The conversation arrives on the `messages` data port — typically a
    // memory_read of the visible conversation buffer (append nodes are the
    // writers). The loop re-reads the buffer on every loop-back iteration, so
    // this node carries NO state of its own: by the time it runs, the buffer
    // already ends with the current user turn (and, mid-loop, tool results).
    // Topology keeps the buffer well-formed on every NORMAL path; ToolPairing
    // additionally heals tool pairing so a turn that crashed mid-loop — leaving
    // a tool_calls turn whose result never landed — can't wedge every future
    // turn on the thread (the dangling call is answered with a synthetic
    // "interrupted" result so the model can re-plan). Reuse the engine DTO
    // rather than fork it, so upstream healing fixes keep flowing in.
    $messages = [];
    foreach ($params->getArray('messages', []) as $raw) {
      if (is_array($raw)) {
        $messages[] = ReasonMessage::fromArray($raw);
      }
    }
    $messages = ToolPairing::heal($messages);
    if ($messages === []) {
      throw new \RuntimeException('Reason node has no messages to reason over');
    }

    // Provider invariant + loop idempotency: a chat request must NOT end on an
    // assistant turn — providers treat a trailing assistant message as a
    // prefill ("the conversation must end with a user message"). A legitimate
    // reason step always runs after a fresh user turn (ends with `user`) or a
    // tool result on the loop-back (ends with `tool`), so a buffer that — after
    // healing — still ends with a COMPLETED assistant turn means there is
    // nothing new to reason over: the loop already produced its answer and this
    // is a spurious re-entry (e.g. an interrupt-resume re-firing the loop,
    // which otherwise both crashes the turn here AND re-issues the previous
    // tool call — a duplicate side effect). Make it a no-op: terminate, append
    // nothing.
    if (end($messages)->getRole() === 'assistant') {
      return [
        'tool_calls' => [],
        'text' => '',
        'has_tool_calls' => FALSE,
        'assistant_message' => [],
        'raw_result' => [],
        'codec' => '',
        'error_detail' => NULL,
      ];
    }

    // Tools come from the native tool_availability wiring, not a data port:
    // ToolProjector resolves this node's wired tool nodes and the runtime
    // injects them via setTools() before process() runs.
    $request = new ReasonRequest(
      $messages,
      $this->getTools()->getDefinitions(),
      (string) $params->get('model', ''),
      (string) $params->get('operation_type', '') ?: 'chat',
      (float) $params->get('temperature', 0),
      (int) $params->get('maxTokens', 1024),
      (string) $params->get('systemPrompt', ''),
    );

    // STOP-AND-REPORT. A provider failure is not a crash to be re-thrown into
    // the engine's FAILED path (a red node with a wrapper sentence, and, off the
    // console, silence): it is an ordinary outcome of this step that the run
    // should end on, with the failure named as its reply. ProviderCall has
    // already retried what was transient and classified the rest, so by here the
    // decision is made — surface it, don't retry. Catching it turns the step into
    // a normal COMPLETED node that carries the failure on `error_detail` (the
    // struct a console card is driven from and an agent branch can read) and the
    // reader-facing sentence on `text` (so every surface — chat, playground,
    // session view — ends with the failure as the answer, not an empty bubble).
    // `has_tool_calls` FALSE steers the loop's gateway to its answer branch, so
    // the run terminates here rather than looping. `assistant_message` stays
    // empty on purpose: a failed turn must NOT be written into the conversation
    // buffer (the append node drops an empty message), or the next turn would
    // carry a phantom assistant turn that never happened.
    //
    // ONLY AiProviderFailure is caught. A local misconfiguration
    // (ProviderConfigurationException, re-thrown as-is by the reasoner) and any
    // genuine bug in mapping/unpacking are NOT provider outcomes — they keep
    // propagating to the FAILED path, where "connect a provider" or a real stack
    // trace is the right surface.
    try {
      $result = $this->reasoner->reasonRich($request);
    }
    catch (AiProviderFailure $e) {
      return [
        'tool_calls' => [],
        'text' => $e->getMessage(),
        'has_tool_calls' => FALSE,
        'assistant_message' => [],
        'raw_result' => [],
        'codec' => '',
        'error_detail' => $e->toDetail(),
      ];
    }

    return [
      'tool_calls' => $result->getToolCalls(),
      'text' => $result->getText(),
      'has_tool_calls' => $result->hasToolCalls(),
      // The assistant turn — wire it to a conversation-append node so it lands
      // in the visible buffer (this node persists nothing itself).
      'assistant_message' => $result->getAssistantMessage(),
      // The extra account only our result carries. `raw_result` is what Phase 4
      // fingerprints; `codec` is the detected dialect (unset until detection
      // runs); `error_detail` is the structured failure — NULL here on the
      // success path, populated only on the catch above.
      'raw_result' => $result->getRawResult(),
      'codec' => $result->getCodec(),
      'error_detail' => $result->getErrorDetail(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    // Populate the model / role fields from the backend's advertised choices,
    // so this node needs no backend service of its own beyond the reasoner.
    $choices = $this->reasoner->getModelChoices('chat');
    $models = $choices->getModels();
    $default_model = $choices->getDefaultModel();
    if (($default_model === '' || $default_model === NULL) && $models !== []) {
      $default_model = $models[0];
    }
    $operation_type_options = $choices->getOperationTypeOptions();
    $operation_type_ids = array_column($operation_type_options, 'value');

    return [
      'type' => 'object',
      'properties' => [
        'messages' => [
          'type' => 'array',
          'title' => 'Messages',
          'description' => 'The conversation to reason over (wire a memory_read of the conversation buffer): items with {role, content}; tool results add tool_call_id, assistant turns may carry tool_calls.',
          'default' => [],
          'required' => FALSE,
        ],
        // The tool input port (ReservedName::PORT_TOOL). This node is
        // ToolsAware, but a tool handle only renders if the port is DECLARED:
        // name = the reserved 'tool' constant (drives runtime classification
        // via the {nodeId}-input-tool handle), dataType = 'tool' (drives the
        // editor's amber handle + connection validation). A tool node wired in
        // here (tool.output → reason.input-tool) is exposed to the model —
        // incoming tool edges are the allow-list.
        ReservedName::PORT_TOOL => [
          'type' => 'tool',
          'title' => 'Tools',
          'description' => 'Tools wired to this node. Wiring a tool here exposes it; the edges are the allow-list.',
          'required' => FALSE,
        ],
        'loop_back' => [
          'type' => 'any',
          'title' => 'Loop Back',
          'description' => 'Loopback trigger — connect the loop body output here to re-run reasoning.',
          'required' => FALSE,
          'is_loopback' => TRUE,
          'loopback_metadata' => [
            'edge_style' => 'dotted',
            'skip_cycle_detection' => TRUE,
            'label' => 'Loop Back',
          ],
        ],
        'operation_type' => [
          'type' => 'string',
          'title' => 'Model Role',
          'description' => "Which model role / operation type resolves this node's model when no explicit model is set.",
          'default' => 'chat',
          'enum' => $operation_type_ids,
          'options' => $operation_type_options,
        ],
        'model' => [
          'type' => 'string',
          'title' => 'Model',
          'description' => 'Optional explicit model override (wins over the role). Defaults to the role-resolved model.',
          'default' => $default_model,
          // The empty string is the "no override — resolve via the Model Role"
          // sentinel. It must be a valid enum member or ParameterResolver
          // rejects the stored '' before the node runs.
          'enum' => array_merge([''], $models),
        ],
        'temperature' => [
          'type' => 'number',
          'title' => 'Temperature',
          'description' => 'Sampling temperature (0 = deterministic, best for routing).',
          'default' => 0,
          'minimum' => 0,
          'maximum' => 2,
        ],
        'maxTokens' => [
          'type' => 'integer',
          'title' => 'Max Tokens',
          'description' => 'Maximum tokens to generate.',
          'default' => 1024,
          'minimum' => 1,
        ],
        'systemPrompt' => [
          'type' => 'string',
          'title' => 'System Prompt',
          'description' => 'Instructions for the agent (its role, when to use which tool, when to stop).',
          'default' => '',
          'format' => 'multiline',
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
        'tool_calls' => [
          'type' => 'array',
          'description' => 'Tool calls the model requested: [{name, args, tool_call_id}]. Empty when it answered directly.',
        ],
        'text' => [
          'type' => 'string',
          'description' => 'The assistant prose (a final answer, or commentary alongside tool calls).',
        ],
        'has_tool_calls' => [
          'type' => 'boolean',
          'description' => 'TRUE if the model wants to call a tool — branch the loop on this.',
        ],
        'assistant_message' => [
          'type' => 'json',
          'description' => 'The assistant turn ({role, content, tool_calls}) — wire to a conversation-append node to persist it.',
        ],
        'raw_result' => [
          'type' => 'json',
          'description' => 'The un-parsed provider response body, for codec fingerprinting. Empty when it could not be captured.',
        ],
        'codec' => [
          'type' => 'string',
          'description' => 'The tool-call dialect detected on the wire (empirical, not the configured model id). Empty until detection runs.',
        ],
        'error_detail' => [
          'type' => 'json',
          'description' => 'Structured provider failure {kind, provider, model, message, retryable}, or null on success.',
        ],
      ],
    ];
  }

}
