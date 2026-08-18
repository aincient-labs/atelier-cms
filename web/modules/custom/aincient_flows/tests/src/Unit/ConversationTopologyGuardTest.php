<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the native conversation topology of the shipped agent loops.
 *
 * Since the Phase 6 migration every agent workflow feeds its reason node
 * through the same enforced tail: two `conversation_buffer` stores — the
 * session-scoped transcript and the pipeline-scoped scratchpad — meet in a
 * `message_assemble` node (transcript first), whose output passes through
 * `conversation_normalize` into the reason node's `messages` input.
 *
 * The shape is load-bearing, not decorative:
 * - The scratchpad reader must sit BELOW the loopback with ONLY loop_back
 *   inbound edges, so it re-reads the store on every iteration AND is ready
 *   to fire at iteration 1 (a data/trigger inbound would either freeze the
 *   first read or starve the scheduler).
 * - The assemble node must declare `conversation` before `scratchpad`, so
 *   the model always sees the transcript ahead of the working notes.
 * - Every buffer must persist via the entity backend; a static backend
 *   silently loses the store across requests.
 *
 * These workflows are the reference anyone reads to learn how to build an
 * agent loop on FlowDrop, so this test pins the exact wiring.
 *
 * @group aincient_flows
 */
final class ConversationTopologyGuardTest extends TestCase {

  /**
   * The shipped workflow configs, keyed by workflow id.
   *
   * @return array<string, array<string, mixed>>
   *   Parsed config, keyed by workflow id.
   */
  private function workflows(): array {
    $dir = dirname(__DIR__, 7) . '/config/sync';
    $out = [];
    foreach (glob($dir . '/flowdrop_workflow.flowdrop_workflow.*.yml') ?: [] as $file) {
      $config = Yaml::parseFile($file);
      $out[$config['id']] = $config;
    }
    self::assertNotEmpty($out, "No shipped workflows found in $dir.");
    return $out;
  }

  /**
   * The agent workflows: any whose reason node has tool edges.
   *
   * Discovered from the configs, never hardcoded — a new agent joins the
   * guard the moment its workflow ships.
   *
   * @return array<string, array{config: array<string, mixed>, reason: string}>
   *   Workflow config plus the tool-wielding reason node id, keyed by
   *   workflow id.
   */
  private function agentWorkflows(): array {
    $agents = [];
    foreach ($this->workflows() as $id => $config) {
      $types = $this->nodeTypes($config);
      foreach ($config['edges'] ?? [] as $edge) {
        $target = $edge['target'] ?? '';
        if (($types[$target] ?? '') !== 'aincient_reason') {
          continue;
        }
        if (str_ends_with($edge['targetHandle'] ?? '', '-input-tool')) {
          $agents[$id] = ['config' => $config, 'reason' => $target];
          break;
        }
      }
    }
    self::assertNotEmpty($agents, 'No agent workflows (reason node with tool edges) found.');
    return $agents;
  }

  /**
   * Node type ids, keyed by node id.
   *
   * @return array<string, string>
   *   node_type_id keyed by node id.
   */
  private function nodeTypes(array $config): array {
    $types = [];
    foreach ($config['nodes'] ?? [] as $node) {
      $types[$node['id']] = $node['data']['metadata']['node_type_id'] ?? '';
    }
    return $types;
  }

  /**
   * A node's config block.
   *
   * @return array<string, mixed>
   *   The node's data.config, empty if absent.
   */
  private function nodeConfig(array $config, string $nodeId): array {
    foreach ($config['nodes'] ?? [] as $node) {
      if ($node['id'] === $nodeId) {
        return $node['data']['config'] ?? [];
      }
    }
    return [];
  }

  /**
   * All edges landing on a specific input port of a node.
   *
   * @return list<array<string, mixed>>
   *   The matching edges.
   */
  private function edgesInto(array $config, string $nodeId, string $port): array {
    $edges = [];
    foreach ($config['edges'] ?? [] as $edge) {
      if (($edge['target'] ?? '') === $nodeId && ($edge['targetHandle'] ?? '') === "$nodeId-input-$port") {
        $edges[] = $edge;
      }
    }
    return $edges;
  }

  /**
   * The single node feeding a port, asserted to be of the expected type.
   */
  private function soleFeeder(string $id, array $config, string $nodeId, string $port, string $expectedType): string {
    $edges = $this->edgesInto($config, $nodeId, $port);
    $this->assertCount(
      1,
      $edges,
      "$id: expected exactly one edge into $nodeId port '$port', found " . count($edges) . '.',
    );
    $source = $edges[0]['source'];
    $types = $this->nodeTypes($config);
    $this->assertSame(
      $expectedType,
      $types[$source] ?? '(unknown node)',
      "$id: $nodeId port '$port' is fed by $source, which is not a $expectedType node.",
    );
    return $source;
  }

  /**
   * Resolves the enforced tail: reason ← normalize ← assemble.
   *
   * @return array{assemble: string, normalize: string}
   *   The node ids of the tail.
   */
  private function enforcedTail(string $id, array $config, string $reason): array {
    $normalize = $this->soleFeeder($id, $config, $reason, 'messages', 'conversation_normalize');
    $assemble = $this->soleFeeder($id, $config, $normalize, 'messages', 'message_assemble');
    return ['assemble' => $assemble, 'normalize' => $normalize];
  }

  /**
   * The reason node's messages arrive through normalize ← assemble.
   */
  public function testReasonMessagesArriveThroughTheEnforcedTail(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      // enforcedTail() carries the assertions: the messages input is fed by
      // exactly one conversation_normalize node, itself fed by exactly one
      // message_assemble node.
      $this->enforcedTail($id, $agent['config'], $agent['reason']);
    }
  }

  /**
   * The assemble node declares `conversation` before `scratchpad`.
   *
   * Pinned names, pinned order: the transcript must precede the working
   * notes in the assembled prompt.
   */
  public function testAssembleDeclaresConversationBeforeScratchpad(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $config = $agent['config'];
      $tail = $this->enforcedTail($id, $config, $agent['reason']);
      $inputs = $this->nodeConfig($config, $tail['assemble'])['dynamicInputs'] ?? [];
      $names = array_map(
        static fn(array $input): string => (string) ($input['name'] ?? ''),
        is_array($inputs) ? $inputs : [],
      );
      $conversation = array_search('conversation', $names, TRUE);
      $scratchpad = array_search('scratchpad', $names, TRUE);
      $this->assertNotFalse(
        $conversation,
        "$id: {$tail['assemble']} declares no 'conversation' dynamic input (got: " . implode(', ', $names) . ').',
      );
      $this->assertNotFalse(
        $scratchpad,
        "$id: {$tail['assemble']} declares no 'scratchpad' dynamic input (got: " . implode(', ', $names) . ').',
      );
      $this->assertLessThan(
        $scratchpad,
        $conversation,
        "$id: {$tail['assemble']} must declare 'conversation' before 'scratchpad' — the transcript comes first.",
      );
    }
  }

  /**
   * The `conversation` port reads the session transcript store.
   */
  public function testConversationPortIsFedByTheSessionTranscriptBuffer(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $config = $agent['config'];
      $tail = $this->enforcedTail($id, $config, $agent['reason']);
      $source = $this->soleFeeder($id, $config, $tail['assemble'], 'conversation', 'conversation_buffer');
      $buffer = $this->nodeConfig($config, $source);
      $this->assertSame(
        'session',
        $buffer['scope'] ?? NULL,
        "$id: transcript buffer $source must use scope 'session'.",
      );
      $this->assertSame(
        'conversation',
        $buffer['key'] ?? NULL,
        "$id: transcript buffer $source must use key 'conversation'.",
      );
    }
  }

  /**
   * The `scratchpad` port reads the pipeline scratchpad store.
   */
  public function testScratchpadPortIsFedByThePipelineScratchpadBuffer(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $config = $agent['config'];
      $tail = $this->enforcedTail($id, $config, $agent['reason']);
      $source = $this->soleFeeder($id, $config, $tail['assemble'], 'scratchpad', 'conversation_buffer');
      $buffer = $this->nodeConfig($config, $source);
      $this->assertSame(
        'pipeline',
        $buffer['scope'] ?? NULL,
        "$id: scratchpad buffer $source must use scope 'pipeline'.",
      );
      $this->assertSame(
        'scratchpad',
        $buffer['key'] ?? NULL,
        "$id: scratchpad buffer $source must use key 'scratchpad'.",
      );
    }
  }

  /**
   * The scratchpad reader sits below the loopback, and nowhere else.
   *
   * At least one inbound loop_back edge, and EVERY inbound edge is a
   * loop_back leaving a declared gateway branch. No data or trigger inbound:
   * that is what makes the reader re-read the store each iteration and be
   * ready to fire at iteration 1.
   */
  public function testScratchpadReaderSitsBelowTheLoopback(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $config = $agent['config'];
      $tail = $this->enforcedTail($id, $config, $agent['reason']);
      $reader = $this->soleFeeder($id, $config, $tail['assemble'], 'scratchpad', 'conversation_buffer');

      $declared = [];
      foreach ($config['nodes'] ?? [] as $node) {
        $branches = $node['data']['config']['branches'] ?? NULL;
        if (is_array($branches)) {
          $declared[$node['id']] = array_map(
            static fn(array $branch): string => strtolower((string) ($branch['name'] ?? '')),
            $branches,
          );
        }
      }

      $inbound = [];
      foreach ($config['edges'] ?? [] as $edge) {
        if (($edge['target'] ?? '') === $reader) {
          $inbound[] = $edge;
        }
      }
      $this->assertNotEmpty(
        $inbound,
        "$id: scratchpad reader $reader has no inbound edges — nothing re-fires it inside the loop.",
      );

      foreach ($inbound as $edge) {
        $handle = $edge['targetHandle'] ?? '';
        $this->assertSame(
          "$reader-input-loop_back",
          $handle,
          "$id: scratchpad reader $reader takes a non-loop_back inbound edge "
          . "($handle from {$edge['source']}). A data or trigger inbound "
          . 'either freezes the first read or stops it re-reading per iteration.',
        );
        $source = $edge['source'];
        $this->assertArrayHasKey(
          $source,
          $declared,
          "$id: loop_back into $reader leaves $source, which declares no gateway branches.",
        );
        $port = preg_replace('/^.*-output-/', '', (string) ($edge['sourceHandle'] ?? ''));
        $this->assertContains(
          strtolower($port),
          $declared[$source],
          "$id: loop_back into $reader leaves $source port '$port', which is "
          . 'not a declared gateway branch (' . implode(', ', $declared[$source]) . ').',
        );
      }
    }
  }

  /**
   * Every conversation buffer in an agent workflow persists via entity.
   *
   * A static-backend buffer silently loses the store across requests.
   */
  public function testEveryConversationBufferUsesTheEntityBackend(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $seen = 0;
      foreach ($agent['config']['nodes'] ?? [] as $node) {
        if (($node['data']['metadata']['node_type_id'] ?? '') !== 'conversation_buffer') {
          continue;
        }
        $seen++;
        $this->assertSame(
          'entity',
          $node['data']['config']['backend'] ?? NULL,
          "$id: conversation buffer {$node['id']} must set backend: entity.",
        );
      }
      $this->assertGreaterThan(0, $seen, "$id: no conversation_buffer nodes found to check.");
    }
  }

  /**
   * The transcript reader is its own node and receives the user turn.
   *
   * It must not be the scratchpad reader (one node cannot serve both
   * stores), and it must take the user's message into its content/message
   * input. It also must not sit below the loopback — the image agent
   * historically looped back into its transcript reader; after the
   * migration no agent may.
   */
  public function testTranscriptReaderIsDistinctAndReceivesTheUserTurn(): void {
    foreach ($this->agentWorkflows() as $id => $agent) {
      $config = $agent['config'];
      $tail = $this->enforcedTail($id, $config, $agent['reason']);
      $transcript = $this->soleFeeder($id, $config, $tail['assemble'], 'conversation', 'conversation_buffer');
      $scratchpad = $this->soleFeeder($id, $config, $tail['assemble'], 'scratchpad', 'conversation_buffer');

      $this->assertNotSame(
        $scratchpad,
        $transcript,
        "$id: the transcript and scratchpad ports read the same buffer node — the two stores must be separate.",
      );

      $userTurn = array_merge(
        $this->edgesInto($config, $transcript, 'content'),
        $this->edgesInto($config, $transcript, 'message'),
      );
      $this->assertNotEmpty(
        $userTurn,
        "$id: transcript reader $transcript receives no user turn — nothing "
        . "lands on its 'content' or 'message' input.",
      );

      foreach ($config['edges'] ?? [] as $edge) {
        if (($edge['target'] ?? '') === $transcript) {
          $this->assertStringEndsNotWith(
            '-input-loop_back',
            $edge['targetHandle'] ?? '',
            "$id: transcript reader $transcript takes a loop_back edge from "
            . "{$edge['source']} — the transcript store must not sit inside the loop.",
          );
        }
      }
    }
  }

}
