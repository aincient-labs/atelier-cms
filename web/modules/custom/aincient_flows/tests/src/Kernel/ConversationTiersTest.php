<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Conversation\Scratchpad;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationAppend;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationRead;
use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two-tier agent context split — DECISIONS 0379, cms#29.
 *
 * The bug these tests exist to prevent: an agent renamed a byline to
 * "Tomas Ricet" when the live draft said "Tomas Ricen". Live state was injected
 * correctly; the conversation buffer contradicted it, because `update_section`
 * on a repeatable prop resends the WHOLE array and the buffer was session-scoped,
 * so every edit deposited a permanent page-state snapshot. A later turn then
 * carried two contradicting copies of the same content, and the model anchored on
 * the adjacent stale one — silently reverting user edits it was never asked to
 * touch.
 *
 * The invariant: live state is injected exactly once, in the system prompt,
 * rebuilt every turn. No DURABLE conversation message may carry a state
 * snapshot. Tool traffic is therefore addressed to the turn, not the session.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class ConversationTiersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flowdrop', 'flowdrop_memory'];

  /**
   * A page-state snapshot of the kind that used to poison the buffer.
   */
  private const STALE_SNAPSHOT = [
    'name' => 'aincient_preview_page',
    'tool_call_id' => 'call_1',
    'args' => [
      'ops' => [
        [
          'op' => 'update_section',
          'id' => '8c9437f7',
          'props' => [
            'quotes' => [
              ['author' => 'Elena Marsh', 'quote' => 'Fast.'],
              ['author' => 'Tomas Rice', 'quote' => 'Solid.'],
            ],
          ],
        ],
      ],
    ],
  ];

  /**
   * An append node bound to one turn (pipeline) of one thread (session).
   */
  private function append(string $pipelineId = 'p1'): ConversationAppend {
    $node = ConversationAppend::create(
      $this->container,
      [],
      'aincient_flows:aincient_conversation_append',
      [],
    );
    return $this->bind($node, $pipelineId);
  }

  /**
   * A read node bound to one turn (pipeline) of one thread (session).
   */
  private function read(string $pipelineId = 'p1'): ConversationRead {
    $node = ConversationRead::create(
      $this->container,
      [],
      'aincient_flows:aincient_conversation_read',
      [],
    );
    return $this->bind($node, $pipelineId);
  }

  /**
   * Binds a node to a session + pipeline, the two scopes the tiers resolve on.
   */
  private function bind(object $node, string $pipelineId): object {
    $node->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: $pipelineId,
      executionId: 'e1',
      nodeId: 'n.1',
      metadata: ['session_id' => '42'],
    ));
    return $node;
  }

  /**
   * Tier A params: the durable transcript.
   */
  private function transcript(array $extra): ParameterBag {
    return new ParameterBag($extra + [
      'scope' => 'session',
      'key' => 'conversation',
      'backend' => 'static',
    ]);
  }

  /**
   * Tier B params: this turn's scratchpad, addressed as a workflow addresses it.
   */
  private function scratchpad(array $extra): ParameterBag {
    return new ParameterBag($extra + [
      'scope' => Scratchpad::SCOPE,
      'key' => Scratchpad::KEY,
      'backend' => 'static',
    ]);
  }

  /**
   * Mid-turn, the agent sees its own tool traffic — nothing is hidden from it.
   *
   * This is the half that must NOT regress: the loop can only close if the
   * assistant tool_calls message and its results come back on the next read.
   */
  public function testAgentSeesItsOwnToolTrafficWithinTheTurn(): void {
    $append = $this->append();
    $append->process($this->transcript(['content' => 'rename the second byline']));
    $append->process($this->scratchpad([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [self::STALE_SNAPSHOT],
      ],
    ]));
    $append->process($this->scratchpad([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Applied.'],
      ],
    ]));

    $result = $this->read()->process($this->transcript([]));

    $this->assertSame(3, $result['count']);
    $this->assertSame(2, $result['scratchpad_count']);
    // Transcript first, then the turn's traffic — and the tool_use/tool_result
    // pair stays adjacent, or a provider rejects the sequence outright.
    $this->assertSame('user', $result['messages'][0]['role']);
    $this->assertSame('assistant', $result['messages'][1]['role']);
    $this->assertSame('call_1', $result['messages'][1]['tool_calls'][0]['tool_call_id']);
    $this->assertSame('tool', $result['messages'][2]['role']);
    $this->assertSame('call_1', $result['messages'][2]['tool_call_id']);
  }

  /**
   * THE REGRESSION TEST: the next turn cannot see the last turn's snapshot.
   *
   * A new turn is a new pipeline, so the scratchpad resolves to a new address —
   * last turn's tool traffic is unreachable rather than stale. This is the exact
   * failure from cms#29: had turn 2 seen `Tomas Rice` here, the model would have
   * produced `Tomas Ricet` from it instead of reading the live draft.
   */
  public function testNextTurnCannotSeeThePreviousTurnsToolTraffic(): void {
    $turnOne = $this->append('p1');
    $turnOne->process($this->transcript(['content' => 'rename the second byline']));
    $turnOne->process($this->scratchpad([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [self::STALE_SNAPSHOT],
      ],
    ]));
    $turnOne->process($this->scratchpad([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Applied.'],
      ],
    ]));
    $turnOne->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    // Turn 2: the user has since edited the draft by hand, then asks for a
    // relative edit. A different pipeline, the same thread.
    $this->append('p2')->process($this->transcript([
      'content' => "add a t after tomas's last name",
    ]));
    $result = $this->read('p2')->process($this->transcript([]));

    $this->assertSame(0, $result['scratchpad_count']);
    $encoded = json_encode($result['messages']);
    $this->assertStringNotContainsString('Tomas Rice', $encoded);
    $this->assertStringNotContainsString('update_section', $encoded);
    $this->assertStringNotContainsString('quotes', $encoded);
    // Prose survives: intent and continuity are exactly what the transcript is
    // for, and the agent's own narration is the only receipt it gets.
    $this->assertCount(3, $result['messages']);
    $this->assertSame('Renamed the second byline.', $result['messages'][1]['content']);
    $this->assertSame('assistant', $result['messages'][1]['role']);
  }

  /**
   * No payload ever reaches the durable store, whatever a turn did.
   *
   * Asserted against the STORE rather than the read, because this is the
   * property that has to hold for the life of the thread: the transcript is
   * what persists, so anything that lands there outlives the state it described.
   */
  public function testDurableStoreNeverHoldsStateSnapshots(): void {
    $append = $this->append();
    $append->process($this->transcript(['content' => 'rename the second byline']));
    $append->process($this->scratchpad([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [self::STALE_SNAPSHOT],
      ],
    ]));
    $append->process($this->scratchpad([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Applied.'],
      ],
    ]));
    $append->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    $durable = $this->container->get('flowdrop_memory.manager')
      ->get('session', '42', 'conversation', [], 'static');

    $this->assertCount(2, $durable);
    foreach ($durable as $message) {
      $this->assertContains($message['role'], ['user', 'assistant', 'system']);
      $this->assertArrayNotHasKey('tool_calls', $message);
      $this->assertArrayNotHasKey('tool_call_id', $message);
      $this->assertNotSame('', $message['content']);
    }
    $this->assertStringNotContainsString('Tomas Rice', (string) json_encode($durable));
  }

  /**
   * Assistant prose is recordable as prose — the config-only half of the split.
   *
   * Without `assistant` in the role enum the transcript could only hold the FULL
   * assistant message, which carries tool_calls: the snapshot would be back.
   */
  public function testAssistantProseIsAcceptedOnTheContentInput(): void {
    $result = $this->append()->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    $this->assertSame(1, $result['appended']);
    $this->assertSame('assistant', $result['messages'][0]['role']);
    $this->assertSame('Renamed the second byline.', $result['messages'][0]['content']);
  }

  /**
   * A read node pointed at the scratchpad is refused, not silently doubled.
   */
  public function testReadRefusesTheScratchpadAsItsTranscript(): void {
    $result = $this->read()->validateParams([
      'scope' => Scratchpad::SCOPE,
      'key' => Scratchpad::KEY,
    ]);

    $this->assertFalse($result->isValid());
  }

  /**
   * And it never double-reads even if such a config reaches process().
   *
   * A well-formed pair, so the assertion is about DOUBLING and not about the
   * normaliser's unrelated habit of dropping an orphan tool result.
   */
  public function testScratchpadAddressIsNotConcatenatedOntoItself(): void {
    $append = $this->append();
    $append->process($this->scratchpad([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [self::STALE_SNAPSHOT],
      ],
    ]));
    $append->process($this->scratchpad([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Applied.'],
      ],
    ]));

    $result = $this->read()->process($this->scratchpad([]));

    $this->assertSame(0, $result['scratchpad_count']);
    $this->assertCount(2, $result['messages']);
  }

}
