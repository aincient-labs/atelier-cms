<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\flowdrop_job\FlowDropJobInterface;
use Drupal\flowdrop_runtime\Event\JobCompletedEvent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the live node-progress wiring through the real container.
 *
 * Covers what the unit tests can't: the services.yml registration — relay is
 * a singleton, the subscriber is tagged and listening on the dispatcher — so
 * a JobCompletedEvent dispatched anywhere in the request lands on an armed
 * stream. FlowDrop itself is NOT enabled (mirroring the suite's constraint);
 * the event class autoloads from contrib and the literal-string subscription
 * means registration never needed FlowDrop in the first place.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class NodeProgressStreamTest extends KernelTestBase {

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
   * A dispatched job event reaches the armed relay as a `node` ChatEvent.
   */
  public function testJobCompletedEventReachesArmedRelay(): void {
    /** @var \Drupal\aincient_chat\Chat\StreamRelay $relay */
    $relay = $this->container->get('aincient_chat.stream_relay');
    $this->assertInstanceOf(StreamRelay::class, $relay);

    $emitted = [];
    $relay->arm(static function (ChatEvent $event) use (&$emitted): void {
      $emitted[] = $event;
    });

    $job = $this->createMock(FlowDropJobInterface::class);
    $job->method('getNodeId')->willReturn('agent_reason');
    $job->method('label')->willReturn('Reason (agent step)');
    $job->method('getStatus')->willReturn('completed');
    $job->method('getMetadata')->willReturn(['node_type_id' => 'aincient_reason', 'execution_time_us' => 42000]);
    $job->method('getErrorMessage')->willReturn('');

    $this->container->get('event_dispatcher')
      ->dispatch(new JobCompletedEvent($job, [], 'exec-1'), 'flowdrop_runtime.job.completed');

    $this->assertCount(1, $emitted);
    $this->assertSame(ChatEventType::NODE, $emitted[0]->type);
    $this->assertSame('Reason (agent step)', $emitted[0]->data['label']);
    $this->assertSame(42, $emitted[0]->data['elapsed_ms']);

    // Disarmed (the controller's `finally`), later node events vanish —
    // a stray late job can't write into a closed stream.
    $relay->disarm();
    $this->container->get('event_dispatcher')
      ->dispatch(new JobCompletedEvent($job, [], 'exec-1'), 'flowdrop_runtime.job.completed');
    $this->assertCount(1, $emitted);
  }

}
