<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\Adapter\AnthropicAdapter;
use Drupal\aincient_core\Inference\Adapter\GeminiAdapter;
use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Inference\ModelTargetResolver;
use Drupal\aincient_core\Inference\PlatformRegistryInterface;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use Drupal\aincient_core\Inference\ResultUnpacker;
use Drupal\aincient_core\Inference\SymfonyAiReasoner;
use Drupal\aincient_core\Inference\ToolSchema;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\flowdrop\DTO\Reason\ReasonMessage;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\Tests\aincient_core\Traits\UnmeteredInferenceTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

/**
 * Pins the options the reasoner hands to a provider, and what it reads back.
 *
 * These are regression tests for two production outages, not incidental
 * coverage. The request options are the one place this class can silently poison
 * every turn, because a rejected parameter fails the whole call rather than
 * degrading. The result SHAPE is the other: read the wrong type back and a
 * perfectly successful turn reaches the screen as silence. The seam is
 * {@see PlatformRegistryInterface} — see its docblock for why it exists.
 *
 * The result-shape cases exercise {@see ResultUnpacker} through the reasoner on
 * purpose: the union is only interesting where its output is consumed, and reading
 * it is no longer this class's own code (an image turn hits the same trap, so the
 * walk is shared).
 */
#[CoversClass(SymfonyAiReasoner::class)]
#[CoversClass(ResultUnpacker::class)]
final class SymfonyAiReasonerTest extends TestCase {

  use UnmeteredInferenceTrait;

  /**
   * The platform stand-in, recording what invoke() was called with.
   */
  private object $platform;

  protected function setUp(): void {
    parent::setUp();

    $this->platform = new class() implements PlatformInterface {

      /**
       * The options of the last invoke() call, or NULL if never called.
       *
       * @var array<string, mixed>|null
       */
      public ?array $options = NULL;

      /**
       * The model id of the last invoke() call.
       */
      public string|Model|null $model = NULL;

      /**
       * The result this platform yields — the SHAPE under test.
       *
       * Parameterised because the bug the shape tests pin is entirely about
       * which ResultInterface a bridge hands back for one and the same turn.
       */
      public ?ResultInterface $result = NULL;

      public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult {
        $this->model = $model;
        $this->options = $options;

        return new DeferredResult(
          new PlainConverter($this->result ?? new TextResult('done')),
          new InMemoryRawResult(),
        );
      }

      public function getModelCatalog(): ModelCatalogInterface {
        throw new \LogicException('The reasoner must never consult the catalog.');
      }

    };
  }

  /**
   * Temperature 0.0 must NOT reach the provider.
   *
   * The outage this prevents: FlowDrop's ReasonRequest defaults temperature to
   * 0.0 with no nullable variant, so every shipped reason node stores exactly
   * that, and Anthropic's newer models (claude-sonnet-5 and up) REJECT the
   * parameter — "`temperature` is deprecated for this model". Send it
   * unconditionally and every single turn on the `reasoning` role is a hard
   * failure, not a degraded answer. Sending it was never "preserving" the old
   * behaviour either: `temperature` appears nowhere in drupal/ai's provider base,
   * so the predecessor never forwarded one at all.
   */
  public function testZeroTemperatureIsNotSent(): void {
    $this->reason($this->request(temperature: 0.0));

    self::assertArrayNotHasKey(
      'temperature',
      $this->platform->options,
      'A default (0.0) temperature must be omitted — newer Anthropic models reject the parameter outright.',
    );
  }

  /**
   * A temperature someone actually typed IS forwarded, unchanged.
   *
   * The other half of the same defect: the fix must not become "we never send
   * temperature". An operator who sets 0.7 gets 0.7, and if a model refuses it
   * that surfaces as a named failure on a setting they chose.
   */
  public function testExplicitTemperatureIsSent(): void {
    $this->reason($this->request(temperature: 0.7));

    self::assertArrayHasKey('temperature', $this->platform->options);
    self::assertSame(0.7, $this->platform->options['temperature']);
  }

  /**
   * `max_tokens` is unconditional — it is not part of the temperature bargain.
   *
   * Guards the fix's blast radius: the omission logic must apply to temperature
   * only. A dropped `max_tokens` would let Anthropic reject the request for a
   * missing required parameter, the same class of total turn failure the
   * temperature bug caused.
   */
  public function testMaxTokensIsAlwaysSent(): void {
    $this->reason($this->request(temperature: 0.0, maxTokens: 2048));
    self::assertSame(2048, $this->platform->options['max_tokens']);

    $this->reason($this->request(temperature: 0.7, maxTokens: 512));
    self::assertSame(512, $this->platform->options['max_tokens']);
  }

  /**
   * On Gemini the SAME cap travels as `maxOutputTokens`. THE OTHER TOTAL FAILURE.
   *
   * Gemini's bridge drops the options array into `generationConfig` verbatim, so
   * `max_tokens` comes back as `Invalid JSON payload received. Unknown name
   * "max_tokens" at 'generation_config'` — a 400 on every single turn, not a
   * degraded answer. Once a Gemini role binding resolves (which it now does), a
   * reasoner that sent our neutral name unconditionally would fail every Gemini
   * turn. The rename belongs to the adapter, and this test proves the reasoner
   * actually goes through it rather than reaching the platform directly.
   */
  public function testGeminiReceivesItsOwnSpellingOfTheTokenCap(): void {
    $this->reason(
      $this->request(temperature: 0.0, maxTokens: 2048),
      NULL,
      (new \ReflectionClass(GeminiAdapter::class))->newInstanceWithoutConstructor(),
    );

    self::assertSame(2048, $this->platform->options['maxOutputTokens']);
    self::assertArrayNotHasKey(
      'max_tokens',
      $this->platform->options,
      'Gemini rejects the whole request when generationConfig carries an unknown key.',
    );
  }

  /**
   * The temperature rule survives translation, on Gemini too.
   *
   * Guards the blast radius from the other side: the dialect layer must rename, not
   * start adding or dropping parameters. A `temperature` that reappeared here would
   * re-open the Anthropic outage on any provider that shares the code path.
   */
  public function testTranslationDoesNotReintroduceADefaultTemperature(): void {
    $this->reason(
      $this->request(temperature: 0.0),
      NULL,
      (new \ReflectionClass(GeminiAdapter::class))->newInstanceWithoutConstructor(),
    );

    self::assertArrayNotHasKey('temperature', $this->platform->options);
  }

  /**
   * A request with no tools sends no `tools` key.
   *
   * An empty `tools: []` is not neutral upstream — providers validate it, and a
   * plain chat node (which declares none) would fail for a reason that has
   * nothing to do with it. Same failure shape as the temperature bug: total, not
   * partial.
   */
  public function testToolsAreOmittedWhenThereAreNone(): void {
    $this->reason($this->request(temperature: 0.0));

    self::assertArrayNotHasKey('tools', $this->platform->options);
  }

  /**
   * Declared tools DO travel, converted through the real ToolSchema.
   *
   * The inverse guard: an over-eager "omit when empty" must not swallow real
   * bindings, which would leave the agent unable to act while still answering —
   * the silent-failure mode DECISIONS 0278/0279 was about.
   */
  public function testToolsArePresentWhenDeclared(): void {
    $this->reason($this->request(temperature: 0.0, tools: [
      ['name' => 'list_pages', 'description' => 'List every page.'],
    ]));

    self::assertArrayHasKey('tools', $this->platform->options);
    self::assertCount(1, $this->platform->options['tools']);
    self::assertSame('list_pages', $this->platform->options['tools'][0]->getName());
  }

  /**
   * A sentence AND a tool call in one turn must both survive. THE REGRESSION.
   *
   * The outage this prevents, exactly as it happened in production: symfony/ai's
   * Anthropic bridge answers a turn that carries both prose and a tool call with
   * a `MultiPartResult` — NEITHER a TextResult nor a ToolCallResult — whose
   * getContent() is an array of parts. Verified live: `content: array(2) -> [0]
   * TextResult, [1] ToolCallResult`, `stop_reason: "tool_use"`. Checking only
   * `instanceof ToolCallResult` therefore missed, and the text extractor (which
   * handles string content only) returned '' — dropping the text AND the tool
   * call.
   *
   * Fail this test and EVERY AGENT TURN RENDERS AS AN EMPTY MESSAGE while every
   * node reports `status: success` and the pipeline is green end to end: the
   * DECISIONS 0278/0279 silent-failure mode, with nothing to debug from. And it
   * is the ORDINARY case, not an edge one — Atelier's shipped system prompts
   * instruct this shape ("Before a tool call, write exactly ONE short plain
   * sentence saying what you are doing").
   */
  public function testMultiPartResultYieldsBothTextAndToolCalls(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new TextResult('I will look that up.'),
      new ToolCallResult([new ToolCall('toolu_01', 'aincient_list_pages', ['limit' => 10])]),
    ]));

    self::assertSame(
      'I will look that up.',
      $result->getText(),
      'The prose part of a MultiPartResult must reach the screen — losing it is the empty-message outage.',
    );
    self::assertTrue($result->hasToolCalls(), 'The tool call must survive the MultiPartResult path.');
    self::assertCount(1, $result->getToolCalls());
    self::assertSame('aincient_list_pages', $result->getToolCalls()[0]['name']);
    self::assertSame(['limit' => 10], $result->getToolCalls()[0]['args']);
    self::assertSame('toolu_01', $result->getToolCalls()[0]['tool_call_id']);
  }

  /**
   * A MultiPartResult of text alone still yields that text.
   *
   * A bridge may wrap a plain answer in a single-part MultiPartResult; the
   * unwrapping must not depend on a tool call being present.
   */
  public function testMultiPartResultWithOnlyTextYieldsText(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new TextResult('Here are your pages.'),
    ]));

    self::assertSame('Here are your pages.', $result->getText());
    self::assertFalse($result->hasToolCalls());
    self::assertSame([], $result->getToolCalls());
  }

  /**
   * A MultiPartResult of tool calls alone still yields those calls.
   *
   * The mirror guard: a model that skips the preamble must still be able to act.
   * Parallel calls arrive as several parts (see MultiPartResult::asToolCallResult),
   * so they must be merged rather than only the first one taken.
   */
  public function testMultiPartResultWithOnlyToolCallsYieldsCalls(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new ToolCallResult([new ToolCall('toolu_a', 'aincient_list_pages')]),
      new ToolCallResult([new ToolCall('toolu_b', 'aincient_preview_page', ['id' => 7])]),
    ]));

    self::assertSame('', $result->getText());
    self::assertCount(2, $result->getToolCalls());
    self::assertSame('aincient_list_pages', $result->getToolCalls()[0]['name']);
    self::assertSame('aincient_preview_page', $result->getToolCalls()[1]['name']);
    self::assertSame('toolu_b', $result->getToolCalls()[1]['tool_call_id']);
  }

  /**
   * Several text parts join into one message, blank-line separated and trimmed.
   *
   * Content-block APIs can split prose across parts. Concatenating without a
   * separator glues sentences together; keeping only one part loses half the
   * answer. The trim keeps a leading/trailing empty part from producing stray
   * blank lines on screen.
   */
  public function testMultiPartResultJoinsSeveralTextParts(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new TextResult('First thought.'),
      new TextResult('Second thought.'),
    ]));

    self::assertSame("First thought.\n\nSecond thought.", $result->getText());
  }

  /**
   * A bare ToolCallResult must not regress.
   *
   * The shape the predecessor DID handle — the one thing that never looked broken
   * before. Handling MultiPartResult must not cost it.
   */
  public function testBareToolCallResultStillYieldsCalls(): void {
    $result = $this->reason($this->request(temperature: 0.0), new ToolCallResult([
      new ToolCall('toolu_bare', 'aincient_list_pages', ['limit' => 3]),
    ]));

    self::assertSame('', $result->getText());
    self::assertCount(1, $result->getToolCalls());
    self::assertSame('aincient_list_pages', $result->getToolCalls()[0]['name']);
    self::assertSame(['limit' => 3], $result->getToolCalls()[0]['args']);
    self::assertSame('toolu_bare', $result->getToolCalls()[0]['tool_call_id']);
  }

  /**
   * A bare TextResult must not regress.
   *
   * The other single-part shape: a final answer with no tool call is the most
   * common turn there is.
   */
  public function testBareTextResultStillYieldsText(): void {
    $result = $this->reason($this->request(temperature: 0.0), new TextResult('All done.'));

    self::assertSame('All done.', $result->getText());
    self::assertFalse($result->hasToolCalls());
  }

  /**
   * A MultiPartResult nested inside a MultiPartResult is unpacked too.
   *
   * No bridge emits this today, which is exactly why the test is cheap insurance:
   * the unpacking recurses precisely so a future bridge cannot re-open this
   * silence, and a recursion nobody exercises is a recursion that rots.
   */
  public function testNestedMultiPartResultIsUnpacked(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new TextResult('Outer sentence.'),
      new MultiPartResult([
        new TextResult('Inner sentence.'),
        new ToolCallResult([new ToolCall('toolu_nested', 'aincient_list_pages')]),
      ]),
    ]));

    self::assertSame("Outer sentence.\n\nInner sentence.", $result->getText());
    self::assertCount(1, $result->getToolCalls());
    self::assertSame('toolu_nested', $result->getToolCalls()[0]['tool_call_id']);
  }

  /**
   * The tool-call id from a MultiPartResult survives all the way to the replay.
   *
   * Why the id and not just the name: {@see MessageMapper::mapToolCalls()} SKIPS
   * any stored call whose `tool_call_id` is empty, so an id lost on the way out
   * of MultiPartResult can never be paired back on the next turn — the assistant
   * tool-use turn vanishes from the replayed history and the provider sees a
   * dangling tool result. That is a second, later silence with the same
   * signature as the first, so the round trip is pinned here rather than assumed.
   */
  public function testToolCallIdSurvivesTheMultiPartPathIntoTheReplayedHistory(): void {
    $result = $this->reason($this->request(temperature: 0.0), new MultiPartResult([
      new TextResult('Looking now.'),
      new ToolCallResult([new ToolCall('toolu_roundtrip', 'aincient_list_pages', ['limit' => 1])]),
    ]));

    $bag = (new MessageMapper())->toMessageBag('You are helpful.', [
      new ReasonMessage('user', 'list my pages'),
      ReasonMessage::fromArray($result->getAssistantMessage()),
    ]);

    $assistant = NULL;
    foreach ($bag->getMessages() as $message) {
      if ($message instanceof AssistantMessage) {
        $assistant = $message;
      }
    }

    self::assertInstanceOf(
      AssistantMessage::class,
      $assistant,
      'An assistant tool-use turn with a lost id is dropped from the replayed history entirely.',
    );
    self::assertCount(1, $assistant->getToolCalls());
    self::assertSame('toolu_roundtrip', $assistant->getToolCalls()[0]->getId());
    self::assertSame('aincient_list_pages', $assistant->getToolCalls()[0]->getName());
  }

  /**
   * Runs one turn through a reasoner wired to the recording platform.
   *
   * @param \Drupal\flowdrop\DTO\Reason\ReasonRequest $request
   *   The request to run.
   * @param \Symfony\AI\Platform\Result\ResultInterface|null $result
   *   The result the platform should yield, or NULL for the default TextResult.
   *
   * @return \Drupal\flowdrop\DTO\Reason\ReasonResult
   *   What the reasoner made of it.
   */
  private function reason(ReasonRequest $request, ?ResultInterface $result = NULL, ?ProviderAdapterInterface $adapter = NULL): ReasonResult {
    $this->platform->result = $result;

    $registry = $this->createMock(PlatformRegistryInterface::class);
    $registry->method('platform')->willReturn($this->platform);
    // The REAL adapter, because the option names it produces are the thing under
    // test — a stub that echoed the options back would pin nothing. Anthropic by
    // default, since every request below names an Anthropic model.
    $registry->method('adapter')->willReturn(
      $adapter ?? (new \ReflectionClass(AnthropicAdapter::class))->newInstanceWithoutConstructor()
    );

    $reasoner = new SymfonyAiReasoner(
      $registry,
      // The real target resolver over a ModelRoleResolver built without its
      // constructor: role resolution must not be consulted here — every request
      // below names an explicit `provider:model`, which short-circuits it — and a
      // dependency-less resolver proves that by fataling if it ever is.
      new ModelTargetResolver(
        (new \ReflectionClass(ModelRoleResolver::class))->newInstanceWithoutConstructor()
      ),
      new MessageMapper(),
      new ToolSchema(),
      new ResultUnpacker(new MessageMapper()),
      $this->unmeteredRecorder(),
      new NullLogger(),
    );

    return $reasoner->reason($request);
  }

  /**
   * A request on an explicit `provider:model`, bypassing the role layer.
   *
   * @param float $temperature
   *   The sampling temperature under test.
   * @param int $maxTokens
   *   The token cap.
   * @param array<int, array<string, mixed>> $tools
   *   Tool definitions the model may call.
   */
  private function request(float $temperature, int $maxTokens = 1024, array $tools = []): ReasonRequest {
    return new ReasonRequest(
      [new ReasonMessage('user', 'hello')],
      $tools,
      'anthropic:claude-sonnet-5',
      'chat',
      $temperature,
      $maxTokens,
      'You are helpful.',
    );
  }

}
