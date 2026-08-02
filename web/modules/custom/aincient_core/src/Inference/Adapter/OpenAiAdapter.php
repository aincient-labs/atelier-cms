<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog as OpenAiModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI, via `symfony/ai-open-ai-platform` — by name, with one field.
 *
 * WHY THIS EXISTS WHEN `OpenAiCompatibleAdapter` ALREADY REACHES OPENAI. It
 * reaches it the way a generic client reaches it: through the chat-completions
 * shape, with the operator supplying `https://api.openai.com` as a "base URL"
 * they should never have to know. Two things were wrong with that as the only
 * path. It could not be OFFERED — a key-plus-endpoint provider needs two fields
 * and the onboarding wizard renders one, so OpenAI was absent from the picker
 * while its logo sat unused in the console's `PROVIDER_BRAND` map. And the
 * provider id it stored was `openai_compatible`, so an install that bound
 * `openai:gpt-…` before the `drupal/ai` teardown never resolved again.
 *
 * This adapter is `openai`, authenticates with a key alone, and rides OpenAI's
 * own bridge — which means the **Responses API** (`/v1/responses`), not chat
 * completions. That is the vendor's current surface and the one their newer
 * models are documented against; the generic adapter remains the right answer
 * for everything that merely *speaks* OpenAI's older shape.
 *
 * ENUMERATION IS LIVE AND FILTERED BY ID, WHICH IS THE HONEST LIMIT.
 * `GET /v1/models` proves the key — that is the conformance rule — but unlike
 * Ollama's `/api/show` or Mistral's own catalogue it returns NO capability
 * metadata at all: an id, a timestamp, an owner. So chat-worthiness has to be
 * read off the id, and the filter is deliberately shaped the way
 * {@see GeminiAdapter} shapes its own: everything OpenAI serves, MINUS the
 * modalities we can name. An unrecognised new model is therefore offered rather
 * than hidden — the failure we prefer, because a model wrongly offered produces
 * a clear API error while a model wrongly hidden is invisible and unreportable.
 */
final class OpenAiAdapter implements ProviderAdapterInterface {

  /**
   * The default base URL, matching the bridge's own default.
   */
  private const DEFAULT_ENDPOINT = 'https://api.openai.com';

  /**
   * Id fragments that mark something other than a text-answering chat model.
   *
   * Every one of these is a modality OpenAI serves from the same `/v1/models`
   * list and that cannot hold Atelier's side of a conversation: embeddings,
   * speech in and out, pictures, video, moderation, and the realtime/session
   * transports that are not a request-response turn. `instruct` and the bare
   * `davinci-002`/`babbage-002` completions models are named for the same reason
   * — they answer, but not to a MESSAGE LIST, so the Responses API turn our
   * reasoner builds does not apply to them.
   */
  private const NON_CHAT_MARKERS = [
    'embedding',
    'whisper',
    'tts',
    'audio',
    'transcribe',
    'speech',
    'dall-e',
    'image',
    'sora',
    'moderation',
    'realtime',
    'instruct',
    'davinci',
    'babbage',
  ];

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly \GuzzleHttp\ClientInterface $guzzle,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // The historical drupal/ai plugin id. Role bindings written before the
    // teardown name `openai`, and this is what makes them resolve again rather
    // than needing a rebind an operator was never told about.
    return 'openai';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'OpenAI';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'GPT models for chat, vision and tool use, over OpenAI’s Responses API. One API key from platform.openai.com.';
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
    // `gpt-5.6`, not `openai/gpt-5.6` — OpenAI serves its own models under its
    // own bare names.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    // THE 400 THIS EXISTS FOR. The Responses API is not chat completions: it
    // caps output with `max_output_tokens`, and rejects unknown top-level
    // parameters outright rather than ignoring them —
    // "Unknown parameter: 'max_tokens'".
    // The bridge cannot save us here, because `OpenResponses\ModelClient` merges
    // the options array into the request body verbatim. So the rename is ours,
    // and it is a rename (not an alias): leaving `max_tokens` beside the new key
    // would still 400.
    if (array_key_exists('max_tokens', $options)) {
      $options['max_output_tokens'] = $options['max_tokens'];
      unset($options['max_tokens']);
    }
    // `temperature` keeps its name on the Responses API, and `tools` is consumed
    // by the bridge's contract before the body is built. This is a rename list,
    // not a whitelist — anything else passes through so a future option produces
    // a named OpenAI error rather than silently vanishing.
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $credential = trim($credential);
    if ($credential === '') {
      throw new ProviderConfigurationException('No OpenAI API key is stored.');
    }

    return OpenAiFactory::createPlatform(
      apiKey: $credential,
      httpClient: $this->httpClient,
      // OpenAI ships new ids faster than the bridge's static catalogue is
      // regenerated, and our picker offers whatever the account can see. Without
      // the decorator, binding this month's model fails as ModelNotFoundException.
      modelCatalog: new LiveModelCatalog(
        new OpenAiModelCatalog(),
        static fn (string $name, array $options): Gpt => new Gpt($name, Capability::cases(), $options),
      ),
      name: $this->id(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    $credential = trim($credential);
    if ($credential === '') {
      return [];
    }
    $base = $endpoint !== '' ? rtrim($endpoint, '/') : self::DEFAULT_ENDPOINT;

    try {
      $response = $this->guzzle->request('GET', $base . '/v1/models', [
        'headers' => ['Authorization' => 'Bearer ' . $credential],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      // A network fault is not a bad key; say so in the log and report "no
      // models", which the caller surfaces as a validation failure.
      $this->logger->warning('OpenAI model enumeration failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      return [];
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($payload) || !is_array($payload['data'] ?? NULL)) {
      return [];
    }

    $models = [];
    foreach ($payload['data'] as $model) {
      $id = is_array($model) ? trim((string) ($model['id'] ?? '')) : '';
      if ($id === '' || $this->isNonChat($id)) {
        continue;
      }
      // No display name to use: OpenAI's record carries the id, when it was
      // created, and who owns it. The id IS the name every operator knows it by.
      $models[$id] = $id;
    }
    ksort($models);
    return $models;
  }

  /**
   * Whether an id names a model that cannot hold a text conversation.
   */
  private function isNonChat(string $modelId): bool {
    $id = strtolower($modelId);
    foreach (self::NON_CHAT_MARKERS as $marker) {
      if (str_contains($id, $marker)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
