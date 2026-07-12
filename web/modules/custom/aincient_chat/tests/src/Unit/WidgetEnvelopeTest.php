<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\WidgetEnvelope;

/**
 * Tests the generative-UI widget envelope decoder.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\WidgetEnvelope
 * @group aincient
 */
final class WidgetEnvelopeTest extends UnitTestCase {

  /**
   * A bare JSON envelope decodes to widget + payload + a synthesized summary.
   *
   * @covers ::decode
   * @covers ::synthesizeSummary
   */
  public function testDecodesBareEnvelope(): void {
    $json = json_encode([
      '__widget__' => 'weather_card',
      'payload' => [
        'location' => ['name' => 'Berlin'],
        'units' => ['temperature' => 'celsius'],
        'current' => ['temperature' => 17.7, 'conditionCode' => 'heavy-rain'],
      ],
    ]);

    $envelope = WidgetEnvelope::decode($json);

    $this->assertNotNull($envelope);
    $this->assertSame('weather_card', $envelope['widget']);
    $this->assertSame('Berlin', $envelope['payload']['location']['name']);
    // Summary is synthesized from the payload when none is supplied.
    $this->assertSame('Weather for Berlin: 18°C, heavy rain.', $envelope['summary']);
  }

  /**
   * A ```fenced``` envelope (as a shaper may emit) decodes the same way.
   *
   * @covers ::decode
   */
  public function testDecodesFencedEnvelope(): void {
    $json = "```json\n" . json_encode([
      '__widget__' => 'weather_card',
      'payload' => ['location' => ['name' => 'Tokyo']],
      'summary' => 'Tokyo weather.',
    ]) . "\n```";

    $envelope = WidgetEnvelope::decode($json);

    $this->assertNotNull($envelope);
    $this->assertSame('weather_card', $envelope['widget']);
    // An explicit summary is preserved verbatim.
    $this->assertSame('Tokyo weather.', $envelope['summary']);
  }

  /**
   * Non-envelopes (prose, malformed, wrong shape) decode to NULL.
   *
   * @covers ::decode
   * @dataProvider nonEnvelopeProvider
   */
  public function testRejectsNonEnvelopes(string $text): void {
    $this->assertNull(WidgetEnvelope::decode($text));
  }

  /**
   * Inputs that must NOT be treated as widget envelopes.
   *
   * @return array<string, array{string}>
   *   Test cases keyed by description.
   */
  public static function nonEnvelopeProvider(): array {
    return [
      'plain prose' => ["Here's the current weather in Berlin."],
      'empty string' => [''],
      'json but no widget key' => ['{"payload": {"a": 1}}'],
      'widget key but no payload object' => ['{"__widget__": "weather_card"}'],
      'empty widget name' => ['{"__widget__": "", "payload": {}}'],
      'json array, not object' => ['[1, 2, 3]'],
      'broken json' => ['{"__widget__": "weather_card", "payload":'],
    ];
  }

}
