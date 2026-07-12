<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Drupal\aincient_chat\Event\ChatEvent;
use Psr\Log\LoggerInterface;

/**
 * The chat conductor.
 *
 * Routes a turn, dispatches it to its FlowDrop workflow, and re-yields the
 * typed event stream (topology §4a).
 *
 * Single lane: every turn runs as a FlowDrop session, which IS the canonical
 * conversation store. The dispatcher's sendMessage() records the user message
 * and the workflow records the assistant reply, so this class persists nothing
 * itself — it is a thin pass-through over the router + dispatcher.
 */
class ChatProcessor implements ChatProcessorInterface {

  public function __construct(
    private readonly ChatRouterInterface $router,
    private readonly FlowDispatcherInterface $flowDispatcher,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processTurn(string $message, string $threadId, ?string $flowOverride = NULL, ?string $workflow = NULL, array $clientContext = []): \Generator {
    try {
      yield ChatEvent::status('Routing your request…', ['thread_id' => $threadId]);

      $decision = $this->router->route($message, $flowOverride);
      yield ChatEvent::status(
        sprintf('Routed to "%s" (%s).', $decision->flow, $decision->reason),
        ['flow' => $decision->flow],
      );

      yield from $this->flowDispatcher->dispatch($message, $threadId, $decision->flow, $workflow, $clientContext);
      yield ChatEvent::done(['thread_id' => $threadId]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Chat turn failed: @m', ['@m' => $e->getMessage()]);
      yield ChatEvent::error('Something went wrong: ' . $e->getMessage());
      yield ChatEvent::done(['thread_id' => $threadId]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Mirrors {@see self::processTurn()}: re-yield the dispatcher's continuation.
   * The session owns persistence — the resumed workflow records the assistant
   * reply there, and a reload re-hydrates the choice from the session's
   * interrupt state, so nothing is persisted here.
   */
  public function resumeInterrupt(string $interruptId, mixed $response, string $threadId): \Generator {
    try {
      if (!$this->flowDispatcher instanceof ResumableFlowDispatcherInterface) {
        yield ChatEvent::error('This flow can\'t be resumed.');
        yield ChatEvent::done(['thread_id' => $threadId]);
        return;
      }

      yield from $this->flowDispatcher->resume($interruptId, $response, $threadId);
      yield ChatEvent::done(['thread_id' => $threadId]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Interrupt resume failed: @m', ['@m' => $e->getMessage()]);
      yield ChatEvent::error('Could not resume: ' . $e->getMessage());
      yield ChatEvent::done(['thread_id' => $threadId]);
    }
  }

}
