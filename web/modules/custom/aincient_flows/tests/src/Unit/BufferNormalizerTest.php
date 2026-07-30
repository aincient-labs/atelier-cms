<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\aincient_flows\Conversation\BufferNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the conversation-buffer invariant (DECISIONS 0269).
 *
 * The regression that matters is testBrickedThreadFromForgeDemoIsRecovered():
 * it replays the exact buffer shape logged from the permanently-failing Forge
 * thread and asserts the repaired result is sendable.
 *
 * @coversDefaultClass \Drupal\aincient_flows\Conversation\BufferNormalizer
 * @group aincient_flows
 */
final class BufferNormalizerTest extends TestCase {

  /**
   * Shorthand for the role sequence of a buffer, which is what we assert on.
   *
   * @param array<int, array<string, mixed>> $messages
   *   A buffer.
   *
   * @return array<int, string>
   *   Its roles in order.
   */
  private function roles(array $messages): array {
    return array_map(static fn (array $m): string => (string) $m['role'], $messages);
  }

  /**
   * Asserts the shapes a provider rejects are all absent.
   *
   * @param array<int, array<string, mixed>> $messages
   *   The normalized buffer.
   */
  private function assertSendable(array $messages): void {
    $callIds = [];
    $previous = NULL;
    foreach ($messages as $i => $message) {
      $role = (string) $message['role'];
      $this->assertNotSame(
        ['user', 'user'],
        [$previous, $role],
        "consecutive user turns at index $i",
      );
      if ($role === 'assistant' && !empty($message['tool_calls'])) {
        foreach ($message['tool_calls'] as $call) {
          $callIds[(string) ($call['tool_call_id'] ?? $call['id'] ?? '')] = $i;
        }
      }
      if ($role === 'tool') {
        $id = (string) $message['tool_call_id'];
        $this->assertArrayHasKey($id, $callIds, "orphan tool result '$id' at index $i");
        unset($callIds[$id]);
      }
      $previous = $role;
    }
    // Every tool_use was answered, except one still-open block at the tail.
    if ($callIds !== []) {
      $this->assertSame(
        count($messages) - 1,
        max($callIds),
        'an unanswered tool_use survives somewhere other than the tail',
      );
    }
  }

  /**
   * @covers ::forInference
   *
   * The bricked Forge thread (thr_f40f835f96024184, job 169): the loop had run
   * find_reference → preview_page when a transient 503 killed it, so the
   * closing assistant turn never landed and each retry appended another user
   * message. Logged shape: user, user, assistant(tool_calls), tool,
   * assistant(tool_calls), tool, user, user.
   */
  public function testBrickedThreadFromForgeDemoIsRecovered(): void {
    $bricked = [
      ['role' => 'user', 'content' => 'Build me a landing page for a coffee roaster'],
      ['role' => 'user', 'content' => 'with a hero'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'find_reference', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'reference found'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'preview_page', 'tool_call_id' => 'c2']]],
      ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'page previewed'],
      ['role' => 'user', 'content' => 'are you there?'],
      ['role' => 'user', 'content' => 'hello?'],
    ];

    $repaired = BufferNormalizer::forInference($bricked);

    $this->assertSendable($repaired);
    // The gap after the last tool result is closed, and the two orphan user
    // messages are merged into the single turn they always were.
    $this->assertSame(
      ['user', 'assistant', 'tool', 'assistant', 'tool', 'assistant', 'user'],
      $this->roles($repaired),
    );
    $this->assertSame(BufferNormalizer::INTERRUPTED_NOTE, $repaired[5]['content']);
    $this->assertSame("are you there?\n\nhello?", $repaired[6]['content']);
    // Nothing the user wrote was thrown away.
    $this->assertStringContainsString('coffee roaster', $repaired[0]['content']);
    $this->assertStringContainsString('with a hero', $repaired[0]['content']);
  }

  /**
   * @covers ::forInference
   *
   * Normalizing is idempotent — a repaired buffer is already well-formed, so a
   * second pass (every subsequent turn re-reads it) changes nothing.
   */
  public function testRepairIsIdempotent(): void {
    $bricked = [
      ['role' => 'user', 'content' => 'one'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
      ['role' => 'user', 'content' => 'two'],
    ];

    $once = BufferNormalizer::forInference($bricked);

    $this->assertSame($once, BufferNormalizer::forInference($once));
    $this->assertTrue(BufferNormalizer::isWellFormed($once));
    $this->assertFalse(BufferNormalizer::isWellFormed($bricked));
  }

  /**
   * @covers ::forInference
   *
   * A healthy buffer is returned untouched — the repair never "fixes" a
   * conversation that was fine (the loop's normal shape must survive verbatim).
   */
  public function testHealthyBufferIsUntouched(): void {
    $healthy = [
      ['role' => 'user', 'content' => 'make a page'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'preview_page', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'previewed'],
      ['role' => 'assistant', 'content' => 'Here it is.'],
      ['role' => 'user', 'content' => 'now make it green'],
    ];

    $this->assertSame($healthy, BufferNormalizer::forInference($healthy));
    $this->assertTrue(BufferNormalizer::isWellFormed($healthy, TRUE));
  }

  /**
   * @covers ::forInference
   *
   * Mid-loop the tail is legitimately open, and the read path must preserve it:
   * a trailing tool run is what Reason continues from, and a trailing
   * assistant(tool_calls) is what Invoke is about to answer.
   */
  public function testOpenTailSurvivesTheReadPath(): void {
    $awaitingReason = [
      ['role' => 'user', 'content' => 'go'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
    ];
    $awaitingInvoke = [
      ['role' => 'user', 'content' => 'go'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
    ];

    $this->assertSame($awaitingReason, BufferNormalizer::forInference($awaitingReason));
    $this->assertSame($awaitingInvoke, BufferNormalizer::forInference($awaitingInvoke));
  }

  /**
   * @covers ::closeOpenTurn
   *
   * The write path's rule: a new user turn is about to be appended, so the tail
   * must be closed first — the open block never reaches storage.
   */
  public function testCloseOpenTurnTerminatesTheTail(): void {
    $openAfterResults = [
      ['role' => 'user', 'content' => 'go'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
    ];

    $closed = BufferNormalizer::closeOpenTurn($openAfterResults);

    $this->assertSame(['user', 'assistant', 'tool', 'assistant'], $this->roles($closed));
    $this->assertSame(BufferNormalizer::INTERRUPTED_NOTE, $closed[3]['content']);
    $this->assertSendable($closed);
  }

  /**
   * @covers ::closeOpenTurn
   *
   * The blip landed before any tool ran: the tail is an assistant turn whose
   * tool_calls will never be answered. Closing the turn drops the dangling
   * tool_use (a provider rejects it) while keeping whatever the model said.
   */
  public function testUnansweredTailCallsAreDropped(): void {
    $spoke = BufferNormalizer::closeOpenTurn([
      ['role' => 'user', 'content' => 'go'],
      [
        'role' => 'assistant',
        'content' => 'Let me look that up.',
        'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']],
      ],
    ]);
    $silent = BufferNormalizer::closeOpenTurn([
      ['role' => 'user', 'content' => 'go'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
    ]);

    $this->assertSame(['user', 'assistant'], $this->roles($spoke));
    $this->assertArrayNotHasKey('tool_calls', $spoke[1]);
    $this->assertSame('Let me look that up.', $spoke[1]['content']);
    // Nothing was said and nothing was answered: the turn carried no content.
    $this->assertSame(['user'], $this->roles($silent));
  }

  /**
   * @covers ::forInference
   *
   * Partially-answered blocks: the answered call keeps its result, the
   * unanswered one is dropped rather than left dangling.
   */
  public function testPartiallyAnsweredBlockKeepsOnlyPairedCalls(): void {
    $repaired = BufferNormalizer::forInference([
      ['role' => 'user', 'content' => 'go'],
      [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [
          ['name' => 'a', 'tool_call_id' => 'c1'],
          ['name' => 'b', 'tool_call_id' => 'c2'],
        ],
      ],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'a done'],
      ['role' => 'assistant', 'content' => 'Only half of that worked.'],
    ]);

    $this->assertCount(1, $repaired[1]['tool_calls']);
    $this->assertSame('c1', $repaired[1]['tool_calls'][0]['tool_call_id']);
    $this->assertSendable($repaired);
  }

  /**
   * @covers ::forInference
   *
   * Orphans and duplicates: a tool result whose tool_use is gone cannot be
   * sent, and a repeated tool_call_id is always a re-fire artefact.
   */
  public function testOrphanAndDuplicateToolResultsAreDropped(): void {
    $repaired = BufferNormalizer::forInference([
      ['role' => 'user', 'content' => 'go'],
      ['role' => 'tool', 'tool_call_id' => 'gone', 'content' => 'orphan'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 't', 'tool_call_id' => 'c1']]],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
      ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'done'],
      ['role' => 'assistant', 'content' => 'Finished.'],
    ]);

    $this->assertSame(['user', 'assistant', 'tool', 'assistant'], $this->roles($repaired));
    $this->assertSendable($repaired);
  }

  /**
   * @covers ::forInference
   *
   * Junk in storage (a NULL entry, an unknown role, a tool turn with no id) is
   * dropped instead of being handed to a provider.
   */
  public function testUnusableEntriesAreDropped(): void {
    $repaired = BufferNormalizer::forInference([
      ['role' => 'system', 'content' => 'you are Atelier'],
      NULL,
      'not a message',
      ['role' => 'moderator', 'content' => 'nope'],
      ['role' => 'tool', 'content' => 'no id'],
      ['role' => 'user', 'content' => 'hi'],
    ]);

    $this->assertSame(['system', 'user'], $this->roles($repaired));
  }

  /**
   * @covers ::forInference
   *
   * An empty buffer stays empty (the first turn of a fresh thread).
   */
  public function testEmptyBufferIsEmpty(): void {
    $this->assertSame([], BufferNormalizer::forInference([]));
    $this->assertSame([], BufferNormalizer::closeOpenTurn([]));
  }

}
