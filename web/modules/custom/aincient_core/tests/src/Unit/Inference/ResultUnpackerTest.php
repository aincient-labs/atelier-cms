<?php

declare(strict_types=1);

namespace Drupal\Tests\aincient_core\Unit\Inference;

use Drupal\aincient_core\Inference\MessageMapper;
use Drupal\aincient_core\Inference\ResultUnpacker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
   * The unpacker under test.
   */
  private function unpacker(): ResultUnpacker {
    return new ResultUnpacker(new MessageMapper());
  }

}
