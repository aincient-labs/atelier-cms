<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\Core\State\StateInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Resolves a provider id to a configured `symfony/ai` platform.
 *
 * Replaces `drupal/ai`'s AiProviderPluginManager for the inference path. Two
 * jobs: hold the adapter set ({@see ProviderAdapterInterface}) and resolve the
 * stored credential each adapter needs.
 *
 * Credentials resolve from exactly two stores, environment first: the
 * `ATELIER_<PROVIDER>_API_KEY` / `_ENDPOINT` variables a deployment supplies,
 * then the `aincient.<provider>_api_key` / `_endpoint` State keys that
 * {@see \Drupal\aincient_onboarding\ProviderConnector} writes. The Key-entity +
 * `aincient.provider.<id>` config-pointer branch, and the legacy `ai_provider_*`
 * / `gemini_provider.settings` fallback, are gone (DECISIONS 0341): those config
 * objects left with their modules, and the Forge demo now supplies its trial key
 * through the environment, so nothing wrote or read them any longer.
 */
final class PlatformRegistry implements PlatformRegistryInterface {

  /**
   * Adapters keyed by provider id.
   *
   * @var array<string, \Drupal\aincient_core\Inference\ProviderAdapterInterface>
   */
  private array $adapters = [];

  /**
   * @param iterable<\Drupal\aincient_core\Inference\ProviderAdapterInterface> $adapters
   *   The adapters, collected from the container by service tag.
   */
  public function __construct(
    iterable $adapters,
    private readonly StateInterface $state,
  ) {
    foreach ($adapters as $adapter) {
      $this->adapters[$adapter->id()] = $adapter;
    }
  }

  /**
   * Every registered adapter, keyed by provider id.
   *
   * @return array<string, \Drupal\aincient_core\Inference\ProviderAdapterInterface>
   *   The adapter set.
   */
  public function adapters(): array {
    return $this->adapters;
  }

  /**
   * The adapter for a provider id.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When no adapter claims that id.
   */
  public function adapter(string $providerId): ProviderAdapterInterface {
    $adapter = $this->adapters[$providerId] ?? NULL;
    if ($adapter === NULL) {
      throw new ProviderConfigurationException(sprintf(
        'No inference adapter is registered for provider "%s".',
        $providerId,
      ));
    }
    return $adapter;
  }

  /**
   * Whether a provider has a usable stored credential, without any network call.
   *
   * The honest "connected?" signal — the thing `drupal/ai`'s `isUsable()` could
   * not give us, because some plugins return TRUE with no key stored at all.
   */
  public function isConnected(string $providerId): bool {
    $adapter = $this->adapters[$providerId] ?? NULL;
    if ($adapter === NULL) {
      return FALSE;
    }
    $endpoint = $this->endpointFor($providerId);
    $credential = $this->credentialFor($providerId);

    return match ($adapter->authShape()) {
      ProviderAdapterInterface::AUTH_HOST => $endpoint !== '',
      ProviderAdapterInterface::AUTH_KEY_ENDPOINT => $credential !== '' && $endpoint !== '',
      default => $credential !== '',
    };
  }

  /**
   * Builds a platform for a provider from its STORED credential.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When the provider is unknown or has no usable credential.
   */
  public function platform(string $providerId): PlatformInterface {
    return $this->adapter($providerId)->createPlatform(
      $this->credentialFor($providerId),
      $this->endpointFor($providerId),
    );
  }

  /**
   * A provider's chat models, resolved from its stored credential.
   *
   * Never throws: a provider that is not connected simply has no models, which
   * is what a caller populating a picker wants.
   *
   * @return array<string, string>
   *   Model id => label, or [] when nothing is resolvable.
   */
  public function chatModels(string $providerId): array {
    $adapter = $this->adapters[$providerId] ?? NULL;
    // A provider that can chat but must not be offered for it has no chat models
    // to give a picker — see ProviderAdapterInterface::servesChat() for the
    // duplicate-catalogue this prevents.
    if ($adapter === NULL || !$adapter->servesChat() || !$this->isConnected($providerId)) {
      return [];
    }
    try {
      return $adapter->listChatModels(
        $this->credentialFor($providerId),
        $this->endpointFor($providerId),
      );
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * A provider's image models, resolved from its stored credential.
   *
   * Same contract as {@see self::chatModels()}, including never throwing. A
   * provider that cannot draw has no image models — and that is a TYPE question
   * ({@see ImageGenerationAdapterInterface}), not an empty list to interpret: the
   * `drupal/ai` path asked for `text_to_image` models and got Anthropic's eleven
   * chat models back, which is how the console came to offer models that cannot
   * draw in its image picker.
   *
   * @return array<string, string>
   *   Model id => label, or [] when nothing is resolvable.
   */
  public function imageModels(string $providerId): array {
    $adapter = $this->adapters[$providerId] ?? NULL;
    if (!$adapter instanceof ImageGenerationAdapterInterface || !$this->isConnected($providerId)) {
      return [];
    }
    try {
      return $adapter->listImageModels(
        $this->credentialFor($providerId),
        $this->endpointFor($providerId),
      );
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Whether this provider's credential is supplied by the environment.
   *
   * The question the WIZARD asks, and the reason this is public: a provider
   * configured by the deployment must not be offered a "connect" form that would
   * write a value nothing reads, nor a "disconnect" that cannot unset a variable
   * this process does not own. Both used to succeed and change nothing.
   *
   * True when EITHER half is supplied from the environment, deliberately: a
   * provider whose key is deployment-managed and whose base URL is not is still
   * not a provider the wizard can honestly own, and half-managing one is how you
   * get a disconnect that leaves a working credential behind.
   */
  public function isEnvironmentManaged(string $providerId): bool {
    return $this->environmentValue($providerId, 'API_KEY') !== ''
      || $this->environmentValue($providerId, 'ENDPOINT') !== '';
  }

  /**
   * One half of a provider's credential as supplied by the environment.
   *
   * `ATELIER_<PROVIDER>_<SUFFIX>`, the provider id upper-cased — so
   * `openai_compatible` reads `ATELIER_OPENAI_COMPATIBLE_API_KEY`. It mirrors the
   * State convention (`aincient.<provider>_api_key` / `_endpoint`) exactly, one
   * store over, and matches `ATELIER_VERSION` — the prefix this product already
   * reads its own environment under. (`AINCIENT_*` is the appliance's shell
   * namespace in `converge.sh`; nothing in PHP reads it.)
   *
   * An unset variable and an empty one are the same answer: a variable declared
   * but not filled in — the ordinary shape of a compose file with a blank value
   * — means "not supplied", never "supplied as empty".
   */
  private function environmentValue(string $providerId, string $suffix): string {
    $raw = getenv('ATELIER_' . strtoupper($providerId) . '_' . $suffix);

    return $raw === FALSE ? '' : trim($raw);
  }

  /**
   * Resolves a provider's secret, environment first.
   *
   * Two stores, in precedence order:
   *
   * 1. `ATELIER_<PROVIDER>_API_KEY` in the environment. Deployment intent wins,
   *    because it is the only one that can be ROTATED without a database write —
   *    an operator who changes the variable and restarts must see the new value,
   *    not a stale copy something wrote to State once. It is also how a secret
   *    reaches a container WITHOUT touching the database at all, which matters
   *    when the database is dumped into a published image (the Forge demo's
   *    per-container trial key travels this way).
   * 2. The conventional `aincient.<provider>_api_key` State key — what the
   *    wizard writes, and what a headless `drush state:set` install writes.
   */
  private function credentialFor(string $providerId): string {
    $fromEnvironment = $this->environmentValue($providerId, 'API_KEY');
    if ($fromEnvironment !== '') {
      return $fromEnvironment;
    }

    return trim((string) $this->state->get('aincient.' . $providerId . '_api_key', ''));
  }

  /**
   * A provider's configured base URL, or '' for the vendor default.
   *
   * `ATELIER_<PROVIDER>_ENDPOINT` wins, for the same reason the credential's
   * does. Then the `aincient.<provider>_endpoint` State convention the wizard
   * writes — the only other place an endpoint is ever stored, for both host
   * providers (whose whole credential is the URL) and OpenAI-compatible ones
   * ({@see \Drupal\aincient_onboarding\ProviderConnector::persistCredential()}).
   */
  private function endpointFor(string $providerId): string {
    $fromEnvironment = $this->environmentValue($providerId, 'ENDPOINT');
    if ($fromEnvironment !== '') {
      return $fromEnvironment;
    }

    return trim((string) $this->state->get('aincient.' . $providerId . '_endpoint', ''));
  }

}
