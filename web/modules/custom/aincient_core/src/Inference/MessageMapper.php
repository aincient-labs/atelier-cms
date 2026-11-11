<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Aincient\Inference\ChatMessageMapper;
use Aincient\Inference\RecapNote;
use Drupal\flowdrop\DTO\Reason\ReasonMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Translates FlowDrop's neutral conversation DTOs into a `symfony/ai` MessageBag.
 *
 * The buffer arriving here is assumed already healed by FlowDrop's
 * {@see \Drupal\flowdrop\DTO\Reason\ToolPairing} — every assistant tool-use turn
 * has its matching tool-result turn. This class does not repair pairing (that
 * invariant belongs to the caller, per the ChatReasonerInterface contract); it
 * only maps shapes, and drops turns it cannot map rather than emitting a
 * malformed history.
 *
 * The system prompt is owned by node config, never by the replayed history —
 * matching the behaviour of the reasoner this replaces.
 *
 * Only the `ReasonMessage` half lives here (it needs FlowDrop); the plain chat
 * bag and both directions of the stored tool-call shape are in
 * {@see ChatMessageMapper}, which the inference package carries.
 */
final class MessageMapper {

  /**
   * Conversation roles we replay. Anything else is node bookkeeping.
   */
  private const REPLAYED_ROLES = ['user', 'assistant', 'tool'];

  public function __construct(
    private readonly ChatMessageMapper $chat = new ChatMessageMapper(),
  ) {}

  /**
   * Builds the message bag for one inference.
   *
   * @param string $systemPrompt
   *   The system prompt, or '' to omit it.
   * @param array<int, \Drupal\flowdrop\DTO\Reason\ReasonMessage> $messages
   *   The healed conversation buffer.
   *
   * @return \Symfony\AI\Platform\Message\MessageBag
   *   The bag to invoke with.
   *
   * @throws \RuntimeException
   *   When nothing in the buffer could be replayed — an empty request would
   *   otherwise reach the provider as a confusing upstream error.
   */
  public function toMessageBag(string $systemPrompt, array $messages): MessageBag {
    $mapped = [];
    if (trim($systemPrompt) !== '') {
      $mapped[] = Message::forSystem($systemPrompt);
    }

    $replayed = 0;
    // Card recaps (0464) lifted off assistant turns, waiting for the next user
    // turn to carry them. {@see RecapNote} for why they never replay as the
    // model's own words.
    $notes = [];
    foreach ($messages as $message) {
      if (!$message instanceof ReasonMessage) {
        continue;
      }
      $role = $message->getRole();
      if (!in_array($role, self::REPLAYED_ROLES, TRUE)) {
        continue;
      }
      if ($role === 'assistant') {
        [$clean, $lifted] = RecapNote::split($message->getContent());
        if ($lifted !== []) {
          array_push($notes, ...$lifted);
          $message = new ReasonMessage('assistant', $clean, $message->getToolCalls(), $message->getToolCallId());
        }
      }
      elseif ($role === 'user' && $notes !== []) {
        $message = new ReasonMessage('user', RecapNote::prepend($notes, $message->getContent()), $message->getToolCalls(), $message->getToolCallId());
        $notes = [];
      }
      foreach ($this->mapOne($message, $role) as $symfonyMessage) {
        $mapped[] = $symfonyMessage;
        $replayed++;
      }
    }

    if ($replayed === 0) {
      throw new \RuntimeException('Reason request has no messages to reason over.');
    }

    return new MessageBag(...$mapped);
  }

  /**
   * Maps one FlowDrop message to zero or more `symfony/ai` messages.
   *
   * @param \Drupal\flowdrop\DTO\Reason\ReasonMessage $message
   *   The message to map.
   * @param string $role
   *   Its already-validated role.
   *
   * @return list<\Symfony\AI\Platform\Message\MessageInterface>
   *   The mapped messages.
   */
  private function mapOne(ReasonMessage $message, string $role): array {
    $content = $message->getContent();

    if ($role === 'user') {
      return $content === '' ? [] : [Message::ofUser($content)];
    }

    if ($role === 'tool') {
      $id = $message->getToolCallId();
      if ($id === NULL || $id === '') {
        // A tool result with no id cannot be attributed to a call; replaying it
        // would produce an unpaired history the provider rejects.
        return [];
      }
      // ReasonMessage carries no tool NAME on a result turn (only the id), and
      // the name is not part of the wire shape for a result either — the id is
      // what pairs it to its call. ToolCall's constructor requires the argument,
      // so pass empty rather than inventing one.
      return [Message::ofToolCall(new ToolCall($id, ''), $content)];
    }

    // Assistant. A turn that requested tools replays as the tool calls it made;
    // otherwise as its text.
    $toolCalls = $this->chat->toToolCalls($message->getToolCalls());
    if ($toolCalls !== []) {
      return [Message::ofAssistant(...$toolCalls)];
    }
    return $content === '' ? [] : [Message::ofAssistant($content)];
  }

}
