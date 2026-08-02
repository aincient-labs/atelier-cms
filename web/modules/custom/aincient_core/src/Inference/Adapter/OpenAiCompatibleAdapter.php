<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Any OpenAI-compatible endpoint, via `symfony/ai-generic-platform`.
 *
 * One adapter covering DeepSeek, Groq, Cerebras, vLLM, LM Studio, a LiteLLM
 * proxy — anything that speaks the OpenAI chat-completions shape. This is the
 * capability the contrib `ai_provider_openai_compatible` module was evaluated
 * for and rejected over: it required every model to be hand-declared in config
 * with capability checkboxes, offered two DeepSeek model ids that no longer
 * exist, and validated credentials by reading that same local config — so any
 * non-empty string "connected" successfully.
 *
 * Here the catalogue comes from `GET {endpoint}/models` and the credential is
 * proven by that call. The bridge's `FallbackModelCatalog` accepts an
 * unregistered model id and routes it by naming convention, so there is no
 * per-model declaration surface at all.
 *
 * CAPABILITY ASSUMPTION. We do not ask the operator to tick capability boxes —
 * a wrong tick is a runtime failure with no feedback. Atelier's agent loop needs
 * tool calling and JSON output; a service that cannot do both is not usable for
 * this product regardless of what a checkbox claims. So we assume that
 * conservative profile and let a real turn be the test.
 */
final class OpenAiCompatibleAdapter implements ProviderAdapterInterface {

  /**
   * The chat-completions path appended to the operator's base URL.
   *
   * Most compatible services mount the OpenAI surface under `/v1`. DeepSeek
   * serves BOTH `/chat/completions` and `/v1/chat/completions`, which is exactly
   * the sort of difference that makes a bare base URL a footgun — so we always
   * send the `/v1`-prefixed path and normalise the operator's input to match
   * ({@see self::normaliseEndpoint()}).
   */
  private const COMPLETIONS_PATH = '/v1/chat/completions';

  /**
   * The embeddings path, for the same reason.
   */
  private const EMBEDDINGS_PATH = '/v1/embeddings';

  /**
   * Id fragments that mark something other than a text-answering chat model.
   *
   * The union of what the sibling adapters each name for their own vendor
   * ({@see OpenAiAdapter}, {@see MistralAdapter}), because this adapter's
   * catalogue is not one vendor's — a LiteLLM or OpenRouter endpoint re-serves
   * OpenAI, Gemini, Anthropic and Mistral from a single `/v1/models`, so every
   * vendor's non-chat modalities arrive in the same list. Measured against the
   * Forge demo's proxy, which offered text-to-speech, native-audio, video,
   * music, robotics and computer-use models as chat options.
   *
   * No capability field exists to read here — the OpenAI list shape carries an
   * id, an owner and a timestamp — so this heuristic is the only filter
   * available, exactly as for {@see OpenAiAdapter}.
   */
  private const NON_CHAT_MARKERS = [
    'embed',
    'whisper',
    'tts',
    'audio',
    'transcribe',
    'speech',
    'dall-e',
    'image',
    'sora',
    'veo',
    'lyria',
    'moderation',
    'guard',
    'rerank',
    'realtime',
    'live',
    'robotics',
    'computer-use',
    'ocr',
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
    return 'openai_compatible';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'OpenAI-compatible endpoint';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    // Names the vendors deliberately. This adapter is the working path for
    // OpenAI, Mistral, OpenRouter and LiteLLM, whose `drupal/ai` provider modules
    // no longer appear in the inventory — an operator looking for one of them by
    // name has to be able to find it here, or "my provider vanished" is the only
    // available reading.
    return 'Any service that speaks the OpenAI chat-completions API — OpenAI, Mistral, DeepSeek, Groq, OpenRouter, a LiteLLM proxy, vLLM or LM Studio. Needs a base URL as well as a key.';
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
    return self::AUTH_KEY_ENDPOINT;
  }

  /**
   * {@inheritdoc}
   */
  public function isProxy(): bool {
    // The ids are whatever the endpoint serves, under the upstream vendor's own
    // naming (`deepseek-v4-pro`), NOT vendor-namespaced like OpenRouter's
    // `anthropic/claude-…`. So the proxy-pass model matching does not apply.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    // `max_tokens` and `temperature` ARE the OpenAI chat-completions field names,
    // and speaking that shape is the one thing every service behind this adapter
    // has in common. Identity, therefore.
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $base = $this->normaliseEndpoint($endpoint);
    if ($base === '') {
      throw new ProviderConfigurationException(
        'An OpenAI-compatible provider needs a base URL (for example https://api.deepseek.com).'
      );
    }
    $credential = trim($credential);
    if ($credential === '') {
      throw new ProviderConfigurationException('No API key is stored for the OpenAI-compatible endpoint.');
    }

    return GenericFactory::createPlatform(
      baseUrl: $base,
      apiKey: $credential,
      httpClient: $this->httpClient,
      completionsPath: self::COMPLETIONS_PATH,
      embeddingsPath: self::EMBEDDINGS_PATH,
      name: $this->id(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    $base = $this->normaliseEndpoint($endpoint);
    $credential = trim($credential);
    if ($base === '' || $credential === '') {
      return [];
    }

    try {
      $response = $this->guzzle->request('GET', $base . '/v1/models', [
        'headers' => ['Authorization' => 'Bearer ' . $credential],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Model enumeration failed for @base: @message', [
        '@base' => $base,
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
      if (!is_array($model) || empty($model['id'])) {
        continue;
      }
      $id = trim((string) $model['id']);
      if ($id === '' || $this->isModelGroup($id) || $this->isNonChat($id)) {
        continue;
      }
      $models[$id] = $id;
    }
    // Sorted, because this pool's order decides a role when nothing else does:
    // an unresolved role ends at "the first model in the pool"
    // ({@see \Drupal\aincient_core\ModelPresetResolver}), and leaving that to
    // the endpoint's own response order makes the binding differ between two
    // installs pointed at the same proxy.
    ksort($models);
    return $models;
  }

  /**
   * Whether an id names a model GROUP rather than a model.
   *
   * LiteLLM publishes a wildcard group per configured vendor — `openai/*`,
   * `anthropic/*` — and serves them from `/v1/models` in a record shaped
   * exactly like a real model's. They are routing patterns: sending one as a
   * `model` is an error, not a call. Left in the pool they are indistinguishable
   * from models in the picker, and — being ids like any other — one can win a
   * role outright, which is what happened on the Forge demo, where all four
   * roles resolved to `openai/*` and no turn could ever have completed.
   */
  private function isModelGroup(string $modelId): bool {
    return str_contains($modelId, '*');
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

  /**
   * Strips a trailing slash and any `/v1` the operator already typed.
   *
   * The paths we pass to the bridge are `/v1`-prefixed, so an operator who
   * pastes `https://api.deepseek.com/v1` (a perfectly reasonable reading of
   * every vendor's docs) would otherwise produce `/v1/v1/chat/completions`. This
   * is the endpoint footgun made harmless.
   */
  private function normaliseEndpoint(string $endpoint): string {
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
      return '';
    }
    if (!preg_match('#^https?://#i', $endpoint)) {
      $endpoint = 'https://' . $endpoint;
    }
    $endpoint = rtrim($endpoint, '/');
    return preg_replace('#/v1$#', '', $endpoint) ?? $endpoint;
  }

}
