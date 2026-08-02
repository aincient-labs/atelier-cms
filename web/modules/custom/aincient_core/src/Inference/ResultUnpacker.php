<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * The ONE place that knows what shapes a `symfony/ai` result can arrive in.
 *
 * THE SHAPE THAT BIT US. A model answering with a sentence *and* a tool call in
 * the same turn returns neither a TextResult nor a ToolCallResult, but a
 * `MultiPartResult` wrapping both. Our shipped system prompts ask for exactly
 * that ("Before a tool call, write exactly ONE short plain sentence saying what
 * you are doing"), so it is the ORDINARY case in production, not an edge one.
 *
 * Handling only the two single-part types meant an `instanceof ToolCallResult`
 * miss followed by a `getContent()` that returns an array rather than a string —
 * so the text AND the tool call were both dropped, and the turn completed with
 * `status: success` on every node and an empty message on screen. That is the
 * DECISIONS 0278/0279 failure mode reproduced exactly: no visible failure, so
 * nothing to debug from.
 *
 * WHY IT IS ITS OWN CLASS. The same union now has a second reader: an image turn
 * ({@see AiGateway::generateImage()}) gets its bytes as a `BinaryResult` which
 * Gemini wraps in a MultiPartResult alongside the model's chatter — the identical
 * trap, one layer over. Reproducing the walk there would mean two places that
 * each know half of the union, which is how the first silence happened. So the
 * walk lives here once, in both directions, and each caller says which part of
 * the result it came for.
 *
 * It reads results only. It never invokes, never resolves a role, and knows
 * nothing about providers.
 */
final class ResultUnpacker {

  public function __construct(
    private readonly MessageMapper $messages,
  ) {}

  /**
   * Pulls the text and the tool calls out of whatever shape came back.
   *
   * Returns both halves instead of branching because there is no shape where
   * picking one is right — see the class docblock for the outage that proves it.
   *
   * Note the predecessor kept the tool call and dropped only the preamble text,
   * which is why no agent ever appeared broken before. Keeping the text is
   * therefore a small improvement rather than just a repair — the sentence the
   * prompt asks for finally survives the trip.
   *
   * @param object $result
   *   The result a platform yielded.
   *
   * @return array{0: string, 1: array<int, array<string, mixed>>}
   *   The text (possibly '') and the tool calls (possibly []).
   */
  public function textAndToolCalls(object $result): array {
    // asToolCalls() THROWS UnexpectedResultTypeException on a non-tool result
    // rather than returning [], so the type is always checked, never guessed.
    if ($result instanceof ToolCallResult) {
      return ['', $this->messages->fromToolCalls($result->getContent())];
    }

    if ($result instanceof MultiPartResult) {
      $texts = [];
      $calls = [];
      foreach ($result->getContent() as $part) {
        if (!is_object($part)) {
          continue;
        }
        // Recurse: a nested MultiPartResult is not something any bridge emits
        // today, but treating one part like the whole result costs nothing and
        // keeps a future bridge from re-opening this exact silence.
        [$partText, $partCalls] = $this->textAndToolCalls($part);
        if ($partText !== '') {
          $texts[] = $partText;
        }
        $calls = array_merge($calls, $partCalls);
      }
      return [trim(implode("\n\n", $texts)), $calls];
    }

    return [$this->textOf($result), []];
  }

  /**
   * Just the text, for a caller that declared no tools.
   *
   * A one-shot call ({@see AiGateway}) cannot receive a tool call it never
   * offered, so it wants the first element and nothing else — but it goes through
   * the same walk, so a MultiPartResult answer is read correctly there too rather
   * than coming back as ''.
   */
  public function text(object $result): string {
    return trim($this->textAndToolCalls($result)[0]);
  }

  /**
   * The first image's raw bytes in a result, or NULL when there are none.
   *
   * Gemini returns a generated image as an `inlineData` part, which its bridge
   * converts to a `BinaryResult` — and when the model also says something about
   * the picture (which it usually does), that BinaryResult arrives as one part of
   * a `MultiPartResult`. Checking only for a bare BinaryResult would therefore
   * drop the image on the ordinary success path and report "the provider returned
   * no image data", which is the text-side outage wearing a different hat.
   *
   * ChoiceResult is deliberately NOT walked: it appears only when a request asks
   * for several candidates, which no call of ours does, and inventing a "pick one
   * of the candidates" rule here would be guessing on behalf of a caller that
   * never asked the question.
   *
   * @param object $result
   *   The result a platform yielded.
   *
   * @return string|null
   *   The raw bytes, or NULL when the result carries no image.
   */
  public function firstBinary(object $result): ?string {
    if ($result instanceof BinaryResult) {
      $binary = $result->getContent();
      return $binary !== '' ? $binary : NULL;
    }

    if ($result instanceof MultiPartResult) {
      foreach ($result->getContent() as $part) {
        if (!is_object($part)) {
          continue;
        }
        // Recurse for the same reason the text walk does.
        $binary = $this->firstBinary($part);
        if ($binary !== NULL) {
          return $binary;
        }
      }
    }

    return NULL;
  }

  /**
   * The total tokens a turn cost, or 0 when the provider did not report any.
   *
   * A REAL number, not an estimate. `symfony/ai` runs each bridge's
   * TokenUsageExtractor over the raw response during result conversion and files
   * the outcome under the `token_usage` metadata key, so this is the provider's
   * own accounting — the reason the predecessor had to guess (four `method_exists`
   * branches over a shape it hoped for) does not exist here.
   *
   * The prompt+completion sum is arithmetic, not a fallback guess: Anthropic
   * reports `input_tokens`/`output_tokens` and NO total at all, so a bare
   * getTotalTokens() would report 0 for every Anthropic turn — which is exactly
   * the kind of plausible-looking zero that makes a metering number worthless.
   * A provider that reports nothing still yields 0, and 0 here means "not
   * reported", never "free".
   *
   * @param object $result
   *   The result a platform yielded.
   *
   * @return int
   *   The token count, or 0 when the provider reported none.
   */
  public function totalTokens(object $result): int {
    $usage = $this->tokenUsage($result);
    if ($usage === NULL) {
      return 0;
    }

    $total = $usage->getTotalTokens();
    if ($total !== NULL) {
      return $total;
    }
    return (int) $usage->getPromptTokens() + (int) $usage->getCompletionTokens();
  }

  /**
   * The provider's own token accounting, or NULL when it reported none.
   *
   * NULL and a zero-filled TokenUsage are DIFFERENT answers, and keeping them
   * apart is the whole reason this returns the object rather than an int tuple.
   * NULL means the metadata key was absent — nobody counted (a streamed call, a
   * bridge with no extractor, our own scripted platform). A present usage whose
   * fields are 0 means the provider counted and said zero. A recorder that
   * flattened both to 0 would write the same plausible-looking row for "free
   * call" and "we never looked", which is precisely the under-reporting that made
   * agent turns invisible in the metering dashboard after the migration.
   *
   * @param object $result
   *   The result a platform yielded.
   *
   * @return \Symfony\AI\Platform\TokenUsage\TokenUsageInterface|null
   *   The usage the bridge extracted, or NULL when none was filed.
   */
  public function tokenUsage(object $result): ?TokenUsageInterface {
    if (!method_exists($result, 'getMetadata')) {
      return NULL;
    }
    $usage = $result->getMetadata()->get('token_usage');
    return $usage instanceof TokenUsageInterface ? $usage : NULL;
  }

  /**
   * Best-effort text extraction from a non-tool-call, non-multipart result.
   */
  private function textOf(object $result): string {
    if (method_exists($result, 'getContent')) {
      $content = $result->getContent();
      if (is_string($content)) {
        return $content;
      }
    }
    return '';
  }

}
