<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationAppend;
use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationRead;
use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the ConversationAppend node — the visible conversation buffer writer.
 *
 * The node is instantiated through the container (it injects the
 * flowdrop_memory MemoryManager); the `static` backend keeps the buffer
 * request-local for most tests, and one test installs the MemoryRecord
 * entity schema to prove `entity` persistence end-to-end.
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ConversationAppend
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class ConversationAppendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flowdrop', 'flowdrop_memory'];

  /**
   * Creates the node with an execution context carrying a session id.
   */
  private function node(string $sessionId = '42'): ConversationAppend {
    $node = ConversationAppend::create(
      $this->container,
      [],
      'aincient_flows:aincient_conversation_append',
      [],
    );
    $node->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: 'p1',
      executionId: 'e1',
      nodeId: 'append.1',
      metadata: ['session_id' => $sessionId],
    ));
    return $node;
  }

  /**
   * Common parameters: static backend, session scope.
   */
  private function params(array $extra): ParameterBag {
    return new ParameterBag($extra + [
      'scope' => 'session',
      'key' => 'conversation',
      'backend' => 'static',
    ]);
  }

  /**
   * @covers ::process
   *
   * Plain content becomes a user turn; a full assistant message keeps its
   * tool_calls; tool messages keep their tool_call_id — the whole agent
   * round-trip shape survives storage.
   */
  public function testAppendsFullAgentShapes(): void {
    $node = $this->node();

    $node->process($this->params(['content' => 'create a page about coconuts']));
    $node->process($this->params([
      'message' => [
        'role' => 'assistant',
        'content' => "I'll create that page.",
        'tool_calls' => [
          ['name' => 'capability_create_content', 'args' => ['title' => 'Coconuts'], 'tool_call_id' => 't1'],
        ],
      ],
    ]));
    $result = $node->process($this->params([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 't1', 'content' => 'Created node 5 (draft).'],
      ],
    ]));

    $this->assertSame(3, $result['count']);
    $this->assertSame(1, $result['appended']);
    [$user, $assistant, $tool] = $result['messages'];
    $this->assertSame(['user', 'create a page about coconuts'], [$user['role'], $user['content']]);
    $this->assertSame('t1', $assistant['tool_calls'][0]['tool_call_id']);
    $this->assertSame(['title' => 'Coconuts'], $assistant['tool_calls'][0]['args']);
    $this->assertSame('t1', $tool['tool_call_id']);
    $this->assertSame('42', $result['resolved_scope_id']);
  }

  /**
   * @covers ::process
   *
   * Idempotency: a tool result is uniquely keyed by tool_call_id, so re-firing
   * the append with the SAME tool_messages (a wave-scheduler re-evaluation or a
   * pipeline resume on the loop_back cycle) must NOT duplicate the tool turn —
   * a duplicate would make the provider reject the buffer on the next inference
   * ("tool_call_id … not found in tool_calls of previous message").
   */
  public function testToolResultAppendIsIdempotent(): void {
    $node = $this->node();
    $node->process($this->params(['content' => 'make it neon']));
    $node->process($this->params([
      'message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [['name' => 'capability_preview_brand', 'args' => [], 'tool_call_id' => 'toolu_dup']],
      ],
    ]));

    // First write of the tool result.
    $first = $node->process($this->params([
      'messages' => [['role' => 'tool', 'tool_call_id' => 'toolu_dup', 'content' => '{"__widget__":"brand_preview"}']],
    ]));
    $this->assertSame(1, $first['appended']);
    $this->assertSame(3, $first['count']);

    // Re-fire with the identical tool_messages — must be a no-op append.
    $second = $node->process($this->params([
      'messages' => [['role' => 'tool', 'tool_call_id' => 'toolu_dup', 'content' => '{"__widget__":"brand_preview"}']],
    ]));
    $this->assertSame(0, $second['appended']);
    $this->assertSame(3, $second['count']);

    // Exactly one tool turn carries that id — the pairing stays well-formed.
    $toolTurns = array_filter($second['messages'], static fn (array $m): bool => ($m['tool_call_id'] ?? '') === 'toolu_dup');
    $this->assertCount(1, $toolTurns);
  }

  /**
   * @covers ::process
   *
   * A single batch carrying the same tool_call_id twice (a malformed upstream
   * emission) still collapses to one stored tool turn.
   */
  public function testDuplicateToolCallIdWithinOneBatchCollapses(): void {
    $node = $this->node();
    $result = $node->process($this->params([
      'messages' => [
        ['role' => 'tool', 'tool_call_id' => 'x1', 'content' => 'first'],
        ['role' => 'tool', 'tool_call_id' => 'x1', 'content' => 'second'],
      ],
    ]));
    $this->assertSame(1, $result['appended']);
    $this->assertSame('first', $result['messages'][0]['content']);
  }

  /**
   * @covers ::process
   *
   * The decline branch: tool_calls synthesize "Not executed" tool results so
   * the buffer stays well-formed without any in-node healing downstream.
   */
  public function testDeclinedToolCallsSynthesizeResults(): void {
    $node = $this->node();
    $node->process($this->params([
      'message' => [
        'role' => 'assistant',
        'content' => 'Deleting it now.',
        'tool_calls' => [['name' => 'capability_delete_content', 'args' => ['nid' => 9], 'tool_call_id' => 'del1']],
      ],
    ]));

    $result = $node->process($this->params([
      'declined_tool_calls' => [
        ['name' => 'capability_delete_content', 'args' => ['nid' => 9], 'tool_call_id' => 'del1'],
      ],
    ]));

    $this->assertSame(2, $result['count']);
    $declined = $result['messages'][1];
    $this->assertSame('tool', $declined['role']);
    $this->assertSame('del1', $declined['tool_call_id']);
    $this->assertStringContainsString('declined the approval for capability_delete_content', $declined['content']);
  }

  /**
   * @covers ::process
   *
   * Junk is dropped: unknown roles, tool messages without a tool_call_id,
   * empty content (except assistant turns that carry tool_calls).
   */
  public function testNormalizationDropsMalformedMessages(): void {
    $node = $this->node();
    $result = $node->process($this->params([
      'messages' => [
        ['role' => 'wizard', 'content' => 'nope'],
        ['role' => 'tool', 'content' => 'orphan result'],
        ['role' => 'user', 'content' => ''],
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'x', 'tool_call_id' => 't9']]],
      ],
    ]));

    $this->assertSame(1, $result['count']);
    $this->assertSame('assistant', $result['messages'][0]['role']);
    $this->assertSame('t9', $result['messages'][0]['tool_calls'][0]['tool_call_id']);
  }

  /**
   * @covers ::process
   *
   * The sliding window trims at a user-turn boundary only — a tool_use is
   * never split from its tool_result.
   */
  public function testWindowTrimsAtUserBoundary(): void {
    $node = $this->node();
    // Turn 1: user + assistant(tool_calls) + tool result. Turn 2: user.
    $node->process($this->params(['content' => 'first ask']));
    $node->process($this->params([
      'message' => ['role' => 'assistant', 'content' => 'ok', 'tool_calls' => [['name' => 'f', 'tool_call_id' => 'a']]],
    ]));
    $node->process($this->params([
      'messages' => [['role' => 'tool', 'tool_call_id' => 'a', 'content' => 'done']],
    ]));
    $result = $node->process($this->params(['content' => 'second ask', 'max_messages' => 2]));

    // Naive window start (count-2) lands on the tool result; the cut advances
    // to the next user turn instead, leaving only turn 2.
    $this->assertSame(1, $result['count']);
    $this->assertSame('second ask', $result['messages'][0]['content']);
  }

  /**
   * @covers ::process
   *
   * Buffers are isolated per resolved session scope.
   */
  public function testSessionScopeIsolation(): void {
    $this->node('7')->process($this->params(['content' => 'seven']));
    $result = $this->node('8')->process($this->params(['content' => 'eight']));

    $this->assertSame(1, $result['count']);
    $this->assertSame('eight', $result['messages'][0]['content']);
  }

  /**
   * The read pair returns exactly what the appenders wrote.
   *
   * This is the loop re-entry read; tool pairing keys must survive.
   */
  public function testReadPairReturnsBuffer(): void {
    $node = $this->node();
    $node->process($this->params(['content' => 'hi']));
    $node->process($this->params([
      'messages' => [['role' => 'tool', 'tool_call_id' => 'r1', 'content' => 'done']],
      'message' => ['role' => 'assistant', 'content' => 'ok', 'tool_calls' => [['name' => 'f', 'tool_call_id' => 'r1']]],
    ]));

    $read = ConversationRead::create(
      $this->container,
      [],
      'aincient_flows:aincient_conversation_read',
      [],
    );
    $read->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: 'p1',
      executionId: 'e1',
      nodeId: 'read.1',
      metadata: ['session_id' => '42'],
    ));
    $result = $read->process(new ParameterBag([
      'scope' => 'session',
      'key' => 'conversation',
      'backend' => 'static',
    ]));

    $this->assertSame(3, $result['count']);
    $this->assertSame('hi', $result['messages'][0]['content']);
    $this->assertSame('r1', $result['messages'][1]['tool_calls'][0]['tool_call_id']);
    $this->assertSame('r1', $result['messages'][2]['tool_call_id']);
    $this->assertSame('42', $result['resolved_scope_id']);
  }

  /**
   * Self-heal: a buffer corrupted before the writer guard (a duplicate tool
   * result) is repaired on READ so Reason never infers over an unpaired tool
   * turn. Storage is left as-is; the read just returns a well-formed view.
   */
  public function testReadHealsDuplicateToolResult(): void {
    // Hand-write a corrupt buffer straight to memory (simulating pre-guard
    // damage): one assistant tool_use, then the SAME tool result twice.
    $this->container->get('flowdrop_memory.manager')->set('session', '42', 'conversation', [
      ['role' => 'user', 'content' => 'make it neon'],
      ['role' => 'assistant', 'content' => '', 'tool_calls' => [['name' => 'preview', 'tool_call_id' => 'dup']]],
      ['role' => 'tool', 'tool_call_id' => 'dup', 'content' => 'result'],
      ['role' => 'tool', 'tool_call_id' => 'dup', 'content' => 'result'],
      ['role' => 'assistant', 'content' => 'done'],
    ], NULL, 'static');

    $read = ConversationRead::create($this->container, [], 'aincient_flows:aincient_conversation_read', []);
    $read->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: 'p1',
      executionId: 'e1',
      nodeId: 'read.1',
      metadata: ['session_id' => '42'],
    ));
    $result = $read->process(new ParameterBag([
      'scope' => 'session',
      'key' => 'conversation',
      'backend' => 'static',
    ]));

    // The duplicate is gone — exactly one tool turn carries 'dup'.
    $this->assertSame(4, $result['count']);
    $toolTurns = array_filter($result['messages'], static fn (array $m): bool => ($m['tool_call_id'] ?? '') === 'dup');
    $this->assertCount(1, $toolTurns);
  }

  /**
   * @covers ::process
   *
   * The entity backend persists across node instances (the cross-request /
   * cross-turn story on the console path).
   */
  public function testEntityBackendPersists(): void {
    $this->installEntitySchema('flowdrop_memory_record');

    $this->node()->process(new ParameterBag([
      'content' => 'remember me',
      'scope' => 'session',
      'backend' => 'entity',
    ]));
    $result = $this->node()->process(new ParameterBag([
      'content' => 'second turn',
      'scope' => 'session',
      'backend' => 'entity',
    ]));

    $this->assertSame(2, $result['count']);
    $this->assertSame('remember me', $result['messages'][0]['content']);
  }

}
