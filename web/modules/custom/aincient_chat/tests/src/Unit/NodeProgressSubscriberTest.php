<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\aincient_chat\EventSubscriber\NodeProgressSubscriber;
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
  private function mockJob(string $nodeId, ?string $label, string $status, array $metadata = [], string $error = ''): FlowDropJobInterface {
    $job = $this->createMock(FlowDropJobInterface::class);
    $job->method('getNodeId')->willReturn($nodeId);
    $job->method('label')->willReturn($label);
    $job->method('getStatus')->willReturn($status);
    $job->method('getMetadata')->willReturn($metadata);
    $job->method('getErrorMessage')->willReturn($error);
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

    (new NodeProgressSubscriber($this->relay))
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

    (new NodeProgressSubscriber($this->relay))
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

    $subscriber = new NodeProgressSubscriber($this->relay);
    $subscriber->onJobCompleted(new JobCompletedEvent($tool, []));
    $subscriber->onJobCompleted(new JobCompletedEvent($plain, []));

    $this->assertTrue($this->emitted[0]->data['tool']);
    $this->assertArrayNotHasKey('tool', $this->emitted[1]->data);
  }

  /**
   * With the relay disarmed (no console stream open) nothing is forwarded.
   *
   * @covers ::onJobCompleted
   */
  public function testDisarmedRelayDropsTheFrame(): void {
    $this->relay->disarm();
    $job = $this->mockJob('chat_output', 'Chat Output', 'completed');

    (new NodeProgressSubscriber($this->relay))
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
