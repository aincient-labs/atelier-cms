<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * A bridge's own catalogue, plus any model the vendor will actually serve.
 *
 * THE TRAP THIS CLOSES. Every `symfony/ai` bridge ships a hand-maintained static
 * model list, and every one of them is behind the vendor by the time a release
 * reaches us — measured on this install, the Gemini bridge knew 27 ids while
 * `GET /v1beta/models` returned 59, `gemini-pro-latest` among the missing. Our
 * adapters enumerate LIVE on purpose ({@see ProviderAdapterInterface::listChatModels()}
 * makes a real round-trip the proof a credential works), so without this decorator
 * an operator can bind a model the picker offered and get `ModelNotFoundException`
 * on the first turn — a failure that reads like a broken key and is actually a
 * stale vendor list.
 *
 * WHY IT IS GENERIC AND NOT ONE CLASS PER PROVIDER. This began as
 * `GeminiLiveModelCatalog`. The moment a second and third provider needed the same
 * decorator the choice was three copies of the same fifteen lines or one class
 * that takes the model factory as an argument — and the option-parsing below is
 * exactly the sort of detail that gets fixed in one copy and not the others.
 *
 * `FallbackModelCatalog` from the platform package cannot do this job: it returns
 * a plain {@see Model}, and every bridge's model client gates on its OWN subclass
 * (`Gpt`, `Gemini`, `Mistral`), so the platform would resolve the model and then
 * find no client for it — a stranger failure than the one being fixed. Hence the
 * `$make` callback: only the bridge knows what class its client will accept.
 *
 * The static catalogue is asked FIRST, so a known model keeps its real capability
 * metadata; only unknown ids get the optimistic all-capabilities answer. Nothing
 * in Atelier reads those capabilities today (we let the real turn be the test),
 * so optimism costs a clear API error instead of a silent wrong route.
 */
final class LiveModelCatalog implements ModelCatalogInterface {

  /**
   * @param \Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface $inner
   *   The bridge's own static catalogue, asked first.
   * @param callable(string, array<string, mixed>): \Symfony\AI\Platform\Model $make
   *   Builds the bridge's model class for an id the static catalogue does not
   *   know, given the bare name and any parsed options.
   */
  public function __construct(
    private readonly ModelCatalogInterface $inner,
    private readonly \Closure $make,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getModel(string $modelName): Model {
    try {
      return $this->inner->getModel($modelName);
    }
    catch (ModelNotFoundException) {
      // The platform's own catalogues accept `id?option=value` and split it into
      // model options; honour that here too, or an id carrying options would end
      // up in the request URL verbatim.
      $name = $modelName;
      $options = [];
      if (str_contains($modelName, '?')) {
        [$name, $query] = explode('?', $modelName, 2);
        parse_str($query, $options);
      }
      return ($this->make)($name, $options);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    return $this->inner->getModels();
  }

}
