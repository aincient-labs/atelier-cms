<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\aincient_chat\EventSubscriber\BrandSliceStreamSubscriber;
use Drupal\flowdrop_job\FlowDropJobInterface;
use Drupal\flowdrop_runtime\Event\JobCompletedEvent;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the live Brand-slice → preview-frame streamer.
 *
 * The user-facing half of the deterministic-merge redesign: each completed
 * specialist sub-workflow's slice is applied to a transient brand_preview frame
 * pushed onto the armed SSE stream (one-way server→client — the agent never
 * sees it). The applier is resolved through the real container; FlowDrop is not
 * enabled (the event class autoloads from contrib, the subscription is by
 * literal string). We assert: a specialist job → one validated brand_preview
 * frame; a non-specialist job → nothing; a disarmed relay → nothing.
 *
 * @coversDefaultClass \Drupal\aincient_chat\EventSubscriber\BrandSliceStreamSubscriber
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class BrandSliceStreamTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'key',
    'ai',
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * Collected frames.
   *
   * @var \Drupal\aincient_chat\Event\ChatEvent[]
   */
  private array $emitted = [];

  private StreamRelay $relay;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('aincient_brand_revision');
    $this->installConfig(['system', 'aincient_pages']);
    $this->relay = $this->container->get('aincient_chat.stream_relay');
    $this->relay->arm(function (ChatEvent $event): void {
      $this->emitted[] = $event;
    });
  }

  /**
   * A scripted job double.
   */
  private function mockJob(string $nodeTypeId, string $status, array $output): FlowDropJobInterface {
    $job = $this->createMock(FlowDropJobInterface::class);
    $job->method('getMetadata')->willReturn(['node_type_id' => $nodeTypeId]);
    $job->method('getStatus')->willReturn($status);
    $job->method('getOutputData')->willReturn($output);
    return $job;
  }

  private function subscriber(): BrandSliceStreamSubscriber {
    return new BrandSliceStreamSubscriber($this->relay);
  }

  /**
   * @covers ::onJobCompleted
   *
   * A completed colour specialist becomes one validated brand_preview frame.
   */
  public function testSpecialistSliceBecomesPreviewFrame(): void {
    $job = $this->mockJob(
      'flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_colour',
      'completed',
      ['slice' => "```json\n{\"tokens_json\":{\"brand_primary\":\"oklch(0.48 0.18 50)\",\"neutral_surface\":\"oklch(0.97 0.02 60)\"}}\n```", 'status' => 'success'],
    );

    $this->subscriber()->onJobCompleted(new JobCompletedEvent($job, [], 'exec-1'));

    $this->assertCount(1, $this->emitted);
    $event = $this->emitted[0];
    $this->assertSame(ChatEventType::TOOL_CALL, $event->type);
    $this->assertSame('brand_preview', $event->data['name']);
    // The frame carries a VALIDATED css-var token map, not raw tokens_json.
    $this->assertArrayHasKey('brand-primary', $event->data['arguments']['tokens']);
    $this->assertSame('oklch(0.48 0.18 50)', $event->data['arguments']['tokens']['brand-primary']);
  }

  /**
   * @covers ::onJobCompleted
   *
   * A non-specialist node (e.g. reason) produces no frame.
   */
  public function testNonSpecialistNodeProducesNoFrame(): void {
    $job = $this->mockJob('aincient_reason', 'completed', ['text' => 'thinking…']);
    $this->subscriber()->onJobCompleted(new JobCompletedEvent($job, [], 'exec-1'));
    $this->assertSame([], $this->emitted);
  }

  /**
   * @covers ::onJobCompleted
   *
   * With the relay disarmed nothing is emitted (and no work is done).
   */
  public function testDisarmedRelayProducesNoFrame(): void {
    $this->relay->disarm();
    $job = $this->mockJob(
      'flowdrop_workflow_executor_flowdrop_workflow_aincient_brand_specialist_colour',
      'completed',
      ['slice' => '{"tokens_json":{"brand_primary":"oklch(0.48 0.18 50)"}}', 'status' => 'success'],
    );
    $this->subscriber()->onJobCompleted(new JobCompletedEvent($job, [], 'exec-1'));
    $this->assertSame([], $this->emitted);
  }

  /**
   * @covers ::getSubscribedEvents
   *
   * Subscribes by the LITERAL event name so registration never autoloads
   * FlowDrop (installable without it).
   */
  public function testSubscribesByLiteralEventName(): void {
    $this->assertSame(
      ['flowdrop_runtime.job.completed' => 'onJobCompleted'],
      BrandSliceStreamSubscriber::getSubscribedEvents(),
    );
    $this->assertSame(JobCompletedEvent::NAME, array_key_first(BrandSliceStreamSubscriber::getSubscribedEvents()));
  }

}
