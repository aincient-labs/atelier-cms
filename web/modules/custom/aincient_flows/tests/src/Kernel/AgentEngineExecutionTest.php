<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\Tests\flowdrop\Kernel\WorkflowExecutionTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs the SHIPPED agent engine under a parent, through a console-style turn.
 *
 * The engine (DECISIONS 0463) is one sub-workflow every studio places. Its
 * contract is the interface: `system_prompt`, `message`, `tool`,
 * `operation_type`, `approval_tools`, `context` in; `message`, `scratchpad` out.
 * This drives it the way a studio does — chat_input → engine → chat_output, a
 * parent-owned capability wired into `tool` — with a scripted reasoner, and
 * pins that every input reaches the step it is bound to.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class AgentEngineExecutionTest extends WorkflowExecutionTestBase {

  use AgentEngineFixtureTrait;
  use UserCreationTrait;

  private const ANSWER = 'The tour is ready.';

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
  protected $strictConfigSchema = FALSE;

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
    ScriptedReasoner::$requests = [];
    ScriptedReasoner::$script = [];
  }

  /**
   * The full contract on one turn: tool call, then answer, every input bound.
   */
  public function testEngineRunsAToolThenAnswersThroughTheParent(): void {
    ScriptedReasoner::$script = [
      new AincientReasonResult('Let me look.', [
        ['name' => 'capability_studio_tour', 'args' => ['rooms' => ['content']], 'tool_call_id' => 'call_1'],
      ]),
      new AincientReasonResult(self::ANSWER),
    ];
    $this->importEngine();
    $workflow = $this->parent('engine_parent', ['operation_type' => 'aincient_role:reasoning'], 'The owner likes teal.');

    $result = $this->turn($workflow, 'Give me a tour.');
    $pipeline = $this->loadPipeline((string) $result->pipelineId);
    $trail = $this->jobTrail($pipeline);

    $this->assertSame(TurnResult::STATUS_COMPLETED, $result->status, $trail);
    $this->assertCount(2, ScriptedReasoner::$requests, 'Reason ran twice. ' . $trail);
    $this->assertSame([], ScriptedReasoner::$script);

    $first = ScriptedReasoner::$requests[0];
    // system_prompt arrives verbatim (no HTML escaping), context framed after it.
    $this->assertSame(
      "You run tours. <b>Keep</b> markup & \"quotes\".\n\n<context>\nThe owner likes teal.\n</context>\n",
      $first->getSystemPrompt(),
    );
    // operation_type: the placement's value beats the engine's own default.
    $this->assertSame('aincient_role:reasoning', $first->getOperationType());
    // tool: the parent's capability is the model's toolbox.
    $names = array_map(static fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t->getName(), $first->getTools());
    $this->assertSame(['capability_studio_tour'], $names);
    // message: the user turn reached the conversation.
    $user = array_values(array_filter($first->getMessages(), static fn ($m) => $m->getRole() === 'user'));
    $this->assertSame('Give me a tour.', $user[0]->getContent() ?? NULL);

    // The tool ran from inside the engine and its result came back paired.
    $tool = array_values(array_filter(ScriptedReasoner::$requests[1]->getMessages(), static fn ($m) => $m->getRole() === 'tool'));
    $this->assertCount(1, $tool, $trail);
    $this->assertSame('call_1', $tool[0]->getToolCallId());
    $this->assertStringNotContainsString('Error:', $tool[0]->getContent());

    // message out → the parent's chat_output.
    $this->assertSame(self::ANSWER, $this->jobOutputForNode($pipeline, 'chat_output.1')['message'] ?? NULL, $trail);
  }

  /**
   * Unwired context = the prompt byte-for-byte; unset role = the task default.
   */
  public function testDefaultsWhenOptionalInputsAreUnwired(): void {
    ScriptedReasoner::$script = [new AincientReasonResult(self::ANSWER)];
    $this->importEngine();

    $result = $this->turn($this->parent('engine_parent_defaults'), 'Hi');

    $this->assertSame(TurnResult::STATUS_COMPLETED, $result->status, $this->jobTrail($this->loadPipeline((string) $result->pipelineId)));
    $this->assertSame('You run tours. <b>Keep</b> markup & "quotes".', ScriptedReasoner::$requests[0]->getSystemPrompt());
    $this->assertSame('aincient_role:task', ScriptedReasoner::$requests[0]->getOperationType());
  }

  /**
   * A guarded tool pauses the turn instead of running; empty never asks.
   */
  public function testApprovalToolsPauseTheTurn(): void {
    ScriptedReasoner::$script = [
      new AincientReasonResult('', [
        ['name' => 'capability_studio_tour', 'args' => ['rooms' => ['content']], 'tool_call_id' => 'call_1'],
      ]),
    ];
    $this->importEngine();

    $result = $this->turn($this->parent('engine_parent_gated', ['approval_tools' => 'capability_studio_tour']), 'Tour please');
    $pipeline = $this->loadPipeline((string) $result->pipelineId);

    $this->assertSame(TurnResult::STATUS_AWAITING_INPUT, $result->status, $this->jobTrail($pipeline));
    $this->assertCount(1, ScriptedReasoner::$requests, 'Nothing reasoned past the gate.');
  }

}
