<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Chat\TurnPipelines;
use Drupal\aincient_chat\Chat\TurnScratchpadSweeper;
use Psr\Log\LoggerInterface;

/**
 * A terminal turn drops its tier-B scratchpad; a continuing turn keeps it.
 *
 * Hygiene only (the next turn is a new pipeline, DECISIONS 0379/0382/0383),
 * but the paused case is a safety property: an interrupt resumes the SAME
 * pipeline, and dropping its scratchpad would make the agent repeat itself.
 *
 * @covers \Drupal\aincient_chat\Chat\TurnScratchpadSweeper
 * @group aincient_chat
 */
final class TurnScratchpadSweeperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_memory',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_memory_record');
    $this->installConfig(['flowdrop_memory']);
  }

  /**
   * A sweeper over this site's (pipeline-less) tree: each id is its own turn.
   *
   * Descendant sweeping runs against real nested pipelines in
   * {@see \Drupal\Tests\aincient_flows\Kernel\NestedTurnDispatchTest}.
   */
  private function sweeper(LoggerInterface $logger): TurnScratchpadSweeper {
    return new TurnScratchpadSweeper($logger, new TurnPipelines($this->container->get('entity_type.manager')));
  }

  /**
   * Seeds a scratchpad and (optionally) a transcript for a pipeline.
   */
  private function seed(string $pipelineId): void {
    $memory = $this->container->get('flowdrop_memory.manager');
    $memory->set('pipeline', $pipelineId, 'scratchpad', [['role' => 'tool', 'content' => 'x']], 86400, 'entity');
    $memory->set('pipeline', $pipelineId, 'other', 'keep', 86400, 'entity');
  }

  /**
   * Whether the pipeline's scratchpad record exists.
   */
  private function has(string $pipelineId, string $key = 'scratchpad'): bool {
    return $this->container->get('flowdrop_memory.manager')
      ->has('pipeline', $pipelineId, $key, 'entity');
  }

  /**
   * Terminal statuses drop; paused/awaiting/running keep; others untouched.
   */
  public function testTerminalDropsAndContinuingKeeps(): void {
    $sweeper = $this->sweeper($this->createMock(LoggerInterface::class));
    foreach (['p-done', 'p-failed', 'p-hitl', 'p-budget', 'p-other'] as $p) {
      $this->seed($p);
    }

    $sweeper->dropIfTerminal('completed', 'p-done');
    $sweeper->dropIfTerminal('failed', 'p-failed');
    $sweeper->dropIfTerminal('awaiting_input', 'p-hitl');
    $sweeper->dropIfTerminal('paused', 'p-budget');

    $this->assertFalse($this->has('p-done'), 'completed turn: scratchpad gone');
    $this->assertFalse($this->has('p-failed'), 'failed turn: scratchpad gone');
    $this->assertTrue($this->has('p-hitl'), 'paused on an interrupt: scratchpad survives');
    $this->assertTrue($this->has('p-budget'), 'paused on budget: scratchpad survives');
    $this->assertTrue($this->has('p-other'), "another pipeline's scratchpad is untouched");
    $this->assertTrue($this->has('p-done', 'other'), 'only the scratchpad key is dropped');
  }

  /**
   * An empty pipeline id never reaches the shared global bucket.
   */
  public function testEmptyPipelineIdIsNoOp(): void {
    $this->seed('p1');
    $memory = $this->container->get('flowdrop_memory.manager');
    $memory->set('global', '', 'scratchpad', 'g', 86400, 'entity');
    $sweeper = $this->sweeper($this->createMock(LoggerInterface::class));
    $sweeper->drop(NULL);
    $sweeper->drop('');
    $this->assertTrue($memory->has('global', '', 'scratchpad', 'entity'));
    $this->assertTrue($this->has('p1'));
  }

  /**
   * A backend failure is logged and swallowed.
   */
  public function testFailureIsSwallowedAndLogged(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');
    $broken = new class () {

      /**
       * Always fails.
       */
      public function delete(): void {
        throw new \RuntimeException('boom');
      }

    };
    $this->container->set('flowdrop_memory.manager', $broken);
    $this->sweeper($logger)->drop('p1');
  }

}
