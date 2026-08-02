<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Usage\UsageRecorder;
use Psr\Log\LoggerInterface;

/**
 * One chat turn on `symfony/ai`, for a node that reasons without tools.
 *
 * The third and last caller of the invoke-and-name-the-failure sequence, next to
 * {@see SymfonyAiReasoner} (the agent loop) and {@see AiGateway} (one-shot product
 * calls). It exists rather than being folded into either because its shape is
 * genuinely a third one: a system prompt plus a `{role, content}` history plus one
 * message, answering with the provider, the model and the token count — which the
 * gateway's string-returning methods cannot express and the reasoner's tool-aware
 * DTOs overstate.
 *
 * It lives in aincient_core, not in the FlowDrop integration layer, for the reason
 * AiGateway's docblock states: `Symfony\AI` types stay on this side of the
 * boundary, so its caller
 * ({@see \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\SimpleChat}) names no
 * vendor type at all and the next backend move is again a change to this
 * directory alone.
 */
final class ChatCompleter {

  public function __construct(
    private readonly PlatformRegistryInterface $registry,
    private readonly ModelTargetResolver $targets,
    private readonly MessageMapper $messages,
    private readonly ResultUnpacker $unpacker,
    private readonly UsageRecorder $usage,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Runs one chat turn.
   *
   * @param string $message
   *   The user message. Must not be empty — an empty prompt is a wiring mistake
   *   upstream, and sending it would spend a call to be told so by the provider.
   * @param array<int|string, mixed> $history
   *   Prior turns as `{role, content}` arrays; only `user`/`assistant` are
   *   replayed ({@see MessageMapper::toSimpleChatBag()} for why that matters).
   * @param string $systemPrompt
   *   The system prompt, or '' to omit it.
   * @param string $operationType
   *   The node's operation type, e.g. `aincient_role:task`.
   * @param string $model
   *   A `provider:model`, a bare model id, or '' to use the role binding.
   * @param float $temperature
   *   The sampling temperature. 0.0 is NOT forwarded — see below.
   * @param int $maxTokens
   *   The token cap.
   *
   * @throws \Drupal\aincient_core\Exception\AiProviderFailure
   *   When the provider could not serve the call.
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When no model resolves, or the resolved provider is not configured.
   * @throws \InvalidArgumentException
   *   When the message is empty.
   */
  public function complete(
    string $message,
    array $history = [],
    string $systemPrompt = '',
    string $operationType = 'chat',
    string $model = '',
    float $temperature = 0.0,
    int $maxTokens = 1000,
  ): ChatCompletion {
    if (trim($message) === '') {
      throw new \InvalidArgumentException('A chat turn needs a message.');
    }

    [$providerId, $modelId] = $this->targets->resolve($operationType, $model);
    $bag = $this->messages->toSimpleChatBag($systemPrompt, $history, $message);

    $options = ['max_tokens' => $maxTokens];
    // Temperature 0.0 is omitted, for the reason SymfonyAiReasoner spells out at
    // length: newer Anthropic models REJECT the parameter outright, and 0.0 is
    // what a node stores when nobody chose a temperature (all three brand
    // specialists store exactly that). Forwarding it would fail every turn on
    // those models rather than degrade an answer. A non-zero value someone typed
    // IS forwarded, and a model that refuses it says so through a named failure.
    if ($temperature !== 0.0) {
      $options['temperature'] = $temperature;
    }

    try {
      // The provider's own spelling, never ours: Gemini rejects `max_tokens`
      // (it wants `maxOutputTokens`) with a 400 on the whole request.
      $options = $this->registry->adapter($providerId)->translateOptions($options);
      $platform = $this->registry->platform($providerId);
      $deferred = $platform->invoke($modelId, $bag, $options);
      // Resolved inside the try: DeferredResult is lazy, so an upstream fault
      // surfaces on conversion, not on invoke().
      $result = $deferred->getResult();
    }
    catch (ProviderConfigurationException $e) {
      // Local misconfiguration, not an upstream fault — let it through as-is so
      // the caller can say "connect a provider" instead of blaming the model.
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->error('Chat node inference failed on @provider/@model: @message', [
        '@provider' => $providerId,
        '@model' => $modelId,
        '@message' => $e->getMessage(),
      ]);
      // Named, not bare: the predecessor threw a vague \Exception, which reached
      // the console as a node failure nobody could act on (DECISIONS 0278/0279).
      throw new AiProviderFailure(sprintf(
        'The %s model "%s" could not complete this step.',
        $providerId,
        $modelId,
      ), 0, $e);
    }

    // The node reports its own token count to the job trail (below), but the trail
    // is live-only and per-run. The metering row is the durable half, and it is
    // written here for the same reason the reasoner writes its own: this is where
    // the resolved provider and model are known.
    $this->usage->record($providerId, $modelId, $result, UsageRecorder::TAG_SIMPLE_CHAT);

    return new ChatCompletion(
      // The full result union, through the one class that knows it: a bridge can
      // wrap a plain answer in a MultiPartResult, and reading one arm of that
      // union is how a successful turn became an empty message on screen.
      $this->unpacker->text($result),
      $providerId,
      $modelId,
      $this->unpacker->totalTokens($result),
    );
  }

}
