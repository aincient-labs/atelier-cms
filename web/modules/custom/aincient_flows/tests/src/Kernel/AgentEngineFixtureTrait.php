<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\flowdrop_session\DTO\TurnOptions;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\flowdrop_workflow\WorkflowDefinitionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports the shipped agent engine and builds a studio-shaped parent around it.
 *
 * For kernel tests extending WorkflowExecutionTestBase (node()/edge()/
 * createWorkflow()), with the reasoner swapped for {@see ScriptedReasoner}.
 */
trait AgentEngineFixtureTrait {

  /**
   * Parses one shipped config file from config/sync.
   *
   * @return array<string, mixed>
   *   The parsed config, without uuid and _core.
   */
  private function shipped(string $name): array {
    $file = dirname(__DIR__, 7) . '/config/sync/' . $name . '.yml';
    self::assertFileExists($file);
    $config = Yaml::parseFile($file);
    self::assertIsArray($config);
    unset($config['uuid'], $config['_core'], $config['dependencies']);
    return $config;
  }

  /**
   * Imports a shipped node type, replacing any default-installed copy.
   */
  private function importNodeType(string $id): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('flowdrop_node_type');
    $storage->load($id)?->delete();
    $storage->create($this->shipped('flowdrop_node_type.flowdrop_node_type.' . $id))->save();
  }

  /**
   * Imports the shipped engine, its node types, and the parent's node types.
   */
  private function importEngine(): void {
    $engine = Yaml::parseFile(dirname(__DIR__, 7) . '/config/sync/flowdrop_workflow.flowdrop_workflow.aincient_agent_engine.yml');
    foreach ($engine['dependencies']['config'] as $dependency) {
      $this->importNodeType(substr($dependency, strlen('flowdrop_node_type.flowdrop_node_type.')));
    }
    foreach (['chat_input', 'chat_output', 'text_input', 'aincient_flows_aincient_capability_studio_tour'] as $id) {
      $this->importNodeType($id);
    }
    $config = $this->shipped('flowdrop_workflow.flowdrop_workflow.aincient_agent_engine');
    $this->container->get('entity_type.manager')->getStorage('flowdrop_workflow')->create($config)->save();
    // The node type that places it — after the workflow, whose derivative
    // plugin it names.
    $this->container->get('flowdrop.node_processor_plugin_manager')->clearCachedDefinitions();
    $this->importNodeType('aincient_agent_engine');
  }

  /**
   * A studio-shaped parent: chat in, prompt + tool into the engine, reply out.
   *
   * @param array<string, mixed> $engineConfig
   *   The engine placement's config (e.g. approval_tools, operation_type).
   * @param string|null $context
   *   A context value fed on the engine's context edge, or NULL for unwired.
   */
  private function parent(string $id, array $engineConfig = [], ?string $context = NULL): WorkflowDefinitionInterface {
    // The model-facing tool name derives from the node label, as in a studio.
    $tour = $this->node('tour', 'aincient_flows_aincient_capability_studio_tour', ['nodeType' => 'tool'], ['name' => 'Capability: Studio tour']);
    $tour['data']['label'] = 'Capability: Studio tour';
    $nodes = [
      $this->node('chat_input.1', 'chat_input', [], ['name' => 'Chat Input']),
      $this->node('prompt', 'text_input', ['defaultValue' => 'You run tours. <b>Keep</b> markup & "quotes".'], ['name' => 'Text Input']),
      $tour,
      $this->node('engine', 'aincient_agent_engine', $engineConfig, ['name' => 'Agent engine']),
      $this->node('chat_output.1', 'chat_output', ['format' => 'text'], ['name' => 'Chat Output']),
    ];
    $edges = [
      $this->edge('chat_input.1', 'message', 'engine', 'message'),
      $this->edge('prompt', 'text', 'engine', 'system_prompt'),
      $this->edge('tour', 'tool', 'engine', 'tool'),
      $this->edge('engine', 'message', 'chat_output.1', 'message'),
    ];
    if ($context !== NULL) {
      $nodes[] = $this->node('ctx', 'text_input', ['defaultValue' => $context], ['name' => 'Text Input']);
      $edges[] = $this->edge('ctx', 'text', 'engine', 'context');
    }
    $workflow = $this->createWorkflow($id, $nodes, $edges);
    // The console pins the stategraph orchestrator per session; a bare
    // session here carries the same settings on the workflow metadata.
    $workflow->setMetadata(['orchestrator_settings' => [
      'type' => 'flowdrop_stategraph:stategraph',
      'max_iterations' => 60,
      'checkpointer_type' => 'memory',
    ]]);
    $workflow->save();
    return $workflow;
  }

  /**
   * Runs one console turn against a parent workflow.
   */
  private function turn(WorkflowDefinitionInterface $workflow, string $message): TurnResult {
    $session = $this->container->get('flowdrop_session.service')->createSession($workflow);
    return $this->container->get('flowdrop_session.turn_service')->executeTurn(
      (string) $session->id(),
      $message,
      new TurnOptions(wait: TRUE),
    );
  }

}
