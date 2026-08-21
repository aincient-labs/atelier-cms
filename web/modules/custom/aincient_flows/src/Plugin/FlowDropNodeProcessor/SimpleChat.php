<?php

declare(strict_types=1);

namespace Drupal\aincient_flows\Plugin\FlowDropNodeProcessor;

use Drupal\aincient_core\Inference\ChatCompleter;
use Drupal\aincient_core\Inference\ModelTargetResolver;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\flowdrop\Attribute\FlowDropNodeProcessor;
use Drupal\flowdrop\DTO\ParameterBagInterface;
use Drupal\flowdrop\DTO\ValidationResult;
use Drupal\flowdrop\Plugin\FlowDropNodeProcessor\AbstractFlowDropNodeProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One chat turn with no tools — the brand specialists' reasoning node.
 *
 * Atelier's replacement for `flowdrop_ai_provider`'s `simple_chat` processor, the
 * LAST `drupal/ai` call path in the product. The three brand specialist
 * workflows (colour, typography, shape) each place one of these between their
 * prompt template and {@see ValidateSlice}, and they were NOT edited to adopt
 * this: the shipped node type `flowdrop_ai_provider_simple_chat` keeps its id and
 * simply points its `executor_plugin` here, so every placed instance, its stored
 * config and its wiring are untouched. (FlowDrop node instances do not
 * auto-update when a type changes, so renaming that id would have meant swapping
 * three instances in shipped workflow config for a cosmetic gain. The id names
 * a module that is no longer installed, which is ugly and correct.)
 *
 * SAME IN, SAME OUT. The node type config is the contract: the parameters it
 * declares (`message`, `history`, `systemPrompt`, `model`, `operation_type`,
 * `temperature`, `maxTokens`) and the outputs it declares (`response`, `model`,
 * `provider`, `tokens_used`, `temperature`, `max_tokens`) are reproduced exactly.
 *
 * WHAT DELIBERATELY CHANGED, all of it honesty:
 *   - The result is read through {@see \Drupal\aincient_core\Inference\ResultUnpacker},
 *     the one class that knows `symfony/ai`'s result union — not four
 *     `method_exists` branches over a response shape the predecessor hoped for.
 *   - `tokens_used` is the provider's OWN number, extracted from result metadata,
 *     where the predecessor guessed from whichever accessor happened to exist. It
 *     is 0 only when the provider reported nothing.
 *   - A provider fault arrives as {@see \Drupal\aincient_core\Exception\AiProviderFailure}
 *     naming the provider and model, not a bare `\Exception` — an unnamed node
 *     failure is a turn nobody can debug (DECISIONS 0278/0279).
 *
 * The work itself is in {@see ChatCompleter} so that no vendor type is named in
 * this module; this class maps a parameter bag onto that call and back.
 */
#[FlowDropNodeProcessor(
  // The base id is namespaced `aincient_flows:` by the plugin manager, but it
  // must ALSO be unique before that: attribute discovery keys definitions by the
  // raw id, so a bare `simple_chat` was silently swallowed by
  // flowdrop_ai_provider's plugin of the same name and never appeared at all.
  // That module is gone now; the id stays distinct so it cannot come back.
  id: "aincient_simple_chat",
  label: new TranslatableMarkup("Simple Chat"),
  description: "One AI chat turn with a plain text system prompt, on Atelier's own inference backend",
  version: "1.0.0",
)]
class SimpleChat extends AbstractFlowDropNodeProcessor {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ChatCompleter $completer,
    protected ModelTargetResolver $targets,
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
      $container->get('aincient_core.inference.chat_completer'),
      $container->get('aincient_core.inference.model_targets'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(ParameterBagInterface $params): array {
    $message = trim($params->getString('message', ''));
    if ($message === '') {
      // Nothing to reason about: an unwired (or empty) `message` input is a graph
      // mistake, and spending a provider call to be told so hides it.
      throw new \InvalidArgumentException('No message provided to the chat node.');
    }

    $temperature = $params->getFloat('temperature', 0.7);
    $maxTokens = $params->getInt('maxTokens', 1000);

    $completion = $this->completer->complete(
      $message,
      $params->getArray('history', []),
      $params->getString('systemPrompt', ''),
      // '' rather than 'chat' is never stored by a shipped node, but an empty
      // operation type must still resolve — the resolver treats anything it does
      // not recognise as the everyday `task` tier.
      $params->getString('operation_type', 'chat'),
      $params->getString('model', ''),
      $temperature,
      $maxTokens,
    );

    return [
      'response' => $completion->text,
      'model' => $completion->modelId,
      'provider' => $completion->providerId,
      'tokens_used' => $completion->tokensUsed,
      // Echoed back as configured, matching the predecessor: these two are what
      // the node was ASKED for, and a job trail reading them is reading the
      // node's settings, not the provider's behaviour.
      'temperature' => $temperature,
      'max_tokens' => $maxTokens,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateParams(array $params): ValidationResult {
    // Nothing to check at configure time. Whether a model resolves depends on the
    // site's role bindings and stored credentials, which change without the node
    // being touched — the predecessor's form-time model check therefore validated
    // a state that could be false minutes later, while a real failure surfaces at
    // run time as a named exception naming the provider and model.
    return ValidationResult::success();
  }

  /**
   * {@inheritdoc}
   */
  public function getParameterSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'message' => [
          'type' => 'string',
          'title' => 'Message',
          'description' => 'Message to send to the model.',
          'required' => FALSE,
        ],
        'model' => [
          'type' => 'string',
          // A free-text field, not an enumerated picker, and that is the point:
          // enumerating models means a live round trip per provider
          // ({@see \Drupal\aincient_core\Inference\ProviderInventory::models()}),
          // which has no business running every time an editor form is built. The
          // sanctioned way to choose a model is the role layer below — all three
          // shipped specialists leave this empty.
          'title' => 'Model',
          'description' => 'Optional override: `provider:model` (e.g. `anthropic:claude-sonnet-5`), or a bare model id to keep the role\'s provider. Leave empty to use the operation type\'s role binding.',
          'default' => '',
          'required' => FALSE,
        ],
        'operation_type' => [
          'type' => 'string',
          'title' => 'Operation Type',
          'description' => 'Which AIncient model role serves this node when no model is set above.',
          'default' => 'aincient_role:task',
          'enum' => array_column($this->targets->operationTypeOptions(), 'value'),
          'options' => $this->targets->operationTypeOptions(),
          'required' => FALSE,
        ],
        'temperature' => [
          'type' => 'number',
          'title' => 'Temperature',
          'description' => 'Model temperature. 0 is not sent to the provider at all — newer Anthropic models reject the parameter.',
          'default' => 0.7,
          'minimum' => 0,
          'maximum' => 2,
          'step' => '0.01',
          'format' => 'range',
        ],
        // NO maximum, matching the reason nodes (which declare `minimum` only).
        // A ceiling here is not a safety rail: the provider bills the tokens it
        // actually generates, so headroom is free, while a cap that is under what
        // the bound model needs converts a working turn into a `too_long` card —
        // a thinking model spends thousands of tokens before its first output
        // token, and the brand specialists' 2048 became exactly that failure
        // (atelier-cms#9). The real ceiling is the provider's: `max_tokens` above
        // a model's own output limit comes back a 400, which is worse than
        // truncation because it fails every turn rather than the long ones. We
        // hold no per-model output limits to clamp against, so the value stays a
        // per-node judgement.
        'maxTokens' => [
          'type' => 'integer',
          'title' => 'Max Tokens',
          'description' => 'Maximum tokens to generate. Reasoning models spend most of this before their first output token — 8192 is the value the reason nodes ship.',
          'default' => 1000,
          'minimum' => 1,
        ],
        'systemPrompt' => [
          'type' => 'string',
          'title' => 'System Prompt',
          'description' => 'System prompt to set model behaviour.',
          'default' => '',
          'format' => 'multiline',
        ],
        'history' => [
          'type' => 'array',
          'title' => 'Chat History',
          'description' => 'Previous turns as items with "role" and "content" keys. Only user and assistant turns are replayed.',
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
        'response' => [
          'type' => 'string',
          'description' => 'The model response',
        ],
        'model' => [
          'type' => 'string',
          'description' => 'The model that served the call',
        ],
        'provider' => [
          'type' => 'string',
          'description' => 'The provider that served the call',
        ],
        'tokens_used' => [
          'type' => 'integer',
          'description' => "The provider's own total token count, or 0 when it reported none",
        ],
        'temperature' => [
          'type' => 'number',
          'description' => 'The temperature setting',
        ],
        'max_tokens' => [
          'type' => 'integer',
          'description' => 'Maximum tokens setting',
        ],
      ],
    ];
  }

}
