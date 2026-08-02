<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\flowdrop\DTO\Reason\ModelChoices;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\flowdrop\Service\Reasoning\ChatReasonerInterface;
use Psr\Log\LoggerInterface;

/**
 * Atelier's reasoning backend, on `symfony/ai` instead of `drupal/ai`.
 *
 * Bound to `flowdrop.chat_reasoner`, the extension seam FlowDrop core documents
 * as `@api`: its `reason` node depends only on `ChatReasonerInterface` and
 * FlowDrop's own neutral DTOs, so core carries no AI backend of its own and the
 * concrete binding belongs to whoever owns that dependency. That is now us.
 *
 * This replaces `flowdrop_ai_provider`'s reasoner. What disappears with it:
 *   - the 84-line JSON Schema workaround ({@see ToolSchema} explains why),
 *   - `patches/ai-tools-null-parameters.patch` (a no-argument tool now renders a
 *     valid empty object schema),
 *   - `patches/gemini-return-tool-calls.patch` (the bridges return tool calls;
 *     none of them executes one, and Gemini's replay signature is a first-class
 *     field on `ToolCall`).
 *
 * The one behaviour this ADDS over its predecessor is honest failure. The old
 * path let a provider exception escape as whatever the vendor threw, which is
 * how a finished turn reached the screen as silence (DECISIONS 0278/0279). Every
 * upstream fault here is wrapped in {@see AiProviderFailure} with the provider
 * and model named, so the console can render something a person can act on.
 */
final class SymfonyAiReasoner implements ChatReasonerInterface {

  public function __construct(
    private readonly PlatformRegistryInterface $registry,
    private readonly ModelTargetResolver $targets,
    private readonly MessageMapper $messages,
    private readonly ToolSchema $tools,
    private readonly ResultUnpacker $unpacker,
    private readonly UsageRecorder $usage,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function reason(ReasonRequest $request): ReasonResult {
    // Resolution order (explicit provider:model → the node's AIncient role → the
    // `task` tier) lives in ModelTargetResolver because the brand specialists'
    // simple-chat node stores the identical two fields and must resolve them the
    // same way — see that class for why a divergence there would be invisible.
    [$providerId, $modelId] = $this->targets->resolve(
      $request->getOperationType(),
      $request->getModel(),
    );

    $bag = $this->messages->toMessageBag($request->getSystemPrompt(), $request->getMessages());

    $options = ['max_tokens' => $request->getMaxTokens()];

    // Send `temperature` ONLY when a node actually asked for one.
    //
    // Two reasons, and the second is the load-bearing one. First, FlowDrop's DTO
    // defaults it to 0.0 with no nullable variant, so 0.0 is indistinguishable
    // from "not configured" and every shipped node stores exactly that. Second,
    // the predecessor never forwarded temperature to a provider at all —
    // `temperature` does not appear anywhere in drupal/ai's provider base, so
    // sending it unconditionally was not "keeping" old behaviour, it was adding a
    // parameter production never sent. Anthropic's newer models reject it
    // outright ("`temperature` is deprecated for this model"), which turned every
    // turn on the `reasoning` role into a hard failure.
    //
    // A non-zero temperature IS forwarded, because someone typed it. If a model
    // refuses it, that surfaces as a named AiProviderFailure rather than silence —
    // which is the behaviour we want from a setting the operator chose.
    if ($request->getTemperature() !== 0.0) {
      $options['temperature'] = $request->getTemperature();
    }

    $declarations = $this->tools->toTools($request->getTools());
    if ($declarations !== []) {
      $options['tools'] = $declarations;
    }

    try {
      // The provider's own spelling of these options, not ours. Gemini rejects
      // `max_tokens` outright (it wants `maxOutputTokens`), and the adapter is
      // where that dialect is known — see
      // {@see ProviderAdapterInterface::translateOptions()} for why this is not a
      // `match ($providerId)` right here.
      $options = $this->registry->adapter($providerId)->translateOptions($options);
      $platform = $this->registry->platform($providerId);
      $deferred = $platform->invoke($modelId, $bag, $options);
      // Resolve here, inside the try: DeferredResult is lazy, so an upstream
      // fault surfaces on conversion rather than on invoke(). Converting outside
      // this block is exactly how an error escapes unwrapped.
      $result = $deferred->getResult();
    }
    catch (ProviderConfigurationException $e) {
      // Local misconfiguration — not an upstream failure. Let it through as-is
      // so the caller can say "connect a provider" instead of blaming the model.
      throw $e;
    }
    catch (\Throwable $e) {
      $this->logger->error('Inference failed on @provider/@model: @message', [
        '@provider' => $providerId,
        '@model' => $modelId,
        '@message' => $e->getMessage(),
      ]);
      throw new AiProviderFailure(sprintf(
        'The %s model "%s" could not complete this step.',
        $providerId,
        $modelId,
      ), 0, $e);
    }

    // Meter the turn HERE, at the seam that made the call, because this is the
    // only place that knows which model actually answered — the request usually
    // names a role, not a model. Recording is failure-proof by contract
    // ({@see UsageRecorder::record()}), so it sits outside the try above rather
    // than being mistaken for part of the inference.
    $this->usage->record($providerId, $modelId, $result, UsageRecorder::TAG_AGENT_TURN);

    // The result union — a sentence and a tool call in one turn arrive as a
    // MultiPartResult, and reading only one arm of that union is what turned a
    // successful turn into an empty message on screen. {@see ResultUnpacker}
    // carries the full account of that outage; it lives there rather than here
    // because an image turn hits the identical trap.
    [$text, $toolCalls] = $this->unpacker->textAndToolCalls($result);
    return new ReasonResult($text, $toolCalls);
  }

  /**
   * {@inheritdoc}
   */
  public function getModelChoices(string $operationType = 'chat'): ModelChoices {
    $models = [];
    foreach ($this->registry->adapters() as $providerId => $adapter) {
      foreach ($this->registry->chatModels($providerId) as $modelId => $label) {
        // Qualified `provider:model`, the value shape the rest of our model
        // layer already speaks (roles, presets, the onboarding wizard).
        $models[] = $providerId . ':' . $modelId;
      }
    }

    return new ModelChoices(
      $models,
      $this->targets->defaultQualifiedModel(),
      // Advertises the AIncient roles so a node stored as `aincient_role:task`
      // carries a valid enum member — without this the ParameterResolver rejects
      // it before the node runs. The list lives with the resolver that reads
      // those values back.
      $this->targets->operationTypeOptions(),
    );
  }

}
