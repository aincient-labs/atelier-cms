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
      yield ChatEvent::status('Getting started…', ['thread_id' => $threadId]);

      $decision = $this->router->route($message, $flowOverride);
      // WHICH lane we picked, and why, is engine detail — a debug-flagged frame
      // so it stays on the wire (and in a technical-detail console) without
      // narrating our own plumbing at someone who asked for a page.
      yield ChatEvent::debugStatus(
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
      yield ChatEvent::error($this->resumeFailureMessage($e));
      yield ChatEvent::done(['thread_id' => $threadId]);
    }
  }

  /**
   * A person-facing reason a resume was refused.
   *
   * The engine refuses an unanswerable interrupt with a published `error_code`
   * (flowdrop 2.6.0) whose accompanying message is explicitly prose that may
   * change between releases — so the code is what we read, and the wording here
   * is ours. The three below are the ones a person can actually cause by using
   * the UI: double-clicking a choice, answering a question a second tab already
   * answered, or coming back to a stale one.
   *
   * Duck-typed on purpose. Both `RefusalCodeInterface` and the exception classes
   * that carry it are `@internal` in 2.6.0 ("Not `@api` yet"), so binding to
   * either by name would couple us to something upstream has reserved the right
   * to move. The code STRINGS are the published contract; `getErrorCode()` is
   * the only thing we ask for, and anything that cannot answer falls through to
   * the generic message.
   */
  private function resumeFailureMessage(\Throwable $e): string {
    $code = method_exists($e, 'getErrorCode') ? (string) $e->getErrorCode() : '';

    return match ($code) {
      'RESOLUTION_IN_PROGRESS' => 'That choice is already being recorded — give it a moment.',
      'INTERRUPT_NOT_PENDING' => 'That choice was already answered.',
      'INTERRUPT_EXPIRED' => 'That choice expired before it was answered.',
      default => 'Could not resume: ' . $e->getMessage(),
    };
  }

}
