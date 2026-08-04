<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_core\Event\InferenceStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Says "thinking" the moment a model call goes out, not a minute later.
 *
 * The gap this closes: FlowDrop's orchestrator dispatches `JobCompletedEvent`
 * and nothing else, so a reasoning node that takes ~50s produced NO frame for
 * its whole duration — the console's last status line ("Handing this to Page
 * Studio…") sat frozen and the turn read as stuck. {@see NodeProgressSubscriber}
 * can only ever report the past.
 *
 * We own the call site, so `aincient_core` announces the outgoing call
 * ({@see InferenceStartedEvent}) and this relays it as a `status` frame — same
 * request, same armed StreamRelay, same pattern as {@see UsageStreamSubscriber}.
 * Outside a streamed console turn the relay is disarmed and this is a no-op.
 *
 * PRESENT TENSE EARNS ITS KEEP HERE. Every other progress frame reports
 * something finished ("Generated an image"); this is the one that says what is
 * happening right now, which is why the wording is the plain "Thinking…" a
 * person would use. The provider and model ride in the frame's data rather than
 * its message: an operator debugging a slow turn wants to know which model is
 * taking the minute, and a site owner does not.
 */
final class InferenceProgressSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StreamRelay $relay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [InferenceStartedEvent::EVENT_NAME => 'onInferenceStarted'];
  }

  /**
   * Push "a call is in flight" onto the open chat stream.
   */
  public function onInferenceStarted(InferenceStartedEvent $event): void {
    // This fires on every AI call site-wide (cron, batch, admin tools), so skip
    // building anything outside a streamed turn.
    if (!$this->relay->isArmed()) {
      return;
    }

    $this->relay->emit(ChatEvent::status('Thinking…', [
      'provider' => $event->providerId,
      'model' => $event->modelId,
      'role' => $event->operationType,
    ]));
  }

}
