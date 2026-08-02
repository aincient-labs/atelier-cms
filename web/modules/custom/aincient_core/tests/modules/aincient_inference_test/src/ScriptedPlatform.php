<?php

declare(strict_types=1);

namespace Drupal\aincient_inference_test;

use Drupal\Core\State\StateInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * The scripted platform: answers from State, records what it was asked.
 *
 * It answers an IMAGE model with a `MultiPartResult` of chatter plus a
 * `BinaryResult` — deliberately the awkward shape, not a bare BinaryResult,
 * because that is what Gemini actually returns and reading only one arm of that
 * union is the failure this whole seam is careful about. A kernel test that
 * passes against this shape has proved the product path, not a convenience.
 */
final class ScriptedPlatform implements PlatformInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult {
    $modelId = $model instanceof Model ? $model->getName() : $model;
    $this->state->set(ScriptedAdapter::LAST_CALL_KEY, [
      'model' => $modelId,
      'options' => $options,
      // Recorded as a count rather than the objects themselves: State is
      // serialised, and what a test needs to know is that the image part was
      // attached at all.
      'parts' => $this->describeParts($input),
    ]);

    if ($this->state->get(ScriptedAdapter::FAIL_KEY, FALSE)) {
      throw new \RuntimeException('Scripted provider failure.');
    }

    $result = str_contains($modelId, 'image')
      ? new MultiPartResult([
        new TextResult('Here is the picture you asked for.'),
        new BinaryResult((string) $this->state->get(ScriptedAdapter::IMAGE_KEY, 'scripted-png-bytes'), 'image/png'),
      ])
      : new TextResult((string) $this->state->get(ScriptedAdapter::TEXT_KEY, 'Scripted answer.'));

    // Filed under the same metadata key a real bridge's TokenUsageExtractor uses,
    // on the result object itself — PlainConverter reports no extractor, so
    // DeferredResult would otherwise never attach one. This is what lets a kernel
    // test drive the whole metering seam (seam → recorder → aincient_ai_usage row)
    // instead of asserting against a mocked recorder.
    $usage = $this->state->get(ScriptedAdapter::USAGE_KEY);
    if (is_array($usage)) {
      $result->getMetadata()->add('token_usage', new TokenUsage(
        promptTokens: $usage['prompt'] ?? NULL,
        completionTokens: $usage['completion'] ?? NULL,
        thinkingTokens: $usage['thinking'] ?? NULL,
        cacheCreationTokens: $usage['cache_creation'] ?? NULL,
        cacheReadTokens: $usage['cache_read'] ?? NULL,
        totalTokens: $usage['total'] ?? NULL,
      ));
    }

    return new DeferredResult(new PlainConverter($result), new InMemoryRawResult());
  }

  /**
   * {@inheritdoc}
   */
  public function getModelCatalog(): ModelCatalogInterface {
    throw new \LogicException('The scripted platform resolves no models.');
  }

  /**
   * A serialisable description of the content parts that were sent.
   *
   * @return array<int, string>
   *   One short class-name-ish label per part of the last user message.
   */
  private function describeParts(array|string|object $input): array {
    if (!is_object($input) || !method_exists($input, 'getMessages')) {
      return [];
    }
    $parts = [];
    foreach ($input->getMessages() as $message) {
      if (!method_exists($message, 'getContent')) {
        continue;
      }
      $content = $message->getContent();
      foreach (is_array($content) ? $content : [$content] as $part) {
        $parts[] = is_object($part) ? (new \ReflectionClass($part))->getShortName() : gettype($part);
      }
    }
    return $parts;
  }

}
