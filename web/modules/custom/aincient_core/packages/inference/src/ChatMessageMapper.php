<?php

declare(strict_types=1);

namespace Aincient\Inference;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * The FlowDrop-free half of the conversation translation.
 *
 * Split out of {@see \Drupal\aincient_core\Inference\MessageMapper} so the inference package can carry it: the
 * plain chat node's bag and both directions of the stored tool-call shape
 * `{name, args, tool_call_id}` (a plain array, not a FlowDrop type). The
 * reasoner's `ReasonMessage` buffer stays with {@see \Drupal\aincient_core\Inference\MessageMapper}, which
 * delegates its tool-call half here so the two directions still live together.
 */
final class ChatMessageMapper {

  /**
   * The only roles a plain chat node's `history` input may contribute.
   *
   * @see self::toSimpleChatBag()
   */
  private const SIMPLE_CHAT_ROLES = ['user', 'assistant'];

  /**
   * Builds the bag for a single-turn chat node: system + history + one message.
   *
   * The shape a plain chat node stores is NOT the reasoner's healed buffer — it is
   * a bare list of `{role, content}` arrays wired into a node input, so it gets
   * its own entry point rather than being forced through the reasoner's mapper.
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

    // Card recaps (0464) lifted off assistant turns ride on the next user turn,
    // as in the reasoner's mapper — {@see RecapNote} for why.
    $notes = [];
    foreach ($history as $turn) {
      if (!is_array($turn) || !isset($turn['role'], $turn['content'])) {
        continue;
      }
      $content = (string) $turn['content'];
      $role = (string) $turn['role'];
      if (!in_array($role, self::SIMPLE_CHAT_ROLES, TRUE)) {
        continue;
      }
      if ($role === 'assistant') {
        [$content, $lifted] = RecapNote::split($content);
        array_push($notes, ...$lifted);
      }
      elseif ($notes !== []) {
        $content = RecapNote::prepend($notes, $content);
        $notes = [];
      }
      if ($content === '') {
        continue;
      }
      $mapped[] = $role === 'user'
        ? Message::ofUser($content)
        : Message::ofAssistant($content);
    }

    $mapped[] = Message::ofUser($notes === [] ? $message : RecapNote::prepend($notes, $message));

    return new MessageBag(...$mapped);
  }

  /**
   * Rebuilds ToolCall objects from the stored `{name, args, tool_call_id}`.
   *
   * @param array<int, mixed> $stored
   *   The stored calls.
   *
   * @return list<\Symfony\AI\Platform\Result\ToolCall>
   *   The reconstructed calls, skipping any without an id.
   */
  public function toToolCalls(array $stored): array {
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
   * Converts `symfony/ai` tool calls back into the stored shape.
   *
   * The inverse of {@see self::toToolCalls()}, and the shape the rest of the
   * graph consumes (ToolInvoke, the conversation buffer): `{name, args,
   * tool_call_id}`. Kept here so both directions of the translation live
   * together and cannot drift.
   *
   * @param array<int, \Symfony\AI\Platform\Result\ToolCall> $calls
   *   The calls the model requested.
   *
   * @return array<int, array<string, mixed>>
   *   The stored tool-call shape.
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
