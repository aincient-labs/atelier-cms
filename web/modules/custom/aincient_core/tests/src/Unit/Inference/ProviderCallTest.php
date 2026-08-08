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
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Result\TextResult;
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
    yield 'malformed tool call' => [
      new MalformedToolCallException('could not decode tool arguments'),
      ProviderCall::KIND_TOOL_MALFORMED,
      'could not read',
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
   * A truncated answer fails as `too_long`, and is NOT retried.
   *
   * The one provider fault that arrives as a success: the call returns, the text
   * converts, and the turn used to be reported as complete (atelier-cms#8). Not
   * retried on purpose — an identical request truncates identically, so a retry
   * would spend money to reach the same wrong place.
   */
  public function testTruncatedAnswerFailsAndIsNotRetried(): void {
    $calls = 0;
    $truncated = new TextResult('Here are the three sections you asked for. The first one');
    $truncated->getMetadata()->add('finish_reason', new FinishReason(FinishReasonCase::LENGTH, 'max_tokens'));

    try {
      $this->providerCall()->run(
        function () use (&$calls, $truncated) {
          $calls++;
          return $truncated;
        },
        'anthropic',
        'claude-sonnet-5',
        'step',
      );
      $this->fail('A truncated answer was handed back as a complete one.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(1, $calls, 'A truncation must not be retried.');
      $this->assertSame(ProviderCall::KIND_TOO_LONG, $e->getKind());
      $this->assertStringContainsString('too long', $e->getMessage());
      // Classified as the same condition the bridges throw for when they do
      // throw, so both routes reach one surface with one sentence.
      $this->assertInstanceOf(MaxOutputTokensException::class, $e->getPrevious());
    }
  }

  /**
   * A complete answer passes through untouched, whatever it says.
   *
   * The false-positive guard at this seam: the check runs on every provider call
   * in the product, so a reason that is absent, clean or unrecognised must return
   * the result unchanged — turning good turns into error cards would be a worse
   * regression than the bug.
   */
  #[DataProvider('completeAnswers')]
  public function testCompleteAnswersPassThrough(?FinishReason $reason): void {
    $result = new TextResult('A complete answer.');
    if ($reason !== NULL) {
      $result->getMetadata()->add('finish_reason', $reason);
    }

    $returned = $this->providerCall()->run(
      fn (): object => $result,
      'anthropic',
      'claude-sonnet-5',
      'step',
    );

    $this->assertSame($result, $returned);
  }

  /**
   * Finish reasons that leave the answer alone.
   *
   * @return iterable<string, array{0: \Symfony\AI\Platform\FinishReason\FinishReason|null}>
   *   The reason a provider reported, or NULL for none at all.
   */
  public static function completeAnswers(): iterable {
    yield 'unreported' => [NULL];
    yield 'clean stop' => [new FinishReason(FinishReasonCase::STOP, 'end_turn')];
    yield 'tool call' => [new FinishReason(FinishReasonCase::TOOL_CALL, 'tool_use')];
    yield 'stop sequence' => [new FinishReason(FinishReasonCase::STOP_SEQUENCE, 'stop_sequence')];
    yield 'unrecognised' => [new FinishReason(FinishReasonCase::OTHER, 'something_new')];
  }

  /**
   * A non-object answer is returned as-is rather than inspected.
   */
  public function testNonObjectAnswerPassesThrough(): void {
    $this->assertSame(
      'plain',
      $this->providerCall()->run(fn (): string => 'plain', 'ollama', 'llama3', 'step'),
    );
  }

  /**
   * The raised failure carries the structured account a branch reads.
   *
   * `toDetail()` is the shape our reason node emits on `error_detail` and the
   * console card is driven from. A transient fault (a rate limit that outlived
   * its retries) names its provider and model and is marked `retryable` — the
   * same predicate the loop trusted to retry it — so a surface can offer "try
   * again" from the struct rather than re-deriving it from the sentence.
   */
  public function testTransientFailureCarriesRetryableDetail(): void {
    try {
      $this->providerCall()->run(
        function () {
          throw new RateLimitExceededException(NULL, 'quota exhausted');
        },
        'openai',
        'gpt-5',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $detail = $e->toDetail();
      $this->assertSame(ProviderCall::KIND_RATE_LIMIT, $detail['kind']);
      $this->assertSame('openai', $detail['provider']);
      $this->assertSame('gpt-5', $detail['model']);
      $this->assertSame($e->getMessage(), $detail['message']);
      $this->assertTrue($detail['retryable'], 'A rate limit is transient in nature.');
    }
  }

  /**
   * A hard fault is marked NOT retryable — trying again cannot clear it.
   *
   * `retryable` is about the fault, not attempts left: a rejected key fails on
   * the first answer and stays false, so a surface does not offer a pointless
   * retry. The truncation path (`too_long`) is the same judgement from the other
   * throw site — a truncated turn is deterministic, so it is not retryable.
   */
  public function testHardFaultDetailIsNotRetryable(): void {
    try {
      $this->providerCall()->run(
        function () {
          throw new AuthenticationException('Missing Authentication header');
        },
        'anthropic',
        'claude-sonnet-5',
        'step',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $detail = $e->toDetail();
      $this->assertSame(ProviderCall::KIND_AUTH, $detail['kind']);
      $this->assertSame('anthropic', $detail['provider']);
      $this->assertSame('claude-sonnet-5', $detail['model']);
      $this->assertFalse($detail['retryable'], 'A rejected key is not cleared by retrying.');
    }
  }

  /**
   * A ProviderCall that never actually sleeps.
   */
  private function providerCall(): ProviderCall {
    return new ProviderCall(new NullLogger(), sleepBetweenAttempts: FALSE);
  }

}
