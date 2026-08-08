<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\AincientReasonerInterface;
use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\AincientReason;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop\DTO\Reason\ModelChoices;
use Drupal\flowdrop\DTO\Reason\ReasonRequest;
use Drupal\flowdrop\DTO\Reason\ReasonResult;
use Drupal\flowdrop\DTO\Tool\ToolCollection;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pins Atelier's owned reason node — its contract against a fake backend.
 *
 * The point of owning the node (ADR 0365) is a richer result the engine's
 * four-field DTO cannot carry, so these tests assert two things at once: that
 * the three load-bearing behaviours the engine node had are preserved verbatim
 * (buffer healing, the trailing-assistant no-op, tools reaching the request),
 * and that the three new ports — `raw_result`, `codec`, `error_detail` — reach
 * the output. A fake {@see AincientReasonerInterface} captures the request and
 * returns a canned {@see AincientReasonResult}, so there is no live provider and
 * the node's own contract is what is under test. Mirrors the engine's ReasonTest
 * so a divergence between the two nodes is visible.
 *
 * @group aincient_flows
 */
#[CoversClass(AincientReason::class)]
final class AincientReasonTest extends UnitTestCase {

  /**
   * A fake reasoner that records requests and returns a canned rich result.
   *
   * @param \Drupal\aincient_core\Inference\AincientReasonResult $result
   *   The canned result to return from every reasonRich() call.
   * @param array<string, mixed> $captured
   *   The by-ref bucket the fake writes each call's request/count into.
   */
  private function fakeReasoner(AincientReasonResult $result, array &$captured): AincientReasonerInterface {
    return new class($result, $captured) implements AincientReasonerInterface {

      /**
       * @param \Drupal\aincient_core\Inference\AincientReasonResult $result
       *   The canned result to return from every reasonRich() call.
       * @param array<string, mixed> $captured
       *   The by-ref bucket to write each call's request/count into.
       */
      public function __construct(
        private AincientReasonResult $result,
        private array &$captured,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function reasonRich(ReasonRequest $request): AincientReasonResult {
        $this->captured['calls'] = ($this->captured['calls'] ?? 0) + 1;
        $this->captured['request'] = $request;
        return $this->result;
      }

      /**
       * {@inheritdoc}
       */
      public function reason(ReasonRequest $request): ReasonResult {
        $rich = $this->reasonRich($request);
        return new ReasonResult($rich->getText(), $rich->getToolCalls());
      }

      /**
       * {@inheritdoc}
       */
      public function getModelChoices(string $operationType = 'chat'): ModelChoices {
        return new ModelChoices();
      }

    };
  }

  /**
   * Builds an owned reason node bound to the given fake reasoner.
   */
  private function reasonNode(AincientReasonerInterface $reasoner): AincientReason {
    $node = new AincientReason([], 'aincient_flows:aincient_reason', [], $reasoner);
    $node->setTools(new ToolCollection([]));
    return $node;
  }

  /**
   * Tool calls pass through, and the three new ports carry the rich result.
   */
  public function testToolCallsAndRichPortsPassThrough(): void {
    $captured = [];
    $calls = [['name' => 'writer', 'args' => ['x' => 1], 'tool_call_id' => 'c1']];
    $raw = ['choices' => [['message' => ['tool_calls' => []]]]];
    $node = $this->reasonNode($this->fakeReasoner(
      new AincientReasonResult('thinking', $calls, $raw, 'openai'),
      $captured,
    ));

    $result = $node->process(new ParameterBag([
      'messages' => [['role' => 'user', 'content' => 'go']],
    ]));

    // The four ports the engine node also emits, unchanged.
    $this->assertTrue($result['has_tool_calls']);
    $this->assertSame($calls, $result['tool_calls']);
    $this->assertSame('thinking', $result['text']);
    $this->assertSame('assistant', $result['assistant_message']['role']);
    $this->assertSame($calls, $result['assistant_message']['tool_calls']);

    // The three ports only the owned node emits.
    $this->assertSame($raw, $result['raw_result']);
    $this->assertSame('openai', $result['codec']);
    $this->assertNull($result['error_detail']);
  }

  /**
   * A trailing assistant turn is a no-op re-entry, on every port.
   *
   * The backend is never consulted, and the three new ports terminate empty
   * rather than absent — a downstream branch reading `error_detail` or `codec`
   * must not trip over a missing key on the no-op path.
   */
  public function testTrailingAssistantIsNoOp(): void {
    $captured = [];
    $node = $this->reasonNode($this->fakeReasoner(
      new AincientReasonResult('unused'),
      $captured,
    ));

    $result = $node->process(new ParameterBag([
      'messages' => [
        ['role' => 'user', 'content' => 'go'],
        ['role' => 'assistant', 'content' => 'here is your answer'],
      ],
    ]));

    $this->assertFalse($result['has_tool_calls']);
    $this->assertSame('', $result['text']);
    $this->assertSame([], $result['assistant_message']);
    $this->assertSame([], $result['raw_result']);
    $this->assertSame('', $result['codec']);
    $this->assertNull($result['error_detail']);
    $this->assertArrayNotHasKey('calls', $captured, 'The backend must not have been consulted.');
  }

  /**
   * Tool pairing is healed before inference — the engine DTO, reused not forked.
   */
  public function testHealingAppliedBeforeInference(): void {
    $captured = [];
    $node = $this->reasonNode($this->fakeReasoner(new AincientReasonResult('ok'), $captured));

    $node->process(new ParameterBag([
      'messages' => [
        ['role' => 'user', 'content' => 'go'],
        [
          'role' => 'assistant',
          'content' => '',
          'tool_calls' => [
            ['name' => 'writer', 'args' => [], 'tool_call_id' => 'c1'],
          ],
        ],
      ],
    ]));

    $this->assertSame(1, $captured['calls']);
    $request = $captured['request'];
    $this->assertInstanceOf(ReasonRequest::class, $request);
    $messages = $request->getMessages();
    // user, assistant(tool_calls), synthetic tool result.
    $this->assertCount(3, $messages);
    $this->assertSame('tool', end($messages)->getRole());
    $this->assertSame('c1', end($messages)->getToolCallId());
  }

  /**
   * A provider failure is caught and reported, not re-thrown.
   *
   * Stop-and-report: the node turns an AiProviderFailure into a COMPLETED step
   * whose `error_detail` carries the struct (for a console card / an agent
   * branch) and whose `text` carries the reader-facing sentence (so the run ends
   * with the failure as its reply on every surface). `has_tool_calls` FALSE
   * steers the loop to its answer branch; `assistant_message` stays empty so a
   * failed turn is never written into the conversation buffer.
   */
  public function testProviderFailureIsCaughtAndReported(): void {
    $failure = new AiProviderFailure(
      'Anthropic rejected the key Atelier has for it. Reconnect anthropic and try again.',
      0,
      NULL,
      ProviderCall::KIND_AUTH,
      'anthropic',
      'claude-sonnet-5',
      FALSE,
    );
    $node = $this->reasonNode(new class($failure) implements AincientReasonerInterface {

      public function __construct(private AiProviderFailure $failure) {}

      public function reasonRich(ReasonRequest $request): AincientReasonResult {
        throw $this->failure;
      }

      public function reason(ReasonRequest $request): ReasonResult {
        throw $this->failure;
      }

      public function getModelChoices(string $operationType = 'chat'): ModelChoices {
        return new ModelChoices();
      }

    });

    $result = $node->process(new ParameterBag([
      'messages' => [['role' => 'user', 'content' => 'go']],
    ]));

    // The run ends here: no tool calls, no loop.
    $this->assertFalse($result['has_tool_calls']);
    $this->assertSame([], $result['tool_calls']);
    // The reader-facing sentence is the reply, on every surface.
    $this->assertStringContainsString('Reconnect anthropic', $result['text']);
    // Nothing is written into the buffer for a failed turn.
    $this->assertSame([], $result['assistant_message']);
    // The struct a card / branch reads, deterministically.
    $this->assertSame([
      'kind' => ProviderCall::KIND_AUTH,
      'provider' => 'anthropic',
      'model' => 'claude-sonnet-5',
      'message' => 'Anthropic rejected the key Atelier has for it. Reconnect anthropic and try again.',
      'retryable' => FALSE,
    ], $result['error_detail']);
  }

  /**
   * A local misconfiguration is NOT caught — it keeps failing the node.
   *
   * Only a provider OUTCOME is stop-and-reported. A missing provider is the
   * operator's to fix ("connect a provider"), and a genuine bug is a real
   * stack trace — both must reach the engine's FAILED path, not be dressed up
   * as an answer. Any non-AiProviderFailure throwable propagates.
   */
  public function testNonProviderThrowablePropagates(): void {
    $node = $this->reasonNode(new class implements AincientReasonerInterface {

      public function reasonRich(ReasonRequest $request): AincientReasonResult {
        throw new \LogicException('provider not configured');
      }

      public function reason(ReasonRequest $request): ReasonResult {
        throw new \LogicException('provider not configured');
      }

      public function getModelChoices(string $operationType = 'chat'): ModelChoices {
        return new ModelChoices();
      }

    });

    $this->expectException(\LogicException::class);
    $node->process(new ParameterBag([
      'messages' => [['role' => 'user', 'content' => 'go']],
    ]));
  }

  /**
   * An empty conversation throws a clear error.
   */
  public function testEmptyMessagesThrows(): void {
    $captured = [];
    $node = $this->reasonNode($this->fakeReasoner(new AincientReasonResult('x'), $captured));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no messages to reason over');
    $node->process(new ParameterBag(['messages' => []]));
  }

}
