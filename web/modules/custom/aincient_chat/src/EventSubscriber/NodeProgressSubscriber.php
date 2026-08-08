<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\ProviderFailureSurface;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_core\Inference\ProviderCall;
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
    // A PROVIDER fault is not a crash, and it should not read like one — the
    // console renders it as a calm card with the one action its kind earns
    // (atelier-cms#8). There are now two shapes it can arrive in:
    //
    // 1. STOP-AND-REPORTED (the agent path). Our own reason node catches the
    //    failure and ends its step as a COMPLETED node carrying the structured
    //    account on its `error_detail` output port — kind included, no string
    //    round-trip. This is the primary path for every agent turn.
    // 2. A FAILED node (one-shot callers — AiGateway, ChatCompleter — that do
    //    not run through the reason node, and local misconfigurations). Here the
    //    typed exception is gone by the time FlowDrop dispatches this event (it
    //    keeps only the message string), so the kind travels beside it in the
    //    request-scoped ProviderFailureLog, matched by the sentence. Deliberately
    //    NOT parsed back out of the text: matching our own copy means one
    //    rewording turns every failure into `unknown`.
    $detail = $this->providerFailureDetail($job);
    $error = $job->getErrorMessage();
    if ($detail !== NULL) {
      $extra['error'] = (string) ($detail['message'] ?? '');
      $this->applyFailureCard($extra, (string) ($detail['kind'] ?? ProviderCall::KIND_UNKNOWN));
    }
    elseif ($error !== '') {
      $extra['error'] = $error;
      $failure = $this->failures->matching($error);
      if ($failure !== NULL) {
        $extra['error'] = $failure->getMessage();
        $this->applyFailureCard($extra, $failure->getKind());
      }
    }

    $this->relay->emit(ChatEvent::node(
      $job->getNodeId(),
      (string) ($job->label() ?? $job->getNodeId()),
      $job->getStatus(),
      $extra,
    ));
  }

  /**
   * The structured failure a stop-and-reported node carried, if any.
   *
   * Our reason node ends a provider failure as a COMPLETED node whose
   * `error_detail` output port carries the struct
   * ({@see \Drupal\aincient_core\Exception\AiProviderFailure::toDetail()}); every
   * other node leaves it NULL (or absent). Reading it here is what lets the card
   * be driven by the machine-readable `kind` with no exception and no string
   * match — the deterministic path.
   *
   * @param \Drupal\flowdrop_job\FlowDropJobInterface $job
   *   The completed job.
   *
   * @return array<string, mixed>|null
   *   The `{kind, provider, model, message, retryable}` struct, or NULL when
   *   this node did not stop-and-report a provider failure.
   */
  private function providerFailureDetail($job): ?array {
    $detail = $job->getOutputData()['error_detail'] ?? NULL;
    return is_array($detail) && $detail !== [] ? $detail : NULL;
  }

  /**
   * Decorate the node frame with the calm card a provider `kind` earns.
   *
   * The single place both failure shapes (the stop-and-reported struct and the
   * FAILED-job fallback) turn a kind into the console's card grant — the action,
   * whether the reader may re-send, and the note — so the two paths cannot drift
   * into rendering the same fault two different ways.
   *
   * @param array<string, mixed> $extra
   *   The node frame's extra payload, decorated in place.
   * @param string $kind
   *   The provider failure kind, one of {@see ProviderCall}'s `KIND_*`.
   */
  private function applyFailureCard(array &$extra, string $kind): void {
    $extra['error_kind'] = $kind;
    $action = ProviderFailureSurface::action(
      $kind,
      static fn(): string => InstallCapabilities::setupUrl(),
    );
    if ($action !== NULL) {
      $extra['error_action'] = $action;
    }
    // Whether the reader may re-send this turn, decided server-side from the kind
    // AND from whether anything already took effect. The console renders what it
    // is granted and never derives this for itself.
    if (ProviderFailureSurface::retry($kind, $this->toolRan)) {
      $extra['error_retry'] = TRUE;
    }
    $note = ProviderFailureSurface::note($kind, $this->toolRan);
    if ($note !== NULL) {
      $extra['error_note'] = $note;
    }
  }

}
