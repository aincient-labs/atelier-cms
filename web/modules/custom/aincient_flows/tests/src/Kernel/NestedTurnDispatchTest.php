<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_flows\Kernel;

use Drupal\aincient_chat\Chat\FlowDropDispatcher;
use Drupal\aincient_core\Inference\AincientReasonResult;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\flowdrop_session\DTO\TurnResult;
use Drupal\Tests\flowdrop\Kernel\WorkflowExecutionTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The console dispatcher reads a turn's whole pipeline tree, not just its root.
 *
 * Once a studio places the agent engine (DECISIONS 0463) the engine runs as a
 * child pipeline: its tool jobs, scratchpad and any interrupt live below the
 * studio's run. Against REAL nested pipelines (shipped engine, scripted
 * reasoner) this pins the four dispatcher rules:
 *
 * - the widget harvest walks descendants (a tool's card is not dropped);
 * - a nested resume streams the ROOT chat_output, not "The flow finished.";
 * - a nested resume persists that reply + its widgets (reload keeps them);
 * - the scratchpad sweep drops the engine's scratchpad too.
 *
 * @group aincient_flows
 */
#[RunTestsInSeparateProcesses]
final class NestedTurnDispatchTest extends WorkflowExecutionTestBase {

  use AgentEngineFixtureTrait;
  use UserCreationTrait;

  private const ANSWER = 'Here is the tour.';

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
    'aincient_chat',
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
    ScriptedReasoner::$script = [
      new AincientReasonResult('Let me look.', [
        ['name' => 'capability_studio_tour', 'args' => ['rooms' => ['content']], 'tool_call_id' => 'call_1'],
      ]),
      new AincientReasonResult(self::ANSWER),
    ];
    $this->importEngine();
  }

  /**
   * The dispatcher service.
   */
  private function dispatcher(): FlowDropDispatcher {
    return $this->container->get('aincient_chat.flow_dispatcher');
  }

  /**
   * Calls one of the dispatcher's private readers.
   */
  private function call(string $method, mixed ...$args): mixed {
    return (new \ReflectionMethod(FlowDropDispatcher::class, $method))->invoke($this->dispatcher(), ...$args);
  }

  /**
   * The pipelines a turn ran in, root first.
   *
   * @return list<string>
   *   Pipeline ids.
   */
  private function tree(string $rootId): array {
    return $this->container->get('aincient_chat.turn_pipelines')->withDescendants($rootId);
  }

  /**
   * Seeds a scratchpad record for a pipeline.
   */
  private function seedScratchpad(string $pipelineId): void {
    $this->container->get('flowdrop_memory.manager')
      ->set('pipeline', $pipelineId, 'scratchpad', [['role' => 'tool', 'content' => 'x']], 86400, 'entity');
  }

  /**
   * Whether a pipeline's scratchpad record exists.
   */
  private function hasScratchpad(string $pipelineId): bool {
    return $this->container->get('flowdrop_memory.manager')->has('pipeline', $pipelineId, 'scratchpad', 'entity');
  }

  /**
   * A completed turn: the engine's tool card is harvested from the child run.
   */
  public function testHarvestReadsTheEngineChildPipeline(): void {
    $result = $this->turn($this->parent('nested_harvest'), 'Tour please');
    $this->assertSame(TurnResult::STATUS_COMPLETED, $result->status, $this->jobTrail($this->loadPipeline((string) $result->pipelineId)));

    $tree = $this->tree((string) $result->pipelineId);
    $this->assertCount(2, $tree, 'The engine ran as one child pipeline.');

    $widgets = $this->call('harvestTurnWidgets', (string) $result->pipelineId);
    $this->assertSame(['studio_tour'], array_column($widgets, 'widget'),
      'The tool ran inside the engine; its widget must reach the console.');

    // The sweep drops the scratchpad of every pipeline in the turn.
    foreach ($tree as $id) {
      $this->seedScratchpad($id);
    }
    $this->container->get('aincient_chat.turn_scratchpad_sweeper')->dropIfTerminal('completed', (string) $result->pipelineId);
    foreach ($tree as $id) {
      $this->assertFalse($this->hasScratchpad($id), "scratchpad of pipeline $id survived the sweep");
    }
  }

  /**
   * Approve inside the engine: reply streams, persists, and keeps its widget.
   */
  public function testNestedResumeStreamsAndPersistsTheRootReply(): void {
    $parent = $this->parent('nested_resume', ['approval_tools' => 'capability_studio_tour']);
    $result = $this->turn($parent, 'Tour please');
    $rootId = (string) $result->pipelineId;
    $this->assertSame(TurnResult::STATUS_AWAITING_INPUT, $result->status, $this->jobTrail($this->loadPipeline($rootId)));

    $interrupts = $this->container->get('flowdrop_interrupt.manager');
    $pending = $interrupts->getPendingInterruptsForPipeline($this->tree($rootId)[1]);
    $this->assertNotEmpty($pending, 'The confirmation is raised in the engine pipeline.');
    $interrupt = reset($pending);
    $this->assertNotSame($rootId, (string) $interrupt->getPipelineId(), 'The interrupt belongs to the child run.');

    $sessionId = $this->container->get('aincient_chat.turn_pipelines')->sessionId($rootId);
    $this->assertNotNull($sessionId);

    $events = iterator_to_array($this->dispatcher()->resume($interrupt->uuid(), TRUE, 'thread-1'), FALSE);
    $payloads = array_map(static fn ($e) => ["type" => $e->type->value, "data" => $e->data], $events);

    // Live: the tool card, then the ROOT chat_output text.
    $toolCalls = array_values(array_filter($payloads, static fn (array $p) => ($p['type'] ?? '') === 'tool_call'));
    $this->assertCount(1, $toolCalls, json_encode($payloads) ?: '');
    $results = array_values(array_filter($payloads, static fn (array $p) => ($p['type'] ?? '') === 'result'));
    $this->assertNotEmpty($results, json_encode($payloads) ?: '');
    $this->assertStringContainsString(self::ANSWER, (string) json_encode(end($results)), 'Not the "The flow finished." fallback.');

    // Reload: the reply is a stored assistant message, and the card hangs on it.
    $messages = $this->container->get('flowdrop_session.service')->getMessages($sessionId, NULL, 100, NULL, TRUE);
    $assistant = array_values(array_filter($messages, static fn ($m) => (string) $m->getRole() === 'assistant' && trim((string) $m->getContent()) === self::ANSWER));
    $this->assertCount(1, $assistant, 'The resumed reply is persisted exactly once.');
    $session = $this->container->get('entity_type.manager')->getStorage('flowdrop_session')->load($sessionId);
    $widgets = $session->getMetadata()['ain_widgets'] ?? [];
    $this->assertSame(['studio_tour'], array_column($widgets[(string) $assistant[0]->getSequenceNumber()] ?? [], 'widget'));
  }


  /**
   * Decline inside the engine: the agent is told, answers, and the reply lands.
   */
  public function testNestedDeclineStillReplies(): void {
    $parent = $this->parent('nested_decline', ['approval_tools' => 'capability_studio_tour']);
    $result = $this->turn($parent, 'Tour please');
    $rootId = (string) $result->pipelineId;
    $this->assertSame(TurnResult::STATUS_AWAITING_INPUT, $result->status, $this->jobTrail($this->loadPipeline($rootId)));

    $pending = $this->container->get('flowdrop_interrupt.manager')->getPendingInterruptsForPipeline($this->tree($rootId)[1]);
    $interrupt = reset($pending);
    $events = iterator_to_array($this->dispatcher()->resume($interrupt->uuid(), FALSE, 'thread-2'), FALSE);
    $payloads = array_map(static fn ($e) => ["type" => $e->type->value, "data" => $e->data], $events);

    $tree = $this->tree($rootId);
    $trail = $this->jobTrail($this->loadPipeline($rootId)) . "\n--- engine ---\n" . $this->jobTrail($this->loadPipeline($tree[1]));
    $this->assertSame('completed', $this->loadPipeline($tree[1])->getStatus(), 'Engine run did not finish after a decline. ' . $trail);
    $this->assertSame('completed', $this->loadPipeline($rootId)->getStatus(), 'Studio run did not finish after a decline. ' . $trail);
    $results = array_values(array_filter($payloads, static fn (array $p) => $p['type'] === 'result'));
    $this->assertStringContainsString(self::ANSWER, (string) json_encode(end($results)), $trail);
    // The model was told the call was declined.
    $tool = array_values(array_filter(ScriptedReasoner::$requests[1]->getMessages(), static fn ($m) => $m->getRole() === 'tool'));
    $this->assertStringContainsString('declined', $tool[0]->getContent() ?? '');
  }

}
