<?php

declare(strict_types=1);

namespace Drupal\aincient_core\Inference;

/**
 * The ONE place in Atelier that asks "which AI providers can this site use?".
 *
 * WHY THIS EXISTS. Five production files injected `drupal/ai`'s
 * AiProviderPluginManager purely to enumerate the inventory — ProviderCatalog
 * and ProviderConnector (onboarding's picker + connect step), ModelRolesForm,
 * ModelRoleResolver, and the `aincient:model-*` Drush commands. Between them
 * they used five of its methods, and every one of those five call sites had to
 * know the vendor's spelling: that the provider list is fetched per *operation
 * type* with a second positional boolean nobody remembers the meaning of, that
 * the "default provider" comes back as a loose `{provider_id, model_id}` array,
 * and that capability + model questions go through `createInstance()` on a
 * plugin manager. A backend change meant editing all five.
 *
 * This class absorbs that. Its signatures speak only Atelier's vocabulary —
 * provider ids, capability constants, plain arrays. **No caller of this class
 * names `Drupal\ai` or `Symfony\AI`.** That is the contract, and it is the
 * entire value: when the backend drifts, the diff is confined to this file.
 * It is the inventory twin of {@see AiGateway}, which is the same boundary for
 * one-shot calls; together they are the whole surface the product needs.
 *
 * THE BOUNDARY WAS PAID OUT — WITH INTEREST. It was introduced on `drupal/ai`
 * with the claim that moving its internals to {@see PlatformRegistry} would be
 * "a change to this file alone". The internals did move, and the *type* half of
 * that claim held: no caller names a vendor. But the SEMANTIC half did not, and
 * pretending otherwise would have been the more expensive lie — because what the
 * old backend answered was, in three separate ways, not true:
 *
 * - **"Installed" was not "servable."** `getProvidersForOperationType('chat')`
 *   answered `anthropic, mistral, ollama, openai, openrouter, gemini` on the
 *   install this was written against. Four of those six have no adapter, so
 *   binding one produced `ProviderConfigurationException: No inference adapter
 *   is registered for provider "mistral"` at call time. The console was offering
 *   choices the product cannot honour. This class now enumerates the ADAPTER SET,
 *   which makes "installed" and "servable" the same word — and a provider that
 *   vanished from the picker is one that never worked.
 * - **`isUsable()` was wrong in both directions.** Measured on the same install:
 *   TRUE for `mistral`, `openai` and `openrouter`, none of which had a key
 *   stored; FALSE for `nanobanana`, which did. It is replaced by
 *   {@see self::isConnected()}, which reads the credential rather than asking a
 *   plugin to describe itself. Onboarding used to keep its own
 *   `hasStoredCredential()` precisely because it could not trust the plugin's
 *   answer; that duplicate reader is now gone.
 * - **Capability was a string, and strings do not check.**
 *   `getProvidersForOperationType('text_to_image')` included `anthropic`, which
 *   then returned its eleven CHAT models, and `openrouter`, which returned all
 *   337 of its text models. The image picker was full of models that cannot
 *   draw. Here image capability is a TYPE
 *   ({@see ImageGenerationAdapterInterface}), so it cannot be claimed by
 *   accident.
 *
 * STILL NOT DEFENSIVE — BUT LESS OF IT IS LEFT TO DEFEND. The old passthroughs
 * threw (no key, unreachable host, plugin failure) and every call site wrapped
 * them in its own `try/catch`, deciding for itself whether that meant "empty
 * pool" or "skip this provider". That was correct for a backend whose failure
 * modes we did not own. We own this one: {@see ProviderAdapterInterface} makes
 * "[] when the credential does not work" the CONTRACT, so the answer is already
 * unambiguous and four call sites deleted their guards. What is left here is
 * still exact: nothing coalesces a real answer into a friendlier one, nothing
 * invents a sentinel, and {@see self::models()} does not pretend a network fault
 * is a credential fault — it reports no models, and the caller's own status
 * vocabulary ({@see AiGateway::roleStatus()}) is what distinguishes the cases
 * that need distinguishing.
 *
 * THE ESCAPE HATCH IS GONE. `instanceFor()` handed a live `drupal/ai` provider
 * back to onboarding so it could `setAuthentication()` a candidate credential
 * that was not stored yet. {@see self::modelsForCredential()} replaces it,
 * because the adapter contract takes the credential as an ARGUMENT — validating
 * an unstored key is a first-class question here, not a hole in the facade.
 */
final class ProviderInventory {

  /**
   * Text in, text out — the capability every provider must have.
   *
   * Replaces the operation-type strings the old backend split hairs with
   * (`chat`, `chat_with_tools`, `chat_with_complex_json`, …). On `symfony/ai`
   * there is one chat transport and a vision turn is a chat turn with an image
   * part, so those five names described one capability. The operation types still
   * exist as a config surface stock FlowDrop reads
   * ({@see \Drupal\aincient_core\ModelRoleResolver::project()}) — they are simply
   * no longer how we ask what a provider can do.
   */
  public const CHAT = 'chat';

  /**
   * Makes pictures — generating from a prompt, and editing a source image.
   */
  public const IMAGE = 'image';

  public function __construct(
    private readonly PlatformRegistryInterface $registry,
  ) {}

  /**
   * Every provider this site can actually serve, keyed by provider id.
   *
   * One row per registered adapter, carrying everything a picker renders: the
   * label + description to show, the credential shape to ask for, what it can
   * do, and whether it is connected. The rows are the whole vocabulary — a
   * caller that needs a per-provider fact should find it here rather than
   * rebuilding a map of its own, which is what the `HOST_PROVIDERS` and
   * capability maps in onboarding were.
   *
   * @return array<string, array{id: string, label: string, description: string, auth: string, capabilities: array{chat: bool, image: bool}, connected: bool}>
   *   Provider id => row.
   */
  public function providers(): array {
    $rows = [];
    foreach ($this->registry->adapters() as $id => $adapter) {
      $rows[$id] = [
        'id' => $id,
        'label' => $adapter->label(),
        'description' => $adapter->description(),
        'auth' => $adapter->authShape(),
        'capabilities' => [
          // Not "can it chat" — every adapter's platform can, since that is the
          // only transport `symfony/ai` gives us. This is "should it be OFFERED
          // for chat", which is the provider's own call: `nanobanana` is the same
          // Google key as `gemini`, and offering both duplicates every model.
          self::CHAT => $adapter->servesChat(),
          self::IMAGE => $adapter instanceof ImageGenerationAdapterInterface,
        ],
        'connected' => $this->registry->isConnected($id),
      ];
    }
    ksort($rows);
    return $rows;
  }

  /**
   * The providers that have a capability, keyed by provider id.
   *
   * Same rows as {@see self::providers()}, filtered. Unlike the old
   * operation-type lookup this includes providers that are not connected yet —
   * onboarding depends on that (the whole point of the wizard is to connect
   * something that is not connected), and the models form reads `connected` and
   * the model list to decide what to offer.
   *
   * @param string $capability
   *   {@see self::CHAT} or {@see self::IMAGE}.
   *
   * @return array<string, array{id: string, label: string, description: string, auth: string, capabilities: array{chat: bool, image: bool}, connected: bool}>
   *   Provider id => row.
   */
  public function providersWith(string $capability): array {
    return array_filter(
      $this->providers(),
      static fn (array $row): bool => !empty($row['capabilities'][$capability]),
    );
  }

  /**
   * Whether this site has an adapter for a provider id at all.
   *
   * The question a STORED binding raises: `aincient_core.model_roles` can name a
   * provider that has since lost its adapter (or never had one), and the console
   * has to be able to say so with a remedy instead of silently dropping the
   * operator's saved choice. FALSE here is the "bound, not servable" signal that
   * {@see AiGateway::STATUS_UNUSABLE} reports at call time.
   */
  public function has(string $providerId): bool {
    return isset($this->registry->adapters()[$providerId]);
  }

  /**
   * A provider's human label, falling back to its id.
   *
   * Deliberately does NOT throw on an unknown id: the callers that need a label
   * are rendering a message about a stored binding, and an orphaned binding must
   * still be nameable — "mistral is no longer available" is a useful sentence,
   * an exception while composing it is not.
   */
  public function label(string $providerId): string {
    return $this->providers()[$providerId]['label'] ?? $providerId;
  }

  /**
   * Which credential shape a provider needs, or '' when it has no adapter.
   *
   * One of {@see ProviderAdapterInterface}'s AUTH_* constants. This is the
   * `HOST_PROVIDERS` list — which onboarding maintained in duplicate, in two
   * classes — becoming the provider's own answer.
   */
  public function authShape(string $providerId): string {
    return $this->providers()[$providerId]['auth'] ?? '';
  }

  /**
   * Whether a provider has a stored credential it could serve a call with.
   *
   * No network. The honest replacement for `drupal/ai`'s `isUsable()`, which on
   * the install this was written against answered TRUE for three providers with
   * no key and FALSE for one that had one.
   */
  public function isConnected(string $providerId): bool {
    return $this->registry->isConnected($providerId);
  }

  /**
   * A provider's models for a capability, from its STORED credential.
   *
   * A real round-trip for every adapter (the contract requires it — a bundled
   * list would validate a garbage key), so callers cache or gate it. Returns []
   * rather than throwing: an unconnected provider, a provider that cannot draw,
   * and an unreachable host all have nothing to offer a picker, and the
   * distinctions that DO carry different remedies are drawn from
   * {@see self::has()} + {@see self::isConnected()} before it gets this far.
   *
   * @param string $providerId
   *   The provider to enumerate.
   * @param string $capability
   *   {@see self::CHAT} or {@see self::IMAGE}.
   *
   * @return array<string, string>
   *   Model id => model label.
   */
  public function models(string $providerId, string $capability): array {
    return $capability === self::IMAGE
      ? $this->registry->imageModels($providerId)
      : $this->registry->chatModels($providerId);
  }

  /**
   * A provider's models for a credential that is NOT stored anywhere yet.
   *
   * Onboarding's validation probe, and the reason `instanceFor()` no longer
   * exists. "Prove the credential works" is answered by asking the provider for
   * its catalogue with that credential in hand — and because
   * {@see ProviderAdapterInterface::listChatModels()} takes it as an argument,
   * that needs no live plugin instance, no `setAuthentication()`, and above all
   * no WRITE: the old probe had to save a host provider's URL to config before
   * the client could read it, then roll the config back in a `finally`, which is
   * a persistence dance in the middle of a validation.
   *
   * @param string $providerId
   *   The provider to probe.
   * @param string $capability
   *   {@see self::CHAT} or {@see self::IMAGE}.
   * @param string $credential
   *   The candidate API key. For an {@see ProviderAdapterInterface::AUTH_HOST}
   *   provider the credential IS the base URL, so pass it here and leave
   *   $endpoint empty — this method sorts that out.
   * @param string $endpoint
   *   A base URL, for the providers that need one alongside a key.
   *
   * @return array<string, string>
   *   Model id => label. Empty when the credential does not work — which is the
   *   contract every adapter is held to, not an interpretation made here.
   */
  public function modelsForCredential(string $providerId, string $capability, string $credential, string $endpoint = ''): array {
    $adapter = $this->registry->adapters()[$providerId] ?? NULL;
    if ($adapter === NULL) {
      return [];
    }
    if ($adapter->authShape() === ProviderAdapterInterface::AUTH_HOST) {
      // One field in the wizard, two meanings in the contract: a host provider's
      // single input is its base URL. Swapping it into place here keeps that
      // knowledge out of the caller, which is the only reason onboarding used to
      // carry a HOST_PROVIDERS list.
      [$credential, $endpoint] = ['', $credential];
    }

    if ($capability === self::IMAGE && !$adapter instanceof ImageGenerationAdapterInterface) {
      return [];
    }
    if ($capability === self::CHAT && !$adapter->servesChat()) {
      // Deliberately NOT a shortcut for "this credential is bad". A provider that
      // only draws is proven by its IMAGE probe, and onboarding treats a group
      // member that answers either probe as connected — so returning [] here is
      // the honest "ask me the other question", not a rejection.
      return [];
    }

    try {
      return $capability === self::IMAGE
        ? $adapter->listImageModels($credential, $endpoint)
        : $adapter->listChatModels($credential, $endpoint);
    }
    catch (\Throwable) {
      // An adapter is contractually meant to return [] rather than throw, but a
      // probe runs against an operator's freshly typed input on a page they are
      // waiting on — a bug in one adapter must not become a WSOD in the wizard.
      return [];
    }
  }

}
