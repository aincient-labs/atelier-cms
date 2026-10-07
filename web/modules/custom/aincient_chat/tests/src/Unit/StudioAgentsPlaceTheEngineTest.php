<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Every studio agent places the shared agent engine; nothing else owns a loop.
 *
 * DECISIONS 0463: the ReAct loop lives ONCE, in `aincient_agent_engine`, and
 * each studio agent places it. Copy drift is the failure this prevents — five
 * edge-identical copies of the loop is how a guardrail matching a tool none of
 * them had survived in all five. FlowDrop node instances do not follow their
 * type, so a re-forked loop is permanent drift. Pure YAML over the shipped
 * config: config/sync plus every custom/studio module's config/install.
 */
#[Group('aincient')]
final class StudioAgentsPlaceTheEngineTest extends UnitTestCase {

  private const ENGINE_NODE_TYPE = 'aincient_agent_engine';

  private const ENGINE_WORKFLOW = 'aincient_agent_engine';

  /**
   * The application root.
   */
  private function appRoot(): string {
    return dirname(__DIR__, 7);
  }

  /**
   * Files matching any of the patterns under the app root (no GLOB_BRACE: musl
   * lacks it).
   *
   * @param list<string> $patterns
   *   Patterns relative to the app root.
   *
   * @return list<string>
   *   Matching paths.
   */
  private function globs(array $patterns): array {
    $files = [];
    foreach ($patterns as $pattern) {
      $files = array_merge($files, glob($this->appRoot() . $pattern) ?: []);
    }
    return $files;
  }

  /**
   * Node type ids of a workflow's nodes.
   *
   * @param array<string, mixed> $workflow
   *   Parsed workflow config.
   *
   * @return list<string>
   *   One entry per node.
   */
  private function nodeTypes(array $workflow): array {
    $types = [];
    foreach ($workflow['nodes'] ?? [] as $node) {
      $types[] = (string) ($node['data']['metadata']['node_type_id'] ?? $node['type'] ?? '');
    }
    return $types;
  }

  /**
   * The studio agents: the console's studio map plus each studio manifest's own.
   *
   * A manifest lists its agent first in `flows:` (the rest are sub-flows:
   * specialists, policies).
   *
   * @return array<string, string>
   *   Workflow id => where it was declared.
   */
  private function studioAgents(): array {
    $agents = [];
    $settings = Yaml::parseFile($this->appRoot() . '/config/sync/aincient_chat.settings.yml');
    foreach ((array) ($settings['studios'] ?? []) as $key => $studio) {
      foreach ((array) ($studio['agents'] ?? []) as $id) {
        $agents[(string) $id] = "aincient_chat.settings:studios.$key";
      }
    }
    foreach ($this->globs(['/web/modules/custom/*/*.studios.yml', '/web/modules/studio/*/*.studios.yml']) as $manifest) {
      foreach ((array) Yaml::parseFile($manifest) as $key => $studio) {
        $flows = (array) ($studio['flows'] ?? []);
        if ($flows !== []) {
          $agents[(string) $flows[0]] ??= basename($manifest) . ":$key";
        }
      }
    }
    self::assertNotEmpty($agents, 'No studio agents found; the discovery is broken.');
    return $agents;
  }

  /**
   * Every studio agent places the engine exactly once and reasons nowhere else.
   */
  public function testEveryStudioAgentPlacesTheEngine(): void {
    foreach ($this->studioAgents() as $id => $from) {
      $file = $this->appRoot() . "/config/sync/flowdrop_workflow.flowdrop_workflow.$id.yml";
      $this->assertFileExists($file, "Studio agent $id (from $from) is not shipped in config/sync.");
      $types = $this->nodeTypes(Yaml::parseFile($file));
      $this->assertSame(1, count(array_keys($types, self::ENGINE_NODE_TYPE, TRUE)),
        "$id (from $from) must place the agent engine exactly once (0463).");
      $this->assertNotContains('aincient_reason', $types,
        "$id (from $from) holds a reason node of its own: the loop belongs in the engine, not a copy.");
    }
  }

  /**
   * Across every shipped workflow, only the engine holds a reason node.
   */
  public function testOnlyTheEngineReasons(): void {
    $files = $this->globs(array_merge(['/config/sync/flowdrop_workflow.flowdrop_workflow.*.yml'], ...array_map(
      static fn (string $tier): array => [
        "/web/modules/$tier/*/config/install/flowdrop_workflow.flowdrop_workflow.*.yml",
        "/web/modules/$tier/*/config/optional/flowdrop_workflow.flowdrop_workflow.*.yml",
      ],
      ['custom', 'studio'],
    )));
    $this->assertNotEmpty($files);
    $owners = [];
    foreach ($files as $file) {
      $workflow = Yaml::parseFile($file);
      if (!is_array($workflow)) {
        continue;
      }
      if (in_array('aincient_reason', $this->nodeTypes($workflow), TRUE)) {
        $owners[] = (string) ($workflow['id'] ?? basename($file));
      }
    }
    $this->assertSame([self::ENGINE_WORKFLOW], array_values(array_unique($owners)),
      'A workflow other than the agent engine places aincient_reason — a forked loop (0463). Place the engine instead.');
  }

}
