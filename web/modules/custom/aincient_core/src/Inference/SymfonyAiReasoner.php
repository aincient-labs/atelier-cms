<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Event\InferenceStartedEvent;
use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Usage\UsageRecorder;
use Drupal\flowdrop\DTO\Reason\ModelChoices;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
final class SymfonyAiReasoner implements AincientReasonerInterface {

  /**
   * Output discipline, appended to the system prompt of every TOOL-AWARE turn.
   *
   * WHY IT IS HERE AND NOT IN THE FLOW TEMPLATES. It is true of every agent we
   * ship and every model an operator can bind, so a copy in each of the six
   * `prompt_template` nodes would be six places to drift and one more thing a new
   * flow forgets. This is the only seam every agent turn passes through, and the
   * only one that knows a turn is tool-aware at all.
   *
   * WHAT IT IS ABOUT. A turn has a finite output budget, and overrunning it is
   * not a partial answer — the provider cuts the tool call mid-argument and the
   * OpenAI-compatible converter then discards the whole malformed call, handing
   * back `finish_reason: length` with empty text (see
   * `Bridge/Generic/Completions/CompletionsConversionTrait::convertChoice()`).
   * Every node still reports success and the user gets an empty bubble: the
   * DECISIONS 0278/0279 failure mode with a third cause.
   *
   * REASONING MODELS make it ordinary rather than rare, which is why this is
   * phrased for all models and names none. A thinking model spends part of the
   * same budget before it writes a single visible token, so the margin a
   * non-reasoning model had is simply gone — a `deepseek-v4-pro` page turn burned
   * 1024 reasoning tokens and then truncated its `preview_page` payload at the
   * cap. Raising the cap buys room; it does not make one enormous argument a good
   * plan, because the next model reasons harder or the next page is longer.
   *
   * It says nothing about which tools exist, what a page is, or how any studio
   * works — that stays in the flow templates, which remain the readable, editable
   * account of what each agent does.
   */
  private const OUTPUT_DISCIPLINE = <<<'TXT'
    OUTPUT BUDGET. Every turn has a finite output allowance, and some models spend
    part of it thinking before writing anything. A tool call that runs past the
    allowance is cut off mid-argument and arrives as NOTHING — the whole call is
    discarded, not delivered in part, and the user sees an empty reply. So:

    - Think briefly, then act. Don't restate your plan at length before calling a
      tool; the call itself is the answer.
    - Keep arguments COMPACT. Pass only the fields the tool documents, in the
      grammar it documents. Never emit HTML, CSS or markup a tool did not ask for,
      and don't pad copy to fill a section.
    - Prefer SEVERAL SMALL CALLS to one large one. Each call layers onto what the
      previous one did, so work in passes — the structure first, then the detail —
      and stop cleanly at the end of each pass rather than risking a cut-off.
    - If you can't fit the work in one call, say what you did and what's next in
      one short sentence, then continue in the following turn.
    TXT;

  public function __construct(
    private readonly PlatformRegistryInterface $registry,
    private readonly ModelTargetResolver $targets,
    private readonly MessageMapper $messages,
    private readonly ToolSchema $tools,
    private readonly ResultUnpacker $unpacker,
    private readonly UsageRecorder $usage,
    private readonly LoggerInterface $logger,
    private readonly ProviderCall $providerCall,
    // Trust-the-wire tool-call recovery: when the config-selected bridge parsed
    // no tool call but the raw body carries one (a lying BYOK gateway), re-read
    // it from the shape it really is. Only consulted on the no-tool-calls path.
    private readonly ToolCallCodec $codec,
    // Announces that a call is going out, so the console can say so while it is
    // in flight ({@see InferenceStartedEvent} for why nobody else can tell it).
    // OPTIONAL on purpose: this class is constructed directly in unit tests and
    // narration is not part of reasoning — no dispatcher simply means no
    // progress frame, never a failed turn.
    private readonly ?EventDispatcherInterface $dispatcher = NULL,
  ) {}

  /**
   * {@inheritdoc}
   *
   * The narrow engine contract: the same inference `reasonRich()` runs,
   * projected down to FlowDrop's four-field DTO. Everything that speaks only
   * `ChatReasonerInterface` — the engine's own reason node — is served here
   * unchanged; only Atelier's own node calls `reasonRich()` for the raw body,
   * codec and structured error the wider result carries.
   */
  public function reason(ReasonRequest $request): ReasonResult {
    $rich = $this->reasonRich($request);
    return new ReasonResult($rich->getText(), $rich->getToolCalls());
  }

  /**
   * {@inheritdoc}
   */
  public function reasonRich(ReasonRequest $request): AincientReasonResult {
    // Resolution order (explicit provider:model → the node's AIncient role → the
    // `task` tier) lives in ModelTargetResolver because the brand specialists'
    // simple-chat node stores the identical two fields and must resolve them the
    // same way — see that class for why a divergence there would be invisible.
    [$providerId, $modelId] = $this->targets->resolve(
      $request->getOperationType(),
      $request->getModel(),
    );

    // Resolved before the message bag, because whether this turn is tool-aware
    // decides what its system prompt says — {@see self::OUTPUT_DISCIPLINE}.
    $declarations = $this->tools->toTools($request->getTools());

    $bag = $this->messages->toMessageBag(
      $this->systemPrompt($request->getSystemPrompt(), $declarations !== []),
      $request->getMessages(),
    );

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
    }
    catch (ProviderConfigurationException $e) {
      // Local misconfiguration — not an upstream failure. Let it through as-is
      // so the caller can say "connect a provider" instead of blaming the model.
      throw $e;
    }

    // The turn's longest wait starts HERE, and this is the only place that
    // knows it: the orchestrator dispatches nothing until the node completes,
    // so without this announcement the console has nothing to say for the
    // whole call. Best-effort — a subscriber's failure must not fail a turn.
    $this->announceStart($providerId, $modelId, $request->getOperationType(), $declarations !== []);

    // Retries and classification live in ProviderCall, shared with the gateway
    // and the chat completer (atelier-cms#8). A retried attempt deliberately
    // does NOT re-announce: the console is already showing this step, and a
    // second "thinking…" frame would read as a second turn.
    $result = $this->providerCall->run(
      // Resolve inside the closure: DeferredResult is lazy, so an upstream fault
      // surfaces on conversion rather than on invoke(). Converting outside is
      // exactly how an error escapes unwrapped — and unretried.
      fn (): object => $platform->invoke($modelId, $bag, $options)->getResult(),
      $providerId,
      $modelId,
      'step',
    );

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

    // The un-parsed wire body, carried through on `raw_result` for any surface
    // that wants to inspect the actual response. Capturing it must never fail a
    // turn ({@see self::rawBody()} swallows to []).
    $raw = $this->rawBody($result);

    // TRUST THE WIRE. The bridge that parsed $toolCalls above was chosen by the
    // CONFIGURED provider id, which a BYOK gateway lies about — so when that
    // parse found NO tool call, the answer may not be tool-less at all: the
    // gateway may have returned a call in a shape (or under a finish_reason) the
    // config bridge does not read and silently dropped. Re-read the raw body as
    // the dialect it actually is. This runs ONLY on the suspected-mismatch path
    // (no tool calls parsed): a clean parse short-circuits it, so the happy path
    // pays nothing but a single array check. `codec` records the detected
    // dialect so the recovery is visible; it stays '' on the happy path, meaning
    // "the config-trusted parse held". A false positive is not possible — detect
    // returns '' unless the raw body genuinely carries tool-call markers the
    // parse missed.
    $codec = '';
    if ($toolCalls === []) {
      $detected = $this->codec->detect($raw);
      if ($detected !== '') {
        $recovered = $this->codec->decode($raw, $detected);
        if ($recovered !== []) {
          $toolCalls = $recovered;
          $codec = $detected;
          $this->logger->warning('Recovered @n tool call(s) the @provider/@model bridge dropped, re-read from the wire as @codec.', [
            '@n' => count($recovered),
            '@provider' => $providerId,
            '@model' => $modelId,
            '@codec' => $detected,
          ]);
        }
      }
    }

    return new AincientReasonResult($text, $toolCalls, $raw, $codec);
  }

  /**
   * The provider's un-parsed response body, or `[]` if it cannot be read.
   *
   * `DeferredResult::getResult()` guarantees the raw result is populated, so on
   * the success path this is present — but this runs on every turn and its only
   * consumer is an optional recovery path, so any shape the bridge does not
   * expose as an array is swallowed to `[]` rather than allowed to turn a good
   * answer into an error.
   *
   * @param mixed $result
   *   Whatever the provider call returned (a symfony/ai ResultInterface).
   *
   * @return array<string, mixed>
   *   The decoded wire body, or `[]`.
   */
  private function rawBody(mixed $result): array {
    if (!is_object($result) || !method_exists($result, 'getRawResult')) {
      return [];
    }
    try {
      $raw = $result->getRawResult();
      $data = $raw?->getData();
      return is_array($data) ? $data : [];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not capture the raw provider body: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * The node's system prompt, plus the output-budget clause on a tool-aware turn.
   *
   * Gated on tools rather than applied to everything: the clause is entirely
   * about the cost of an overlong TOOL ARGUMENT, so on a turn that declared no
   * tools it would be advice about a thing that cannot happen — and the one-shot
   * callers ({@see AiGateway}) ask for a sentence, where "prefer several small
   * calls" is noise that eats their own budget.
   *
   * Appended, never prepended: the node's prompt establishes who the agent is,
   * and a generic paragraph in front of that reads as the instruction and buries
   * the role.
   *
   * @param string $prompt
   *   The system prompt as the node stored it, possibly ''.
   * @param bool $toolAware
   *   Whether this turn declared any tools.
   *
   * @return string
   *   The prompt to send.
   */
  private function systemPrompt(string $prompt, bool $toolAware): string {
    if (!$toolAware) {
      return $prompt;
    }

    $prompt = trim($prompt);
    return $prompt === ''
      ? self::OUTPUT_DISCIPLINE
      : $prompt . "\n\n" . self::OUTPUT_DISCIPLINE;
  }

  /**
   * Announce an outgoing call, never letting the announcement break the call.
   *
   * A subscriber here writes to a live SSE stream; a broken pipe or a client
   * that walked away must cost the progress line and nothing else. Same reason
   * the dispatcher is optional: reasoning does not depend on being narrated.
   */
  private function announceStart(string $providerId, string $modelId, string $operationType, bool $toolAware): void {
    if ($this->dispatcher === NULL) {
      return;
    }
    try {
      $this->dispatcher->dispatch(
        new InferenceStartedEvent($providerId, $modelId, $operationType, $toolAware),
        InferenceStartedEvent::EVENT_NAME,
      );
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not announce the start of an inference call: @m', ['@m' => $e->getMessage()]);
    }
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
