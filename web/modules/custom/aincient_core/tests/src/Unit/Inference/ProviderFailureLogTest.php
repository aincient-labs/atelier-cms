<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\Tests\UnitTestCase;
use Drupal\aincient_core\Exception\AiProviderFailure;
use Drupal\aincient_core\Inference\ProviderCall;
use Drupal\aincient_core\Inference\ProviderFailureLog;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\AuthenticationException;

/**
 * Tests the side channel that carries a failure's `kind` to the console.
 *
 * FlowDrop hands a failed node's error to the stream relay as a STRING, so the
 * typed exception — and the kind — is gone by then. This log holds it, and
 * `matching()` is what stops an unrelated later failure inheriting it.
 *
 * @coversDefaultClass \Drupal\aincient_core\Inference\ProviderFailureLog
 * @group aincient
 */
final class ProviderFailureLogTest extends UnitTestCase {

  /**
   * Nothing recorded: nothing matches, and nothing crashes.
   *
   * @covers ::matching
   */
  public function testEmptyLogMatchesNothing(): void {
    $this->assertNull((new ProviderFailureLog())->matching('Node execution failed for x: boom'));
  }

  /**
   * FlowDrop's wrapper still contains the sentence, so the failure is found.
   *
   * @covers ::record
   * @covers ::matching
   */
  public function testWrappedMessageFindsTheFailure(): void {
    $log = new ProviderFailureLog();
    $failure = new AiProviderFailure('Anthropic rejected the key.', 0, NULL, ProviderCall::KIND_AUTH);
    $log->record($failure);

    $found = $log->matching('Node execution failed for agent_reason: Anthropic rejected the key.');
    $this->assertSame($failure, $found);
    $this->assertSame(ProviderCall::KIND_AUTH, $found->getKind());
  }

  /**
   * A different failure in the same request does NOT borrow the kind.
   *
   * @covers ::matching
   */
  public function testUnrelatedMessageDoesNotMatch(): void {
    $log = new ProviderFailureLog();
    $log->record(new AiProviderFailure('Anthropic rejected the key.', 0, NULL, ProviderCall::KIND_AUTH));

    $this->assertNull($log->matching('Node execution failed for save_page: Column "title" cannot be null'));
    $this->assertNull($log->matching(''));
  }

  /**
   * ProviderCall records what it raises — on the throwing path.
   *
   * This is the wiring the console depends on: a kind that is classified but
   * never recorded reaches the reader as a red node again.
   *
   * @covers ::record
   */
  public function testProviderCallRecordsWhatItRaises(): void {
    $log = new ProviderFailureLog();
    $call = new ProviderCall(new NullLogger(), FALSE, $log);

    try {
      $call->run(
        static fn() => throw new AuthenticationException('401 Unauthorized'),
        'anthropic',
        'claude-x',
        'request',
      );
      $this->fail('Expected an AiProviderFailure.');
    }
    catch (AiProviderFailure $e) {
      $this->assertSame(ProviderCall::KIND_AUTH, $e->getKind());
      $this->assertSame($e, $log->matching('Node execution failed for agent_reason: ' . $e->getMessage()));
    }
  }

}
