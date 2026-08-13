<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards that the context policy can actually reach every shipped agent.
 *
 * Per-turn client context is OFFERED, not demanded: the controller always sends
 * `variables` (the studio draft plus the context policy, DECISIONS 0379) and a
 * workflow opts in by declaring the port. The dispatcher drops an offer the
 * workflow has no port for, so a missing declaration no longer breaks the chat
 * — it degrades it, silently, to an agent reasoning WITHOUT the rule that its
 * earlier tool receipts are gone. That is the 0379 bug with no symptom.
 *
 * So the declaration is load-bearing, and it is config: no PHP change can break
 * it and no PHP test would catch it being undone on the canvas. Both halves are
 * asserted here — the workflow declares the port, AND the template it feeds
 * renders `{{ context_policy }}`. A port that reaches a template without the
 * placeholder passes the launcher and still loses the rule.
 *
 * Only the agents are covered. `aincient_onboarding` is deterministic (no model
 * reasons over a conversation), and a hand-authored workshop flow is a toy —
 * neither owes the policy, and the dispatcher's filter is what keeps them from
 * crashing.
 *
 * In the same spirit as SpecialistParsesInGraphTest and ShippedLoopGateTest.
 *
 * @group aincient
 */
#[Group('aincient')]
final class ContextPolicyReachesAgentsTest extends TestCase {

  /**
   * The agents that reason over a conversation, so all of them owe the policy.
   */
  private const AGENTS = [
    'brand_studio_rice',
    'aincient_pages_agent',
    'aincient_audit_agent',
    'aincient_chrome_agent',
    'aincient_image_agent',
    'aincient_operator_agent_loop',
  ];

  /**
   * One agent's shipped config.
   *
   * @return array<string, mixed>
   *   The parsed workflow config.
   */
  private function agent(string $id): array {
    // …/aincient_chat/tests/src/Unit → …/custom → …/modules → …/web → cms root.
    $file = dirname(__DIR__, 7)
      . '/config/sync/flowdrop_workflow.flowdrop_workflow.' . $id . '.yml';
    self::assertFileExists($file, "Shipped agent $id has no config.");
    return Yaml::parseFile($file);
  }

  /**
   * Every agent declares a `variables` input port, naming the node it feeds.
   *
   * Without this the launcher refuses the turn outright (an undeclared key is a
   * hard error) — which is how the General room died with "The flow hit an
   * unexpected error" the moment the policy became unconditional.
   *
   * @return array<string, string>
   *   Node id keyed by workflow id, for the template assertion below.
   */
  public function testEveryAgentDeclaresTheVariablesInput(): array {
    $targets = [];
    foreach (self::AGENTS as $id) {
      $ports = [];
      foreach ((array) ($this->agent($id)['input_ports'] ?? []) as $port) {
        if (is_array($port) && isset($port['name'])) {
          $ports[(string) $port['name']] = (string) ($port['node_id'] ?? '');
        }
      }
      self::assertArrayHasKey(
        'variables',
        $ports,
        "Agent $id declares no `variables` input, so the context policy is dropped before it runs.",
      );
      self::assertNotSame('', $ports['variables'], "Agent $id's `variables` port names no node.");
      $targets[$id] = $ports['variables'];
    }
    return $targets;
  }

  /**
   * The node that port feeds renders the policy into the system prompt.
   *
   * A declared port satisfies the launcher; only the placeholder puts the rule
   * in front of the model.
   *
   * @param array<string, string> $targets
   *   Node id keyed by workflow id.
   */
  #[\PHPUnit\Framework\Attributes\Depends('testEveryAgentDeclaresTheVariablesInput')]
  public function testDeclaredInputFeedsThePromptTemplate(array $targets): void {
    foreach ($targets as $id => $nodeId) {
      $config = $this->agent($id);
      $templates = [];
      foreach ($config['nodes'] ?? [] as $node) {
        if (($node['id'] ?? '') === $nodeId) {
          $templates[] = (string) ($node['data']['config']['template'] ?? '');
        }
      }
      self::assertCount(1, $templates, "Agent $id's `variables` port names a node that isn't in the graph.");
      // The brand studio routes variables through a state node rather than
      // straight into the template, so accept the placeholder anywhere in the
      // workflow's prompts — what matters is that some template renders it.
      $rendered = $templates[0] !== '' && str_contains($templates[0], '{{ context_policy }}');
      if (!$rendered) {
        foreach ($config['nodes'] ?? [] as $node) {
          if (str_contains((string) ($node['data']['config']['template'] ?? ''), '{{ context_policy }}')) {
            $rendered = TRUE;
            break;
          }
        }
      }
      self::assertTrue(
        $rendered,
        "Agent $id accepts `variables` but no template renders {{ context_policy }}, so the rule never reaches the model.",
      );
    }
  }

}
