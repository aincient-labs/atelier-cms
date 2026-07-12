<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Event\ChatEvent;
use Drupal\aincient_chat\Event\ChatEventType;

/**
 * Tests the typed chat event protocol, focused on the `node` frame.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Event\ChatEvent
 * @group aincient
 */
final class ChatEventTest extends UnitTestCase {

  /**
   * node() carries the trail fields the console renders, extras merged in.
   *
   * @covers ::node
   */
  public function testNodeFactory(): void {
    $event = ChatEvent::node('agent_reason', 'Reason (agent step)', 'completed', [
      'node_type_id' => 'aincient_reason',
      'elapsed_ms' => 1300,
    ]);

    $this->assertSame(ChatEventType::NODE, $event->type);
    $this->assertSame([
      'node_id' => 'agent_reason',
      'label' => 'Reason (agent step)',
      'status' => 'completed',
      'node_type_id' => 'aincient_reason',
      'elapsed_ms' => 1300,
    ], $event->data);
  }

  /**
   * The canonical keys win over same-named extras (`+` union semantics).
   *
   * @covers ::node
   */
  public function testNodeExtrasCannotOverrideCanonicalKeys(): void {
    $event = ChatEvent::node('a', 'A', 'completed', ['status' => 'spoofed']);
    $this->assertSame('completed', $event->data['status']);
  }

  /**
   * A node frame serializes as `event: node` + one JSON data line.
   *
   * @covers ::toSseFrame
   */
  public function testNodeSseFrame(): void {
    $frame = ChatEvent::node('chat_output', 'Chat Output', 'completed', ['elapsed_ms' => 2])
      ->toSseFrame();

    $this->assertStringStartsWith("event: node\n", $frame);
    $this->assertStringEndsWith("\n\n", $frame);

    [, $dataLine] = explode("\n", $frame);
    $this->assertStringStartsWith('data: ', $dataLine);
    $decoded = json_decode(substr($dataLine, 6), TRUE);
    $this->assertSame(
      ['node_id' => 'chat_output', 'label' => 'Chat Output', 'status' => 'completed', 'elapsed_ms' => 2],
      $decoded,
    );
  }

}
