<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\flowdrop_session\DTO\TurnOptions;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\Tests\flowdrop\Kernel\WorkflowExecutionTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Yaml\Yaml;

/**
 * Executes the SHIPPED Operator agent loop end to end with a scripted reasoner.
 *
 * No provider key, no network: `aincient_core.inference.reasoner` is replaced
 * by {@see ScriptedReasoner}. Turn 1 asks for the side-effect-free
 * `aincient_list_pages` tool, turn 2 answers in prose. Pins that the loop
 * (reason -> gateway -> invoke -> append -> loop_back) re-enters the reason
 * node exactly once and then terminates on the answer branch.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class OperatorLoopExecutionTest extends WorkflowExecutionTestBase {

  use UserCreationTrait;

  private const WORKFLOW = 'aincient_operator_agent_loop';

  private const ANSWER = 'You have no pages yet.';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'filter',
    'node',
    'key',
    'file',
    'workflows',
    'content_moderation',
    'language',
    'content_translation',
    'flowdrop_memory',
    'aincient_core',
    'aincient_pages',
    'aincient_onboarding',
    'aincient_flows',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->getDefinition('aincient_core.inference.reasoner')
      ->setClass(ScriptedReasoner::class)
      ->setArguments([]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_memory_record');
    $this->installConfig(['flowdrop_memory']);
    $this->setCurrentUser($this->createUser(['administer aincient pages']));
  }

  /**
   * Parses one shipped config file from config/sync.
   *
   * @return array<string, mixed>
   *   The parsed config.
   */
  private function shipped(string $name): array {
    $file = dirname(__DIR__, 7) . '/config/sync/' . $name . '.yml';
    self::assertFileExists($file);
    $config = Yaml::parseFile($file);
    self::assertIsArray($config);
    unset($config['uuid'], $config['_core']);
    return $config;
  }

  /**
   * Imports the shipped workflow and every node type it depends on.
   */
  private function importShippedLoop(): \Drupal\flowdrop_workflow\WorkflowDefinitionInterface {
    $manager = $this->container->get('entity_type.manager');
    $workflow = $this->shipped('flowdrop_workflow.flowdrop_workflow.' . self::WORKFLOW);

    $nodeTypes = $manager->getStorage('flowdrop_node_type');
    foreach ($workflow['dependencies']['config'] as $dependency) {
      if (!str_starts_with($dependency, 'flowdrop_node_type.flowdrop_node_type.')) {
        continue;
      }
      $config = $this->shipped($dependency);
      unset($config['dependencies']);
      $existing = $nodeTypes->load($config['id']);
      $existing?->delete();
      $nodeTypes->create($config)->save();
    }

    // The console pins the stategraph orchestrator per session
    // (FlowDropDispatcher::ORCHESTRATOR_SETTINGS); a bare launch has no
    // session, so the same settings ride on the workflow metadata here.
    $workflow['metadata']['orchestrator_settings'] = [
      'type' => 'flowdrop_stategraph:stategraph',
      'max_iterations' => 60,
      'checkpointer_type' => 'memory',
    ];
    unset($workflow['dependencies']);
    $entity = $manager->getStorage('flowdrop_workflow')->create($workflow);
    $entity->save();
    return $entity;
  }

  /**
   * Tool call, then answer: two reason passes, one tool run, answer text out.
   */
  public function testOperatorLoopRunsToolThenAnswers(): void {
    ScriptedReasoner::$requests = [];
    ScriptedReasoner::$script = [
      new AincientReasonResult('Let me look.', [
        ['name' => 'capability_studio_tour', 'args' => ['rooms' => ['content']], 'tool_call_id' => 'call_1'],
      ]),
      new AincientReasonResult(self::ANSWER),
    ];

    $workflow = $this->importShippedLoop();
    // Same path the console takes: a session bound to the workflow, one turn.
    $session = $this->container->get('flowdrop_session.service')->createSession($workflow);
    $result = $this->container->get('flowdrop_session.turn_service')->executeTurn(
      (string) $session->id(),
      'What pages do I have?',
      new TurnOptions(wait: TRUE),
    );
    $this->assertNotNull($result->pipelineId);
    $pipeline = $this->loadPipeline($result->pipelineId);
    $trail = $this->jobTrail($pipeline);

    $this->assertSame(TurnResult::STATUS_COMPLETED, $result->status, $trail);

    $this->assertCount(2, ScriptedReasoner::$requests, 'Reason node ran exactly twice. ' . $trail);
    $this->assertSame([], ScriptedReasoner::$script, 'Whole script consumed.');

    // The model was shown the wired tool on both turns.
    foreach (ScriptedReasoner::$requests as $request) {
      $names = array_map(static fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t->getName(), $request->getTools());
      $this->assertContains('capability_studio_tour', $names);
    }

    // The tool really ran with the model's args: turn 2 saw its tool-role
    // result, paired to the call id, carrying only the requested room.
    $toolMessages = array_values(array_filter(
      ScriptedReasoner::$requests[1]->getMessages(),
      static fn ($m) => $m->getRole() === 'tool',
    ));
    $this->assertCount(1, $toolMessages, $trail);
    $this->assertSame('call_1', $toolMessages[0]->getToolCallId());
    $this->assertStringNotContainsString('Error:', $toolMessages[0]->getContent());
    $this->assertStringContainsString('content', $toolMessages[0]->getContent());

    // Counts COMPLETED jobs: the stategraph also schedules a clone of the
    // fan-in invoke node that is SKIPPED (never runs), which is not a run.
    $count = fn (string $nodeId): int => count(array_filter(
      iterator_to_array($pipeline->getJobs(), FALSE),
      static fn ($job) => $job->getNodeId() === $nodeId && $job->getStatus() === 'completed',
    ));
    $this->assertSame(2, $count('aincient_reason.2'), $trail);
    $this->assertSame(1, $count('aincient_flows_aincient_invoke.2'), $trail);
    $this->assertSame(1, $count('chat_output.1'), $trail);

    // Final answer reached the chat output.
    $this->assertSame(self::ANSWER, $this->jobOutputForNode($pipeline, 'chat_output.1')['message'] ?? NULL, $trail);
  }

}
