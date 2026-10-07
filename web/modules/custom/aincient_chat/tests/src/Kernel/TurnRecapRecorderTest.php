<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\aincient_chat\Chat\TurnRecapRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A widget turn's recap lands on its assistant entry in the model's history.
 *
 * DECISIONS 0464. The buffer address is owned by the agent engine's config, so
 * this also pins that the engine still reads and writes where the recorder
 * appends.
 *
 * @covers \Drupal\aincient_chat\Chat\TurnRecapRecorder
 * @group aincient_chat
 */
final class TurnRecapRecorderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'flowdrop',
    'flowdrop_node_category',
    'flowdrop_node_type',
    'flowdrop_memory',
  ];

  private const TABLE = [[
    'widget' => 'data_table',
    'payload' => ['columns' => [['key' => 'title']], 'rows' => [['id' => 7, 'cells' => ['title' => 'Menu']]]],
  ]];

  private const LINE = '[shown to the user: a table of 1 row — 1. Menu (id 7)]';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('flowdrop_memory_record');
    $this->installConfig(['flowdrop_memory']);
  }

  /**
   * The recorder under test.
   */
  private function recorder(): TurnRecapRecorder {
    return new TurnRecapRecorder($this->createMock(LoggerInterface::class));
  }

  /**
   * Seeds session 5's conversation.
   */
  private function seed(array $messages): void {
    $this->container->get('flowdrop_memory.manager')->set('session', '5', 'conversation', $messages, NULL, 'entity');
  }

  /**
   * Session 5's conversation as stored.
   */
  private function conversation(): array {
    return $this->container->get('flowdrop_memory.manager')->get('session', '5', 'conversation', [], 'entity');
  }

  /**
   * The recap is appended to the last assistant entry, once.
   */
  public function testAppendsToTheTurnsReplyOnce(): void {
    $this->seed([
      ['role' => 'user', 'content' => 'List my pages'],
      ['role' => 'assistant', 'content' => 'Here are your pages.'],
    ]);
    $this->recorder()->record(5, self::TABLE);
    $this->recorder()->record(5, self::TABLE);

    $messages = $this->conversation();
    $this->assertCount(2, $messages);
    $this->assertSame('List my pages', $messages[0]['content']);
    $this->assertSame("Here are your pages.\n\n" . self::LINE, $messages[1]['content']);
  }

  /**
   * No reply to attach to (the turn failed before answering): nothing changes.
   */
  public function testLeavesAConversationEndingOnTheUserAlone(): void {
    $seeded = [['role' => 'user', 'content' => 'List my pages']];
    $this->seed($seeded);
    $this->recorder()->record(5, self::TABLE);
    $this->assertSame($seeded, $this->conversation());
  }

  /**
   * No widgets, no session, or an empty buffer: nothing is written.
   */
  public function testNoOps(): void {
    $this->recorder()->record(5, []);
    $this->recorder()->record(0, self::TABLE);
    $this->recorder()->record(5, self::TABLE);
    $this->assertSame([], $this->conversation());
  }

  /**
   * The engine's history nodes use the address the recorder writes to.
   */
  public function testEngineConfigMatchesTheRecorderAddress(): void {
    $engine = Yaml::parseFile(DRUPAL_ROOT . '/../config/sync/flowdrop_workflow.flowdrop_workflow.aincient_agent_engine.yml');
    $history = [];
    foreach ($engine['nodes'] as $node) {
      $config = $node['data']['config'] ?? [];
      if (($node['data']['metadata']['node_type_id'] ?? '') === 'conversation_buffer' && ($config['key'] ?? '') === TurnRecapRecorder::KEY) {
        $history[$node['id']] = [$config['scope'] ?? NULL, $config['backend'] ?? NULL];
      }
    }
    // "Append user turn" and the final "Conversation append".
    $this->assertCount(2, $history, 'The engine appends to the conversation from two nodes.');
    foreach ($history as $id => $address) {
      $this->assertSame([TurnRecapRecorder::SCOPE, TurnRecapRecorder::BACKEND], $address, $id);
    }
  }

}
