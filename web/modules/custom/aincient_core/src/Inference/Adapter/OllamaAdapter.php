<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference\Adapter;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ollama — models on the operator's own machine — via `symfony/ai-ollama-platform`.
 *
 * The only provider Atelier can serve with NO secret at all: what it
 * authenticates with is a server URL ({@see ProviderAdapterInterface::AUTH_HOST}),
 * because an Ollama server on your laptop or your LAN has no key to give. That
 * shape has been in the contract, in the onboarding connector's storage path
 * (`aincient.ollama_endpoint` in State) and in the wizard's single field since
 * the adapter set was written; this class is what makes anything answer to it.
 *
 * WHY IT WAS ABSENT, AND WHY THAT WAS RIGHT AT THE TIME. `drupal/ai_provider_ollama`
 * was installed for most of the project's life and never worked the way its
 * presence implied: its client read the host from SAVED config rather than taking
 * it as an argument, so probing a URL meant writing config, building a plugin, and
 * rolling the config back in a `finally` — a persistence dance in the middle of a
 * validation. When the provider modules were uninstalled it went with them, and
 * the honest position was "there is no Ollama adapter, so Ollama is not offered"
 * rather than a picker row that stores a URL and fails at call time. This restores
 * the capability on the terms the contract asks for: the endpoint is an ARGUMENT,
 * enumeration is a real round-trip, and a server that is not there says so.
 *
 * WHAT IT COSTS TO BE HONEST HERE. `GET /api/tags` lists what is pulled, and
 * nothing more — an embedding model and a chat model are indistinguishable in it.
 * So enumeration also asks `POST /api/show` per model, which is where Ollama keeps
 * the capability list, and that is N+1 requests against a server that is usually
 * on the same machine (each answer is a local metadata read, milliseconds). The
 * alternative was to return the raw tag list, which would put `nomic-embed-text`
 * in the chat picker and turn a role binding into a runtime error — the precise
 * failure this whole adapter layer exists to make impossible.
 *
 * TOOL CALLING IS LABELLED, NOT FILTERED. Atelier's agent loop needs tool calls,
 * and plenty of pullable models cannot make them. Hiding those would be a lie in
 * the other direction — they are perfectly good for a vision or a plain-chat role
 * — so a model without the `tools` capability keeps its place in the list and
 * says so in its label. The operator picks with the constraint in front of them.
 */
final class OllamaAdapter implements ProviderAdapterInterface {

  /**
   * How long to wait on the local server, per request.
   *
   * Shorter than the remote adapters' 15s on purpose: this is a machine on the
   * operator's desk or LAN, so a slow answer means "wrong URL", and enumeration
   * makes one request per pulled model. Waiting a quarter of a minute each to
   * learn that `localhost` is the container, not the host, is the wrong shape of
   * patience.
   */
  private const TIMEOUT = 8;

  public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly \GuzzleHttp\ClientInterface $guzzle,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    // The historical drupal/ai plugin id. An install that bound a role to
    // `ollama` before the provider modules were uninstalled resolves again the
    // moment its endpoint is re-entered — and the console's provider mark is
    // already keyed by this string.
    return 'ollama';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'Ollama';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    // Names the container trap in the sentence the operator reads BEFORE typing,
    // because it is the one mistake this provider invites and the error it
    // produces (connection refused) does not explain itself. Atelier runs in a
    // container; `localhost` there is the container, not the machine Ollama is on.
    return 'Models running on your own machine or network — no API key, nothing leaves your infrastructure. Needs the server URL; from Atelier’s container that is http://host.docker.internal:11434, not localhost.';
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
    return self::AUTH_HOST;
  }

  /**
   * {@inheritdoc}
   */
  public function isProxy(): bool {
    // Ollama model ids are the vendor's own tags (`llama3.2:3b`, `qwen3:8b`), not
    // the `vendor/model` namespacing an aggregating proxy applies.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function translateOptions(array $options): array {
    // Ollama's `/api/chat` takes generation settings in a NESTED `options` object
    // under its own names, and `OllamaClient::normalizeOllamaOptions()` does that
    // nesting for us — anything not in its top-level allowlist (`stream`,
    // `format`, `tools`, …) is moved there verbatim. So `tools` needs no help and
    // `temperature` is already spelled Ollama's way; the cap is not. Ollama calls
    // it `num_predict`, and an unknown key in `options` is silently IGNORED rather
    // than rejected — which is the worst case of the three dialects: the request
    // succeeds and the cap simply does not apply.
    if (array_key_exists('max_tokens', $options)) {
      $options['num_predict'] = $options['max_tokens'];
      unset($options['max_tokens']);
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function createPlatform(string $credential, string $endpoint = ''): PlatformInterface {
    $base = $this->normaliseEndpoint($endpoint);
    if ($base === '') {
      throw new ProviderConfigurationException(
        'Ollama needs the URL of your server (for example http://host.docker.internal:11434).'
      );
    }
    $credential = trim($credential);

    return OllamaFactory::createPlatform(
      // Trailing slash on purpose: the bridge's client mixes root-relative
      // (`/api/chat`) and relative (`api/tags`) paths, and only a base URI ending
      // in `/` resolves both to the same place.
      endpoint: $base . '/',
      // A plain Ollama server has no secret, and that is the normal case — but a
      // team fronting one with a reverse proxy does, and the bridge sends it as a
      // bearer token. NULL rather than '' so no empty Authorization header is set.
      apiKey: $credential !== '' ? $credential : NULL,
      httpClient: $this->httpClient,
      name: $this->id(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function listChatModels(string $credential, string $endpoint = ''): array {
    $base = $this->normaliseEndpoint($endpoint);
    if ($base === '') {
      return [];
    }

    $tags = $this->get($base . '/api/tags', $credential);
    if (!is_array($tags['models'] ?? NULL)) {
      return [];
    }

    $models = [];
    foreach ($tags['models'] as $model) {
      $name = is_array($model) ? trim((string) ($model['name'] ?? '')) : '';
      if ($name === '') {
        continue;
      }
      $capabilities = $this->capabilities($base, $credential, $name);
      // No `completion` means it cannot hold a conversation at all — an embedding
      // model, most often. Offering it as a chat option is how a role binding
      // becomes a failed turn.
      if (!in_array('completion', $capabilities, TRUE)) {
        continue;
      }
      $models[$name] = in_array('tools', $capabilities, TRUE)
        ? $name
        : $name . ' — no tool calling';
    }
    return $models;
  }

  /**
   * One model's capability list, as Ollama itself reports it.
   *
   * `POST /api/show` is the only place the distinction lives. A server too old to
   * report capabilities (they arrived in Ollama 0.6) answers without the key; we
   * treat that as "chat, no tools", which is the conservative reading — it keeps
   * the model listed and warns about the limit rather than hiding a model that
   * probably works.
   *
   * @return list<string>
   *   Ollama's own capability strings (`completion`, `tools`, `vision`, …).
   */
  private function capabilities(string $base, string $credential, string $model): array {
    $payload = $this->get($base . '/api/show', $credential, ['model' => $model]);
    $capabilities = $payload['capabilities'] ?? NULL;
    if (!is_array($capabilities)) {
      return ['completion'];
    }
    return array_values(array_filter(array_map(
      static fn ($capability): string => is_string($capability) ? $capability : '',
      $capabilities,
    )));
  }

  /**
   * One request against the Ollama server, decoded, or [] on any failure.
   *
   * Guzzle rather than the Symfony client for the same reason as every other
   * adapter: this is a Drupal-side metadata call with no relationship to the
   * inference transport. Nothing here distinguishes "server not there" from
   * "server said no" to the CALLER — an empty catalogue is the contract for both —
   * but the log does, because the two have completely different remedies.
   *
   * @param string $url
   *   The absolute URL to call.
   * @param string $credential
   *   A bearer token, or '' for the ordinary keyless server.
   * @param array<string, mixed>|null $json
   *   A JSON body, which makes this a POST. NULL sends a GET.
   *
   * @return array<mixed>
   *   The decoded payload, or [] when the call did not produce one.
   */
  private function get(string $url, string $credential, ?array $json = NULL): array {
    $options = ['timeout' => self::TIMEOUT, 'http_errors' => FALSE];
    if ($credential !== '') {
      $options['headers'] = ['Authorization' => 'Bearer ' . $credential];
    }
    if ($json !== NULL) {
      $options['json'] = $json;
    }

    try {
      $response = $this->guzzle->request($json === NULL ? 'GET' : 'POST', $url, $options);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Ollama request to @url failed: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      return [];
    }
    $payload = json_decode((string) $response->getBody(), TRUE);
    return is_array($payload) ? $payload : [];
  }

  /**
   * Fills in what an operator reasonably leaves out of a server URL.
   *
   * Defaults to `http://` rather than `https://` — the opposite of
   * {@see OpenAiCompatibleAdapter}, and deliberately: Ollama serves plain HTTP
   * out of the box and terminates no TLS of its own, so a bare
   * `host.docker.internal:11434` means http. Anyone behind a TLS proxy pastes the
   * scheme, and we honour it.
   */
  private function normaliseEndpoint(string $endpoint): string {
    $endpoint = trim($endpoint);
    if ($endpoint === '') {
      return '';
    }
    if (!preg_match('#^https?://#i', $endpoint)) {
      $endpoint = 'http://' . $endpoint;
    }
    $endpoint = rtrim($endpoint, '/');
    // `…:11434/api` is the other reasonable paste (it is the path every Ollama
    // doc example contains); our paths are `/api`-prefixed already.
    return preg_replace('#/api$#', '', $endpoint) ?? $endpoint;
  }

}
