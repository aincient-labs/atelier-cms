<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Drupal\aincient_core\Inference\Exception\ProviderConfigurationException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Resolves a provider id to a configured `symfony/ai` platform.
 *
 * Replaces `drupal/ai`'s AiProviderPluginManager for the inference path. Two
 * jobs: hold the adapter set ({@see ProviderAdapterInterface}) and resolve the
 * stored credential each adapter needs.
 *
 * CREDENTIAL STORAGE IS UNCHANGED. It deliberately reads the SAME Key entity →
 * State chain that {@see \Drupal\aincient_onboarding\ProviderConnector} already
 * writes (`<provider>_default_key` backed by `aincient.<provider>_api_key`), so
 * this can serve traffic on a site that was onboarded before the migration and
 * an operator never re-enters a key. Onboarding is not touched in this phase.
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
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
   * Three stores, in precedence order:
   *
   * 1. `ATELIER_<PROVIDER>_API_KEY` in the environment. Deployment intent wins,
   *    because it is the only one of the three that can be ROTATED without a
   *    database write — an operator who changes the variable and restarts must
   *    see the new value, not a stale copy something wrote to State once.
   * 2. A Key entity named by `aincient.provider.<id>: api_key`. Kept because it
   *    is how a secret reaches a container WITHOUT touching the database at all
   *    (an `env`-provider Key entity resolves live, per request), which matters
   *    when the database is dumped into a published image.
   * 3. The conventional `aincient.<provider>_api_key` State key — what the
   *    wizard writes, and what a headless `drush state:set` install writes.
   *
   * 1 and 2 express the same intent by different means; 1 exists so expressing
   * it costs one variable instead of a Key entity plus a config pointer.
   */
  private function credentialFor(string $providerId): string {
    $fromEnvironment = $this->environmentValue($providerId, 'API_KEY');
    if ($fromEnvironment !== '') {
      return $fromEnvironment;
    }

    $keyId = (string) $this->configFactory
      ->get($this->settingsNameFor($providerId))
      ->get('api_key');

    if ($keyId !== '') {
      try {
        $entity = $this->entityTypeManager->getStorage('key')->load($keyId);
        if ($entity !== NULL) {
          $value = (string) $entity->getKeyValue();
          if ($value !== '') {
            return $value;
          }
        }
      }
      catch (\Throwable) {
        // Fall through to the State convention below.
      }
    }

    return trim((string) $this->state->get('aincient.' . $providerId . '_api_key', ''));
  }

  /**
   * A provider's configured base URL, or '' for the vendor default.
   *
   * `ATELIER_<PROVIDER>_ENDPOINT` wins, for the same reason the credential's
   * does. Then config — both `endpoint` (our own key, and the contrib
   * OpenAI-compatible module's) and `host_name` (the Ollama convention), so an
   * operator's existing value is honoured whichever module wrote it — and
   * finally the State convention the wizard writes.
   */
  private function endpointFor(string $providerId): string {
    $fromEnvironment = $this->environmentValue($providerId, 'ENDPOINT');
    if ($fromEnvironment !== '') {
      return $fromEnvironment;
    }

    $settings = $this->configFactory->get($this->settingsNameFor($providerId));
    foreach (['endpoint', 'host_name'] as $key) {
      $value = trim((string) $settings->get($key));
      if ($value !== '') {
        return $value;
      }
    }
    return trim((string) $this->state->get('aincient.' . $providerId . '_endpoint', ''));
  }

  /**
   * The settings config object holding a provider's credential pointer.
   *
   * Our own providers use `aincient.provider.<id>`; the historical
   * `drupal/ai` names are read as a fallback so a pre-migration install keeps
   * resolving without a re-connect. The `gemini_provider` special case is the
   * one module that broke the `ai_provider_<id>.settings` convention.
   *
   * THE FALLBACK IS NOW DEAD ON EVERY SUPPORTED VERSION, and is kept only
   * because deleting credential-resolution code deserves its own change rather
   * than riding along with a composer cleanup. Those config objects belonged to
   * the `ai_provider_*` and `gemini_provider` modules, so they left with the
   * uninstall in v0.1.0 — verified absent from the active store, and they are not
   * `config_ignore`d, so nothing preserved them. A site old enough to still hold
   * them is old enough that `docker/converge.sh` refuses to upgrade it at all
   * (its module code is gone from the image; see the missing-code guard there).
   * `Config::get()` on an absent object returns NULL, so the branch is a
   * harmless no-op rather than an error.
   */
  private function settingsNameFor(string $providerId): string {
    $own = 'aincient.provider.' . $providerId;
    if ($this->configFactory->get($own)->get('api_key') !== NULL) {
      return $own;
    }
    return match ($providerId) {
      'gemini', 'nanobanana' => 'gemini_provider.settings',
      default => 'ai_provider_' . $providerId . '.settings',
    };
  }

}
