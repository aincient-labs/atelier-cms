<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Bridge\Mistral\Mistral;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralModelCatalog;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mistral, via `symfony/ai-mistral-platform`.
 *
 * `mistral` was the id this project spent the longest offering and never being
 * able to serve: `getProvidersForOperationType('chat')` listed it because
 * `drupal/ai_provider_mistral` was installed, the wizard accepted a working key,
 * and the next turn threw. It is the example named in
 * {@see \Drupal\aincient_core\Inference\ProviderInventory}'s docblock, and in the
 * kernel test that pins the picker's honesty. This adapter is what finally makes
 * the offer true — the same way Ollama's did: by existing.
 *
 * IT IS THE MOST HONEST CATALOGUE OF THE SET, BECAUSE MISTRAL SAYS SO ITSELF.
 * `GET /v1/models` returns a per-model capability record — `completion_chat`,
 * `function_calling`, `vision` — which is what OpenAI's list conspicuously lacks
 * and what Ollama makes us fetch one model at a time. So the filter here is a
 * READ, not a heuristic: a model is offered for chat because Mistral says it can
 * chat, and a model without `function_calling` carries that in its label rather
 * than being hidden (the rule set by {@see OllamaAdapter} — Atelier's agent loop
 * needs tools, a vision or plain-chat role does not, and the constraint belongs
 * in front of the operator).
 *
 * The fallback matters as much as the happy path: an account whose response
 * carries no capability block at all falls back to excluding the modalities we
 * can name, rather than to an empty picker. A provider that answered with models
 * must never look like a provider that refused the key.
 */
final class MistralAdapter implements ProviderAdapterInterface {

  /**
   * The default base URL, matching the bridge's own default.
   */
  private const DEFAULT_ENDPOINT = 'https://api.mistral.ai';

  /**
   * Id fragments that mark a non-chat model, used ONLY without a capability block.
   *
   * Mistral's own capability record is the source of truth; this is the degraded
   * reading for a response that does not carry one — embeddings, the OCR models,
   * the moderation classifiers, and the fill-in-the-middle code models, none of
   * which answer a message list.
   */
  private const NON_CHAT_MARKERS = ['embed', 'ocr', 'moderation', 'codestral-mamba', 'fim'];

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly \GuzzleHttp\ClientInterface $guzzle,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // The historical drupal/ai plugin id, so a role binding written while
    // ai_provider_mistral was installed resolves again.
    return 'mistral';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Mistral';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Mistral’s open-weight and commercial models for chat, vision and tool use — European infrastructure, one API key from console.mistral.ai.';
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
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    // Mistral serves `/v1/chat/completions` and spells the cap `max_tokens` and
    // the sampler `temperature` — the neutral names our callers already use. The
    // identity function, deliberately: this is the dialect seam, and a provider
    // that already speaks our vocabulary must be visibly a no-op rather than
    // absent from the seam altogether.
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $credential = trim($credential);
    if ($credential === '') {
      throw new ProviderConfigurationException('No Mistral API key is stored.');
    }

    return MistralFactory::createPlatform(
      apiKey: $credential,
      httpClient: $this->httpClient,
      modelCatalog: new LiveModelCatalog(
        new MistralModelCatalog(),
        static fn (string $name, array $options): Mistral => new Mistral($name, Capability::cases(), $options),
      ),
      name: $this->id(),
      baseUrl: $this->baseUrl($endpoint),
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

    try {
      $response = $this->guzzle->request('GET', $this->baseUrl($endpoint) . '/v1/models', [
        'headers' => ['Authorization' => 'Bearer ' . $credential],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Mistral model enumeration failed: @message', [
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
      if (!is_array($model)) {
        continue;
      }
      $id = trim((string) ($model['id'] ?? ''));
      if ($id === '') {
        continue;
      }
      $capabilities = is_array($model['capabilities'] ?? NULL) ? $model['capabilities'] : NULL;

      if ($capabilities === NULL ? $this->isNonChat($id) : empty($capabilities['completion_chat'])) {
        continue;
      }

      // Mistral's own display name where it has one; ids like
      // `mistral-large-2411` are what an operator recognises otherwise.
      $label = trim((string) ($model['name'] ?? '')) ?: $id;
      // Only claimable when Mistral actually answered the question. An absent
      // capability block means we do not know, and inventing "no tool calling"
      // would libel a model that calls tools perfectly well.
      if ($capabilities !== NULL && empty($capabilities['function_calling'])) {
        $label .= ' — no tool calling';
      }
      $models[$id] = $label;
    }
    return $models;
  }

  /**
   * The base URL to talk to, honouring an override.
   *
   * Mistral is AUTH_KEY, so an endpoint is never required — but the registry
   * passes whatever an operator stored, and a proxy in front of Mistral (or its
   * EU/Azure deployments) is a legitimate arrangement.
   */
  private function baseUrl(string $endpoint): string {
    $endpoint = trim($endpoint);
    return $endpoint !== '' ? rtrim($endpoint, '/') : self::DEFAULT_ENDPOINT;
  }

  /**
   * Whether an id names a non-chat model — the fallback reading only.
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
