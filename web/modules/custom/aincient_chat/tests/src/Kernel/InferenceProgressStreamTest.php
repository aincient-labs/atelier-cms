<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;
use Drupal\aincient_core\Event\InferenceStartedEvent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The "thinking" frame that covers the longest silence in a turn.
 *
 * A reasoning call routinely runs ~50s and FlowDrop dispatches nothing until it
 * finishes, so this frame is the console's only word during that wait. Covered
 * through the real container, because what can break is the wiring: the
 * subscriber tagged, listening, and honouring the relay's armed state.
 *
 * @group aincient
 */
#[RunTestsInSeparateProcesses]
final class InferenceProgressStreamTest extends KernelTestBase {

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
    'aincient_core',
    'workflows',
    'content_moderation',
    'aincient_pages',
    'aincient_chat',
  ];

  /**
   * An announced call reaches the armed relay, present tense, and stops there.
   */
  public function testInferenceStartReachesArmedRelay(): void {
    $relay = $this->container->get('aincient_chat.stream_relay');

    $emitted = [];
    $relay->arm(static function (ChatEvent $event) use (&$emitted): void {
      $emitted[] = $event;
    });

    $this->container->get('event_dispatcher')->dispatch(
      new InferenceStartedEvent('openai_compatible', 'deepseek-v4-pro', 'reasoning', TRUE),
      InferenceStartedEvent::EVENT_NAME,
    );

    $this->assertCount(1, $emitted);
    $this->assertSame(ChatEventType::STATUS, $emitted[0]->type);
    $this->assertSame('Thinking…', $emitted[0]->data['message']);
    // The model rides in the DATA, not the message: an operator debugging a slow
    // turn needs it, a site owner does not.
    $this->assertSame('deepseek-v4-pro', $emitted[0]->data['model']);
    $this->assertSame('openai_compatible', $emitted[0]->data['provider']);
    // Not debug-flagged — this one is FOR the user; it is the whole reason the
    // wait is no longer silent.
    $this->assertArrayNotHasKey('debug', $emitted[0]->data);

    // Site-wide event: outside a streamed turn (relay disarmed) it is a no-op,
    // so cron and admin tools never write into a closed stream.
    $relay->disarm();
    $this->container->get('event_dispatcher')->dispatch(
      new InferenceStartedEvent('anthropic', 'claude-x', 'task'),
      InferenceStartedEvent::EVENT_NAME,
    );
    $this->assertCount(1, $emitted);
  }

}
