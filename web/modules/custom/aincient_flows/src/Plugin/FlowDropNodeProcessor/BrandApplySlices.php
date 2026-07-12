<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_pages\BrandPreviewApplier;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Merges a Brand turn's specialist slices and applies ONE brand preview.
 *
 * The deterministic end-of-delegation apply for the Brand orchestrator. It sits
 * on the agent loop's no-more-tool-calls branch (boolean_gateway FALSE →
 * here → chat_output), reads the turn's conversation buffer, collects every
 * specialist slice the agent already delegated (colour `tokens_json`, shape
 * `presets_json`, typography `fonts`/`tokens_json`), deep-merges them, and emits
 * a SINGLE authoritative `brand_preview` widget envelope via the shared
 * {@see BrandPreviewApplier}.
 *
 * Why this exists: when `preview_brand` was an LLM tool the agent saw its
 * (mid-turn) result and, when a change didn't visibly land in the live preview
 * (fonts apply-on-publish), concluded it had failed and re-delegated — an
 * uncontrollable retry loop no prompt could fix. Taking apply away from the LLM
 * removes the feedback that drove the loop: the agent only ever sees specialist
 * slices, delegates once per dimension, and stops; the merge runs once here,
 * deterministically. (Live per-slice preview feedback is given back to the
 * USER, not the agent, over the one-way SSE channel — see
 * BrandSliceStreamSubscriber.)
 *
 * The emitted envelope is this turn's PERSISTED source of truth: the dispatcher
 * harvests it from this node's job output by node_type_id
 * (`aincient_flows_brand_apply_slices`) and stores it on the session, so a
 * thread reload reconstructs the final merged state.
 *
 * An empty turn (the user just chatted, only brand_picker ran, or every slice
 * was unparseable) is a NO-OP: it emits no widget but still flows to
 * chat_output so the agent's prose is returned.
 *
 * @see \Drupal\aincient_pages\BrandPreviewApplier
 * @see \Drupal\aincient_chat\Chat\FlowDropDispatcher::harvestTurnWidgets()
 */
#[FlowDropNodeProcessor(
  id: "brand_apply_slices",
  label: new TranslatableMarkup("Apply brand slices"),
  description: "Merge the Brand turn's specialist slices and apply ONE authoritative brand preview (no LLM apply).",
  version: "0.1.0",
)]
class BrandApplySlices extends AbstractFlowDropNodeProcessor {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly BrandPreviewApplier $applier,
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
      $container->get('aincient_pages.preview_applier'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $messages = $params->getArray('messages', []);

    // Scope to THIS turn: everything after the last user message. A new turn
    // always appends a fresh user message, so this isolates the slices the
    // agent delegated this turn (and a re-delegated dimension is deduped
    // last-wins below).
    $lastUser = -1;
    foreach ($messages as $i => $message) {
      if (is_array($message) && ($message['role'] ?? '') === 'user') {
        $lastUser = $i;
      }
    }
    $turn = $lastUser >= 0 ? array_slice($messages, $lastUser + 1) : $messages;

    // Collect + deep-merge the turn's specialist slices. Dimensions don't
    // overlap, so a key clash is rare; when it happens (a re-delegated
    // dimension) the LATER slice wins — a stray duplicate is harmless, never
    // additive.
    $presets = [];
    $tokens = [];
    $fonts = [];
    foreach ($turn as $message) {
      if (!is_array($message) || ($message['role'] ?? '') !== 'tool') {
        continue;
      }
      $slice = $this->applier->decodeSlice((string) ($message['content'] ?? ''));
      if ($slice === NULL) {
        continue;
      }
      if (is_array($slice['presets_json'] ?? NULL)) {
        $presets = array_merge($presets, $slice['presets_json']);
      }
      if (is_array($slice['tokens_json'] ?? NULL)) {
        $tokens = array_merge($tokens, $slice['tokens_json']);
      }
      if (isset($slice['fonts'])) {
        $raw = $slice['fonts'];
        $names = is_array($raw) ? $raw : explode(',', (string) $raw);
        foreach ($names as $name) {
          $name = trim((string) $name);
          if ($name !== '') {
            $fonts[] = $name;
          }
        }
      }
    }

    // Build the merged preview_brand args (back to the *_json string shape the
    // applier consumes) and apply ONCE.
    $args = [];
    if ($presets !== []) {
      $args['presets_json'] = (string) json_encode($presets);
    }
    if ($tokens !== []) {
      $args['tokens_json'] = (string) json_encode($tokens);
    }
    if ($fonts !== []) {
      $args['fonts'] = implode(', ', array_values(array_unique($fonts)));
    }

    // Empty turn (no specialist slices) → no-op: emit no widget, still flow on.
    if ($args === []) {
      return ['widget' => '', 'applied' => 0, 'summary' => ''];
    }

    $envelope = $this->applier->apply($args);
    if (isset($envelope['error'])) {
      // A merge that produced nothing valid (all rejected): no widget, no crash.
      return ['widget' => '', 'applied' => 0, 'summary' => (string) $envelope['error']];
    }

    return [
      'widget' => (string) json_encode($envelope),
      'applied' => count($envelope['payload']['tokens'] ?? []) + count($envelope['payload']['fonts'] ?? []),
      'summary' => (string) ($envelope['summary'] ?? ''),
    ];
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
        'messages' => [
          'type' => 'array',
          'title' => 'Messages',
          'description' => 'The conversation buffer ([{role, content, …}]) — wire a ConversationRead node\'s messages output here. The node reads this turn\'s specialist tool results.',
          'default' => [],
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
        'widget' => [
          'type' => 'string',
          'description' => 'The merged brand_preview widget envelope (JSON), or empty on a no-op turn. Harvested by the dispatcher.',
        ],
        'applied' => [
          'type' => 'integer',
          'description' => 'Number of tokens + fonts applied.',
        ],
        'summary' => [
          'type' => 'string',
          'description' => 'Human-readable apply summary (or an error/no-op note).',
        ],
      ],
    ];
  }

}
