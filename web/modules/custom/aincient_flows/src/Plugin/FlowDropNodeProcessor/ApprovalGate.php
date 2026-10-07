<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;

/**
 * Decides whether a reason step's tool calls need a human approval first.
 *
 * Sits in the agent engine (DECISIONS 0463) between the reason node's
 * `tool_calls` and the confirmation guardrail:
 *
 *   reason.tool_calls → HERE → boolean gateway → confirmation | invoke
 *
 * It replaces a `data_extractor → switch_gateway` pair that read only the FIRST
 * call's name and matched one hard-coded tool (`capability_quick_brand_picker`)
 * — copied into five agents where that tool was never wired, so the guardrail
 * could not fire. Here the list is the engine's `approval_tools` input, set per
 * studio, and EVERY call in the step is checked: a model that batches a safe
 * call ahead of a guarded one must still stop for approval.
 *
 * Tool names are the projected `capability_<name>` form the model sees. An empty
 * list never asks, which is the shipped behaviour of every studio today.
 */
#[FlowDropNodeProcessor(
  id: "approval_gate",
  label: new TranslatableMarkup("Approval gate"),
  description: "TRUE when any of the step's tool calls is in the approval list (a CSV of capability_* names); an empty list never asks.",
  version: "1.0.0",
)]
class ApprovalGate extends AbstractFlowDropNodeProcessor {

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $guarded = self::parseList((string) $params->get('approval_tools', ''));
    if ($guarded === []) {
      return ['needs_approval' => FALSE];
    }
    foreach ($params->getArray('tool_calls', []) as $call) {
      $name = is_array($call) ? ($call['name'] ?? NULL) : NULL;
      if (is_string($name) && isset($guarded[$name])) {
        return ['needs_approval' => TRUE];
      }
    }
    return ['needs_approval' => FALSE];
  }

  /**
   * Parses the CSV into a name set; blanks and stray whitespace are ignored.
   *
   * @return array<string, true>
   *   The guarded tool names, as keys.
   */
  public static function parseList(string $csv): array {
    $names = [];
    foreach (explode(',', $csv) as $name) {
      $name = trim($name);
      if ($name !== '') {
        $names[$name] = TRUE;
      }
    }
    return $names;
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
          'description' => "The reason step's tool calls: [{name, args, tool_call_id}].",
          'default' => [],
        ],
        'approval_tools' => [
          'type' => 'string',
          'title' => 'Tools that need approval',
          'description' => 'Comma-separated capability_* tool names. Empty = never ask.',
          'default' => '',
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
        'needs_approval' => [
          'type' => 'boolean',
          'description' => 'TRUE when any call is in the approval list — wire to a boolean gateway in front of the confirmation node.',
        ],
      ],
    ];
  }

}
