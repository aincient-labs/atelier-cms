<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\EventSubscriber;

use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_core\Event\UsageRecordedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Relays per-call AI token usage + cost onto the console's SSE stream.
 *
 * {@see \Drupal\aincient_core\Usage\UsageLog} writes a row for every AI call and
 * then dispatches {@see UsageRecordedEvent} carrying the tokens and the USD cost
 * it just recorded — all inside the same blocking request that holds the chat
 * stream open (the operator's Reason step calls the provider synchronously
 * within the FlowDrop turn). When the StreamRelay is armed (i.e. we are inside a
 * streamed console turn), each record becomes a `usage` frame; the console sums
 * them into a per-turn footer and a running session total. Outside a console
 * turn the relay is disarmed and this is a no-op.
 *
 * READING THE RECORDED ROW, NOT THE RAW RESPONSE, IS THE WHOLE POINT. The frame
 * carries the same numbers the usage report will later add up, because both come
 * from the one write. Recomputing tokens and cost here from the provider result
 * would give the console a second opinion — and a footer that disagreed with the
 * dashboard about what a turn cost would leave no way to tell which one was
 * lying.
 *
 * Subscribed by class constant, not by a literal string. That used to be a
 * literal precisely so registering this subscriber could never autoload a class
 * from an optional contrib module; the event is `aincient_core`'s now, and
 * `aincient_chat` already depends on `aincient_core`, so the indirection bought
 * nothing but a name no IDE could follow.
 */
final class UsageStreamSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly StreamRelay $relay,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [UsageRecordedEvent::EVENT_NAME => 'onRecordCreated'];
  }

  /**
   * Push one recorded AI call onto the open chat stream.
   */
  public function onRecordCreated(UsageRecordedEvent $event): void {
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
