<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic, via `symfony/ai-anthropic-platform`.
 *
 * The provider we label `recommended` in the curated document, so it is the
 * first adapter and the reference implementation of the contract.
 *
 * Model enumeration goes over the wire (`GET /v1/models`) rather than reading a
 * bundled list, because our onboarding treats a returned catalogue as proof the
 * key works. The bridge ships its own `ModelCatalog` for capability metadata,
 * but that catalogue is static and would happily "validate" a garbage key — so
 * it is deliberately NOT the source of truth here.
 */
final class AnthropicAdapter implements ProviderAdapterInterface {

  /**
   * The Anthropic API version header its REST endpoints require.
   */
  private const API_VERSION = '2023-06-01';

  /**
   * The default base URL, matching the bridge's own default.
   */
  private const DEFAULT_ENDPOINT = 'https://api.anthropic.com';

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly \GuzzleHttp\ClientInterface $guzzle,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // Deliberately equal to the historical drupal/ai plugin id: stored role
    // bindings in aincient_core.model_roles name `anthropic` and must keep
    // resolving across this migration.
    return 'anthropic';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Anthropic';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'Claude models for chat, vision and tool use — the provider Atelier is developed against.';
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
    // Anthropic's Messages API spells the output cap `max_tokens`, which is the
    // neutral name our callers already use, so this is deliberately the identity
    // function — Anthropic behaviour must stay byte-identical to what it was
    // before the option seam existed. (The bridge additionally defaults
    // `max_tokens` to 1000 when absent, in its own Claude model class.)
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $credential = trim($credential);
    if ($credential === '') {
      throw new ProviderConfigurationException('No Anthropic API key is stored.');
    }
    return AnthropicFactory::createPlatform(
      apiKey: $credential,
      httpClient: $this->httpClient,
      baseUrl: $endpoint !== '' ? rtrim($endpoint, '/') : self::DEFAULT_ENDPOINT,
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
      // Guzzle rather than the Symfony client: this is a plain Drupal-side
      // request with no relationship to the inference transport, and Guzzle is
      // what the rest of our code (RecommendationSource) already uses.
      $response = $this->guzzle->request('GET', $base . '/v1/models', [
        'headers' => [
          'x-api-key' => $credential,
          'anthropic-version' => self::API_VERSION,
        ],
        'query' => ['limit' => 100],
        'timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      // A network fault is not a bad key; say so in the log and report "no
      // models" to the caller, which surfaces as a validation failure.
      $this->logger->warning('Anthropic model enumeration failed: @message', [
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
      $id = (string) $model['id'];
      $models[$id] = (string) ($model['display_name'] ?? $id);
    }
    return $models;
  }

}
