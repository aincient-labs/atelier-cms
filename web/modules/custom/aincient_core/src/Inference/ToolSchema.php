<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Translates FlowDrop tool definitions into `symfony/ai` Tool objects.
 *
 * FlowDrop hands us `{name, description, input_schema}` where `input_schema` is
 * already JSON Schema, and `symfony/ai` takes JSON Schema verbatim as a Tool's
 * `parameters`. So this class is a near pass-through.
 *
 * That is the point. The equivalent in `flowdrop_ai_provider` is 84 lines
 * (`buildToolsInput()` + `toPropertyInput()`) that exist almost entirely to
 * work around `drupal/ai` emitting INVALID JSON Schema:
 *   - `ToolsPropertyInput` renders `"required": <bool>` inside each property,
 *     which draft 2020-12 forbids (`required` is an object-level array) and
 *     Anthropic rejects outright — so required-ness had to be smuggled into the
 *     property DESCRIPTION as a "(required)" suffix and the real flag dropped.
 *   - `ToolsFunctionInput::renderFunctionArray()` emits `"parameters": null` for
 *     a no-argument tool, which kills an entire request through a LiteLLM proxy
 *     (our `patches/ai-tools-null-parameters.patch`).
 *
 * Here required-ness is passed through as the object-level array it always was,
 * and a no-argument tool gets `{"type":"object"}` — a valid empty schema. Both
 * workarounds simply cease to exist, and the patch becomes unnecessary.
 */
final class ToolSchema {

  /**
   * Marker class for the inert execution reference.
   *
   * `symfony/ai`'s Tool carries an ExecutionReference so its own agent layer can
   * auto-execute a call. We never do: the graph routes tool calls (that is the
   * whole reason `patches/gemini-return-tool-calls.patch` exists — the Gemini
   * provider executed them in-provider). The reference is required by the
   * constructor but never dereferenced on the declaration path, so it points at
   * this name purely to be self-documenting in a stack trace.
   */
  private const INERT_REFERENCE = 'Drupal\\aincient_core\\Inference\\GraphRoutedTool';

  /**
   * Converts FlowDrop tool definitions into Tool value objects.
   *
   * @param array<int, array<string, mixed>> $definitions
   *   FlowDrop's native ToolBinding shape: `{name, description, input_schema}`.
   *   Entries without a name are skipped rather than throwing — a malformed
   *   binding must not cost the operator the whole turn.
   *
   * @return list<\Symfony\AI\Platform\Tool\Tool>
   *   The declarations to hand the model.
   */
  public function toTools(array $definitions): array {
    $reference = new ExecutionReference(self::INERT_REFERENCE);
    $tools = [];
    foreach ($definitions as $definition) {
      if (!is_array($definition) || trim((string) ($definition['name'] ?? '')) === '') {
        continue;
      }
      $tools[] = new Tool(
        $reference,
        (string) $definition['name'],
        (string) ($definition['description'] ?? ''),
        $this->normaliseSchema($definition['input_schema'] ?? NULL),
      );
    }
    return $tools;
  }

  /**
   * Coerces a tool's input schema into valid JSON Schema.
   *
   * Guarantees an object schema even when the tool takes no arguments — never
   * NULL, which is the failure our drupal/ai core patch addresses. `properties`
   * is omitted rather than emitted empty, because an empty PHP array encodes as
   * `[]` and the schema calls for `{}`; both the Anthropic and OpenAI schemas
   * treat the key as optional.
   *
   * @param mixed $schema
   *   The declared `input_schema`, or anything else.
   *
   * @return array<string, mixed>
   *   A valid JSON Schema object.
   */
  private function normaliseSchema(mixed $schema): array {
    if (!is_array($schema)) {
      return ['type' => 'object'];
    }

    $properties = is_array($schema['properties'] ?? NULL) ? $schema['properties'] : [];
    $out = ['type' => (string) ($schema['type'] ?? 'object')];

    if ($properties !== []) {
      $out['properties'] = $this->normaliseProperties($properties);
    }

    // Required-ness travels as the object-level array JSON Schema defines. The
    // drupal/ai path could not do this and folded it into descriptions instead.
    $required = $this->collectRequired($schema, $properties);
    if ($required !== []) {
      $out['required'] = $required;
    }

    return $out;
  }

  /**
   * Recursively strips FlowDrop's per-property boolean `required` flag.
   *
   * FlowDrop's parameter-schema dialect marks required-ness as a BOOLEAN on each
   * property. That is legal in its own dialect but not in JSON Schema, so it is
   * lifted to the object-level array by {@see self::collectRequired()} and
   * removed here.
   *
   * @param array<string, mixed> $properties
   *   The raw property map.
   *
   * @return array<string, mixed>
   *   The cleaned property map.
   */
  private function normaliseProperties(array $properties): array {
    $out = [];
    foreach ($properties as $name => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      unset($definition['required']);
      if (is_array($definition['properties'] ?? NULL)) {
        $nested = $definition['properties'];
        $definition['properties'] = $this->normaliseProperties($nested);
        $nestedRequired = $this->collectRequired($definition, $nested);
        if ($nestedRequired !== []) {
          $definition['required'] = $nestedRequired;
        }
      }
      $out[(string) $name] = $definition;
    }
    return $out;
  }

  /**
   * Resolves the object-level `required` list from either dialect.
   *
   * Accepts an explicit JSON Schema `required` array, and additionally lifts any
   * property carrying a truthy boolean `required` flag, so both FlowDrop's
   * dialect and plain JSON Schema produce the same output.
   *
   * @param array<string, mixed> $schema
   *   The schema (or nested property) being normalised.
   * @param array<string, mixed> $properties
   *   Its raw property map, before cleaning.
   *
   * @return list<string>
   *   Deduplicated required property names, in declaration order.
   */
  private function collectRequired(array $schema, array $properties): array {
    $required = [];
    foreach (is_array($schema['required'] ?? NULL) ? $schema['required'] : [] as $name) {
      if (is_string($name) && $name !== '') {
        $required[$name] = TRUE;
      }
    }
    foreach ($properties as $name => $definition) {
      if (is_array($definition) && ($definition['required'] ?? NULL) === TRUE) {
        $required[(string) $name] = TRUE;
      }
    }
    return array_keys($required);
  }

}
