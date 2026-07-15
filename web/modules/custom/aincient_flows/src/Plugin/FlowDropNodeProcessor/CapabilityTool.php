<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\Constants\ReservedName;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Drupal\aincient_flows\Plugin\Deriver\CapabilityToolDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A single AIncient capability, placed on the canvas as a tool node.
 *
 * One derivative per AIncient FunctionCall (see
 * {@see CapabilityToolDeriver} for the provider list). This is where a
 * capability actually *runs* —
 * on the graph, as a node execution on the shared session — so that when an
 * agent (a `ToolsAware` Reason/Invoke node) calls a tool via FlowDrop's
 * `ScopedToolInvoker`, the call reuses the runtime's logging, status, events
 * and interrupt handling and is auditable. The invoker also enforces the
 * allow-list (the wired `tool_availability` edges) and validates args first.
 *
 * The node's parameter schema is the FunctionCall's OWN declared context, so
 * the tool's `input_schema` (what the model is shown) can never drift from the
 * primitive's signature. The bound capability is carried in the derivative's
 * `function_call_id`.
 *
 * Supersedes the earlier tool-emitter + command-dispatch pair (both removed):
 * with the native tool runtime, exposure is the wiring and execution is the
 * node — no emitter, no PHP `const ALLOW_LIST`.
 */
#[FlowDropNodeProcessor(
  id: "aincient_capability",
  label: new TranslatableMarkup("AIncient capability"),
  description: "Run one allow-listed AIncient capability as a tool node (auditable node execution)",
  deriver: CapabilityToolDeriver::class,
)]
class CapabilityTool extends AbstractFlowDropNodeProcessor {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FunctionCallPluginManager $functionCallManager,
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
      $container->get('plugin.manager.ai.function_calls'),
    );
  }

  /**
   * The FunctionCall id this node is bound to (from the derivative definition).
   */
  protected function functionCallId(): string {
    $definition = $this->getPluginDefinition();
    return is_array($definition) ? (string) ($definition['function_call_id'] ?? '') : '';
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $id = $this->functionCallId();
    if ($id === '') {
      return ['ok' => FALSE, 'result' => 'This capability node is not bound to a command.'];
    }

    try {
      $plugin = $this->functionCallManager->createInstance($id);
      assert($plugin instanceof ExecutableFunctionCallInterface);

      // Set only the arguments the capability actually declares; the model's
      // tool-call args arrive as the node's runtime inputs. Entities emitted by
      // the model are normalised here (see self::normalizeArg) — this is the one
      // boundary every capability's model args cross, so the decode lives once.
      $definitions = $plugin->getContextDefinitions();
      foreach ($definitions as $name => $context) {
        if ($params->has($name)) {
          $plugin->setContextValue($name, $this->normalizeArg($params->get($name)));
        }
      }

      $plugin->execute();
      // The capability runs under the current user's Drupal permissions and
      // returns a readable string (its own graceful "Error: …" on a bad arg /
      // missing permission), which the model reads and can recover from.
      return ['ok' => TRUE, 'result' => (string) $plugin->getReadableOutput()];
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'result' => 'The capability failed: ' . $e->getMessage()];
    }
  }

  /**
   * Normalise one model-supplied tool argument.
   *
   * LLMs habitually HTML-escape text they think is destined for a web page, so a
   * value like `Ember & Oak` arrives as `Ember &amp; Oak` (and `O'Brien` as
   * `O&#39;Brien`). Our capabilities store these as PLAIN TEXT and escape at
   * OUTPUT (Twig / the SDC templates), so persisting the entity double-encodes
   * it — surfacing a literal `&amp;` in the studio field and on the rendered
   * site. This is a cross-cutting LLM behaviour, so we strip it once here, at the
   * single boundary every capability's model args cross, rather than per field.
   *
   * JSON-aware: a `*_json` arg is decoded on its LEAF string values only (parse →
   * decode → re-encode), so an entity inside a value (e.g. a `&quot;`) can never
   * corrupt the JSON envelope. Non-JSON strings are decoded directly. Non-strings
   * pass through untouched. Idempotent + cheap: a bare `&` is not a valid entity,
   * so entity-free text is returned unchanged (and the `&` fast-path skips the
   * work entirely).
   */
  protected function normalizeArg(mixed $value): mixed {
    if (!is_string($value) || !str_contains($value, '&')) {
      return $value;
    }
    // A JSON object/array arg: decode the leaves, never the raw envelope.
    $trimmed = ltrim($value);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
      $decoded = json_decode($value, TRUE);
      if (is_array($decoded)) {
        return (string) json_encode(
          $this->decodeEntitiesDeep($decoded),
          JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
      }
    }
    return $this->decodeEntities($value);
  }

  /**
   * Recursively decode HTML entities in the leaf string values of a parsed
   * structure (array keys are left as-is — they are field names, not content).
   */
  protected function decodeEntitiesDeep(mixed $value): mixed {
    if (is_array($value)) {
      return array_map([$this, 'decodeEntitiesDeep'], $value);
    }
    return is_string($value) ? $this->decodeEntities($value) : $value;
  }

  /**
   * Decode HTML entities in a single string (named + numeric, single + double
   * quotes, HTML5 set), UTF-8. A no-op for entity-free text.
   */
  protected function decodeEntities(string $value): string {
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   *
   * The tool's `input_schema` — built from the bound FunctionCall's own context
   * so it cannot drift from the primitive's signature. Read by FlowDrop's
   * ToolProjector on a bare instance (no node config), so it relies only on the
   * derivative's `function_call_id`.
   */
  public function getParameterSchema(): array {
    $empty = ['type' => 'object', 'properties' => []];
    $id = $this->functionCallId();
    if ($id === '') {
      return $empty;
    }
    try {
      $plugin = $this->functionCallManager->createInstance($id);
    }
    catch (\Throwable) {
      return $empty;
    }

    $properties = [];
    $required = [];
    foreach ($plugin->getContextDefinitions() as $name => $context) {
      $description = (string) $context->getLabel();
      $detail = (string) $context->getDescription();
      if ($detail !== '') {
        $description .= ' — ' . $detail;
      }
      $properties[$name] = [
        'type' => $this->toJsonType((string) $context->getDataType()),
        'description' => $description,
      ];
      if ($context->isRequired()) {
        $required[] = $name;
      }
    }

    $schema = ['type' => 'object', 'properties' => $properties];
    if ($required !== []) {
      $schema['required'] = $required;
    }
    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        // The tool OUTPUT port (ReservedName::PORT_TOOL): an authoring
        // affordance only. It renders the {nodeId}-output-tool handle so this
        // node can be wired AS a tool into a consumer's -input-tool handle
        // (tool.output → consumer.input). It carries no runtime value — the
        // node is invoked as an ordinary node and its getParameterSchema() is
        // read as the tool's input schema. Mirrors the consumer's PORT_TOOL
        // input; marked exposed + visual_type:tool in the node-type config.
        ReservedName::PORT_TOOL => [
          'type' => 'tool',
          'description' => 'This capability, offered as a callable tool.',
        ],
        'ok' => ['type' => 'boolean', 'description' => 'Whether the capability ran.'],
        'result' => ['type' => 'string', 'description' => 'The capability\'s readable output.'],
      ],
    ];
  }

  /**
   * Map a Drupal data type to a JSON-schema type for the tool properties.
   */
  private function toJsonType(string $dataType): string {
    return match ($dataType) {
      'boolean' => 'boolean',
      'integer' => 'integer',
      'float', 'decimal' => 'number',
      default => 'string',
    };
  }

}
