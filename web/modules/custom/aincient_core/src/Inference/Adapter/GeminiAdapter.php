<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ImageGenerationAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Gemini\Gemini;
use Symfony\AI\Platform\Bridge\Gemini\ModelCatalog as GeminiModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Gemini (chat + vision), via `symfony/ai-gemini-platform`.
 *
 * Closes the coverage gap the first two adapters left: the model-roles form still
 * offers `gemini`, and binding it threw `ProviderConfigurationException` because
 * no adapter claimed the id.
 *
 * A GOOGLE KEY DRAWS, so this adapter says so. It used to claim chat only, and
 * image capability lived exclusively on {@see NanoBananaAdapter} — with the result
 * that an operator who connected their Google key as `gemini` got an empty image
 * picker and no hint that a SECOND provider id, backed by the very same key, was
 * the missing piece (issue #12). The capability is a property of the credential,
 * not of which of our two historical ids it was stored under, so it belongs here.
 *
 * WHY TWO CLASSES, NOT ONE SERVICE TWICE. Atelier persists two provider ids that
 * both mean "a Google API key": `gemini` for chat/vision and `nanobanana` for
 * image generation ({@see NanoBananaAdapter}). They share every byte of transport,
 * so the shared work lives here and the image id extends it. `nanobanana` stays a
 * registered provider of its own because installs have their image role bound to
 * that id and their key stored under it — retiring it is a migration, not a
 * cleanup. What is left on the subclass is therefore only its identity and the
 * fact that it must not be OFFERED for chat ({@see self::servesChat()}), which
 * would show one Google catalogue twice.
 *
 * Model enumeration goes over the wire (`GET /v1beta/models?key=…`) rather than
 * reading the bridge's bundled `ModelCatalog`, for the same reason as
 * {@see AnthropicAdapter}: onboarding treats a returned catalogue as proof the key
 * works, and a static list validates a garbage key happily. The bundled catalogue
 * is also materially behind what Google serves, which is what
 * {@see LiveModelCatalog} is for.
 */
class GeminiAdapter implements ImageGenerationAdapterInterface {

  /**
   * The default base URL, matching the bridge's own default.
   */
  protected const DEFAULT_ENDPOINT = 'https://generativelanguage.googleapis.com';

  /**
   * The only generation method this bridge speaks.
   *
   * `Gemini\ModelClient` posts to `…/models/{id}:generateContent`, so a model
   * that does not advertise that method is unreachable through this platform no
   * matter what it can do — Imagen (`predict`), Veo (`predictLongRunning`), the
   * Live models (`bidiGenerateContent`), the embedding models (`embedContent`)
   * and `aqa` (`generateAnswer`) are all filtered out by this one check.
   */
  protected const GENERATION_METHOD = 'generateContent';

  /**
   * Id fragments marking a model whose OUTPUT is not text.
   *
   * CONTRADICTS THE OBVIOUS EXPECTATION, so read this before "simplifying" it:
   * `GET /v1beta/models` reports no output modality at all. Its record for
   * `gemini-2.5-flash-image` is indistinguishable from a chat model's —
   * `supportedGenerationMethods: [generateContent, countTokens,
   * batchGenerateContent]`, same as `gemini-2.5-flash`. The generation method is
   * therefore a necessary but insufficient filter, and the id is the only
   * remaining signal Google gives us. Filtering conservatively on it beats
   * offering a text-to-speech or music model as a chat option and letting an
   * operator discover the mistake mid-conversation.
   */
  protected const NON_TEXT_OUTPUT_MARKERS = [
    'image',
    'tts',
    'native-audio',
    'lyria',
    'nano-banana',
    'embedding',
  ];

  public function __construct(
    protected readonly HttpClientInterface $httpClient,
    protected readonly \GuzzleHttp\ClientInterface $guzzle,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // Deliberately equal to the historical drupal/ai plugin id: stored role
    // bindings in aincient_core.model_roles name `gemini` and must keep
    // resolving across this migration.
    return 'gemini';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Google Gemini';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Gemini models for chat and vision, from a Google AI Studio key.';
  }

  /**
   * {@inheritdoc}
   */
  public function servesChat(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function authShape(): string {
    return self::AUTH_KEY;
  }

  /**
   * {@inheritdoc}
   */
  public function isProxy(): bool {
    // Google's ids are bare (`gemini-2.5-flash`), not vendor-namespaced. The
    // `models/` prefix the REST API wraps them in is stripped on the way in
    // ({@see self::enumerate()}) — it is an API resource path, not a namespace,
    // and the bridge builds it back itself.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    // THE 400 THIS EXISTS FOR. `Gemini\ModelClient::request()` assigns the whole
    // options array to `generationConfig` and only ever unsets its own transport
    // keys (`stream`, `tools`, `tool_config`, `server_tools`) — so every other key
    // is sent to Google verbatim and validated by Google. An OpenAI-shaped
    // `max_tokens` is therefore not ignored, it is fatal:
    //   Invalid JSON payload received. Unknown name "max_tokens" at
    //   'generation_config': Cannot find field.
    // Gemini's field is `maxOutputTokens` (GenerationConfig, v1beta). Verified
    // live: the same request 400s with `max_tokens` and answers with
    // `maxOutputTokens`.
    //
    // Only the keys we actually send are translated. Anything else is passed
    // through untouched rather than filtered, so a future option surfaces as a
    // named Google error instead of silently disappearing — a rejected request is
    // debuggable, a dropped cap is not.
    if (array_key_exists('max_tokens', $options)) {
      $options['maxOutputTokens'] = $options['max_tokens'];
      unset($options['max_tokens']);
    }
    // `temperature` needs no translation: Gemini's GenerationConfig spells it the
    // same, and `tools` is consumed by the bridge before generationConfig is
    // built. Leaving them alone is the point — this is a rename list, not a
    // whitelist.
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $credential = trim($credential);
    if ($credential === '') {
      throw new ProviderConfigurationException(sprintf(
        'No Google API key is stored for provider "%s".',
        $this->id(),
      ));
    }
    return GeminiFactory::createPlatform(
      apiKey: $credential,
      httpClient: $this->httpClient,
      // The bridge's static list is behind Google by 30-odd ids; the decorator
      // lets a model our live enumeration offered actually resolve.
      modelCatalog: new LiveModelCatalog(
        new GeminiModelCatalog(),
        static fn (string $name, array $options): Gemini => new Gemini($name, Capability::cases(), $options),
      ),
      // The platform's own name, used only for provider identity inside the
      // bridge; kept equal to our provider id so a job trail names the id an
      // operator actually bound.
      name: $this->id(),
      baseUrl: $this->baseUrl($endpoint),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    return $this->enumerate($credential, $endpoint, imageOutput: FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function listImageModels(string $credential, string $endpoint = ''): array {
    // The same round-trip as chat, sliced the other way — and disjoint from it,
    // because `image` is one of the NON_TEXT_OUTPUT_MARKERS. No model is offered
    // in both pools, so nothing here double-lists.
    return $this->enumerate($credential, $endpoint, imageOutput: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsImageEditing(): bool {
    // Gemini's image models take image parts as input as readily as text, so
    // editing a source image is the same `generateContent` call with one more
    // part. This was the `ImageToImageInterface` half of the old drupal/ai
    // provider and it still holds.
    return TRUE;
  }

  /**
   * The base URL to talk to, honouring an override.
   *
   * Gemini is AUTH_KEY, so an endpoint is never required — but the registry still
   * passes whatever an operator stored, and a proxy in front of Google is a
   * legitimate deployment.
   */
  protected function baseUrl(string $endpoint): string {
    $endpoint = trim($endpoint);
    return $endpoint !== '' ? rtrim($endpoint, '/') : static::DEFAULT_ENDPOINT;
  }

  /**
   * Enumerates the credential's models, filtered to one output modality.
   *
   * Shared by {@see self::listChatModels()} and {@see self::listImageModels()} —
   * one round-trip shape, one place where a Google response is read.
   *
   * @param string $credential
   *   The Google API key.
   * @param string $endpoint
   *   A base-URL override, or '' for Google's own host.
   * @param bool $imageOutput
   *   TRUE to keep only the image-output models, FALSE to keep only the
   *   text-output ones.
   *
   * @return array<string, string>
   *   Model id => label, or [] when the credential does not work.
   */
  protected function enumerate(string $credential, string $endpoint, bool $imageOutput): array {
    $credential = trim($credential);
    if ($credential === '') {
      return [];
    }

    try {
      // Guzzle rather than the Symfony client, matching AnthropicAdapter: this is
      // a plain Drupal-side request with no relationship to the inference
      // transport.
      $response = $this->guzzle->request('GET', $this->baseUrl($endpoint) . '/v1beta/models', [
        // Header auth, not the `?key=` query parameter Google's quickstarts show:
        // both work, but a key in a URL ends up in access logs and in any
        // exception message that quotes the request. This is also the shape the
        // bridge itself uses for inference calls.
        'headers' => ['x-goog-api-key' => $credential],
        // 1000 is the endpoint's maximum page size and comfortably above the real
        // catalogue (59 ids here), so there is no pagination loop to get wrong.
        'query' => ['pageSize' => 1000],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      // A network fault is not a bad key; say so in the log and report "no
      // models" to the caller, which surfaces as a validation failure.
      $this->logger->warning('Gemini model enumeration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    // A rejected key is a 400 here, not a 401 — hence the plain "not 200".
    if ($response->getStatusCode() !== 200) {
      return [];
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload) || !is_array($payload['models'] ?? NULL)) {
      return [];
    }

    $models = [];
    foreach ($payload['models'] as $model) {
      if (!is_array($model) || empty($model['name'])) {
        continue;
      }
      $methods = is_array($model['supportedGenerationMethods'] ?? NULL)
        ? $model['supportedGenerationMethods']
        : [];
      if (!in_array(static::GENERATION_METHOD, $methods, TRUE)) {
        continue;
      }
      // `models/gemini-2.5-flash` is a resource path; every other layer wants the
      // bare id.
      $id = (string) preg_replace('#^models/#', '', (string) $model['name']);
      // Asymmetric on purpose: "draws pictures" is a positive claim about two id
      // shapes, while "answers in text" is everything Google serves minus the
      // modalities we can name.
      $keep = $imageOutput ? $this->isImageOutput($id) : !$this->isNonTextOutput($id);
      if ($id === '' || !$keep) {
        continue;
      }
      $models[$id] = (string) ($model['displayName'] ?? $id);
    }
    return $models;
  }

  /**
   * Whether a model id names an image-output model.
   *
   * Two markers rather than one: Google's newest image models are `*-image*`,
   * while `nano-banana-pro-preview` carries the marketing name instead.
   */
  protected function isImageOutput(string $modelId): bool {
    $id = strtolower($modelId);
    return str_contains($id, 'image') || str_contains($id, 'nano-banana');
  }

  /**
   * Whether a model id names something that does not answer in text.
   */
  protected function isNonTextOutput(string $modelId): bool {
    $id = strtolower($modelId);
    foreach (static::NON_TEXT_OUTPUT_MARKERS as $marker) {
      if (str_contains($id, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
