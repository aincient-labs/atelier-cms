<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\flowdrop_pipeline\Constants\PipelineStatus;
use Drupal\flowdrop_workflow\WorkflowDefinitionInterface;
use Drupal\Tests\flowdrop\Kernel\WorkflowExecutionTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A failure inside a sub-workflow reaches the parent's reserved error port.
 *
 * The agent engine (DECISIONS 0463) runs as its own pipeline under a Run
 * Workflow node. Operator's "something went wrong" reply hangs off the reason
 * node's reserved `error` port, which fires only when the node FAILS; once the
 * reason node lives inside the engine, that reply can only survive if a child
 * failure fails the parent's Run Workflow node, so its own `error` edge routes
 * it. This pins that it does, under the stategraph orchestrator the console
 * uses, with the error port revealed on the engine's node type.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class EngineErrorBubblesTest extends WorkflowExecutionTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['flowdrop_runtime_test'];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $nodeTypes = $this->container->get('entity_type.manager')->getStorage('flowdrop_node_type');
    $nodeTypes->create([
      'id' => 'failing_node',
      'label' => 'Failing node',
      'category' => 'processing',
      'enabled' => TRUE,
      'parameters' => ['message' => ['configurable' => TRUE, 'connectable' => FALSE, 'required' => FALSE]],
      'outputs' => ['ok' => ['exposed' => TRUE]],
      'executor_plugin' => 'flowdrop_runtime_test:failing_node',
    ])->save();
  }

  /**
   * A child whose only node fails, and the node type that places it.
   */
  private function failingChild(): WorkflowDefinitionInterface {
    $child = $this->createStateGraphWorkflow('err_child', [
      $this->node('boom', 'failing_node', ['message' => 'provider misconfigured'], ['name' => 'Boom']),
    ], []);
    $child->setOutputPorts([['name' => 'message', 'node_id' => 'boom', 'port' => 'ok']]);
    $child->save();

    $this->container->get('entity_type.manager')->getStorage('flowdrop_node_type')->create([
      'id' => 'workflow_err_child',
      'label' => 'Run err_child',
      'category' => 'agents',
      'enabled' => TRUE,
      'parameters' => [],
      'outputs' => ['message' => ['exposed' => TRUE]],
      'executor_plugin' => 'flowdrop_workflow_executor:flowdrop_workflow:err_child',
      'reserved_port_exposure' => ['error' => TRUE],
    ])->save();
    return $child;
  }

  /**
   * Child fails → parent's Run Workflow node routes down its error edge.
   */
  public function testChildFailureRoutesDownTheParentErrorEdge(): void {
    $this->failingChild();
    $parent = $this->createStateGraphWorkflow('err_parent', [
      $this->node('engine', 'workflow_err_child', [], ['name' => 'Engine']),
      $this->loggerNode('handler', 'something went wrong!'),
    ], [
      $this->edge('engine', 'error', 'handler', 'trigger'),
    ]);

    $result = $this->launchAndWait($parent);
    $pipeline = $this->loadPipeline($result->pipelineId);
    $trail = $this->jobTrail($pipeline);

    $this->assertSame(PipelineStatus::Completed->value, $result->status, $trail);
    $this->assertSame('completed', $this->jobStatusForNode($pipeline, 'handler'), $trail);
    $error = $this->jobOutputForNode($pipeline, 'engine')['error'] ?? NULL;
    $this->assertIsArray($error, $trail);
    $this->assertStringContainsString('provider misconfigured', (string) json_encode($error), $trail);
  }

  /**
   * Control: with no error edge the child failure fails the parent run.
   */
  public function testWithoutAnErrorEdgeTheParentFails(): void {
    $this->failingChild();
    $parent = $this->createStateGraphWorkflow('err_parent_bare', [
      $this->node('engine', 'workflow_err_child', [], ['name' => 'Engine']),
    ], []);

    $result = $this->launchAndWait($parent);
    $this->assertSame(PipelineStatus::Failed->value, $result->status, $this->jobTrail($this->loadPipeline($result->pipelineId)));
  }

}
