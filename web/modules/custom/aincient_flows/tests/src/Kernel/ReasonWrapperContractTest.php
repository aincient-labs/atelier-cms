<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\flowdrop\Constants\ReservedName;
use Drupal\flowdrop_node_type\Controller\Api\NodesController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the shipped `reason` wrapper's two load-bearing config choices.
 *
 * The wrapper is the one place an agent step is authored, so both of the
 * choices this test guards are choices about EVERY agent:
 *
 * 1. THE ERROR PORT IS EXPOSED. A provider that says "come back later" is not
 *    the same failure as a bug, and the recovery for it is semantic — authored
 *    per agent, visible in the editor. FlowDrop injects a reserved `error`
 *    output port on every executable node but ships it HIDDEN, so the seam
 *    exists and nobody can see it. `reserved_port_exposure['error'] = TRUE`
 *    reveals it. That flag is UI-facing, which is exactly why a YAML assertion
 *    would prove nothing: this test drives the real NodeMetadataResolver
 *    through NodesController and reads the built port list the canvas gets.
 *    (Mirrors upstream's ErrorPortInjectionTest.)
 *
 * 2. THE TIER IS ONE CONFIGURABLE PARAM, NOT THREE WORKFLOWS. `reason`, `task`
 *    and `fast` were byte-identical but for `operation_type`. Three copies of
 *    one graph is three places a recovery policy drifts — and FlowDrop node
 *    instances do not auto-update when their type changes, so drift there is
 *    permanent. `task` and `fast` are gone; the tier is a per-placement
 *    setting. For that to work the wrapper must declare `operation_type` as an
 *    input port mapped at the inner node, or WorkflowNode::process() drops it
 *    (it forwards only declared input ports) and every placement silently runs
 *    the wrapper's own default.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class ReasonWrapperContractTest extends KernelTestBase {

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
   * The node types whose error port must be revealed, and why each one is.
   *
   * The inner one so the retry path can be authored INSIDE the wrapper, once,
   * for every agent that places it; the wrapper's own so an agent can still
   * add a recovery step of its own around the whole step.
   */
  private const ERROR_PORT_NODE_TYPES = [
    'aincient_reason',
    'flowdrop_workflow_executor_flowdrop_workflow_reason',
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
   * Both reason node types reveal the reserved error port on the canvas.
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
   * The tier is a per-placement setting that actually reaches the inner node.
   *
   * Three things have to agree or the setting is decoration: the node type
   * marks `operation_type` configurable, the workflow declares it in its
   * parameter schema (so the config form has it), and the workflow maps it to
   * the inner node as an input port (so WorkflowNode::process() forwards it).
   */
  public function testTierIsOneConfigurableParamWiredToTheInnerNode(): void {
    $nodeType = $this->shipped(
      'flowdrop_node_type.flowdrop_node_type.flowdrop_workflow_executor_flowdrop_workflow_reason'
    );
    $param = $nodeType['parameters']['operation_type'] ?? NULL;

    $this->assertIsArray($param,
      'The wrapper node type must declare operation_type, or the tier cannot be set per placement.');
    $this->assertTrue($param['configurable'] ?? FALSE,
      'operation_type must be configurable: the tier is a setting, not a data edge.');
    $this->assertSame('aincient_role:task', $param['default'] ?? NULL,
      'The default tier is task — the middle tier, so an unconsidered placement is not the expensive one.');

    $workflow = $this->shipped('flowdrop_workflow.flowdrop_workflow.reason');

    $this->assertArrayHasKey(
      'operation_type',
      $workflow['parameter_schema']['properties'] ?? [],
      'The wrapper must declare operation_type in its parameter schema or it never reaches the config form.',
    );

    $mapped = array_values(array_filter(
      $workflow['input_ports'] ?? [],
      static fn (array $port): bool => ($port['name'] ?? '') === 'operation_type',
    ));
    $this->assertCount(1, $mapped,
      'operation_type must be a declared input port: WorkflowNode::process() forwards only '
      . 'declared input ports, so without this the placement setting is silently dropped and '
      . 'every agent runs the wrapper default.');
    $this->assertSame('operation_type', $mapped[0]['port'] ?? NULL,
      'The input port must target the inner node parameter of the same name.');
  }

  /**
   * There is exactly one agent-step wrapper, so there is one place to change.
   *
   * `task` and `fast` were this workflow with a different operation_type. They
   * are deleted; a reappearance means someone forked the graph again instead of
   * setting the param, and the recovery policy now has copies to drift.
   */
  public function testNoForkedPerTierWrappers(): void {
    foreach (['task', 'fast'] as $id) {
      $this->assertFileDoesNotExist(
        $this->configSyncDir() . '/flowdrop_workflow.flowdrop_workflow.' . $id . '.yml',
        "A per-tier fork of the reason wrapper is back as '$id'. The tier is the "
        . 'operation_type param on the one wrapper — fork the setting, not the graph.',
      );
    }
  }

}
