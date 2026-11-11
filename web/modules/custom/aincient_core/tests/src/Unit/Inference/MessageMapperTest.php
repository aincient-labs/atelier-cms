<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Aincient\Inference\ChatMessageMapper;
use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\flowdrop\DTO\Reason\ReasonMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Role;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * Covers the FlowDrop ↔ symfony/ai conversation translation.
 *
 * The invariant under test: a healed buffer in produces a well-formed tool-use /
 * tool-result history out, and anything unmappable is DROPPED rather than
 * emitted malformed — a broken pair reaching the provider is an opaque upstream
 * 400, which is the failure mode DECISIONS 0278/0279 was about.
 */
#[CoversClass(MessageMapper::class)]
final class MessageMapperTest extends TestCase {

  private function mapper(): MessageMapper {
    return new MessageMapper();
  }

  /**
   * The system prompt leads, and comes from config rather than the history.
   */
  public function testSystemPromptIsPrepended(): void {
    $bag = $this->mapper()->toMessageBag('You are helpful.', [
      new ReasonMessage('user', 'hello'),
    ]);

    $messages = $bag->getMessages();
    self::assertInstanceOf(SystemMessage::class, $messages[0]);
    self::assertInstanceOf(UserMessage::class, $messages[1]);
  }

  /**
   * An empty system prompt is omitted rather than sent blank.
   */
  public function testEmptySystemPromptIsOmitted(): void {
    $bag = $this->mapper()->toMessageBag('   ', [
      new ReasonMessage('user', 'hello'),
    ]);

    self::assertInstanceOf(UserMessage::class, $bag->getMessages()[0]);
  }

  /**
   * A full tool round-trip replays as assistant-tool-calls then a tool result.
   */
  public function testToolUseHistoryReplaysInOrder(): void {
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'what pages exist?'),
      new ReasonMessage('assistant', '', [
        ['name' => 'list_pages', 'args' => [], 'tool_call_id' => 'call_1'],
      ]),
      new ReasonMessage('tool', '{"pages":["Home"]}', [], 'call_1'),
    ]);

    $messages = $bag->getMessages();
    self::assertCount(3, $messages);
    self::assertInstanceOf(UserMessage::class, $messages[0]);
    self::assertInstanceOf(AssistantMessage::class, $messages[1]);
    self::assertTrue($messages[1]->hasToolCalls());
    self::assertSame('call_1', $messages[1]->getToolCalls()[0]->getId());
    self::assertInstanceOf(ToolCallMessage::class, $messages[2]);
    self::assertSame('call_1', $messages[2]->getToolCall()->getId());
  }

  /**
   * A tool result with no id is dropped — it cannot be paired to a call.
   */
  public function testUnattributableToolResultIsDropped(): void {
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'hi'),
      new ReasonMessage('tool', 'orphan result'),
    ]);

    $messages = $bag->getMessages();
    self::assertCount(1, $messages);
    self::assertInstanceOf(UserMessage::class, $messages[0]);
  }

  /**
   * An assistant tool call with no id is dropped from the replay.
   */
  public function testToolCallWithoutIdIsDropped(): void {
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'hi'),
      new ReasonMessage('assistant', 'some text', [
        ['name' => 'nameless', 'args' => []],
      ]),
    ]);

    $messages = $bag->getMessages();
    // Falls back to the assistant's text, since no call survived.
    self::assertCount(2, $messages);
    self::assertInstanceOf(AssistantMessage::class, $messages[1]);
    self::assertFalse($messages[1]->hasToolCalls());
  }

  /**
   * Roles outside the replay set (node bookkeeping) are ignored.
   */
  public function testUnknownRolesAreIgnored(): void {
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('system', 'should not be replayed'),
      new ReasonMessage('developer', 'nor this'),
      new ReasonMessage('user', 'only this'),
    ]);

    $messages = $bag->getMessages();
    self::assertCount(1, $messages);
    self::assertSame(Role::User, $messages[0]->getRole());
  }

  /**
   * A card recap replays on the next user turn, never as assistant words. B2.
   *
   * Stored on the assistant entry (0464), it was replayed as the model's own
   * speech — so the model echoed it and imitated a turn where an image
   * "appeared" without a tool call.
   */
  public function testCardRecapMovesOffTheAssistantTurn(): void {
    $recap = '[shown to the user: media_result card: Generated a red fox]';
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'make a fox'),
      new ReasonMessage('assistant', "Here is your fox.\n\n" . $recap),
      new ReasonMessage('user', 'now a hare'),
    ]);

    $messages = $bag->getMessages();
    self::assertCount(3, $messages);
    self::assertInstanceOf(AssistantMessage::class, $messages[1]);
    self::assertSame('Here is your fox.', $messages[1]->asText());
    self::assertInstanceOf(UserMessage::class, $messages[2]);
    $user = $messages[2]->asText();
    self::assertSame($recap . "\n\nnow a hare", $user);
    foreach ($messages as $message) {
      if ($message instanceof AssistantMessage) {
        self::assertStringNotContainsString('[shown to the user:', (string) $message->asText());
      }
    }
  }

  /**
   * A recap-only assistant turn drops; its note still reaches the next user turn.
   */
  public function testRecapOnlyAssistantTurnDrops(): void {
    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'list pages'),
      new ReasonMessage('assistant', '[shown to the user: a table of 1 row — 1. Home (id 1)]'),
      new ReasonMessage('user', 'open it'),
    ]);

    $messages = $bag->getMessages();
    self::assertCount(2, $messages);
    self::assertSame("[shown to the user: a table of 1 row — 1. Home (id 1)]\n\nopen it", $messages[1]->asText());
  }

  /**
   * An unreplayable buffer throws rather than sending an empty request.
   */
  public function testEmptyBufferThrows(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('no messages to reason over');
    $this->mapper()->toMessageBag('You are helpful.', []);
  }

  /**
   * The two directions are inverses of each other.
   */
  public function testRoundTripIsStable(): void {
    $stored = [['name' => 'list_pages', 'args' => ['limit' => 5], 'tool_call_id' => 'c1']];

    $bag = $this->mapper()->toMessageBag('', [
      new ReasonMessage('user', 'go'),
      new ReasonMessage('assistant', '', $stored),
    ]);
    /** @var \Symfony\AI\Platform\Message\AssistantMessage $assistant */
    $assistant = $bag->getMessages()[1];

    self::assertSame($stored, (new ChatMessageMapper())->fromToolCalls($assistant->getToolCalls()));
  }

}
