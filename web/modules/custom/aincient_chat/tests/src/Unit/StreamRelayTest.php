<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\StreamRelay;
use Drupal\aincient_chat\Event\ChatEvent;

/**
 * Tests the per-request SSE side channel.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\StreamRelay
 * @group aincient
 */
final class StreamRelayTest extends UnitTestCase {

  /**
   * Disarmed (the default), emit() must be a silent no-op — subscribers fire
   * blindly from cron/playground runs where no stream is open.
   *
   * @covers ::emit
   */
  public function testEmitWithoutArmIsANoOp(): void {
    $relay = new StreamRelay();
    $relay->emit(ChatEvent::status('nobody is listening'));
    // Reaching here without an error IS the assertion.
    $this->addToAssertionCount(1);
  }

  /**
   * Armed, every emitted event reaches the emitter in order.
   *
   * @covers ::arm
   * @covers ::emit
   */
  public function testArmedRelayForwardsEvents(): void {
    $relay = new StreamRelay();
    $seen = [];
    $relay->arm(static function (ChatEvent $event) use (&$seen): void {
      $seen[] = $event;
    });

    $first = ChatEvent::node('agent_reason', 'Reason', 'completed');
    $second = ChatEvent::node('chat_output', 'Chat Output', 'completed');
    $relay->emit($first);
    $relay->emit($second);

    $this->assertSame([$first, $second], $seen);
  }

  /**
   * Disarming closes the channel; later emits go nowhere.
   *
   * @covers ::disarm
   */
  public function testDisarmStopsForwarding(): void {
    $relay = new StreamRelay();
    $seen = [];
    $relay->arm(static function (ChatEvent $event) use (&$seen): void {
      $seen[] = $event;
    });
    $relay->disarm();

    $relay->emit(ChatEvent::status('after disarm'));

    $this->assertSame([], $seen);
  }

  /**
   * Re-arming replaces the emitter (one stream at a time per request).
   *
   * @covers ::arm
   */
  public function testRearmReplacesEmitter(): void {
    $relay = new StreamRelay();
    $firstSeen = $secondSeen = [];
    $relay->arm(static function (ChatEvent $event) use (&$firstSeen): void {
      $firstSeen[] = $event;
    });
    $relay->arm(static function (ChatEvent $event) use (&$secondSeen): void {
      $secondSeen[] = $event;
    });

    $relay->emit(ChatEvent::status('hello'));

    $this->assertSame([], $firstSeen);
    $this->assertCount(1, $secondSeen);
  }

}
