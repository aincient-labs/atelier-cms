<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\BrandPreviewApplier;
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
 * Sits INSIDE each brand specialist sub-workflow, in the middle of a chain that
 * separates PARSING from VALIDATION:
 *
 *   simple_chat → JSON to Data (tolerant) → HERE → Data to JSON → chat_output
 *
 * It takes a DECODED slice ({tokens_json, presets_json, fonts}), deterministically
 * re-validates every token against the design-token registry
 * ({@see \Drupal\aincient_pages\DesignTokens::validate}), STRIPS the invalid
 * ones, and re-emits the cleaned slice with a `rejected` block of
 * {token, value, reason} entries.
 *
 * Why it no longer parses. It used to take the model's raw response and strip a
 * ```-fence itself — with a regex that assumed the fence wrapped the WHOLE
 * string. Specialists emit fenced JSON followed by a rationale about half the
 * time, and on those turns the closing fence sat mid-string: the decode failed,
 * the node passed the text through as an "intentional no-op", the merge applied
 * nothing, and the orchestrator told the user their palette was ready. A coin
 * flip between working and silently doing nothing.
 *
 * The engine already solved that problem — `json_to_data` in tolerant mode
 * extracts the first fenced block and reports `success` — so parsing moved to
 * the graph, where a failure is a JOB with recorded input and output rather than
 * a NULL returned inside PHP that leaves no trace. This node is now a pure
 * validator over structured data: no formats to guess, and every branch is unit
 * testable. All consumers are in this repo (three call sites, all ours), so the
 * leniency was deleted outright rather than kept for compatibility.
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
    $decoded = BrandPreviewApplier::normalizeSliceShape($params->getArray('slice', []));
    // An empty object is a deliberate no-op (the model chose to change nothing),
    // and so is anything that carries none of the three slice keys. Nothing to
    // validate, nothing to reject.
    if ($decoded === []) {
      return ['slice' => []];
    }

    $rejected = [];
    $cleanTokens = [];
    $tokens = is_array($decoded['tokens_json'] ?? NULL) ? $decoded['tokens_json'] : [];
    foreach ($tokens as $name => $value) {
      $name = (string) $name;
      // Non-string values (e.g. a bare number for shadow_strength) — coerce to
      // string for validation, and pass the raw value on: the applier coerces
      // numbers the same way ({@see BrandPreviewApplier::scalarToCss}). It did
      // NOT until 2026-08-04 — it required is_string(), so a token accepted
      // here was silently dropped at apply and the agent claimed a change the
      // preview never made.
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

    return ['slice' => $out];
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
          'type' => 'object',
          'title' => 'Slice',
          'description' => "The specialist's DECODED slice {tokens_json, presets_json, fonts} — wire a tolerant JSON to Data node's `data` output here, never the model's raw response. Models fence their JSON and add a rationale after it about half the time; the engine's tolerant parser handles that, and this node then only validates.",
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
          'type' => 'object',
          'description' => 'The validated slice (invalid tokens stripped) with a `rejected` block of {token, value, reason}. Wire a Data to JSON node between this and chat_output — the sub-workflow returns a string across the tool boundary.',
        ],
      ],
    ];
  }

}
