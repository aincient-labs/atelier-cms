<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit;

use Drupal\aincient_core\Exception\UpstreamDetail;
use Drupal\Tests\aincient_core\Unit\Fixtures\GuzzleStyleException;
use Drupal\Tests\aincient_core\Unit\Fixtures\OpenAiStyleException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a provider failure can be made to say why (DECISIONS 0269).
 *
 * @coversDefaultClass \Drupal\aincient_core\Exception\UpstreamDetail
 * @group aincient_core
 */
final class UpstreamDetailTest extends TestCase {

  /**
   * @covers ::from
   *
   * The openai-php shape: the message throws the body away, but the exception
   * still carries the response on a public property.
   */
  public function testReadsPublicResponseProperty(): void {
    $e = new OpenAiStyleException(new Response(503, [], '{"error":{"message":"gemini overloaded"}}'));

    $this->assertSame('{"error":{"message":"gemini overloaded"}}', UpstreamDetail::from($e));
    // The point of the exercise: the message alone told us nothing.
    $this->assertSame('Server error (HTTP 503) occurred.', $e->getMessage());
  }

  /**
   * @covers ::from
   *
   * The Guzzle shape: the response comes from getResponse().
   */
  public function testReadsGetResponseAccessor(): void {
    $e = new GuzzleStyleException('Server error', new Response(502, [], 'upstream connect error'));

    $this->assertSame('upstream connect error', UpstreamDetail::from($e));
  }

  /**
   * @covers ::from
   *
   * The real chain is nested — Drupal AI rethrows the transport exception, so
   * the response is somewhere further down `previous`.
   */
  public function testWalksThePreviousChain(): void {
    $inner = new OpenAiStyleException(new Response(503, [], 'budget exceeded for key'));
    $outer = new \RuntimeException('Node execution failed', 0, new \RuntimeException('reason failed', 0, $inner));

    $this->assertSame('budget exceeded for key', UpstreamDetail::from($outer));
  }

  /**
   * @covers ::from
   *
   * Nothing to add: no response anywhere, or an empty body. The caller must
   * rethrow the original untouched rather than append noise.
   */
  public function testReturnsNullWhenThereIsNothingToSay(): void {
    $this->assertNull(UpstreamDetail::from(new \RuntimeException('no model available')));
    $this->assertNull(UpstreamDetail::from(new OpenAiStyleException(new Response(503, [], ''))));
    $this->assertNull(UpstreamDetail::from(new GuzzleStyleException('nope', NULL)));
  }

  /**
   * @covers ::from
   *
   * A body already consumed by an earlier reader still yields its content: the
   * stream is rewound first.
   */
  public function testRewindsAnAlreadyReadBody(): void {
    $response = new Response(503, [], 'read me twice');
    $response->getBody()->getContents();

    $this->assertSame('read me twice', UpstreamDetail::from(new OpenAiStyleException($response)));
  }

  /**
   * @covers ::from
   *
   * A huge HTML error page is capped, so one bad gateway cannot flood a log
   * line or a chat bubble.
   */
  public function testCapsLongBody(): void {
    $detail = UpstreamDetail::from(new OpenAiStyleException(new Response(503, [], str_repeat('x', 5000))));

    $this->assertSame(801, mb_strlen((string) $detail));
    $this->assertStringEndsWith('…', (string) $detail);
  }

}
