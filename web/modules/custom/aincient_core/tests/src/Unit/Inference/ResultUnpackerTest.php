<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Inference\ResultUnpacker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Anthropic\FinishReasonMapper as AnthropicFinishReason;
use Symfony\AI\Platform\Bridge\Gemini\Gemini\FinishReasonMapper as GeminiFinishReason;
use Symfony\AI\Platform\Bridge\Generic\Completions\FinishReasonMapper as CompletionsFinishReason;
use Symfony\AI\Platform\Bridge\Ollama\FinishReasonMapper as OllamaFinishReason;
use Symfony\AI\Platform\Bridge\OpenResponses\FinishReasonMapper as ResponsesFinishReason;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

/**
 * The image half of the result union, pinned where it is easiest to see.
 *
 * The text half is exercised through the reasoner (see SymfonyAiReasonerTest) and
 * the gateway; these cases cover the byte extraction, whose failure mode is the
 * same and just as quiet: an image that arrives wrapped alongside the model's
 * chatter and is reported as "no image data" because only one arm of the union was
 * read.
 */
#[CoversClass(ResultUnpacker::class)]
final class ResultUnpackerTest extends TestCase {

  /**
   * A bare BinaryResult yields its bytes.
   */
  public function testBareBinaryResult(): void {
    self::assertSame('PNGBYTES', $this->unpacker()->firstBinary(new BinaryResult('PNGBYTES', 'image/png')));
  }

  /**
   * Bytes beside text yield too — Gemini's ordinary image answer.
   */
  public function testBinaryBesideTextInAMultiPartResult(): void {
    $result = new MultiPartResult([
      new TextResult('Here is the picture you asked for.'),
      new BinaryResult('PNGBYTES', 'image/png'),
    ]);

    self::assertSame('PNGBYTES', $this->unpacker()->firstBinary($result));
  }

  /**
   * A nested wrapper is walked, for the same reason the text side recurses.
   */
  public function testNestedMultiPartResult(): void {
    $result = new MultiPartResult([
      new TextResult('Working on it.'),
      new MultiPartResult([new BinaryResult('PNGBYTES', 'image/png')]),
    ]);

    self::assertSame('PNGBYTES', $this->unpacker()->firstBinary($result));
  }

  /**
   * Text-only, and empty bytes, are both "no image" — NULL, never ''.
   *
   * A caller distinguishes NULL ("nothing came back, say so") from bytes it can
   * save; returning '' would sail into the media pipeline as a zero-byte image.
   */
  public function testNoUsableBytesIsNull(): void {
    $unpacker = $this->unpacker();

    self::assertNull($unpacker->firstBinary(new TextResult('I would rather not.')));
    self::assertNull($unpacker->firstBinary(new BinaryResult('', 'image/png')));
    self::assertNull($unpacker->firstBinary(new MultiPartResult([new TextResult('No picture here.')])));
  }

  /**
   * The first image wins when a model returns several.
   *
   * Not an arbitrary pick: the callers mint ONE media item per request, and
   * "several images" has no defined product meaning yet — so taking the first is
   * the documented contract rather than a coincidence of iteration order.
   */
  public function testFirstImageWins(): void {
    $result = new MultiPartResult([
      new BinaryResult('FIRST', 'image/png'),
      new BinaryResult('SECOND', 'image/png'),
    ]);

    self::assertSame('FIRST', $this->unpacker()->firstBinary($result));
  }

  /**
   * A tool-call result carries no image, and asking must not throw.
   *
   * `ToolCallResult::getContent()` returns an array of ToolCall objects, so a walk
   * that assumed a string would blow up on a shape no image caller expects but
   * cannot rule out.
   */
  public function testToolCallResultCarriesNoImage(): void {
    $result = new ToolCallResult([new ToolCall('toolu_01', 'aincient_list_pages')]);

    self::assertNull($this->unpacker()->firstBinary($result));
  }

  /**
   * Every provider's spelling of "I hit the token cap" reads as a truncation.
   *
   * Mapped through the REAL bridge mappers, not through hand-written strings:
   * what has to hold is that the wording each provider actually sends arrives as
   * the normalised LENGTH case, and only the bridge knows its provider's
   * vocabulary. A test that asserted our own table would keep passing after a
   * bridge renamed something.
   */
  #[DataProvider('lengthSpellings')]
  public function testTokenCapTruncationIsRecognised(?FinishReason $reason, string $expectedRaw): void {
    $result = new TextResult('Here are the three sections you asked for. The first one');
    $result->getMetadata()->add('finish_reason', $reason);

    self::assertSame($expectedRaw, ResultUnpacker::truncatedAtTokenCap($result));
  }

  /**
   * How each bridge spells a token-cap truncation.
   *
   * @return iterable<string, array{0: \Symfony\AI\Platform\FinishReason\FinishReason|null, 1: string}>
   *   Reason, and the raw wording expected back for the log.
   */
  public static function lengthSpellings(): iterable {
    yield 'anthropic max_tokens' => [AnthropicFinishReason::map('max_tokens'), 'max_tokens'];
    // Our `openai` rides the Responses API, whose cap is max_output_tokens.
    yield 'openai responses max_output_tokens' => [ResponsesFinishReason::map('max_output_tokens'), 'max_output_tokens'];
    // Chat-completions: mistral, deep-seek and everything behind
    // `openai_compatible` all convert through this one bridge.
    yield 'chat-completions length' => [CompletionsFinishReason::map('length'), 'length'];
    yield 'gemini MAX_TOKENS' => [GeminiFinishReason::map('MAX_TOKENS'), 'MAX_TOKENS'];
    yield 'ollama length' => [OllamaFinishReason::map('length'), 'length'];
  }

  /**
   * Anything that is not a reported truncation is NULL — the false-positive guard.
   *
   * The regression to fear is the opposite of the bug: a turn that finished fine
   * being raised as an error. So an unreported reason, a reason the bridge could
   * not normalise, a clean stop, a stop sequence and a tool call are all "not
   * truncated", and the length of the text is never evidence of anything.
   */
  #[DataProvider('nonTruncations')]
  public function testEverythingElseIsNotATruncation(?FinishReason $reason): void {
    // Deliberately a very long answer that also stops mid-sentence: the two
    // things a heuristic would have been tempted to read as a truncation.
    $result = new TextResult(str_repeat('a plausible looking sentence that just stops ', 40));
    if ($reason !== NULL) {
      $result->getMetadata()->add('finish_reason', $reason);
    }

    self::assertNull(ResultUnpacker::truncatedAtTokenCap($result));
  }

  /**
   * Finish reasons that must NOT be read as a token-cap truncation.
   *
   * @return iterable<string, array{0: \Symfony\AI\Platform\FinishReason\FinishReason|null}>
   *   The reason, or NULL for "the provider reported none".
   */
  public static function nonTruncations(): iterable {
    yield 'unreported' => [NULL];
    yield 'anthropic end_turn' => [AnthropicFinishReason::map('end_turn')];
    yield 'anthropic tool_use' => [AnthropicFinishReason::map('tool_use')];
    yield 'anthropic stop_sequence' => [AnthropicFinishReason::map('stop_sequence')];
    yield 'chat-completions stop' => [CompletionsFinishReason::map('stop')];
    yield 'chat-completions tool_calls' => [CompletionsFinishReason::map('tool_calls')];
    yield 'chat-completions content_filter' => [CompletionsFinishReason::map('content_filter')];
    yield 'gemini STOP' => [GeminiFinishReason::map('STOP')];
    yield 'responses completed' => [ResponsesFinishReason::map('completed')];
    // Wording no bridge recognises normalises to OTHER, which is "we cannot
    // tell" — and "we cannot tell" must behave exactly like a normal answer.
    yield 'unrecognised wording' => [CompletionsFinishReason::map('something_new')];
  }

  /**
   * A result that carries no metadata at all does not blow up.
   *
   * Our own scripted platform and any future non-metadata result are this shape,
   * and the check runs on EVERY provider call — so it must be incapable of
   * turning a good answer into an exception.
   */
  public function testAResultWithoutMetadataIsSafe(): void {
    $bare = new class {

      /**
       * A result-shaped object that files nothing.
       */
      public function getContent(): string {
        return 'Fine.';
      }

    };

    self::assertNull(ResultUnpacker::truncatedAtTokenCap($bare));
    self::assertNull(ResultUnpacker::finishReason($bare));
  }

  /**
   * The unpacker under test.
   */
  private function unpacker(): ResultUnpacker {
    return new ResultUnpacker(new MessageMapper());
  }

}
