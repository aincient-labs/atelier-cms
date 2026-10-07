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
 * Executes the SHIPPED Operator studio end to end with a scripted reasoner.
 *
 * No provider key, no network: `aincient_core.inference.reasoner` is replaced
 * by {@see ScriptedReasoner}. The Operator places the shared agent engine
 * (DECISIONS 0463), so the loop (reason -> gateway -> invoke -> append ->
 * loop_back) runs in the engine's child pipeline: turn 1 asks for the
 * side-effect-free studio tour, turn 2 answers in prose, and the reply leaves
 * through the Operator's own chat_output. A reason FAILURE inside the engine
 * still reaches the Operator's crash reply through the engine's error port.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class OperatorLoopExecutionTest extends WorkflowExecutionTestBase {

  use AgentEngineFixtureTrait;
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
   * Imports the shipped workflow and every node type it depends on.
   */
  private function importShippedLoop(): \Drupal\flowdrop_workflow\WorkflowDefinitionInterface {
    $manager = $this->container->get('entity_type.manager');
    $workflow = $this->shipped('flowdrop_workflow.flowdrop_workflow.' . self::WORKFLOW);

    $raw = Yaml::parseFile(dirname(__DIR__, 7) . '/config/sync/flowdrop_workflow.flowdrop_workflow.' . self::WORKFLOW . '.yml');
    foreach ($raw['dependencies']['config'] as $dependency) {
      $id = substr($dependency, strlen('flowdrop_node_type.flowdrop_node_type.'));
      if (str_starts_with($dependency, 'flowdrop_node_type.flowdrop_node_type.') && $id !== 'aincient_agent_engine') {
        $this->importNodeType($id);
      }
    }
    // Last: the engine workflow, then the node type that places it.
    $this->importEngine();

    // The console pins the stategraph orchestrator per session
    // (FlowDropDispatcher::ORCHESTRATOR_SETTINGS); a bare launch has no
    // session, so the same settings ride on the workflow metadata here.
    $workflow['metadata']['orchestrator_settings'] = [
      'type' => 'flowdrop_stategraph:stategraph',
      'max_iterations' => 60,
      'checkpointer_type' => 'memory',
    ];
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

    // The loop ran in the engine's child pipeline: two reason passes, one tool
    // run. Counts COMPLETED jobs: the stategraph also schedules a clone of the
    // fan-in invoke node that is SKIPPED (never runs), which is not a run.
    $engine = $this->childPipeline($pipeline);
    $count = fn ($run, string $nodeId): int => count(array_filter(
      iterator_to_array($run->getJobs(), FALSE),
      static fn ($job) => $job->getNodeId() === $nodeId && $job->getStatus() === 'completed',
    ));
    $this->assertSame(2, $count($engine, 'aincient_reason.2'), $trail);
    $this->assertSame(1, $count($engine, 'aincient_flows_aincient_invoke.2'), $trail);
    $this->assertSame(1, $count($pipeline, 'chat_output.1'), $trail);
    $this->assertSame(0, $count($pipeline, 'prompt_template.2'), 'No crash reply on a good turn.');

    // Final answer reached the chat output.
    $this->assertSame(self::ANSWER, $this->jobOutputForNode($pipeline, 'chat_output.1')['message'] ?? NULL, $trail);
  }


  /**
   * A reason failure inside the engine still gets Operator's crash reply.
   *
   * The scripted reasoner throws (script exhausted) — not a provider failure,
   * which AincientReason would turn into a normal reply — so the reason node
   * FAILS, the engine run fails, and the engine node's reserved error port
   * routes it to prompt_template.2 → the same chat_output.1 the answer uses
   * (the two paths are mutually exclusive; same-port edges are OR'd).
   */
  public function testEngineFailureReachesTheCrashReply(): void {
    ScriptedReasoner::$requests = [];
    ScriptedReasoner::$script = [];

    $workflow = $this->importShippedLoop();
    $session = $this->container->get('flowdrop_session.service')->createSession($workflow);
    $result = $this->container->get('flowdrop_session.turn_service')->executeTurn(
      (string) $session->id(),
      'Hello?',
      new TurnOptions(wait: TRUE),
    );
    $pipeline = $this->loadPipeline((string) $result->pipelineId);
    $trail = $this->jobTrail($pipeline);

    $this->assertSame(TurnResult::STATUS_COMPLETED, $result->status, $trail);
    $this->assertSame('something went wrong!', $this->jobOutputForNode($pipeline, 'chat_output.1')['message'] ?? NULL, $trail);
  }

  /**
   * The one child pipeline a turn spawned (the engine's run).
   */
  private function childPipeline(object $parent): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('flowdrop_pipeline');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('parent_pipeline_id', (int) $parent->id())->execute();
    $this->assertCount(1, $ids, 'The engine ran as exactly one child pipeline.');
    return $this->loadPipeline((string) reset($ids));
  }

}
