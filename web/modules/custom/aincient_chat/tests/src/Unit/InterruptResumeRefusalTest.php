<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_chat\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_chat\Chat\ChatProcessor;
use Drupal\aincient_chat\Chat\ChatRouterInterface;
use Drupal\aincient_chat\Chat\ResumableFlowDispatcherInterface;
use Drupal\aincient_chat\Event\ChatEventType;
use Psr\Log\LoggerInterface;

/**
 * Tests how a refused interrupt resume is reported to the person.
 *
 * The engine refuses an unanswerable interrupt by throwing, carrying a
 * published `error_code` whose accompanying message flowdrop 2.6.0 explicitly
 * declares to be prose that may change. These assert that we read the code and
 * say something of our own — and that an exception with no code still degrades
 * to the generic message rather than disappearing.
 *
 * @coversDefaultClass \Drupal\aincient_chat\Chat\ChatProcessor
 * @group aincient
 */
final class InterruptResumeRefusalTest extends UnitTestCase {

  /**
   * Drive resumeInterrupt() with a dispatcher that throws $e, return the frames.
   *
   * @return array<int, \Drupal\aincient_chat\Event\ChatEvent>
   *   Every event the processor yielded.
   */
  private function framesForThrow(\Throwable $e): array {
    $dispatcher = new class($e) implements ResumableFlowDispatcherInterface {

      public function __construct(private readonly \Throwable $e) {}

      public function dispatch(string $message, string $threadId, string $flow, ?string $workflow = NULL, array $clientContext = []): \Generator {
        yield from [];
      }

      public function resume(string $interruptId, mixed $response, string $threadId): \Generator {
        throw $this->e;
        // @phpstan-ignore-next-line  Unreachable, but this must be a Generator.
        yield from [];
      }

    };

    $processor = new ChatProcessor(
      $this->createMock(ChatRouterInterface::class),
      $dispatcher,
      $this->createMock(LoggerInterface::class),
    );

    return iterator_to_array($processor->resumeInterrupt('irq-1', 'yes', 'thr_1'), FALSE);
  }

  /**
   * The error frame's message text.
   */
  private function errorMessage(array $frames): string {
    foreach ($frames as $frame) {
      if ($frame->type === ChatEventType::ERROR) {
        return (string) ($frame->data['message'] ?? '');
      }
    }
    return '';
  }

  /**
   * Each published refusal code gets our wording, not the engine's.
   *
   * @dataProvider refusalCodes
   *
   * @covers ::resumeInterrupt
   */
  public function testPublishedRefusalCodeGetsOurWording(string $code, string $expected): void {
    // Duck-typed exactly as the engine's: an exception answering getErrorCode().
    // Built here rather than imported because flowdrop's own classes are
    // @internal in 2.6.0 — the CODE is the contract, not the class.
    $e = new class($code) extends \InvalidArgumentException {

      public function __construct(private readonly string $errorCode) {
        parent::__construct('engine prose that may change between releases');
      }

      public function getErrorCode(): string {
        return $this->errorCode;
      }

    };

    $frames = $this->framesForThrow($e);
    $message = $this->errorMessage($frames);

    self::assertSame($expected, $message);
    self::assertStringNotContainsString('engine prose', $message);
  }

  /**
   * The three codes a person can actually cause through the UI.
   *
   * @return array<string, array{string, string}>
   */
  public static function refusalCodes(): array {
    return [
      'double-clicked the choice' => [
        'RESOLUTION_IN_PROGRESS',
        'That choice is already being recorded — give it a moment.',
      ],
      'another tab already answered' => [
        'INTERRUPT_NOT_PENDING',
        'That choice was already answered.',
      ],
      'came back to a stale choice' => [
        'INTERRUPT_EXPIRED',
        'That choice expired before it was answered.',
      ],
    ];
  }

  /**
   * An exception carrying no code still reports, rather than vanishing.
   *
   * The fallback is what keeps a genuine bug visible: we only special-case the
   * refusals we recognise, and everything else keeps saying what went wrong.
   *
   * @covers ::resumeInterrupt
   */
  public function testUncodedFailureKeepsTheGenericMessage(): void {
    $frames = $this->framesForThrow(new \RuntimeException('database went away'));

    self::assertSame('Could not resume: database went away', $this->errorMessage($frames));
  }

  /**
   * An unrecognised code is not swallowed either.
   *
   * A code we have no wording for is a refusal from a newer engine. Falling
   * through to the message is the honest answer: silence would strand the
   * person on a choice that never resolves.
   *
   * @covers ::resumeInterrupt
   */
  public function testUnknownCodeFallsThroughToTheMessage(): void {
    $e = new class extends \InvalidArgumentException {

      public function __construct() {
        parent::__construct('some future refusal');
      }

      public function getErrorCode(): string {
        return 'SOME_FUTURE_CODE';
      }

    };

    self::assertSame('Could not resume: some future refusal', $this->errorMessage($this->framesForThrow($e)));
  }

  /**
   * The engine's real exceptions still carry the codes we branch on.
   *
   * Everything above drives a duck-typed fake, which cannot notice upstream
   * re-pointing a code — the mapping would simply stop matching and every
   * refusal would quietly fall back to engine prose. This is the only test that
   * touches flowdrop's own classes, and it exists to fail the build when that
   * happens, not to test flowdrop.
   *
   * Skips rather than fails when the classes are absent: they are `@internal`
   * in 2.6.0, so upstream may move or rename them, and that is a signal to
   * revisit the mapping, not a broken build on an engine that never promised
   * otherwise.
   *
   * @covers ::resumeInterrupt
   */
  public function testEngineExceptionsStillCarryTheCodesWeBranchOn(): void {
    $expected = [
      'Drupal\flowdrop_interrupt\Exception\InterruptResolutionInProgressException' => 'RESOLUTION_IN_PROGRESS',
      'Drupal\flowdrop_interrupt\Exception\InterruptNotPendingException' => 'INTERRUPT_NOT_PENDING',
      'Drupal\flowdrop_interrupt\Exception\InterruptExpiredException' => 'INTERRUPT_EXPIRED',
    ];

    $missing = array_filter(array_keys($expected), static fn(string $c) => !class_exists($c));
    if ($missing !== []) {
      self::markTestSkipped('flowdrop moved its interrupt refusals: ' . implode(', ', $missing));
    }

    foreach ($expected as $class => $code) {
      $e = new $class('prose');
      self::assertInstanceOf(\InvalidArgumentException::class, $e, "$class must stay catchable as InvalidArgumentException");
      self::assertTrue(method_exists($e, 'getErrorCode'), "$class must still answer getErrorCode()");
      self::assertSame($code, $e->getErrorCode(), "$class re-pointed its code — update resumeFailureMessage()");
      // The mapping must actually recognise it.
      self::assertArrayHasKey($code, array_column(self::refusalCodes(), 1, 0));
    }
  }

  /**
   * A refused resume still closes the stream.
   *
   * Without DONE the console's thinking indicator never stops, so the person
   * sees the error AND a stream that looks alive. Asserted for every path.
   *
   * @covers ::resumeInterrupt
   */
  public function testRefusedResumeStillEmitsDone(): void {
    foreach ([new \RuntimeException('boom'), new \InvalidArgumentException('nope')] as $e) {
      $types = array_map(static fn($f) => $f->type, $this->framesForThrow($e));
      self::assertContains(ChatEventType::ERROR, $types);
      self::assertSame(ChatEventType::DONE, end($types));
    }
  }

}
