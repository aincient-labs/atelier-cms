<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop\DTO\Reason\ReasonMessage;
use Drupal\flowdrop_memory\Plugin\FlowDropNodeProcessor\ConversationBuffer;
use Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\ConversationNormalize;
use Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\MessageAssemble;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The two-tier agent context split, rebuilt on NATIVE nodes — Phase 6.
 *
 * Successor to the fork's ConversationTiersTest (DECISIONS 0379/0382): the six
 * agent workflows now compose FlowDrop 2.3.0-alpha1 native nodes instead of
 * the fork's ConversationAppend/ConversationRead/MessageAssemble —
 * `conversation_buffer` (flowdrop_memory) for BOTH tiers,
 * `message_assemble` and `conversation_normalize` (flowdrop_node_processor)
 * to join and repair the prompt. These tests compose the same nodes the
 * migrated graphs wire, with the same addresses, and must outlive the fork.
 *
 * The bug the tiers exist to prevent (cms#29): an agent renamed a byline to
 * "Tomas Ricet" when the live draft said "Tomas Ricen", because a
 * session-scoped buffer deposited a permanent page-state snapshot every edit
 * and a later turn carried two contradicting copies of the same content. The
 * invariant: live state is injected exactly once per turn; no DURABLE
 * conversation message may carry a state snapshot; tool traffic is addressed
 * to the TURN (scope `pipeline`), not the session.
 *
 * The native mapping of the fork's roles:
 * - transcript append = conversation_buffer at scope `session`, key
 *   `conversation`, backend `entity`;
 * - scratchpad append = conversation_buffer at scope `pipeline`, key
 *   `scratchpad`, backend `entity`;
 * - a READ is the SAME buffer node invoked with role `user` and no
 *   content/message input: the empty append is dropped (empty content that is
 *   not a silent tool call), so the `messages` output is exactly the stored
 *   buffer;
 * - message_assemble(conversation, scratchpad) → conversation_normalize
 *   produces the flat, provider-sendable list ReasonMessage::fromArray()
 *   expects (top-level tool_calls / tool_call_id, system-first, tool pairing
 *   intact).
 *
 * `backend: entity` is passed directly in the ParameterBag: at PLUGIN level
 * the node-type config exposure gate does not apply. Whether the ACTIVE
 * node-type CONFIG exposes it to graphs is asserted separately in
 * testActiveNodeTypeConfigDeclaresConfigurableBackend().
 *
 * @group aincient_flows
 */
#[Group('aincient_flows')]
#[RunTestsInSeparateProcesses]
final class NativeConversationTiersTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Every module whose services or config the composed nodes touch is listed
   * explicitly — a cross-module service dependency with a missing module here
   * fails container compile only in the full parallel run.
   */
  protected static $modules = [
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_node_processor',
    'flowdrop_memory',
  ];

  /**
   * A page-state snapshot of the kind that used to poison the buffer.
   */
  private const STALE_SNAPSHOT = [
    'tool_call_id' => 'call_1',
    'name' => 'aincient_preview_page',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_memory_record');
    $this->installConfig(['flowdrop_memory']);
  }

  /**
   * A conversation_buffer node bound to one turn (pipeline) of one thread.
   */
  private function buffer(string $pipelineId = 'p1'): ConversationBuffer {
    $node = ConversationBuffer::create(
      $this->container,
      [],
      'conversation_buffer',
      ['id' => 'conversation_buffer'],
    );
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
   * Tier A params: the durable transcript, addressed as the graphs address it.
   *
   * @param array<string, mixed> $extra
   *   The append inputs (role/content or message); empty for a pure read.
   */
  private function transcript(array $extra = []): ParameterBag {
    return new ParameterBag($extra + [
      'scope' => 'session',
      'key' => 'conversation',
      'backend' => 'entity',
    ]);
  }

  /**
   * Tier B params: this turn's scratchpad.
   *
   * @param array<string, mixed> $extra
   *   The append inputs (role/content or message); empty for a pure read.
   */
  private function scratchpad(array $extra = []): ParameterBag {
    return new ParameterBag($extra + [
      'scope' => 'pipeline',
      'key' => 'scratchpad',
      'backend' => 'entity',
    ]);
  }

  /**
   * The pure-read invocation: role `user`, NO content, NO message.
   *
   * The buffer drops an empty non-tool-call append, so this returns the
   * stored buffer without changing it — the native replacement for the
   * fork's dedicated read node.
   *
   * @param array<string, mixed> $address
   *   The store address params (scope/key/backend).
   *
   * @return array<int, array<string, mixed>>
   *   The stored messages.
   */
  private function readVia(ConversationBuffer $node, ParameterBag $address): array {
    $params = new ParameterBag(['role' => 'user'] + $address->all());
    return $node->process($params)['messages'];
  }

  /**
   * Assemble(conversation, scratchpad) → normalize, as the graphs wire it.
   *
   * Port order IS message order, so the transcript port is declared first:
   * the turn's tool traffic lands nearest the question, never before the
   * prose that prompted it.
   *
   * @param array<int, array<string, mixed>> $conversation
   *   The transcript reader's messages.
   * @param array<int, array<string, mixed>> $scratchpad
   *   The scratchpad reader's messages.
   *
   * @return array<string, mixed>
   *   The normalize node's output ({messages, dropped}).
   */
  private function assembleAndNormalize(array $conversation, array $scratchpad): array {
    $assemble = MessageAssemble::create(
      $this->container,
      [],
      'message_assemble',
      ['id' => 'message_assemble'],
    );
    $assembled = $assemble->process(new ParameterBag([
      'dynamicInputs' => [
        ['name' => 'conversation', 'label' => 'Conversation', 'dataType' => 'messages'],
        ['name' => 'scratchpad', 'label' => 'Scratchpad', 'dataType' => 'messages'],
      ],
      'conversation' => $conversation,
      'scratchpad' => $scratchpad,
    ]));

    $normalize = ConversationNormalize::create(
      $this->container,
      [],
      'conversation_normalize',
      ['id' => 'conversation_normalize'],
    );
    return $normalize->process(new ParameterBag(['messages' => $assembled['messages']]));
  }

  /**
   * Runs one full turn's appends: user prose, then the turn's tool traffic.
   */
  private function runTurnOne(ConversationBuffer $buffer): void {
    $buffer->process($this->transcript([
      'role' => 'user',
      'content' => 'rename the second byline',
    ]));
    $buffer->process($this->scratchpad([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [self::STALE_SNAPSHOT],
      ],
    ]));
    $buffer->process($this->scratchpad([
      'message' => [
        ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Applied.'],
      ],
    ]));
  }

  /**
   * (a) Mid-turn, the assembled list carries this turn's tool traffic.
   *
   * The half that must NOT regress: the loop only closes if the assistant
   * tool_calls message and its results come back on the next read — flat,
   * with top-level tool_calls / tool_call_id, the shape
   * ReasonMessage::fromArray() expects.
   */
  public function testMidTurnAssembledListCarriesThisTurnsToolTraffic(): void {
    $buffer = $this->buffer();
    $this->runTurnOne($buffer);

    $result = $this->assembleAndNormalize(
      $this->readVia($buffer, $this->transcript()),
      $this->readVia($buffer, $this->scratchpad()),
    );

    $this->assertSame(0, $result['dropped']);
    $messages = $result['messages'];
    $this->assertCount(3, $messages);

    // Transcript first, then the turn's traffic — and the tool_use/tool_result
    // pair stays adjacent, or a provider rejects the sequence outright.
    $this->assertSame('user', $messages[0]['role']);
    $this->assertSame('rename the second byline', $messages[0]['content']);
    $this->assertSame('assistant', $messages[1]['role']);
    $this->assertSame('call_1', $messages[1]['tool_calls'][0]['tool_call_id']);
    $this->assertSame('tool', $messages[2]['role']);
    $this->assertSame('call_1', $messages[2]['tool_call_id']);

    // The rows are FLAT — normalize lifted the buffer's metadata nesting, and
    // no buffer bookkeeping leaks into the prompt.
    foreach ($messages as $message) {
      $this->assertArrayNotHasKey('metadata', $message);
      $this->assertArrayNotHasKey('timestamp', $message);
    }

    // The list round-trips through the DTO the reason node builds from it:
    // tool identity survives fromArray() exactly.
    $assistant = ReasonMessage::fromArray($messages[1]);
    $this->assertSame('call_1', $assistant->getToolCalls()[0]['tool_call_id']);
    $toolResult = ReasonMessage::fromArray($messages[2]);
    $this->assertSame('call_1', $toolResult->getToolCallId());
  }

  /**
   * (b) THE REGRESSION TEST: a new turn cannot see last turn's snapshot.
   *
   * A new turn is a new pipeline, so the scratchpad resolves to a new address
   * — last turn's tool traffic is UNREACHABLE rather than stale. This is the
   * exact failure from cms#29: had turn 2 seen `Tomas Rice` here, the model
   * would have produced `Tomas Ricet` from it instead of reading the live
   * draft.
   */
  public function testNewTurnScratchpadIsUnreachable(): void {
    $turnOne = $this->buffer('p1');
    $this->runTurnOne($turnOne);
    $turnOne->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    // Turn 2: the user has since edited the draft by hand, then asks for a
    // relative edit. A different pipeline, the same thread.
    $turnTwo = $this->buffer('p2');
    $turnTwo->process($this->transcript([
      'role' => 'user',
      'content' => "add a t after tomas's last name",
    ]));

    $scratchpad = $this->readVia($turnTwo, $this->scratchpad());
    $this->assertSame([], $scratchpad, 'The new turn reads an EMPTY scratchpad — last turn is unreachable.');

    $result = $this->assembleAndNormalize(
      $this->readVia($turnTwo, $this->transcript()),
      $scratchpad,
    );

    $encoded = (string) json_encode($result['messages']);
    $this->assertStringNotContainsString('Tomas Rice', $encoded);
    $this->assertStringNotContainsString('update_section', $encoded);
    $this->assertStringNotContainsString('quotes', $encoded);

    // Prose survives: intent and continuity are exactly what the transcript is
    // for, and the agent's own narration is the only receipt it gets.
    $this->assertCount(3, $result['messages']);
    $this->assertSame('assistant', $result['messages'][1]['role']);
    $this->assertSame('Renamed the second byline.', $result['messages'][1]['content']);
  }

  /**
   * (c) The durable session store never holds tool_calls rows.
   *
   * Asserted against the STORE rather than the read, because this is the
   * property that must hold for the life of the thread: whatever lands in the
   * `session`/`conversation` record outlives the state it described. The
   * graphs enforce it by ADDRESSING — every tool-traffic append points at the
   * pipeline scratchpad — so the test composes the appends exactly that way
   * and then opens the durable record.
   */
  public function testDurableSessionStoreNeverHoldsToolCallsRows(): void {
    $buffer = $this->buffer();
    $this->runTurnOne($buffer);
    $buffer->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    $durable = $this->container->get('flowdrop_memory.manager')
      ->get('session', '42', 'conversation', [], 'entity');

    $this->assertIsArray($durable);
    $this->assertCount(2, $durable);
    foreach ($durable as $message) {
      $this->assertContains($message['role'], ['user', 'assistant', 'system']);
      $this->assertArrayNotHasKey('tool_calls', $message);
      $this->assertArrayNotHasKey('tool_call_id', $message);
      // The buffer nests extras under metadata — the snapshot must not hide
      // there either.
      $this->assertArrayNotHasKey('tool_calls', $message['metadata']);
      $this->assertArrayNotHasKey('tool_call_id', $message['metadata']);
      $this->assertNotSame('', $message['content']);
    }
    $this->assertStringNotContainsString('Tomas Rice', (string) json_encode($durable));
  }

  /**
   * (d) Assistant prose is appendable to the transcript as prose.
   *
   * Without this the transcript could only hold the FULL assistant message,
   * which carries tool_calls: the snapshot would be back.
   */
  public function testAssistantProseIsAppendableToTheTranscript(): void {
    $result = $this->buffer()->process($this->transcript([
      'role' => 'assistant',
      'content' => 'Renamed the second byline.',
    ]));

    $this->assertSame(1, $result['count']);
    $this->assertSame('assistant', $result['messages'][0]['role']);
    $this->assertSame('Renamed the second byline.', $result['messages'][0]['content']);
  }

  /**
   * (e) Iteration 1: an empty scratchpad assembles cleanly.
   *
   * The first reasoning pass of every turn runs before any tool has fired:
   * the scratchpad reader emits [], and the assembled+normalized prompt is
   * just the transcript, with nothing dropped and nothing synthesized.
   */
  public function testIterationOneEmptyScratchpadAssemblesCleanly(): void {
    $buffer = $this->buffer();
    $buffer->process($this->transcript([
      'role' => 'user',
      'content' => 'rename the second byline',
    ]));

    $result = $this->assembleAndNormalize(
      $this->readVia($buffer, $this->transcript()),
      $this->readVia($buffer, $this->scratchpad()),
    );

    $this->assertSame(0, $result['dropped']);
    $this->assertCount(1, $result['messages']);
    $this->assertSame(
      ['role' => 'user', 'content' => 'rename the second byline'],
      $result['messages'][0],
    );
  }

  /**
   * The pure-read invocation appends nothing, ever.
   *
   * The native design has no dedicated read node: a buffer invoked with role
   * `user` and no content/message IS the reader. That only works if the empty
   * append is dropped — a read that grew the buffer would corrupt every
   * multi-iteration turn.
   */
  public function testReadInvocationAppendsNothing(): void {
    $buffer = $this->buffer();
    $this->runTurnOne($buffer);

    $first = $this->readVia($buffer, $this->scratchpad());
    $second = $this->readVia($buffer, $this->scratchpad());

    $this->assertCount(2, $first);
    $this->assertSame($first, $second, 'Reading twice returns the same buffer — the read is not an append.');

    $stored = $this->container->get('flowdrop_memory.manager')
      ->get('pipeline', 'p1', 'scratchpad', [], 'entity');
    $this->assertCount(2, $stored, 'The stored buffer did not grow.');
  }

  /**
   * A system turn in the transcript floats to the front of the prompt.
   *
   * Normalize's system-first rule, exercised through the full native pipe
   * rather than in isolation: whatever order the stores replay, the provider
   * sees leading system messages only.
   */
  public function testSystemTurnFloatsToTheFrontOfTheAssembledPrompt(): void {
    $buffer = $this->buffer();
    $buffer->process($this->transcript([
      'role' => 'user',
      'content' => 'rename the second byline',
    ]));
    $buffer->process($this->transcript([
      'role' => 'system',
      'content' => 'House style: bylines are never abbreviated.',
    ]));

    $result = $this->assembleAndNormalize(
      $this->readVia($buffer, $this->transcript()),
      $this->readVia($buffer, $this->scratchpad()),
    );

    $this->assertSame(0, $result['dropped']);
    $this->assertSame('system', $result['messages'][0]['role']);
    $this->assertSame('user', $result['messages'][1]['role']);
  }

  /**
   * The engine's own node-type config declares everything the graphs need.
   *
   * Everything above drives the PLUGIN with `backend: entity` in the
   * ParameterBag, which works regardless of config. The migrated graphs,
   * though, set the backend in node instance config — which the editor and
   * the runtime only honour when `flowdrop_node_type.conversation_buffer`
   * declares the parameter configurable. Through 2.3.0-alpha3 that flag was
   * ours to add; FlowDrop 2.3.0-alpha4 ships all four flags AND the gate
   * policy in `flowdrop_memory`'s `config/install`, so this test now installs
   * the pristine engine YAML and pins it unamended. A regression upstream
   * fails here.
   */
  public function testActiveNodeTypeConfigDeclaresConfigurableBackend(): void {
    $nodeType = $this->container->get('entity_type.manager')
      ->getStorage('flowdrop_node_type')
      ->load('conversation_buffer');
    $this->assertNotNull($nodeType, 'The conversation_buffer node type config entity exists.');

    $reloaded = $nodeType;
    $parameters = $reloaded->get('parameters');
    $this->assertArrayHasKey('backend', $parameters, 'The shipped node type declares a backend parameter.');
    $this->assertTrue($parameters['backend']['configurable'] ?? FALSE, 'The backend parameter is configurable.');
    $this->assertSame('static', $parameters['backend']['default'] ?? NULL, 'The default stays the plugin default; durable instances opt in explicitly.');
    $this->assertFalse($parameters['backend']['connectable'] ?? TRUE, 'A storage address is not runtime data.');

    // The engine-contract realignment (the live MissingParameterException on
    // the pure-reader invocation, and message-input drops): message declared
    // and connectable; content/role never schema-required — the plugin
    // enforces role/content conditionally in process().
    $this->assertArrayHasKey('message', $parameters, 'The message parameter is declared, so wired message inputs resolve.');
    $this->assertTrue($parameters['message']['connectable'] ?? FALSE, 'The message parameter is connectable.');
    $this->assertFalse($parameters['content']['required'] ?? TRUE, 'content is not schema-required (the pure reader leaves it unwired).');
    $this->assertFalse($parameters['role']['required'] ?? TRUE, 'role is not schema-required.');

    // The gate: a memory write is not an external action. An undecided policy
    // derives to "ask" from HasSideEffectsInterface, which would pause every
    // buffer touch for operator approval; alpha4 decides it upstream.
    $this->assertSame('skip', $reloaded->getConfirmation()->policy, 'The shipped buffer declares the skip confirmation policy.');

    // An EXISTING site's drifted map is reconverged by `config:import`, not by
    // a post_update: this node type is tracked in config/sync and is not
    // config_ignore'd, so converge.sh's cim re-asserts every assertion above
    // on upgrade. (Upstream's `config/install` fires at module install only —
    // that is the gap cim closes, and the gap the retired realign hook used
    // to.) Proven end-to-end by docker/tests/upgrade-from-released.sh.
  }

}
