<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\ai_metering\Event\MeteringRecordCreatedEvent;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Relays per-call AI token usage + estimated cost onto the console's SSE stream.
 *
 * ai_metering records EVERY AI call (PostGenerate/PostStreaming) and, after it
 * persists the row, dispatches MeteringRecordCreatedEvent with the tokens it
 * extracted and the USD cost it computed — all inside the same blocking request
 * that holds the chat stream open (the operator's Reason step calls the
 * provider synchronously within the FlowDrop turn). When the StreamRelay is
 * armed (i.e. we're inside a streamed console turn), each record becomes a
 * `usage` frame; the console sums them into a per-turn footer and a running
 * session total. Outside a console turn the relay is disarmed and this is a
 * no-op.
 *
 * Reusing ai_metering's record (not the raw provider response) means we inherit
 * its cost math and its handling of the awkward cases — streaming events with
 * no provider context, wrapper providers that would double-count, local
 * providers with zero per-token cost — so the console can never disagree with
 * the metering dashboard.
 *
 * The event name is subscribed as a literal string (not the class constant) so
 * registering this subscriber never autoloads an ai_metering class — the module
 * stays installable without ai_metering; the event simply never fires.
 */
final class UsageStreamSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StreamRelay $relay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['ai_metering.record_created' => 'onRecordCreated'];
  }

  /**
   * Push one metered AI call onto the open chat stream.
   */
  public function onRecordCreated(MeteringRecordCreatedEvent $event): void {
    // Cheap check, but skip building the frame entirely outside a streamed turn
    // (this fires on every AI call site-wide — cron, batch, admin tools — not
    // just the console).
    if (!$this->relay->isArmed()) {
      return;
    }

    $this->relay->emit(ChatEvent::usage(
      $event->getInputTokens(),
      $event->getOutputTokens(),
      $event->getCachedTokens(),
      $event->getCostUsd(),
      $event->getModelId(),
      $event->getProviderId(),
    ));
  }

}
