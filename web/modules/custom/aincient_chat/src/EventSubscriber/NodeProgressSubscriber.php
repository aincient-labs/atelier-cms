<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\ProviderFailureSurface;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_core\Inference\ProviderFailureLog;
use Drupal\aincient_core\InstallCapabilities;
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
 * A FAILED node gets one extra thing: if its error is the sentence a provider
 * failure raised, the frame also carries that failure's `kind` and the action that
 * kind earns ({@see \Drupal\aincient_chat\Chat\ProviderFailureSurface}). That is
 * what lets the console render a provider fault as a calm card instead of a red
 * crash — an expired key is not a bug in the flow, and it never should have looked
 * like one (atelier-cms#8). For the transient kinds it also carries whether the
 * reader may RE-SEND the turn, which is why this subscriber tracks {@see
 * self::$toolRan}: it is the one place that sees every job of a turn go by.
 *
 * The event name is subscribed as a literal string (not the class constant) so
 * registering this subscriber never autoloads a FlowDrop class — the module
 * stays installable without FlowDrop; the event simply never fires.
 */
final class NodeProgressSubscriber implements EventSubscriberInterface {

  /**
   * Whether a tool has run in this request — the "already applied" signal.
   *
   * THE SIGNAL FOR GATE 2 of the Retry affordance, and deliberately the coarsest
   * one available. Every capability that changes the site (create a page, stage a
   * brand, generate an image, save an entity) runs as a TOOL, and every tool call
   * is recorded as a pipeline job that dispatches this same event
   * (ScopedToolInvoker::finalizeToolJob). So "a tool job has passed through here"
   * is the closest thing to "this turn already did something" that exists without
   * asking each capability to declare itself.
   *
   * COUNTED REGARDLESS OF STATUS: a tool that failed may have applied half its
   * work before it did, which is exactly the case a re-send must not double.
   * Read-only tools count too — over-suppressing Retry on a turn that merely
   * listed pages is a small annoyance; a second published page is not.
   *
   * Request-scoped by construction (one console turn is one blocking POST, and
   * the container is rebuilt per request) — NOT persisted anywhere, and not a
   * count of attempts. The workflow stays stateless about retrying.
   */
  private bool $toolRan = FALSE;

  public function __construct(
    private readonly StreamRelay $relay,
    private readonly ProviderFailureLog $failures,
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
      // Set BEFORE the failure block below, so a provider fault raised inside the
      // tool node itself counts its own possible side effects.
      $this->toolRan = TRUE;
    }
    $error = $job->getErrorMessage();
    if ($error !== '') {
      $extra['error'] = $error;
      // A PROVIDER fault is not a crash, and it should not read like one. When
      // this node's error is the sentence a provider failure raised, replace the
      // engine's "Node execution failed for <node>: …" wrapper with that sentence
      // and add the machine-readable kind, so the console can render a calm card
      // with the one action that kind actually earns (atelier-cms#8).
      //
      // The kind cannot ride the exception here — FlowDrop keeps only the string
      // (see ProviderFailureLog) — and it is deliberately not parsed back out of
      // the text: matching our own copy means one rewording turns every failure
      // into `unknown`.
      $failure = $this->failures->matching($error);
      if ($failure !== NULL) {
        $extra['error'] = $failure->getMessage();
        $extra['error_kind'] = $failure->getKind();
        $action = ProviderFailureSurface::action(
          $failure->getKind(),
          static fn(): string => InstallCapabilities::setupUrl(),
        );
        if ($action !== NULL) {
          $extra['error_action'] = $action;
        }
        // Whether the reader may re-send this turn, decided server-side from the
        // kind AND from whether anything already took effect. The console renders
        // what it is granted and never derives this for itself.
        if (ProviderFailureSurface::retry($failure->getKind(), $this->toolRan)) {
          $extra['error_retry'] = TRUE;
        }
        $note = ProviderFailureSurface::note($failure->getKind(), $this->toolRan);
        if ($note !== NULL) {
          $extra['error_note'] = $note;
        }
      }
    }

    $this->relay->emit(ChatEvent::node(
      $job->getNodeId(),
      (string) ($job->label() ?? $job->getNodeId()),
      $job->getStatus(),
      $extra,
    ));
  }

}
