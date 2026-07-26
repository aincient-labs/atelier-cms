<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ToolInvoke;
use Drupal\flowdrop\DTO\ExecutionContextDTO;
use Drupal\flowdrop\DTO\ParameterBag;
use Drupal\flowdrop\DTO\Tool\ToolBinding;
use Drupal\flowdrop\DTO\Tool\ToolCollection;
use Drupal\flowdrop\DTO\Tool\ToolResult;
use Drupal\flowdrop\DTO\Tool\ToolResultInterface;
use Drupal\flowdrop\Service\Tool\ToolInvokerInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the once-per-run guarantee of the Invoke node.
 *
 * The upstream contract (resolve by name, forward args, emit a tool-role
 * message paired by id, recover from an unwired name) is covered by
 * flowdrop_node_processor's own ToolInvokeTest; what matters here is the part
 * we added — a tool_call_id executes AT MOST ONCE per run, however many times
 * the stategraph schedules the node.
 *
 * A stub ToolInvokerInterface counts invocations; the ledger rides on the
 * `entity` memory backend, so the guard is proven across node INSTANCES (a
 * cloned job is a fresh processor instance, which is exactly the case that
 * double-built pages in the studio).
 *
 * @coversDefaultClass \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\ToolInvoke
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class ToolInvokeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flowdrop', 'flowdrop_memory'];

  /**
   * Invocations the stub tool has seen, by tool node id.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $invocations = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_memory_record');
  }

  /**
   * A node instance bound to one pipeline (the ledger's scope).
   */
  private function node(string $pipelineId = 'p1'): ToolInvoke {
    $node = ToolInvoke::create($this->container, [], 'aincient_flows:aincient_tool_invoke', []);
    $node->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: $pipelineId,
      executionId: 'e1',
      nodeId: 'invoke.2',
      metadata: [],
    ));
    $node->setTools($this->tools());
    return $node;
  }

  /**
   * One wired tool that records every invocation it receives.
   */
  private function tools(): ToolCollection {
    $invoker = new class($this->invocations) implements ToolInvokerInterface {

      /**
       * @param array<int, array<string, mixed>> $invocations
       *   The by-ref bucket recording each invocation.
       *
       * @phpstan-ignore-next-line property.onlyWritten (read by the test
       *   through the shared by-ref variable)
       */
      public function __construct(private array &$invocations) {}

      /**
       * {@inheritdoc}
       */
      public function invokeTool(string $nodeId, array $args): ToolResultInterface {
        $this->invocations[] = ['nodeId' => $nodeId, 'args' => $args];
        return ToolResult::success(['result' => 'Previewing 5 page edits.']);
      }

    };
    return new ToolCollection([
      new ToolBinding(
        'preview_page',
        'cap_preview_page',
        'Preview page',
        ['type' => 'object', 'properties' => []],
        'Preview page (cap_preview_page)',
        $invoker,
      ),
    ]);
  }

  /**
   * One call, one invocation, one paired message — the baseline.
   *
   * @covers ::process
   */
  public function testInvokesAndPairsResult(): void {
    $result = $this->node()->process(new ParameterBag([
      'tool_calls' => [
        ['name' => 'preview_page', 'args' => ['ops' => ['add_section']], 'tool_call_id' => 'call_a'],
      ],
    ]));

    $this->assertCount(1, $this->invocations);
    $this->assertSame(['ops' => ['add_section']], $this->invocations[0]['args']);
    $this->assertTrue($result['ok']);
    $this->assertSame([], $result['skipped']);
    $this->assertCount(1, $result['tool_messages']);
    $this->assertSame('call_a', $result['tool_messages'][0]['tool_call_id']);
    $this->assertSame('Previewing 5 page edits.', $result['tool_messages'][0]['content']);
  }

  /**
   * A re-scheduled node never re-runs a call it already ran.
   *
   * The stategraph clones a fan-in node once per predecessor that routes to it
   * (the Reason node feeds `tool_calls`, the confirmation router feeds
   * `trigger`), and the clone is a FRESH processor instance handed the SAME
   * batch. Without the ledger the tool ran twice — for preview_page that meant
   * a second hero/features/testimonials/CTA on every page built in chat.
   *
   * @covers ::process
   */
  public function testRepeatOfTheSameToolCallIdIsNotInvoked(): void {
    $batch = new ParameterBag([
      'tool_calls' => [
        ['name' => 'preview_page', 'args' => ['ops' => ['add_section']], 'tool_call_id' => 'call_a'],
      ],
    ]);

    $this->node()->process($batch);
    $second = $this->node()->process($batch);

    $this->assertCount(1, $this->invocations, 'The tool ran exactly once.');
    // And it emits NOTHING: the first execution's tool message is already
    // paired to the call in the buffer, so an empty batch here is what leaves
    // the conversation untouched — which is what stops the phantom wave from
    // advancing the agent loop (see ConversationAppend's stall flag).
    $this->assertSame([], $second['tool_messages']);
    $this->assertSame([], $second['tool_results']);
    $this->assertSame(['call_a'], $second['skipped']);
  }

  /**
   * A mixed batch runs only the calls that have not run yet.
   *
   * @covers ::process
   */
  public function testOnlyTheRepeatIsDroppedFromAMixedBatch(): void {
    $this->node()->process(new ParameterBag([
      'tool_calls' => [
        ['name' => 'preview_page', 'args' => [], 'tool_call_id' => 'call_a'],
      ],
    ]));

    $result = $this->node()->process(new ParameterBag([
      'tool_calls' => [
        ['name' => 'preview_page', 'args' => [], 'tool_call_id' => 'call_a'],
        ['name' => 'preview_page', 'args' => ['ops' => ['update_section']], 'tool_call_id' => 'call_b'],
      ],
    ]));

    $this->assertCount(2, $this->invocations);
    $this->assertSame(['ops' => ['update_section']], $this->invocations[1]['args']);
    $this->assertSame(['call_a'], $result['skipped']);
    $this->assertCount(1, $result['tool_messages']);
    $this->assertSame('call_b', $result['tool_messages'][0]['tool_call_id']);
  }

  /**
   * The ledger is per RUN: the next turn's pipeline starts clean.
   *
   * Ids are unique per call in practice, but the guard must not be able to
   * silence a later turn — the scope is the thing that keeps it a re-fire
   * guard rather than a global mute.
   *
   * @covers ::process
   */
  public function testLedgerIsScopedToTheRun(): void {
    $batch = new ParameterBag([
      'tool_calls' => [
        ['name' => 'preview_page', 'args' => [], 'tool_call_id' => 'call_a'],
      ],
    ]);

    $this->node('p1')->process($batch);
    $result = $this->node('p2')->process($batch);

    $this->assertCount(2, $this->invocations);
    $this->assertSame([], $result['skipped']);
  }

  /**
   * A tool that fails is still spent — a failure must not become replayable.
   *
   * @covers ::process
   */
  public function testAFailedCallIsNotRetriedByARescheduledNode(): void {
    $node = ToolInvoke::create($this->container, [], 'aincient_flows:aincient_tool_invoke', []);
    $node->setExecutionContext(new ExecutionContextDTO(
      initialData: [],
      workflowId: 'wf',
      pipelineId: 'p1',
      executionId: 'e1',
      nodeId: 'invoke.2',
      metadata: [],
    ));
    // No tools wired: every call is an unknown-tool error result.
    $first = $node->process(new ParameterBag([
      'tool_calls' => [['name' => 'preview_page', 'args' => [], 'tool_call_id' => 'call_a']],
    ]));
    $this->assertFalse($first['ok']);
    $this->assertStringContainsString('not an available tool', $first['tool_messages'][0]['content']);

    $second = $this->node()->process(new ParameterBag([
      'tool_calls' => [['name' => 'preview_page', 'args' => [], 'tool_call_id' => 'call_a']],
    ]));
    $this->assertSame([], $this->invocations, 'The spent id never reaches the tool.');
    $this->assertSame(['call_a'], $second['skipped']);
  }

  /**
   * An id-less call is not guardable, and still runs (unchanged behaviour).
   *
   * @covers ::process
   */
  public function testCallWithoutAnIdIsStillInvoked(): void {
    $batch = new ParameterBag([
      'tool_calls' => [['name' => 'preview_page', 'args' => []]],
    ]);

    $this->node()->process($batch);
    $this->node()->process($batch);

    $this->assertCount(2, $this->invocations);
  }

}
