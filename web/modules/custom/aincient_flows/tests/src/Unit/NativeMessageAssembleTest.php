<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop_node_processor\Plugin\FlowDropNodeProcessor\MessageAssemble;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the native message_assemble contract OUR six graphs rely on.
 *
 * Successor to the fork's MessageAssembleTest (Phase 6, DECISIONS 0383): the
 * six agent workflows now wire the FlowDrop-native
 * `flowdrop_node_processor:message_assemble` node, so the contract our graphs
 * depend on — declared port order IS the message order — must be pinned
 * against the native plugin. Upstream's own MessageAssembleTest already pins
 * the generic behaviour (order, unwired ports, wrapping, junk handling,
 * reserved names, schemas); this test pins only what the MIGRATED GRAPHS
 * lean on, in their own vocabulary:
 *
 * - the conversation-then-scratchpad ordering every agent graph wires,
 * - an iteration-1 EMPTY scratchpad (the buffer read returns `[]`),
 * - buffer-shaped rows ({role, content, metadata, timestamp}) passing through
 *   UNTOUCHED — shape repair is conversation_normalize's job, downstream,
 * - the contract DIFFERENCES from the fork node, encoded as the native
 *   node's actual behaviour (no counters, silent malformed-source drop).
 *
 * A unit test on purpose, same as upstream's: the node touches no storage and
 * no container.
 *
 * @group aincient_flows
 */
#[Group('aincient_flows')]
final class NativeMessageAssembleTest extends TestCase {

  /**
   * The native node under test, constructed the way upstream's test does.
   */
  private function node(): MessageAssemble {
    return new MessageAssemble([], 'message_assemble', ['id' => 'message_assemble']);
  }

  /**
   * Builds params the way an instance's config plus wiring arrives.
   *
   * @param list<string> $ports
   *   Port names, in declaration order.
   * @param array<string, mixed> $values
   *   Values arriving on those ports, keyed by port name. A port named in
   *   $ports but absent here is an unconnected port.
   */
  private function params(array $ports, array $values = []): ParameterBag {
    $defs = [];
    foreach ($ports as $port) {
      $defs[] = ['name' => $port, 'label' => ucfirst($port), 'dataType' => 'messages'];
    }
    return new ParameterBag($values + ['dynamicInputs' => $defs]);
  }

  /**
   * A flat message, terse enough to read an expected sequence at a glance.
   *
   * @return array<string, mixed>
   *   The message.
   */
  private function msg(string $role, string $content): array {
    return ['role' => $role, 'content' => $content];
  }

  /**
   * A conversation_buffer-shaped row, as the buffer's messages output emits.
   *
   * @param array<string, mixed> $metadata
   *   The metadata bag (tool_calls / tool_call_id live here).
   *
   * @return array<string, mixed>
   *   The buffer row.
   */
  private function bufferRow(string $role, string $content, array $metadata = []): array {
    return [
      'role' => $role,
      'content' => $content,
      'metadata' => $metadata,
      'timestamp' => 1755000000,
    ];
  }

  /**
   * Roles+content of an assembled result, for sequence assertions.
   *
   * @param array<string, mixed> $result
   *   The node output.
   *
   * @return list<string>
   *   One "role:content" string per message, in order.
   */
  private function sequence(array $result): array {
    return array_map(
      static fn (array $m): string => $m['role'] . ':' . $m['content'],
      $result['messages'],
    );
  }

  /**
   * Conversation first, scratchpad second — the wiring all six graphs use.
   *
   * The turn's tool traffic must land nearest the question, never before the
   * prose that prompted it: the transcript port is declared first, so its
   * messages come first.
   */
  public function testConversationThenScratchpadOrder(): void {
    $result = $this->node()->process($this->params(
      ['conversation', 'scratchpad'],
      [
        'conversation' => [
          $this->msg('user', 'rename the second byline'),
        ],
        'scratchpad' => [
          $this->msg('assistant', 'calling the tool'),
          $this->msg('tool', 'Applied.'),
        ],
      ],
    ));

    $this->assertSame(
      ['user:rename the second byline', 'assistant:calling the tool', 'tool:Applied.'],
      $this->sequence($result),
    );
  }

  /**
   * Reordering the declared ports reorders the output, nothing else.
   *
   * The same stores, the same wires: only the config list moved. If this ever
   * stops being true the node has become a black box again.
   */
  public function testReorderingPortsReordersTheOutput(): void {
    $values = [
      'conversation' => [$this->msg('user', 'said')],
      'scratchpad' => [$this->msg('tool', 'called')],
    ];

    $before = $this->node()->process($this->params(['conversation', 'scratchpad'], $values));
    $after = $this->node()->process($this->params(['scratchpad', 'conversation'], $values));

    $this->assertSame(['user:said', 'tool:called'], $this->sequence($before));
    $this->assertSame(['tool:called', 'user:said'], $this->sequence($after));
  }

  /**
   * Iteration 1: the scratchpad buffer read is `[]` and contributes nothing.
   *
   * The first reasoning pass of every turn assembles before any tool has run,
   * so the scratchpad reader emits an empty list — which must vanish, not
   * inject an empty entry or fail.
   */
  public function testEmptyScratchpadAtIterationOneContributesNothing(): void {
    $result = $this->node()->process($this->params(
      ['conversation', 'scratchpad'],
      [
        'conversation' => [$this->msg('user', 'said')],
        'scratchpad' => [],
      ],
    ));

    $this->assertSame(['user:said'], $this->sequence($result));
  }

  /**
   * An unwired port contributes nothing — no NULL placeholder, no gap.
   */
  public function testUnwiredScratchpadContributesNothing(): void {
    $result = $this->node()->process($this->params(
      ['conversation', 'scratchpad'],
      ['conversation' => [$this->msg('user', 'said')]],
    ));

    $this->assertSame(['user:said'], $this->sequence($result));
  }

  /**
   * Buffer-shaped rows pass through byte-identical.
   *
   * The conversation_buffer emits {role, content, metadata, timestamp} rows
   * with tool traffic nested under metadata. The native assembler does NOT
   * reshape them — lifting tool_calls to the top level is
   * conversation_normalize's job, wired downstream. A change here would mean
   * the assemble → normalize division of labour moved, and the graphs' wiring
   * assumptions with it.
   */
  public function testBufferShapedRowsPassThroughUnchanged(): void {
    $assistantRow = $this->bufferRow('assistant', '', [
      'tool_calls' => [['tool_call_id' => 'call_1', 'name' => 'aincient_preview_page', 'args' => []]],
    ]);
    $toolRow = $this->bufferRow('tool', 'Applied.', ['tool_call_id' => 'call_1']);

    $result = $this->node()->process($this->params(
      ['scratchpad'],
      ['scratchpad' => [$assistantRow, $toolRow]],
    ));

    $this->assertSame([$assistantRow, $toolRow], $result['messages']);
  }

  /**
   * A port fed by a node that emits ONE message object still works.
   */
  public function testSingleMessageObjectIsWrappedAsOneMessage(): void {
    $result = $this->node()->process($this->params(
      ['conversation', 'assistant_turn'],
      [
        'conversation' => [$this->msg('user', 'said')],
        'assistant_turn' => $this->msg('assistant', 'replied'),
      ],
    ));

    $this->assertSame(['user:said', 'assistant:replied'], $this->sequence($result));
  }

  /**
   * The output is one flat sequence, not a container of lists.
   */
  public function testListInputsAreFlattenedNotNested(): void {
    $result = $this->node()->process($this->params(
      ['conversation', 'scratchpad'],
      [
        'conversation' => [$this->msg('user', 'one'), $this->msg('assistant', 'two')],
        'scratchpad' => [$this->msg('tool', 'three')],
      ],
    ));

    $this->assertCount(3, $result['messages']);
    foreach ($result['messages'] as $message) {
      $this->assertArrayHasKey('role', $message, 'A message, not a list of messages.');
    }
  }

  /**
   * A malformed source is dropped silently — no exception, no counter.
   *
   * DIFFERENT from the fork node, deliberately so: the fork counted the drop
   * in a `skipped` output and per-port `sources`. The native node has NO
   * observability outputs — its whole output is the `messages` key — and it
   * delegates shape validation to conversation_normalize (whose `dropped`
   * output is now where a lost source shows up). What survives of the fork
   * contract is the part the agent depends on: one bad source (a JSON string
   * never decoded in the graph, DECISIONS 0380) costs its own messages, never
   * the whole prompt and never the run.
   */
  public function testMalformedSourceIsDroppedSilentlyNotThrown(): void {
    $result = $this->node()->process($this->params(
      ['broken', 'conversation'],
      [
        'broken' => '[{"role":"user","content":"never decoded"}]',
        'conversation' => [$this->msg('user', 'said')],
      ],
    ));

    $this->assertSame(['user:said'], $this->sequence($result));
    // The native node's entire output surface: messages, nothing else. A graph
    // must not wire count/sources/skipped ports — they belonged to the fork.
    $this->assertSame(['messages'], array_keys($result));
  }

  /**
   * Junk items inside an otherwise good list are dropped item by item.
   *
   * Note the difference from the fork: an empty array survives here (it is
   * array-shaped; whether it is a MESSAGE is normalize's question), while a
   * string or number is dropped. Roleless rows are then dropped downstream by
   * conversation_normalize.
   */
  public function testNonArrayItemsAreDroppedOneByOne(): void {
    $result = $this->node()->process($this->params(
      ['conversation'],
      ['conversation' => [$this->msg('user', 'one'), 'junk', 42, $this->msg('tool', 'two')]],
    ));

    $this->assertSame(['user:one', 'tool:two'], $this->sequence($result));
  }

  /**
   * Two ports of the same name would make the order ambiguous — refused.
   *
   * The node's contract is the port list, so a duplicate is a contract
   * failure, not a cosmetic one (DynamicPortTrait enforces it).
   */
  public function testDuplicatePortNamesAreRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->node()->process($this->params(
      ['conversation', 'conversation'],
      ['conversation' => [$this->msg('user', 'said')]],
    ));
  }

}
