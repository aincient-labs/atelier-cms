<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * The retry-and-classify seam every provider call now goes through.
 *
 * What these pin is the judgement, not the plumbing: which failures are worth a
 * second attempt, how long to wait, and whether the reader is told this is
 * their configuration or someone else's outage. Three bug reports (atelier-cms
 * #4, #5, #6) were the same missing distinction three times.
 *
 * Every ProviderCall here is built sleepless — the wait is asserted through
 * waitSeconds(), which is pure, rather than by making the suite sit through it.
 */
#[CoversClass(ProviderCall::class)]
final class ProviderCallTest extends UnitTestCase {

  /**
   * A call that succeeds first time is not retried, and its value comes back.
   */
  public function testSuccessPassesThrough(): void {
    $calls = 0;
    $result = $this->providerCall()->run(
      function () use (&$calls) {
        $calls++;
        return (object) ['ok' => TRUE];
      },
      'anthropic',
      'claude-sonnet-5',
      'step',
    );

    $this->assertSame(1, $calls);
    $this->assertTrue($result->ok);
  }

  /**
   * A transient failure that then succeeds is invisible to the caller.
   *
   * This is issue #6 in one test: the reporter's very first prompt hit a 429 on
   * a shared proxy and the whole run ended. It should have been a pause.
   */
  public function testTransientFailureIsRetriedThenSucceeds(): void {
    $calls = 0;
    $result = $this->providerCall()->run(
      function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
          throw new RateLimitExceededException(NULL, 'slow down');
        }
        return (object) ['ok' => TRUE];
      },
      'openai',
      'gpt-5',
      'step',
    );

    $this->assertSame(2, $calls, 'The call should have been retried exactly once.');
    $this->assertTrue($result->ok);
  }

  /**
   * Retries are bounded, and the give-up says the limit was upstream.
   */
  public function testRateLimitGivesUpAfterMaxAttempts(): void {
    $calls = 0;
    try {
      $this->providerCall()->run(
        function () use (&$calls) {
          $calls++;
          throw new RateLimitExceededException(NULL, 'quota exhausted');
        },
        'openai',
        'gpt-5',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(ProviderCall::MAX_ATTEMPTS, $calls);
      $this->assertSame(ProviderCall::KIND_RATE_LIMIT, $e->getKind());
      $this->assertStringContainsString('rate-limiting', $e->getMessage());
      $this->assertStringContainsString('tried 3 times', $e->getMessage());
      // The upstream's own words stay in the log, not in the interface.
      $this->assertStringNotContainsString('quota exhausted', $e->getMessage());
      $this->assertInstanceOf(RateLimitExceededException::class, $e->getPrevious());
    }
  }

  /**
   * A rejected credential is NOT retried — trying again cannot help.
   *
   * Issue #4: "Missing Authentication header" reached the reader verbatim, which
   * told them nothing about the key they needed to reconnect.
   */
  public function testAuthFailureIsNotRetriedAndNamesTheKey(): void {
    $calls = 0;
    try {
      $this->providerCall()->run(
        function () use (&$calls) {
          $calls++;
          throw new AuthenticationException('Missing Authentication header');
        },
        'anthropic',
        'claude-sonnet-5',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(1, $calls, 'A bad key must not be retried.');
      $this->assertSame(ProviderCall::KIND_AUTH, $e->getKind());
      $this->assertStringContainsString('Reconnect anthropic', $e->getMessage());
      $this->assertStringNotContainsString('Missing Authentication header', $e->getMessage());
    }
  }

  /**
   * Server errors are transient, so they are retried before giving up.
   */
  public function testServerErrorIsRetried(): void {
    $calls = 0;
    try {
      $this->providerCall()->run(
        function () use (&$calls) {
          $calls++;
          throw new ServerException(503, 'upstream unavailable');
        },
        'gemini',
        'gemini-2.5-pro',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(ProviderCall::MAX_ATTEMPTS, $calls);
      $this->assertSame(ProviderCall::KIND_UNAVAILABLE, $e->getKind());
      $this->assertStringContainsString('Nothing is wrong with your site', $e->getMessage());
    }
  }

  /**
   * A transport fault (connection refused, DNS, timeout) is transient too.
   */
  public function testTransportFaultIsRetried(): void {
    $calls = 0;
    try {
      $this->providerCall()->run(
        function () use (&$calls) {
          $calls++;
          throw new TransportException('Connection refused');
        },
        'ollama',
        'llama3',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(ProviderCall::MAX_ATTEMPTS, $calls);
      $this->assertSame(ProviderCall::KIND_UNAVAILABLE, $e->getKind());
    }
  }

  /**
   * Each non-transient failure gets its own kind and its own advice.
   *
   * The point of the table is that #4 (a credential) and #6 (a capacity limit)
   * can no longer render identically — every row here differs from every other.
   */
  #[DataProvider('classifications')]
  public function testClassification(\Throwable $thrown, string $kind, string $needle): void {
    $calls = 0;
    try {
      $this->providerCall()->run(
        function () use (&$calls, $thrown) {
          $calls++;
          throw $thrown;
        },
        'openai',
        'gpt-5',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(1, $calls, 'Only transient failures are retried.');
      $this->assertSame($kind, $e->getKind());
      $this->assertStringContainsString($needle, $e->getMessage());
    }
  }

  /**
   * Data provider for {@see self::testClassification()}.
   *
   * @return iterable<string, array{\Throwable, string, string}>
   *   Thrown exception, expected kind, a phrase the message must carry.
   */
  public static function classifications(): iterable {
    yield 'unknown model' => [
      new ModelNotFoundException('no such model'),
      ProviderCall::KIND_MODEL_MISSING,
      'does not offer "gpt-5"',
    ];
    yield 'context too long' => [
      new ExceedContextSizeException('too many tokens'),
      ProviderCall::KIND_TOO_LONG,
      'too long',
    ];
    yield 'content filtered' => [
      new ContentFilterException('refused'),
      ProviderCall::KIND_REFUSED,
      'content rules',
    ];
    yield 'malformed request' => [
      new BadRequestException('unknown parameter'),
      ProviderCall::KIND_REJECTED,
      'fault on our side',
    ];
    yield 'something else entirely' => [
      new \RuntimeException('who knows'),
      ProviderCall::KIND_UNKNOWN,
      'could not complete this step',
    ];
  }

  /**
   * A provider that says when to come back is obeyed, within reason.
   */
  public function testRetryAfterIsHonoured(): void {
    $call = $this->providerCall();

    $this->assertSame(
      7,
      $call->waitSeconds(new RateLimitExceededException(7), 1),
      'A sane Retry-After should be used as given.',
    );
    $this->assertSame(
      20,
      $call->waitSeconds(new RateLimitExceededException(600), 1),
      'A minute-scale hint should be capped, not obeyed — waiting that long inside a request is worse than failing.',
    );
  }

  /**
   * Without a hint the backoff grows, and never repeats exactly.
   *
   * The jitter is the load-bearing part on a shared proxy: without it every
   * appliance that hit the same limit comes back at the same instant.
   */
  public function testBackoffGrowsAndIsJittered(): void {
    $call = $this->providerCall();
    $e = new ServerException(503);

    // 2 ** (n - 1) plus 0..1 of jitter: 1-2, then 2-3, then 4-5.
    $this->assertGreaterThanOrEqual(1, $call->waitSeconds($e, 1));
    $this->assertLessThanOrEqual(2, $call->waitSeconds($e, 1));

    $this->assertGreaterThanOrEqual(2, $call->waitSeconds($e, 2));
    $this->assertLessThanOrEqual(3, $call->waitSeconds($e, 2));

    $this->assertGreaterThanOrEqual(4, $call->waitSeconds($e, 3));
    $this->assertLessThanOrEqual(5, $call->waitSeconds($e, 3));

    // Whatever the curve says, the cap wins.
    $this->assertLessThanOrEqual(20, $call->waitSeconds($e, 12));
  }

  /**
   * A ProviderCall that never actually sleeps.
   */
  private function providerCall(): ProviderCall {
    return new ProviderCall(new NullLogger(), sleepBetweenAttempts: FALSE);
  }

}
