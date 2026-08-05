<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

use Symfony\AI\Platform\PlatformInterface;

/**
 * What the inference callers need from the provider layer — and nothing more.
 *
 * {@see PlatformRegistry} is the only implementation and stays `final`: it reads
 * config, State and Key entities, and none of that should be subclassable. This
 * interface exists so {@see SymfonyAiReasoner} and {@see AiGateway} depend on a
 * CAPABILITY — "hand me a platform for this provider id, tell me whether it is
 * actually connected, and tell me what models exist" — rather than on that
 * concrete registry.
 *
 * The payoff is testability. The options the reasoner builds for
 * `PlatformInterface::invoke()` are load-bearing (a stray `temperature` broke
 * every turn on newer Anthropic models), and pinning them used to require a live
 * provider and a stored credential. Against this seam a test can substitute a
 * platform that simply records what it was called with.
 */
interface PlatformRegistryInterface {

  /**
   * Every registered adapter, keyed by provider id.
   *
   * @return array<string, \Drupal\aincient_core\Inference\ProviderAdapterInterface>
   *   The adapter set.
   */
  public function adapters(): array;

  /**
   * The adapter for a provider id.
   *
   * On the interface because both callers need the adapter itself, not just a
   * platform: it is what knows the provider's option dialect
   * ({@see ProviderAdapterInterface::translateOptions()}) and its image
   * capability ({@see ImageGenerationAdapterInterface}).
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return \Drupal\aincient_core\Inference\ProviderAdapterInterface
   *   The adapter.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When no adapter claims that id.
   */
  public function adapter(string $providerId): ProviderAdapterInterface;

  /**
   * Whether a provider has a usable stored credential, without any network call.
   *
   * Never throws — an unknown or keyless provider is simply not connected. This
   * is the question `drupal/ai`'s `isUsable()` could not answer honestly, and it
   * is what lets {@see AiGateway} say "bound, but unusable" instead of failing at
   * call time.
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return bool
   *   TRUE when a credential (and, for host providers, an endpoint) is stored.
   */
  public function isConnected(string $providerId): bool;

  /**
   * Whether this provider's credential comes from the environment.
   *
   * Such a provider is connected but not MANAGEABLE: the wizard cannot write a
   * credential the resolver will prefer over the variable, and cannot unset a
   * variable this process does not own.
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return bool
   *   TRUE when either half of the credential is supplied by the environment.
   */
  public function isEnvironmentManaged(string $providerId): bool;

  /**
   * Builds a platform for a provider from its stored credential.
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return \Symfony\AI\Platform\PlatformInterface
   *   The platform to invoke.
   *
   * @throws \Drupal\aincient_core\Inference\Exception\ProviderConfigurationException
   *   When the provider is unknown or has no usable credential.
   */
  public function platform(string $providerId): PlatformInterface;

  /**
   * A provider's chat models, resolved from its stored credential.
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return array<string, string>
   *   Model id => label, or [] when nothing is resolvable — including for a
   *   provider that is not offered for chat work
   *   ({@see ProviderAdapterInterface::servesChat()}).
   */
  public function chatModels(string $providerId): array;

  /**
   * A provider's image models, resolved from its stored credential.
   *
   * On the interface because {@see ProviderInventory} populates the console's
   * image picker from it, and [] must mean "nothing to offer" for both reasons
   * that produce it — cannot draw, or not connected.
   *
   * @param string $providerId
   *   The provider id.
   *
   * @return array<string, string>
   *   Model id => label, or [] when the provider cannot draw or is not
   *   connected.
   */
  public function imageModels(string $providerId): array;

}
