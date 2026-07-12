<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\flowdrop_runtime\Event\JobCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Relays FlowDrop node executions onto the console's SSE stream, live.
 *
 * The stategraph orchestrator dispatches JobCompletedEvent after EVERY node it
 * executes — inside the same blocking request that holds the chat stream open.
 * When the StreamRelay is armed (i.e. we're inside a streamed console turn),
 * each completed node becomes a `node` frame, which the console renders as a
 * live execution trail (the chat-side equivalent of the session view's job
 * list). Outside a console turn the relay is disarmed and this is a no-op.
 *
 * The event name is subscribed as a literal string (not the class constant) so
 * registering this subscriber never autoloads a FlowDrop class — the module
 * stays installable without FlowDrop; the event simply never fires.
 */
final class NodeProgressSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StreamRelay $relay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['flowdrop_runtime.job.completed' => 'onJobCompleted'];
  }

  /**
   * Push one completed node onto the open chat stream.
   */
  public function onJobCompleted(JobCompletedEvent $event): void {
    $job = $event->getJob();
    $metadata = $job->getMetadata();

    $extra = ['node_type_id' => (string) ($metadata['node_type_id'] ?? '')];
    if (isset($metadata['execution_time_us'])) {
      $extra['elapsed_ms'] = (int) round(((int) $metadata['execution_time_us']) / 1000);
    }
    // Tool calls are recorded as pipeline jobs too (ScopedToolInvoker); flag
    // them so the trail can render them as tool usage, not plain nodes.
    if (!empty($metadata['tool_invocation'])) {
      $extra['tool'] = TRUE;
    }
    $error = $job->getErrorMessage();
    if ($error !== '') {
      $extra['error'] = $error;
    }

    $this->relay->emit(ChatEvent::node(
      $job->getNodeId(),
      (string) ($job->label() ?? $job->getNodeId()),
      $job->getStatus(),
      $extra,
    ));
  }

}
