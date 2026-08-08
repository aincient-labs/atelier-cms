<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\aincient_chat\EventSubscriber\NodeProgressSubscriber;
use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\aincient_core\Inference\ProviderFailureLog;
use Drupal\flowdrop_job\FlowDropJobInterface;
use Drupal\flowdrop_runtime\Event\JobCompletedEvent;

/**
 * Tests the FlowDrop job → `node` frame relay.
 *
 * Pure unit: the job is mocked, the relay is real with a collecting emitter,
 * and the FlowDrop event class is autoloaded from contrib (no container).
 *
 * @coversDefaultClass \Drupal\aincient_chat\EventSubscriber\NodeProgressSubscriber
 * @group aincient
 */
final class NodeProgressSubscriberTest extends UnitTestCase {

  /**
   * The relay under the subscriber.
   */
  private StreamRelay $relay;

  /**
   * Events the armed relay forwarded.
   *
   * @var \Drupal\aincient_chat\Event\ChatEvent[]
   */
  private array $emitted = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->relay = new StreamRelay();
    $this->relay->arm(function (ChatEvent $event): void {
      $this->emitted[] = $event;
    });
  }

  /**
   * A scripted job double.
   *
   * @param array $metadata
   *   The job metadata (node_type_id, execution_time_us, …).
   */
  private function mockJob(string $nodeId, ?string $label, string $status, array $metadata = [], string $error = '', array $outputData = []): FlowDropJobInterface {
    $job = $this->createMock(FlowDropJobInterface::class);
    $job->method('getNodeId')->willReturn($nodeId);
    $job->method('label')->willReturn($label);
    $job->method('getStatus')->willReturn($status);
    $job->method('getMetadata')->willReturn($metadata);
    $job->method('getErrorMessage')->willReturn($error);
    $job->method('getOutputData')->willReturn($outputData);
    return $job;
  }

  /**
   * A completed job becomes a `node` frame with label/type/elapsed.
   *
   * @covers ::onJobCompleted
   */
  public function testCompletedJobBecomesNodeFrame(): void {
    $job = $this->mockJob('agent_reason', 'Reason (agent step)', 'completed', [
      'node_type_id' => 'aincient_reason',
      // 1.234567s — must round to whole milliseconds.
      'execution_time_us' => 1234567,
    ]);

    (new NodeProgressSubscriber($this->relay, new ProviderFailureLog()))
      ->onJobCompleted(new JobCompletedEvent($job, [], 'exec-1'));

    $this->assertCount(1, $this->emitted);
    $event = $this->emitted[0];
    $this->assertSame(ChatEventType::NODE, $event->type);
    $this->assertSame([
      'node_id' => 'agent_reason',
      'label' => 'Reason (agent step)',
      'status' => 'completed',
      'node_type_id' => 'aincient_reason',
      'elapsed_ms' => 1235,
    ], $event->data);
  }

  /**
   * A failed job carries its error; a label-less job falls back to node id;
   * absent timing emits no elapsed_ms key.
   *
   * @covers ::onJobCompleted
   */
  public function testFailedJobCarriesErrorAndFallbackLabel(): void {
    $job = $this->mockJob('invoke_capability', NULL, 'failed', [], 'The capability exploded.');

    (new NodeProgressSubscriber($this->relay, new ProviderFailureLog()))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame('invoke_capability', $data['label']);
    $this->assertSame('failed', $data['status']);
    $this->assertSame('The capability exploded.', $data['error']);
    $this->assertArrayNotHasKey('elapsed_ms', $data);
  }

  /**
   * A tool-call job (ScopedToolInvoker recording) is flagged `tool` so the
   * trail renders it as tool usage; plain nodes carry no flag.
   *
   * @covers ::onJobCompleted
   */
  public function testToolInvocationJobIsFlagged(): void {
    $tool = $this->mockJob('create_1', 'Create content', 'completed', [
      'node_type_id' => 'create_content',
      'tool_invocation' => TRUE,
    ]);
    $plain = $this->mockJob('agent_reason', 'Reason', 'completed');

    $subscriber = new NodeProgressSubscriber($this->relay, new ProviderFailureLog());
    $subscriber->onJobCompleted(new JobCompletedEvent($tool, []));
    $subscriber->onJobCompleted(new JobCompletedEvent($plain, []));

    $this->assertTrue($this->emitted[0]->data['tool']);
    $this->assertArrayNotHasKey('tool', $this->emitted[1]->data);
  }

  /**
   * A failed node whose error is a PROVIDER failure carries the kind — and the
   * engine's "Node execution failed for …" wrapper is replaced by the sentence.
   *
   * `rate_limit` on purpose: it is the kind that offers no action, so this asserts
   * the classification alone without needing a route table for the link (see
   * ProviderFailureSurfaceTest for the kind→action split).
   *
   * @covers ::onJobCompleted
   */
  public function testProviderFailureCarriesItsKindAndCleanSentence(): void {
    $log = new ProviderFailureLog();
    $sentence = 'Anthropic is rate-limiting this key, so it refused the request.';
    $log->record(new AiProviderFailure($sentence, 0, NULL, ProviderCall::KIND_RATE_LIMIT));

    $job = $this->mockJob(
      'agent_reason',
      'Reason',
      'failed',
      [],
      'Node execution failed for agent_reason: ' . $sentence,
    );
    (new NodeProgressSubscriber($this->relay, $log))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame($sentence, $data['error']);
    $this->assertSame('rate_limit', $data['error_kind']);
    // No action for this kind: the limit is the provider's, and a link to our
    // settings would blame the reader for someone else's quota.
    $this->assertArrayNotHasKey('error_action', $data);
    // Nothing ran before it, so the reader may simply send the turn again.
    $this->assertTrue($data['error_retry']);
    $this->assertArrayNotHasKey('error_note', $data);
  }

  /**
   * A stop-and-reported node (COMPLETED, carrying `error_detail`) drives the
   * SAME card as a failed one — off the struct, with no string round-trip.
   *
   * This is the agent path after the reason node started catching provider
   * failures: the node completes rather than failing, and the kind rides its
   * `error_detail` output. No ProviderFailureLog entry is needed — the struct is
   * self-describing — so this asserts the card is built without one recorded.
   *
   * @covers ::onJobCompleted
   */
  public function testStopAndReportedNodeDrivesTheCardFromErrorDetail(): void {
    $sentence = 'Anthropic is rate-limiting this key, so it refused the request.';
    $job = $this->mockJob('agent_reason', 'Reason', 'completed', [], '', [
      'error_detail' => [
        'kind' => ProviderCall::KIND_RATE_LIMIT,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'message' => $sentence,
        'retryable' => TRUE,
      ],
    ]);

    (new NodeProgressSubscriber($this->relay, new ProviderFailureLog()))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame('completed', $data['status']);
    $this->assertSame($sentence, $data['error']);
    $this->assertSame('rate_limit', $data['error_kind']);
    // Nothing ran before it, so the reader may simply send the turn again.
    $this->assertTrue($data['error_retry']);
  }

  /**
   * The struct path wins over the FAILED-string path when both are present.
   *
   * A defensive precedence: a node that stop-and-reported should never ALSO be
   * classified from a stale ProviderFailureLog entry left by an earlier hiccup —
   * its own `error_detail` is the authoritative, self-describing account.
   *
   * @covers ::onJobCompleted
   */
  public function testErrorDetailTakesPrecedenceOverTheLog(): void {
    $log = new ProviderFailureLog();
    // A stale, UNRELATED auth failure recorded earlier in the same request.
    $log->record(new AiProviderFailure('Anthropic rejected the key.', 0, NULL, ProviderCall::KIND_AUTH));

    $sentence = 'This turn was too long for "gpt-5" to finish.';
    $job = $this->mockJob('agent_reason', 'Reason', 'completed', [], '', [
      'error_detail' => [
        'kind' => ProviderCall::KIND_TOO_LONG,
        'provider' => 'openai',
        'model' => 'gpt-5',
        'message' => $sentence,
        'retryable' => FALSE,
      ],
    ]);

    (new NodeProgressSubscriber($this->relay, $log))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame('too_long', $data['error_kind'], 'The struct, not the stale log, classifies this frame.');
    $this->assertSame($sentence, $data['error']);
  }

  /**
   * A tool that already ran in this turn withholds Retry from a later fault.
   *
   * GATE 2, end to end. The signal is this subscriber having SEEN a tool job go by
   * in the same request (ScopedToolInvoker records every tool call as a pipeline
   * job that dispatches this event). A rate limit that lands after `create_content`
   * has run is the StaleTurnRecovery hazard wearing a friendly button: re-sending
   * the same words would make a second page. The card says so instead.
   *
   * @covers ::onJobCompleted
   */
  public function testAToolAlreadyRunWithholdsRetry(): void {
    $log = new ProviderFailureLog();
    $sentence = 'Anthropic is rate-limiting this key, so it refused the request.';
    $log->record(new AiProviderFailure($sentence, 0, NULL, ProviderCall::KIND_RATE_LIMIT));

    $tool = $this->mockJob('create_1', 'Create content', 'completed', [
      'node_type_id' => 'create_content',
      'tool_invocation' => TRUE,
    ]);
    $failed = $this->mockJob('agent_reason', 'Reason', 'failed', [], 'Node execution failed for agent_reason: ' . $sentence);

    $subscriber = new NodeProgressSubscriber($this->relay, $log);
    $subscriber->onJobCompleted(new JobCompletedEvent($tool, []));
    $subscriber->onJobCompleted(new JobCompletedEvent($failed, []));

    $data = $this->emitted[1]->data;
    $this->assertSame('rate_limit', $data['error_kind']);
    $this->assertArrayNotHasKey('error_retry', $data);
    $this->assertStringContainsString('already took effect', $data['error_note']);
  }

  /**
   * A tool that FAILED still withholds Retry — it may have applied half its work.
   *
   * The conservative direction, on purpose: "the tool errored, so nothing landed"
   * is an assumption no capability guarantees.
   *
   * @covers ::onJobCompleted
   */
  public function testAFailedToolAlsoWithholdsRetry(): void {
    $log = new ProviderFailureLog();
    $sentence = 'Anthropic could not be reached, or answered with an error of their own.';
    $log->record(new AiProviderFailure($sentence, 0, NULL, ProviderCall::KIND_UNAVAILABLE));

    $tool = $this->mockJob('create_1', 'Create content', 'failed', [
      'tool_invocation' => TRUE,
    ], 'Half of it saved, then this.');
    $failed = $this->mockJob('agent_reason', 'Reason', 'failed', [], $sentence);

    $subscriber = new NodeProgressSubscriber($this->relay, $log);
    $subscriber->onJobCompleted(new JobCompletedEvent($tool, []));
    $subscriber->onJobCompleted(new JobCompletedEvent($failed, []));

    $this->assertArrayNotHasKey('error_retry', $this->emitted[1]->data);
  }

  /**
   * An `auth` fault never offers Retry, clean turn or not — it offers the link.
   *
   * @covers ::onJobCompleted
   */
  public function testAuthNeverOffersRetry(): void {
    // `auth` resolves the models URL, so the route table must be there.
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturn('/admin/config/aincient/models');
    $container = new ContainerBuilder();
    $container->set('url_generator', $generator);
    \Drupal::setContainer($container);

    $log = new ProviderFailureLog();
    $sentence = 'Anthropic rejected the key Atelier has for it.';
    $log->record(new AiProviderFailure($sentence, 0, NULL, ProviderCall::KIND_AUTH));

    $job = $this->mockJob('agent_reason', 'Reason', 'failed', [], $sentence);
    (new NodeProgressSubscriber($this->relay, $log))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertArrayNotHasKey('error_retry', $data);
    // And no note: there was never a button here to explain the absence of.
    $this->assertArrayNotHasKey('error_note', $data);
  }

  /**
   * An `auth` failure's frame carries the models link, off the route table.
   *
   * The end-to-end wiring of the one thing this slice adds beyond a sentence: the
   * URL is never spelled in the frame's source, it is generated — the console's IA
   * has already moved once (/aincient → /atelier).
   *
   * @covers ::onJobCompleted
   */
  public function testAuthFailureFrameCarriesTheModelsLink(): void {
    $generator = $this->createMock(UrlGeneratorInterface::class);
    $generator->method('generateFromRoute')->willReturn('/admin/config/aincient/models');
    $container = new ContainerBuilder();
    $container->set('url_generator', $generator);
    \Drupal::setContainer($container);

    $log = new ProviderFailureLog();
    $sentence = 'Anthropic rejected the key Atelier has for it.';
    $log->record(new AiProviderFailure($sentence, 0, NULL, ProviderCall::KIND_AUTH));

    $job = $this->mockJob('agent_reason', 'Reason', 'failed', [], 'Node execution failed for agent_reason: ' . $sentence);
    (new NodeProgressSubscriber($this->relay, $log))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame('auth', $data['error_kind']);
    $this->assertSame(
      ['label' => 'Reconnect provider', 'url' => '/admin/config/aincient/models'],
      $data['error_action'],
    );
  }

  /**
   * An UNRELATED node failure never inherits the recorded provider kind.
   *
   * The whole safety property of matching on the sentence. Without it, a graph
   * mistake that happens after a provider hiccup in the same request would tell
   * the reader to reconnect a provider that is working fine.
   *
   * @covers ::onJobCompleted
   */
  public function testUnrelatedFailureIsNotClassifiedAsAProviderFault(): void {
    $log = new ProviderFailureLog();
    $log->record(new AiProviderFailure(
      'Anthropic rejected the key Atelier has for it.',
      0,
      NULL,
      ProviderCall::KIND_AUTH,
    ));

    $job = $this->mockJob('invoke_capability', 'Create content', 'failed', [], 'The capability exploded.');
    (new NodeProgressSubscriber($this->relay, $log))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $data = $this->emitted[0]->data;
    $this->assertSame('The capability exploded.', $data['error']);
    $this->assertArrayNotHasKey('error_kind', $data);
    $this->assertArrayNotHasKey('error_action', $data);
  }

  /**
   * With the relay disarmed (no console stream open) nothing is forwarded.
   *
   * @covers ::onJobCompleted
   */
  public function testDisarmedRelayDropsTheFrame(): void {
    $this->relay->disarm();
    $job = $this->mockJob('chat_output', 'Chat Output', 'completed');

    (new NodeProgressSubscriber($this->relay, new ProviderFailureLog()))
      ->onJobCompleted(new JobCompletedEvent($job, []));

    $this->assertSame([], $this->emitted);
  }

  /**
   * The subscription uses the literal event name — NOT the class constant —
   * so registering the subscriber never autoloads FlowDrop code.
   *
   * @covers ::getSubscribedEvents
   */
  public function testSubscribesByLiteralEventName(): void {
    $this->assertSame(
      ['flowdrop_runtime.job.completed' => 'onJobCompleted'],
      NodeProgressSubscriber::getSubscribedEvents(),
    );
    // Guard the literal against upstream drift: it must match the constant.
    $this->assertSame(JobCompletedEvent::NAME, array_key_first(NodeProgressSubscriber::getSubscribedEvents()));
  }

}
