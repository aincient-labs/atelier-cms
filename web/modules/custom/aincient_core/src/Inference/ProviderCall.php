<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Exception\AiProviderFailure;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Runs one provider call: retries what is transient, names what is not.
 *
 * Every model call in this module used to be its own `try { invoke } catch
 * (\Throwable)` with the same two consequences: a single upstream hiccup ended
 * the whole run, and whatever the provider's API happened to say became the
 * entire user-facing message. Three separate bug reports (atelier-cms #4, #5,
 * #6) turned out to be three different upstream conditions wearing that one
 * shape — a 401, a 429 and a 503, all rendered identically, none of them
 * actionable, and the 429 one filed as our bug because a transient limit
 * presented as a hard failure.
 *
 * This class is the single seam those three call sites now share.
 *
 * **What it retries.** `symfony/ai-platform` already classifies provider
 * responses into typed exceptions ({@see RateLimitExceededException},
 * {@see ServerException}, {@see AuthenticationException}, …) — every bridge we
 * ship does so except Ollama, which is a local server. So the retry decision is
 * a type check, not error-body parsing: rate limits, 5xx and transport faults
 * are retried; everything else is the caller's problem and fails on the first
 * answer. A 429 that carries `Retry-After` is honoured up to
 * {@see self::MAX_WAIT_SECONDS}; anything else backs off exponentially with
 * jitter, because a fleet of appliances retrying a shared proxy in lockstep is
 * how a rate limit becomes a rate limit again.
 *
 * **What it says.** {@see self::describe()} turns the typed exception into a
 * sentence that says *whose* problem it is — a rejected key and an overloaded
 * provider need opposite responses from the reader, and both used to render as
 * the provider's own wire text. The upstream's exact words stay in the log,
 * where they help, and out of the interface, where they did not.
 *
 * The machine-readable half of that judgement rides on the thrown
 * {@see AiProviderFailure} as its `kind`, so a surface that wants to offer
 * "reconnect your provider" versus "try again in a minute" has something to
 * branch on. Nothing branches on it yet; wiring the AI nodes' reserved `error`
 * port is the change that will (plans/current.md).
 */
final class ProviderCall {

  /**
   * Total attempts, including the first. Two retries.
   *
   * Three is a compromise, not a tuning result: enough to ride out the
   * single-instant blips that produced #6, few enough that a genuinely
   * unavailable provider still fails inside a person's patience. A chat turn
   * already blocks for many seconds, so the added wait is small next to the
   * call it is protecting — but it is real, and it is why this is 3 and not 5.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * The longest we will honour a `Retry-After`, in seconds.
   *
   * Providers do hand out minute-scale hints when a quota window resets. Waiting
   * that long inside a request is worse than failing: the reader has no idea
   * anything is happening, and on the queue it holds a worker hostage. Past this
   * we stop and say the provider is rate-limiting, which is true and actionable.
   */
  private const MAX_WAIT_SECONDS = 20;

  /**
   * Kinds carried on the thrown failure. Stable strings — surfaces match them.
   */
  public const KIND_AUTH = 'auth';
  public const KIND_RATE_LIMIT = 'rate_limit';
  public const KIND_UNAVAILABLE = 'unavailable';
  public const KIND_MODEL_MISSING = 'model_missing';
  public const KIND_TOO_LONG = 'too_long';
  public const KIND_REFUSED = 'refused';
  public const KIND_REJECTED = 'rejected';
  public const KIND_UNKNOWN = 'unknown';

  /**
   * Constructs a ProviderCall.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Where the provider's own words go.
   * @param bool $sleepBetweenAttempts
   *   FALSE makes the backoff instantaneous. For tests only — the delay itself
   *   is what {@see self::waitSeconds()} exists to assert, separately.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly bool $sleepBetweenAttempts = TRUE,
  ) {}

  /**
   * Run a provider call, retrying transient failures.
   *
   * @param callable $call
   *   The call to make. Must resolve the result *inside* itself: a
   *   DeferredResult is lazy, so an upstream fault surfaces on conversion, and
   *   converting outside this closure is exactly how an error escapes unwrapped.
   * @param string $providerId
   *   Provider whose failure this would be, for the message and the log.
   * @param string $modelId
   *   Model being called.
   * @param string $what
   *   What the caller was doing, as a noun phrase that completes "could not
   *   complete this …" — e.g. 'step', 'request'.
   * @param array<string, string> $logContext
   *   Extra placeholders to merge into the log line (e.g. the feature tag).
   *
   * @return mixed
   *   Whatever the call returned.
   *
   * @throws \Drupal\aincient_core\Exception\AiProviderFailure
   *   When the provider could not serve the call, retries included.
   */
  public function run(callable $call, string $providerId, string $modelId, string $what, array $logContext = []): mixed {
    $attempt = 0;

    while (TRUE) {
      $attempt++;
      try {
        return $call();
      }
      catch (\Throwable $e) {
        $lastAttempt = $attempt >= self::MAX_ATTEMPTS;

        if (!$lastAttempt && $this->isTransient($e)) {
          $wait = $this->waitSeconds($e, $attempt);
          $this->logger->warning('Provider @provider/@model failed transiently (attempt @n of @max), retrying in @wait s: @message', [
            '@provider' => $providerId,
            '@model' => $modelId,
            '@n' => $attempt,
            '@max' => self::MAX_ATTEMPTS,
            '@wait' => $wait,
            '@message' => $e->getMessage(),
          ] + $logContext);
          if ($this->sleepBetweenAttempts && $wait > 0) {
            sleep($wait);
          }
          continue;
        }

        // Out of attempts, or never retryable in the first place.
        $this->logger->error('Inference failed on @provider/@model after @n attempt(s): @message', [
          '@provider' => $providerId,
          '@model' => $modelId,
          '@n' => $attempt,
          '@message' => $e->getMessage(),
        ] + $logContext);

        throw new AiProviderFailure(
          $this->describe($e, $providerId, $modelId, $what, $attempt),
          0,
          $e,
          $this->kind($e),
        );
      }
    }
  }

  /**
   * Whether this failure is worth trying again.
   *
   * Type checks, not string matching: the bridges have already read the status
   * code and the error body for us. A transport fault (connection refused, DNS,
   * a read timeout) counts — an Ollama endpoint that is simply wrong will burn
   * two short retries before saying so, which is a fair trade for surviving the
   * blip that a local server restarting produces.
   */
  private function isTransient(\Throwable $e): bool {
    return $e instanceof RateLimitExceededException
      || $e instanceof ServerException
      || $e instanceof TransportExceptionInterface;
  }

  /**
   * How long to wait before the next attempt, in seconds.
   *
   * Public and pure so the policy can be asserted directly — the sleep itself is
   * suppressed in tests, so this is the only part of the backoff worth testing.
   *
   * @param \Throwable $e
   *   The failure that triggered the retry.
   * @param int $attempt
   *   Which attempt just failed, 1-based.
   *
   * @return int
   *   Seconds to wait. Never longer than {@see self::MAX_WAIT_SECONDS}.
   */
  public function waitSeconds(\Throwable $e, int $attempt): int {
    // A provider that told us when to come back knows better than our curve.
    if ($e instanceof RateLimitExceededException) {
      $after = $e->getRetryAfter();
      if ($after !== NULL && $after > 0) {
        return min($after, self::MAX_WAIT_SECONDS);
      }
    }

    // 1s, 2s, 4s … plus up to a second of jitter. The jitter is the point on a
    // shared proxy: without it, every appliance that hit the same limit at the
    // same moment comes back at the same moment.
    $base = 2 ** ($attempt - 1);

    return min($base + random_int(0, 1), self::MAX_WAIT_SECONDS);
  }

  /**
   * The kind of failure this is, for surfaces that want to branch.
   */
  private function kind(\Throwable $e): string {
    return match (TRUE) {
      $e instanceof AuthenticationException => self::KIND_AUTH,
      $e instanceof RateLimitExceededException => self::KIND_RATE_LIMIT,
      $e instanceof ServerException, $e instanceof TransportExceptionInterface => self::KIND_UNAVAILABLE,
      $e instanceof ModelNotFoundException => self::KIND_MODEL_MISSING,
      $e instanceof ExceedContextSizeException, $e instanceof MaxOutputTokensException => self::KIND_TOO_LONG,
      $e instanceof ContentFilterException => self::KIND_REFUSED,
      $e instanceof BadRequestException => self::KIND_REJECTED,
      default => self::KIND_UNKNOWN,
    };
  }

  /**
   * What to tell the reader.
   *
   * Every sentence answers "whose problem is this, and what do I do now" — the
   * thing the provider's own error text never answered. None of them repeat the
   * upstream's wording; that is in the log.
   */
  private function describe(\Throwable $e, string $providerId, string $modelId, string $what, int $attempts): string {
    $tries = $attempts > 1
      ? sprintf(' Atelier tried %d times.', $attempts)
      : '';

    return match ($this->kind($e)) {
      self::KIND_AUTH => sprintf(
        '%s rejected the key Atelier has for it. Reconnect %s and try again — the key is either wrong, revoked, or not allowed to use this model.',
        ucfirst($providerId),
        $providerId,
      ),

      self::KIND_RATE_LIMIT => sprintf(
        '%s is rate-limiting this key, so it refused the request.%s The limit is theirs, not Atelier\'s — wait a moment and try again, or connect a provider with more headroom.',
        ucfirst($providerId),
        $tries,
      ),

      self::KIND_UNAVAILABLE => sprintf(
        '%s could not be reached, or answered with an error of their own.%s Nothing is wrong with your site — try again shortly.',
        ucfirst($providerId),
        $tries,
      ),

      self::KIND_MODEL_MISSING => sprintf(
        '%s does not offer "%s" to this key. Choose a different model for this role, or use a key that has access to it.',
        ucfirst($providerId),
        $modelId,
      ),

      self::KIND_TOO_LONG => sprintf(
        'This turn was too long for "%s" to finish. Start a new conversation, or ask for it in smaller pieces.',
        $modelId,
      ),

      self::KIND_REFUSED => sprintf(
        '%s declined to answer this request under their own content rules. Rephrasing usually clears it.',
        ucfirst($providerId),
      ),

      self::KIND_REJECTED => sprintf(
        '%s would not accept the request Atelier sent for "%s". This is a fault on our side, not yours — the details are in the log.',
        ucfirst($providerId),
        $modelId,
      ),

      default => sprintf(
        'The %s model "%s" could not complete this %s.',
        $providerId,
        $modelId,
        $what,
      ),
    };
  }

}
