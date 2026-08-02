<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

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
 */
final class MessageMapper {

  /**
   * Conversation roles we replay. Anything else is node bookkeeping.
   */
  private const REPLAYED_ROLES = ['user', 'assistant', 'tool'];

  /**
   * The only roles a plain chat node's `history` input may contribute.
   *
   * @see self::toSimpleChatBag()
   */
  private const SIMPLE_CHAT_ROLES = ['user', 'assistant'];

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
    foreach ($messages as $message) {
      if (!$message instanceof ReasonMessage) {
        continue;
      }
      $role = $message->getRole();
      if (!in_array($role, self::REPLAYED_ROLES, TRUE)) {
        continue;
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
   * Builds the bag for a single-turn chat node: system + history + one message.
   *
   * The shape a plain chat node stores is NOT the reasoner's healed buffer — it is
   * a bare list of `{role, content}` arrays wired into a node input, so it gets
   * its own entry point rather than being forced through
   * {@see self::toMessageBag()}.
   *
   * ONLY `user` and `assistant` survive the allow-list, and that is a security
   * control, not tidiness: `history` is a CONNECTABLE node input, so whatever
   * upstream node (or model) produced it could otherwise smuggle in a `system`
   * turn and rewrite the specialist's instructions from inside its own
   * conversation. The node's own `systemPrompt` is the only source of system
   * instruction. A `tool` turn is dropped for a different reason — it would be
   * unpaired here, which providers reject outright.
   *
   * @param string $systemPrompt
   *   The node's system prompt, or '' to omit it.
   * @param array<int|string, mixed> $history
   *   Prior turns as `{role, content}` arrays. Anything else is skipped.
   * @param string $message
   *   The user message this turn is about.
   *
   * @return \Symfony\AI\Platform\Message\MessageBag
   *   The bag to invoke with.
   */
  public function toSimpleChatBag(string $systemPrompt, array $history, string $message): MessageBag {
    $mapped = [];
    if (trim($systemPrompt) !== '') {
      $mapped[] = Message::forSystem($systemPrompt);
    }

    foreach ($history as $turn) {
      if (!is_array($turn) || !isset($turn['role'], $turn['content'])) {
        continue;
      }
      $content = (string) $turn['content'];
      if ($content === '') {
        continue;
      }
      $role = (string) $turn['role'];
      if (!in_array($role, self::SIMPLE_CHAT_ROLES, TRUE)) {
        continue;
      }
      $mapped[] = $role === 'user'
        ? Message::ofUser($content)
        : Message::ofAssistant($content);
    }

    $mapped[] = Message::ofUser($message);

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
    $toolCalls = $this->mapToolCalls($message->getToolCalls());
    if ($toolCalls !== []) {
      return [Message::ofAssistant(...$toolCalls)];
    }
    return $content === '' ? [] : [Message::ofAssistant($content)];
  }

  /**
   * Rebuilds ToolCall objects from FlowDrop's stored `{name, args, tool_call_id}`.
   *
   * @param array<int, array<string, mixed>> $stored
   *   The stored calls.
   *
   * @return list<\Symfony\AI\Platform\Result\ToolCall>
   *   The reconstructed calls, skipping any without an id.
   */
  private function mapToolCalls(array $stored): array {
    $calls = [];
    foreach ($stored as $call) {
      if (!is_array($call)) {
        continue;
      }
      $id = trim((string) ($call['tool_call_id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $args = is_array($call['args'] ?? NULL) ? $call['args'] : [];
      $calls[] = new ToolCall($id, (string) ($call['name'] ?? ''), $args);
    }
    return $calls;
  }

  /**
   * Converts `symfony/ai` tool calls back into FlowDrop's stored shape.
   *
   * The inverse of {@see self::mapToolCalls()}, and the shape the rest of the
   * graph consumes (ToolInvoke, the conversation buffer): `{name, args,
   * tool_call_id}`. Kept here so both directions of the translation live
   * together and cannot drift.
   *
   * @param array<int, \Symfony\AI\Platform\Result\ToolCall> $calls
   *   The calls the model requested.
   *
   * @return array<int, array<string, mixed>>
   *   FlowDrop's tool-call shape.
   */
  public function fromToolCalls(array $calls): array {
    $out = [];
    foreach ($calls as $call) {
      if (!$call instanceof ToolCall) {
        continue;
      }
      $out[] = [
        'name' => $call->getName(),
        'args' => $call->getArguments(),
        'tool_call_id' => $call->getId(),
      ];
    }
    return $out;
  }

}
