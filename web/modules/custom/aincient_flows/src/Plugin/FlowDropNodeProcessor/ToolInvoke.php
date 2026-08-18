<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\Constants\ReservedName;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\Tool\ToolResultInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ExecutionContextAwareInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\HasSideEffectsInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ToolsAwareInterface;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\ToolsAwareTrait;
use Drupal\flowdrop_memory\Plugin\FlowDropNodeProcessor\ScopeResolutionTrait;
use Drupal\flowdrop_memory\Service\MemoryManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs an agent's chosen tool calls — EXACTLY ONCE per tool_call_id.
 *
 * Behaviourally the upstream `flowdrop_node_processor:tool_invoke` (same ports,
 * same `tool_availability` allow-list, same tool-role output), plus a run-scoped
 * EXECUTION LEDGER — the sibling of the idempotency guard in
 * the retired fork ConversationAppend, moved one step earlier so a repeat never reaches
 * the tool at all.
 *
 * WHY. The stategraph schedules a node once per predecessor that routes to it,
 * and de-dupes only against jobs that have not STARTED yet (see
 * StateGraphOrchestrator::ensureJobExistsForNode). The agent loop's Invoke node
 * is a fan-in — the Reason node feeds its `tool_calls`, and the confirmation
 * router feeds its `trigger` — so when the router evaluates its edges after the
 * Reason-spawned job has completed, the node is CLONED and runs the identical
 * batch a second time. (A clone also
 * inherits only its spawning parent as a dependency, so it can run before the
 * predecessors it fans in from.) Read-only tools survive that; a page edit does
 * not — the duplicate re-applied a whole `add_section` batch, giving every page
 * built in chat a second hero, features band, testimonials and CTA.
 *
 * A tool_call_id is unique per call by construction, so "already invoked" is a
 * total answer: the ledger records every id this run has executed, and a repeat
 * is dropped — no invocation, and NO tool-role message (the first execution
 * already put one in the buffer, and re-emitting it would only be dropped
 * downstream). An empty batch then leaves the conversation untouched, which is
 * what stops the phantom wave from advancing the loop — see the
 * `appended_any` output port the retired fork ConversationAppend exposed and
 * the Boolean Gateway each agent loop wires the equivalent signal into.
 *
 * The ledger is scoped to the PIPELINE (one console turn), so it survives a
 * pause/resume of the same run — where the re-fire actually happens — while a
 * genuinely new turn always starts empty.
 *
 * @see \Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\ToolInvoke
 */
#[FlowDropNodeProcessor(
  id: 'aincient_tool_invoke',
  label: new TranslatableMarkup('Invoke tool (once)'),
  description: 'Run the tool calls a Reason node chose, guaranteeing each tool_call_id executes at most once per run.',
  version: '0.1.0',
)]
final class ToolInvoke extends AbstractFlowDropNodeProcessor implements ToolsAwareInterface, ExecutionContextAwareInterface, HasSideEffectsInterface {

  use ToolsAwareTrait;
  use ScopeResolutionTrait;

  /**
   * Memory scope/key/backend of the per-run execution ledger.
   *
   * `pipeline` = one console turn; `entity` so a resumed run (a HITL approval
   * comes back in a later request) still sees what the first segment ran.
   */
  private const LEDGER_SCOPE = 'pipeline';
  private const LEDGER_KEY = 'invoked_tool_calls';
  private const LEDGER_BACKEND = 'entity';

  /**
   * Ledger lifetime; a run is done in seconds, a day is pure slack.
   */
  private const LEDGER_TTL = 86400;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly MemoryManager $memoryManager,
    private readonly LoggerChannelInterface $logger,
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
      $container->get('logger.factory')->get('aincient_flows'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $toolCalls = $params->getArray('tool_calls', []);
    $tools = $this->getTools();

    $scopeId = $this->resolveScopeId(self::LEDGER_SCOPE);
    $invoked = $this->readLedger($scopeId);

    $messages = [];
    $results = [];
    $skipped = [];
    $ok = TRUE;
    foreach ($toolCalls as $call) {
      if (!is_array($call)) {
        continue;
      }
      $name = trim((string) ($call['name'] ?? ''));
      if ($name === '') {
        continue;
      }
      $args = is_array($call['args'] ?? NULL) ? $call['args'] : [];
      $toolCallId = (string) ($call['tool_call_id'] ?? '');

      // Only an id-bearing call can be guarded — the id IS the identity. An
      // unkeyed call (a provider that omits one) runs, as it did before: the
      // alternative, keying on name+args, would refuse a legitimate second
      // call with the same arguments.
      if ($toolCallId === '') {
        $this->logger->warning('Tool call to @tool carries no tool_call_id; it cannot be guarded against a re-fire.', [
          '@tool' => $name,
        ]);
      }
      elseif (isset($invoked[$toolCallId])) {
        // Already executed in this run: drop it whole. The first execution's
        // tool-role message is already paired to the call in the buffer.
        $skipped[] = $toolCallId;
        $this->logger->notice('Skipped a repeat invocation of @tool (@id) — already executed in this run.', [
          '@tool' => $name,
          '@id' => $toolCallId,
        ]);
        continue;
      }

      // Claim the id BEFORE invoking: a tool that throws must not become
      // replayable, and the claim is what a later clone reads.
      if ($toolCallId !== '') {
        $invoked[$toolCallId] = $name;
        $this->writeLedger($scopeId, $invoked);
      }

      // An unknown/unwired tool name is recoverable: tell the model so it can
      // re-plan (the wiring is the allow-list — never trust a forged name).
      $binding = $tools->get($name);
      if ($binding === NULL) {
        $content = sprintf('Error: "%s" is not an available tool.', $name);
        $ok = FALSE;
      }
      else {
        $result = $binding->invoke($this->coerceArgs($args, $binding->getInputSchema()));
        $content = $this->resultToString($result);
        $ok = $ok && $result->isSuccess();
      }

      $messages[] = [
        'role' => 'tool',
        'tool_call_id' => $toolCallId,
        'content' => $content,
      ];
      $results[] = [
        'name' => $name,
        'tool_call_id' => $toolCallId,
        'content' => $content,
      ];
    }

    return [
      'tool_messages' => $messages,
      'tool_results' => $results,
      'ok' => $ok,
      // The loop-progress signal: gateways take a strict boolean, and `ok`
      // is the wrong signal there — a failed tool still produces an error
      // message the model must see, so the loop must continue.
      'produced_any' => $messages !== [],
      'skipped' => $skipped,
    ];
  }

  /**
   * Reconcile a model's tool arguments with the tool's declared input schema.
   *
   * Providers disagree about STRUCTURED arguments. Given a parameter declared
   * `array`, some send a native array and some send the same JSON as a STRING —
   * and the same provider does both across runs. The runtime type check
   * (`ParameterResolver::validateValue()`) is strict, so the mismatched shape is
   * rejected before the capability — which accepts either form quite happily —
   * ever sees it, and the whole run dies on a difference of quoting.
   *
   * We have now been bitten from both directions: "expects type 'string', got
   * 'array'" when the reasoning role moved off Claude (fixed by re-declaring the
   * parameter as `array`), then "expects type 'array', got 'string'" when Claude
   * later stringified `ops` mid-turn and killed the pipeline. Flipping the
   * declaration chases the symptom; the declaration serves the LLM-facing tool
   * schema too, and `array` is the honest thing to advertise there. So the fix
   * belongs HERE, at the boundary we own: decode a JSON string when the schema
   * asks for a structure, and leave everything else exactly as the model sent it.
   *
   * Deliberately narrow. It acts only when (a) the schema declares `array` or
   * `object` for that named parameter, (b) the value arrived as a string, and
   * (c) that string parses to the declared shape. A parameter declared `string`
   * is untouched even if its value looks like JSON — a page heading that happens
   * to read `[1,2]` stays text.
   *
   * @param array<string, mixed> $args
   *   The arguments as the model produced them.
   * @param array<string, mixed> $schema
   *   The tool's JSON-schema input definition ({@see ToolBindingInterface}).
   *
   * @return array<string, mixed>
   *   The arguments, with structured parameters decoded where needed.
   */
  private function coerceArgs(array $args, array $schema): array {
    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    foreach ($args as $name => $value) {
      if (!is_string($value)) {
        continue;
      }
      $expected = is_array($properties[$name] ?? NULL) ? (string) ($properties[$name]['type'] ?? '') : '';
      if ($expected !== 'array' && $expected !== 'object') {
        continue;
      }
      $decoded = json_decode($value, TRUE);
      if (!is_array($decoded)) {
        // Not JSON at all — leave it and let validation report the real problem
        // rather than silently substituting something the model never sent.
        continue;
      }
      // A JSON array decodes to a list, an object to a map. Only accept the one
      // the schema actually asked for, so `"{…}"` can't slip into an array slot.
      $isList = array_is_list($decoded);
      if (($expected === 'array') !== $isList) {
        continue;
      }
      $args[$name] = $decoded;
      $this->logger->notice('Decoded a JSON-string @type argument "@name" the model sent for a structured parameter.', [
        '@type' => $expected,
        '@name' => $name,
      ]);
    }
    return $args;
  }

  /**
   * The ids this run has already invoked, as an id => tool-name map.
   *
   * @param string $scopeId
   *   The pipeline id the ledger is scoped to ('' when unresolvable).
   *
   * @return array<string, string>
   *   The ledger (empty when the run has invoked nothing, or is unscoped).
   */
  private function readLedger(string $scopeId): array {
    if ($scopeId === '') {
      return [];
    }
    $ledger = $this->memoryManager->get(self::LEDGER_SCOPE, $scopeId, self::LEDGER_KEY, [], self::LEDGER_BACKEND);
    return is_array($ledger) ? $ledger : [];
  }

  /**
   * Persists the ledger (a no-op when the run has no resolvable scope id).
   *
   * @param string $scopeId
   *   The pipeline id the ledger is scoped to ('' when unresolvable).
   * @param array<string, string> $ledger
   *   The ledger to store.
   */
  private function writeLedger(string $scopeId, array $ledger): void {
    if ($scopeId === '') {
      return;
    }
    $this->memoryManager->set(self::LEDGER_SCOPE, $scopeId, self::LEDGER_KEY, $ledger, self::LEDGER_TTL, self::LEDGER_BACKEND);
  }

  /**
   * Render a tool result as the string content the model reads back.
   *
   * A node's result whose data carries a `result` key surfaces that; any other
   * tool's data is JSON-encoded. A failed result becomes an "Error: …" string
   * the model can recover from. (Mirrors upstream ToolInvoke.)
   */
  private function resultToString(ToolResultInterface $result): string {
    if (!$result->isSuccess()) {
      return 'Error: ' . ($result->getError() ?? 'the tool failed.');
    }
    $data = $result->getData();
    if (array_key_exists('result', $data)) {
      return (string) $data['result'];
    }
    return (string) json_encode($data);
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
        'tool_calls' => [
          'type' => 'array',
          'title' => 'Tool calls',
          'description' => 'The tool calls to run: [{name, args, tool_call_id}]. Each is invoked against the wired tools, at most once per run.',
          'default' => [],
          'required' => FALSE,
        ],
        // The tool input port (ReservedName::PORT_TOOL): the tools this node
        // may execute, wired tool.output → invoke.input-tool. Wire the same
        // tool set the paired reasoning node advertised — "advertised ==
        // executable", enforced by the topology. Marked connectable in config.
        ReservedName::PORT_TOOL => [
          'type' => 'tool',
          'title' => 'Tools',
          'description' => 'Tools this node may execute. Wire the same tool set as the paired reasoning node.',
          'required' => FALSE,
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
        'tool_messages' => [
          'type' => 'array',
          'description' => 'One tool-role message per EXECUTED call ({role, tool_call_id, content}) — append to a conversation buffer to persist.',
        ],
        'tool_results' => [
          'type' => 'array',
          'description' => 'Per-call results ({name, tool_call_id, content}).',
        ],
        'ok' => [
          'type' => 'boolean',
          'description' => 'TRUE if every executed tool call succeeded.',
        ],
        'produced_any' => [
          'type' => 'boolean',
          'description' => 'TRUE when any tool message was produced (success or error) — the loop-progress signal for gateways.',
        ],
        'skipped' => [
          'type' => 'array',
          'description' => 'tool_call_ids dropped as repeats of a call this run already executed.',
        ],
      ],
    ];
  }

}
