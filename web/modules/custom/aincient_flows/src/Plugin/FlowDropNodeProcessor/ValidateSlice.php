<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\DesignTokens;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Validates a brand-specialist's raw slice and reports what was rejected.
 *
 * Sits INSIDE each brand specialist sub-workflow, between the model
 * (`simple_chat`) and `chat_output` (simple_chat → HERE → chat_output). The
 * model emits a JSON slice ({tokens_json, presets_json, fonts}); this node
 * deterministically re-validates every token against the design-token registry
 * ({@see \Drupal\aincient_pages\DesignTokens::validate}), STRIPS the invalid
 * ones, and re-emits the cleaned slice with a `rejected` block of
 * {token, value, reason} entries.
 *
 * Why here, and why it matters for cheap models (Haiku):
 *  - The old failure was silent: invalid tokens (raw colours for sub-palette
 *    colour tokens, invented names like `radius-rounded`, a `card_border`
 *    border-shorthand) were dropped only by the end-of-turn merge
 *    ({@see BrandApplySlices}), which runs AFTER the orchestrator has already
 *    written its closing prose — so the agent claimed success for changes that
 *    never applied, and the rejection reached only the UI chip.
 *  - Validating in the specialist puts the rejection into the slice the
 *    orchestrator's reasoning loop reads as a tool result, BEFORE it writes the
 *    final message. The agent can now tell the user the truth (and re-delegate
 *    with the precise reason) without any graph rewire downstream.
 *  - Deterministic PHP feedback (not a flaky visual signal) is safe to surface:
 *    it can't drive the unbounded retry loop the apply-hiding was meant to kill.
 *
 * Only the cleaned slice's tokens reach apply, so {@see BrandApplySlices} /
 * {@see \Drupal\aincient_pages\BrandPreviewApplier} no longer surface a
 * "Skipped invalid" surprise — the specialist already stripped + reported it.
 */
#[FlowDropNodeProcessor(
  id: "brand_validate_slice",
  label: new TranslatableMarkup("Validate brand slice"),
  description: "Re-validate a brand specialist's slice against the design-token registry; strip invalid tokens and report them with reasons.",
  version: "0.1.0",
)]
class ValidateSlice extends AbstractFlowDropNodeProcessor {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly DesignTokens $designTokens,
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
      $container->get('aincient_pages.design_tokens'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $raw = trim($params->getString('slice', ''));
    $decoded = $this->parse($raw);
    // Not a JSON slice (the model returned {} or prose) — pass through verbatim
    // so an intentional no-op stays a no-op.
    if ($decoded === NULL) {
      return ['slice' => $raw];
    }

    $rejected = [];
    $cleanTokens = [];
    $tokens = is_array($decoded['tokens_json'] ?? NULL) ? $decoded['tokens_json'] : [];
    foreach ($tokens as $name => $value) {
      $name = (string) $name;
      // Non-string values (e.g. a bare number for shadow_strength) — coerce to
      // string for validation, the applier reads strings too.
      $strValue = is_scalar($value) ? (string) $value : '';
      $reason = $this->designTokens->rejectionReason($name, $strValue);
      if ($reason === NULL) {
        $cleanTokens[$name] = $value;
      }
      else {
        $rejected[] = ['token' => $name, 'value' => $strValue, 'reason' => $reason];
      }
    }

    // Rebuild the slice: keep presets/fonts as-is (the applier validates those),
    // carry only the surviving tokens, and append the rejection report so the
    // orchestrator's next reasoning pass sees exactly what failed and why.
    $out = [];
    if (is_array($decoded['presets_json'] ?? NULL) && $decoded['presets_json'] !== []) {
      $out['presets_json'] = $decoded['presets_json'];
    }
    if ($cleanTokens !== []) {
      $out['tokens_json'] = $cleanTokens;
    }
    if (isset($decoded['fonts']) && $decoded['fonts'] !== '' && $decoded['fonts'] !== []) {
      $out['fonts'] = $decoded['fonts'];
    }
    if ($rejected !== []) {
      $out['rejected'] = $rejected;
    }

    return ['slice' => (string) json_encode($out, JSON_UNESCAPED_SLASHES)];
  }

  /**
   * Parse a model slice (optionally ```-fenced) into its decoded array, or NULL
   * when the content isn't a JSON object (prose / empty / a `{}` no-op).
   *
   * @return array<string, mixed>|null
   */
  private function parse(string $content): ?array {
    if ($content === '') {
      return NULL;
    }
    if (str_starts_with($content, '```')) {
      $content = trim((string) preg_replace('/^```[a-zA-Z0-9_-]*\s*|\s*```$/', '', $content));
    }
    if ($content === '' || $content[0] !== '{') {
      return NULL;
    }
    $data = json_decode($content, TRUE);
    if (!is_array($data)) {
      return NULL;
    }
    // An empty object is a deliberate no-op — nothing to validate.
    return $data === [] ? NULL : $data;
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
        'slice' => [
          'type' => 'string',
          'title' => 'Slice',
          'description' => "The specialist model's raw JSON slice — wire the simple_chat node's response here.",
          'default' => '',
          'required' => TRUE,
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
        'slice' => [
          'type' => 'string',
          'description' => 'The validated slice (invalid tokens stripped) with a `rejected` block of {token, value, reason}.',
        ],
      ],
    ];
  }

}
