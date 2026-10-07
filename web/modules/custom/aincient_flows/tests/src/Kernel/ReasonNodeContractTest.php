<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\flowdrop\Constants\ReservedName;
use Drupal\flowdrop_node_type\Controller\Api\NodesController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the shipped reason node's load-bearing config choices.
 *
 * Every agent step is the `aincient_reason` node placed once, inside the shared
 * `aincient_agent_engine` sub-workflow (DECISIONS 0463), so the choices this
 * test guards are choices about EVERY agent. The STOP-AND-REPORT recovery lives
 * in the node's own PHP ({@see
 * \Drupal\aincient_flows\Plugin\FlowDropNodeProcessor\AincientReason}), which
 * catches a provider failure and ends the step on its `error_detail` output.
 *
 * 1. THE ERROR PORT IS EXPOSED. A provider that says "come back later" is not
 *    the same failure as a bug. The node handles the common case in PHP
 *    (stop-and-report), but the reserved `error` output port stays exposed so an
 *    agent can still author an OPTIONAL semantic recovery step of its own around
 *    it, visible in the editor. FlowDrop injects that port on every executable
 *    node but ships it HIDDEN, so the seam exists and nobody can see it.
 *    `reserved_port_exposure['error'] = TRUE` reveals it. That flag is UI-facing,
 *    which is exactly why a YAML assertion would prove nothing: this test drives
 *    the real NodeMetadataResolver through NodesController and reads the built
 *    port list the canvas gets. (Mirrors upstream's ErrorPortInjectionTest.)
 *
 * 2. THE TIER IS ONE PARAM, NOT A FORKED GRAPH. The model role is the node's
 *    `operation_type`, set per placement or bound by the engine's own
 *    `operation_type` input. The old `reason` wrapper workflow (and before it
 *    the per-tier `task` / `fast` forks) were second copies of the agent step;
 *    FlowDrop node instances do not auto-update when their type changes, so a
 *    copy is permanent drift. They are gone and must stay gone.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class ReasonNodeContractTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_node_processor',
  ];

  /**
   * The node types whose error port must be revealed.
   *
   * The node itself, so an agent can author an optional semantic recovery
   * around the step the node already stop-and-reports in PHP.
   */
  private const ERROR_PORT_NODE_TYPES = [
    'aincient_reason',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_node_category');
    $this->installEntitySchema('flowdrop_node_type');
  }

  /**
   * The directory holding the shipped config.
   */
  private function configSyncDir(): string {
    $dir = dirname(__DIR__, 7) . '/config/sync';
    self::assertDirectoryExists($dir);
    return $dir;
  }

  /**
   * Parses one shipped config file.
   *
   * @return array<string, mixed>
   *   The parsed config.
   */
  private function shipped(string $name): array {
    $file = $this->configSyncDir() . '/' . $name . '.yml';
    self::assertFileExists($file, "Shipped config $name is missing.");
    $config = Yaml::parseFile($file);
    self::assertIsArray($config);
    return $config;
  }

  /**
   * Creates a node type entity from its shipped config and returns its ports.
   *
   * The processor plugin a shipped node type names may not exist under this
   * test's module list (the workflow-executor derivative needs the workflow
   * entity). What matters here is the reserved-port decision, which
   * NodeMetadataResolver takes from the entity — so the executor plugin is
   * swapped for a real no-op processor and everything else is shipped as-is.
   *
   * @return array<int, array<string, mixed>>
   *   The built output ports.
   */
  private function builtOutputPortsFor(string $nodeTypeId): array {
    $config = $this->shipped('flowdrop_node_type.flowdrop_node_type.' . $nodeTypeId);
    unset($config['uuid'], $config['_core'], $config['dependencies']);
    $config['executor_plugin'] = 'flowdrop_node_processor:nop';

    $this->container->get('entity_type.manager')
      ->getStorage('flowdrop_node_type')
      ->create($config)
      ->save();

    $response = NodesController::create($this->container)->getNodeMetadata($nodeTypeId);
    self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $payload = json_decode((string) $response->getContent(), TRUE);

    return $payload['data']['metadata']['outputs'] ?? [];
  }

  /**
   * The reason node type reveals the reserved error port on the canvas.
   *
   * An exposed port omits `exposedByDefault` entirely — exposed is the lean
   * default in the payload, so the presence of the key IS the hidden state.
   */
  public function testReasonNodeTypesRevealTheErrorPort(): void {
    foreach (self::ERROR_PORT_NODE_TYPES as $nodeTypeId) {
      $ports = $this->builtOutputPortsFor($nodeTypeId);

      $errorPorts = array_values(array_filter(
        $ports,
        static fn (array $port): bool => ($port['id'] ?? '') === ReservedName::PORT_ERROR,
      ));

      $this->assertCount(1, $errorPorts,
        "$nodeTypeId: expected exactly one reserved error output port. Built ports: "
        . (string) json_encode($ports));
      $this->assertArrayNotHasKey('exposedByDefault', $errorPorts[0],
        "$nodeTypeId: the error port is still hidden, so no author can wire a "
        . 'recovery path to it. Set reserved_port_exposure.error = true.');
    }
  }

  /**
   * The reason node is OURS — our executor, and it emits the three extra ports.
   *
   * Owning the node (ADR 0365) is what lets the structured error contract and
   * the trust-the-wire codec ship with no upstream FlowDrop change: our
   * processor returns `raw_result`, `codec` and `error_detail`, none of which
   * the engine's four-field `ReasonResult` DTO could carry. Two ways this
   * silently regresses — the executor gets repointed back at the engine plugin,
   * or one of the three ports loses its `exposed: true` and stops rendering on
   * the canvas (so no author can wire it) — and this pins both from the shipped
   * config. (The processor actually EMITTING the three is pinned separately by
   * the unit AincientReasonTest; a canvas port needs both the config exposure
   * asserted here AND the processor's output schema, and the executor plugin
   * that carries that schema is not constructable under this test's minimal
   * module set — which is exactly why builtOutputPortsFor swaps in a nop.)
   */
  public function testOwnedReasonNodeExecutorAndExtraPorts(): void {
    $nodeType = $this->shipped('flowdrop_node_type.flowdrop_node_type.aincient_reason');
    $this->assertSame(
      'aincient_flows:aincient_reason',
      $nodeType['executor_plugin'] ?? NULL,
      'The reason node must run our own processor, not the engine plugin — the '
      . 'richer result contract lives there (ADR 0365).',
    );

    foreach (['raw_result', 'codec', 'error_detail'] as $extra) {
      $this->assertTrue(
        ($nodeType['outputs'][$extra]['exposed'] ?? FALSE) === TRUE,
        "aincient_reason must declare the '$extra' output exposed, or the codec / "
        . 'structured-error wiring has no canvas seam to read.',
      );
    }
  }

  /**
   * There is one agent step and no wrapper copy of it.
   *
   * `task` and `fast` were per-tier forks of the `reason` wrapper; the wrapper
   * itself was placed nowhere once every agent bound `aincient_reason` directly,
   * and the engine (0463) is now the one place the step lives. A reappearance
   * of any of them means someone forked the graph again instead of setting
   * `operation_type`, and the step has copies to drift.
   */
  public function testNoWrapperCopiesOfTheReasonStep(): void {
    foreach (['reason', 'task', 'fast'] as $id) {
      $this->assertFileDoesNotExist(
        $this->configSyncDir() . '/flowdrop_workflow.flowdrop_workflow.' . $id . '.yml',
        "A wrapper copy of the reason step is back as '$id'. The tier is the "
        . 'operation_type param on aincient_reason: set the param, do not fork the graph.',
      );
      $this->assertFileDoesNotExist(
        $this->configSyncDir() . '/flowdrop_node_type.flowdrop_node_type.flowdrop_workflow_executor_flowdrop_workflow_' . $id . '.yml',
        "The node type of a wrapper copy '$id' is back.",
      );
    }
  }

  /**
   * Every shipped Reason node instance names its operation_type explicitly.
   *
   * Owed since DECISIONS 0257: an unset operation_type silently falls back to a
   * default tier, so a placement that forgot it is a latent wrong-model bug no
   * other test sees. Pure YAML over every shipped workflow (config/sync plus
   * each custom/studio module's config/install and config/optional).
   */
  public function testEveryShippedReasonNodeNamesItsOperationType(): void {
    $appRoot = dirname(__DIR__, 7);
    $patterns = [
      $appRoot . '/config/sync',
      $appRoot . '/web/modules/custom/*/config/install',
      $appRoot . '/web/modules/custom/*/config/optional',
      $appRoot . '/web/modules/studio/*/config/install',
      $appRoot . '/web/modules/studio/*/config/optional',
    ];
    $files = [];
    foreach ($patterns as $dirPattern) {
      foreach (glob($dirPattern . '/flowdrop_workflow.flowdrop_workflow.*.yml') ?: [] as $file) {
        $files[] = $file;
      }
    }
    $this->assertNotEmpty($files, 'No shipped flowdrop_workflow config found; the glob is broken.');

    $reasonNodes = 0;
    $missing = [];
    foreach ($files as $file) {
      $workflow = Yaml::parseFile($file);
      if (!is_array($workflow)) {
        continue;
      }
      $label = str_replace($appRoot . '/', '', $file);
      foreach (($workflow['nodes'] ?? []) as $node) {
        if (!is_array($node)) {
          continue;
        }
        $type = $node['data']['metadata']['node_type_id'] ?? $node['type'] ?? NULL;
        if ($type !== 'aincient_reason' && $type !== 'reason') {
          continue;
        }
        $reasonNodes++;
        $op = $node['data']['config']['operation_type'] ?? NULL;
        if (!is_string($op) || trim($op) === '') {
          $missing[] = $label . ' (workflow ' . ($workflow['id'] ?? '?') . ') node ' . ($node['id'] ?? '?');
        }
      }
    }

    $this->assertGreaterThan(0, $reasonNodes, 'Found no Reason nodes in any shipped workflow; the scan is vacuous.');
    $this->assertSame([], $missing,
      'Shipped Reason node(s) without an explicit, non-empty operation_type (DECISIONS 0257): '
      . implode('; ', $missing));
  }

}
