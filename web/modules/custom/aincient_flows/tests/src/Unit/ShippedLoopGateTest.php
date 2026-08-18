<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards how the shipped agent loops express their termination rule.
 *
 * Every agent loop must stop when a conversation append writes nothing —
 * otherwise a re-fired append spawns an agent step that reads a half-written
 * buffer and redoes the work (the doubled-page-sections bug).
 *
 * The rule used to be `condition: state.data.conversation_stalled != true` on
 * the loop_back edge. That was correct and invisible: no editor UI, no canvas
 * affordance, and honoured by exactly ONE orchestrator — under the
 * asynchronous orchestrator the same graph would loop forever with no error.
 *
 * FlowDrop 2.x gates loop re-entry on `active_branches`, so the rule is now a
 * Boolean Gateway anyone can see and rewire. These workflows are the reference
 * anyone reads to learn how to build an agent loop on FlowDrop, so this test
 * pins the visible form: no hidden edge conditions, and every loop re-entry
 * arrives from a gateway branch.
 *
 * @group aincient_flows
 */
final class ShippedLoopGateTest extends TestCase {

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
   * A routing decision belongs on the canvas, not in an undeclared edge key.
   */
  public function testNoWorkflowHidesRoutingInAnEdgeCondition(): void {
    foreach ($this->workflows() as $id => $config) {
      foreach ($config['edges'] ?? [] as $edge) {
        $this->assertArrayNotHasKey(
          'condition',
          $edge['data'] ?? [],
          "$id: edge {$edge['id']} carries a hidden routing condition. Express "
          . 'the decision as a gateway node so it is visible in the editor.',
        );
      }
    }
  }

  /**
   * Loop re-entry is a branch decision, so it must leave a gateway.
   */
  public function testLoopReentryLeavesGatewayBranch(): void {
    $seen = 0;
    foreach ($this->workflows() as $id => $config) {
      $nodeTypes = [];
      foreach ($config['nodes'] ?? [] as $node) {
        $nodeTypes[$node['id']] = $node['data']['metadata']['node_type_id'] ?? '';
      }

      foreach ($config['edges'] ?? [] as $edge) {
        if (!str_ends_with($edge['targetHandle'] ?? '', '-input-loop_back')) {
          continue;
        }
        $seen++;
        $source = $edge['source'];
        $this->assertSame(
          'boolean_gateway',
          $nodeTypes[$source] ?? '(unknown node)',
          "$id: loop re-entry into {$edge['target']} leaves $source, which is "
          . 'not a gateway — nothing on the canvas says when the loop ends.',
        );
        $this->assertSame(
          "$source-output-True",
          $edge['sourceHandle'],
          "$id: loop re-entry from $source must leave the True branch.",
        );
      }
    }
    $this->assertGreaterThan(0, $seen, 'No loop_back edges found to check.');
  }

  /**
   * No edge may leave a gateway port its node does not declare.
   *
   * `Set Brand` and `Restore Brand` were removed from switch_gateway.1's
   * branch config when those capabilities were deleted, and four workflows
   * kept the edges — pointing at ports that no longer existed. FlowDrop gated
   * them off by accident (an unknown name was compared against the active set
   * and lost); since the branch-vs-value rule started asking the node's
   * DECLARED branches, a name in neither set reads as a value port and fires
   * unconditionally. That turned dead wiring into a live approval prompt on
   * every page edit, for an action the graph had already executed.
   */
  public function testNoEdgeLeavesAnUndeclaredGatewayBranch(): void {
    foreach ($this->workflows() as $id => $config) {
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

      foreach ($config['edges'] ?? [] as $edge) {
        $source = $edge['source'];
        if (!isset($declared[$source])) {
          continue;
        }
        $prefix = "$source-output-";
        $handle = $edge['sourceHandle'] ?? '';
        if (!str_starts_with($handle, $prefix)) {
          continue;
        }
        $port = substr($handle, strlen($prefix));
        if ($port === 'trigger') {
          continue;
        }
        $this->assertContains(
          strtolower($port),
          $declared[$source],
          "$id: edge leaves $handle, but $source declares no such branch ("
          . implode(', ', $declared[$source]) . '). A dangling branch edge '
          . 'fires unconditionally.',
        );
      }
    }
  }

  /**
   * The gate reads "did this step actually produce anything", never weaker.
   *
   * On the fork nodes the signal was the append's `appended_any`. The native
   * `conversation_buffer` publishes no such port, so since the Phase 6
   * migration the same signal comes from the step that PRODUCED the messages:
   * the invoke node's `tool_messages` (BooleanGateway's plain (bool) cast —
   * a non-empty list is progress) on the tool-results loop, and the decline
   * synthesizer's `synthesized_any` on the guardrail's decline loop. Anything
   * else — a raw trigger, has_tool_calls, a constant — would re-enter the
   * loop without evidence of progress and re-create the doubled-page bug.
   */
  public function testLoopGatewaysAreFedByAProgressSignal(): void {
    // Gateways type-check `value` as a strict boolean at parameter
    // resolution, before BooleanGateway's own cast — so the progress signal
    // must be a boolean output, never a messages array.
    $progressPorts = ['produced_any', 'synthesized_any'];
    foreach ($this->workflows() as $id => $config) {
      $gateways = [];
      foreach ($config['edges'] ?? [] as $edge) {
        if (str_ends_with($edge['targetHandle'] ?? '', '-input-loop_back')) {
          $gateways[$edge['source']] = TRUE;
        }
      }
      if (!$gateways) {
        continue;
      }

      $fed = [];
      foreach ($config['edges'] ?? [] as $edge) {
        if (isset($gateways[$edge['target']]) && ($edge['targetHandle'] ?? '') === $edge['target'] . '-input-value') {
          $fed[$edge['target']] = $edge['sourceHandle'];
        }
      }

      foreach (array_keys($gateways) as $gateway) {
        $this->assertArrayHasKey(
          $gateway,
          $fed,
          "$id: loop gateway $gateway has nothing wired into its value input.",
        );
        $port = preg_replace('/^.*-output-/', '', (string) $fed[$gateway]);
        $this->assertContains(
          $port,
          $progressPorts,
          "$id: loop gateway $gateway must be driven by a progress signal ("
          . implode(' or ', $progressPorts) . "); got {$fed[$gateway]}.",
        );
      }
    }
  }

}
