<?php

declare(strict_types=1);

namespace Drupal\aincient_chat\Chat;

use Psr\Log\LoggerInterface;

/**
 * Appends a turn's card recap to the model's conversation history. 0464.
 *
 * The history is tier A: the engine's session-scoped conversation buffer
 * (scope `session`, key `conversation`, backend `entity`), addressed by config
 * in the agent engine's "Append user turn" and "Conversation append" nodes.
 * After a turn that rendered widgets, the recap line ({@see WidgetRecap}) is
 * added to that turn's assistant entry, the last one in the buffer. It reaches
 * the model's later turns and stays out of the visible thread.
 *
 * Holds the same lock FlowDrop's conversation_buffer node takes for its
 * read-modify-write, so an append racing this one cannot drop either write.
 * Idempotent: a recap already on the entry is not added twice.
 *
 * Best effort by contract, like TurnScratchpadSweeper: failures are logged and
 * swallowed, and FlowDrop's memory manager is resolved lazily.
 */
final class TurnRecapRecorder {

  /**
   * The tier-A address (the one home outside the workflow config).
   */
  public const SCOPE = 'session';
  public const KEY = 'conversation';
  public const BACKEND = 'entity';

  public function __construct(
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Adds the recap of a turn's widgets to its assistant entry. Never throws.
   *
   * @param int $sessionId
   *   The FlowDrop session the turn ran in; 0 is a no-op.
   * @param array<int, array{widget: string, payload: array, summary?: string}> $widgets
   *   The turn's harvested widget envelopes.
   */
  public function record(int $sessionId, array $widgets): void {
    $line = WidgetRecap::line($widgets);
    if ($sessionId === 0 || $line === '') {
      return;
    }
    try {
      if (!\Drupal::hasService('flowdrop_memory.manager')) {
        return;
      }
      $memory = \Drupal::service('flowdrop_memory.manager');
      $lock = \Drupal::lock();
      // FlowDrop's ConversationBuffer::bufferLockName() format.
      $lockName = 'flowdrop_memory_buffer:' . self::SCOPE . ':' . $sessionId . ':' . self::KEY;
      if (!$lock->acquire($lockName, 5.0)) {
        $lock->wait($lockName, 5);
        if (!$lock->acquire($lockName, 5.0)) {
          $this->logger->warning('Could not lock the conversation of session @id to record a card recap.', ['@id' => $sessionId]);
          return;
        }
      }
      try {
        $messages = $memory->get(self::SCOPE, (string) $sessionId, self::KEY, [], self::BACKEND);
        $last = is_array($messages) && $messages !== [] ? array_key_last($messages) : NULL;
        if ($last === NULL || !is_array($messages[$last]) || ($messages[$last]['role'] ?? '') !== 'assistant') {
          return;
        }
        $content = (string) ($messages[$last]['content'] ?? '');
        if (str_contains($content, $line)) {
          return;
        }
        $messages[$last]['content'] = $content === '' ? $line : $content . "\n\n" . $line;
        $memory->set(self::SCOPE, (string) $sessionId, self::KEY, $messages, NULL, self::BACKEND);
      }
      finally {
        $lock->release($lockName);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not record a card recap for session @id: @m', [
        '@id' => $sessionId,
        '@m' => $e->getMessage(),
      ]);
    }
  }

}
