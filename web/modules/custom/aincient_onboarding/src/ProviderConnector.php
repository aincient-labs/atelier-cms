<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\aincient_core\Inference\ProviderAdapterInterface;
use Drupal\aincient_core\Inference\ProviderInventory;
use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Connects AI for a fresh install — provider-aware, on top of the adapter set.
 *
 * The onboarding wizard lets the user pick any provider Atelier can serve. This
 * service connects whichever they chose WITHOUT hard-coding a single vendor: it
 * asks {@see ProviderInventory} what shape of credential the provider wants,
 * proves the credential works, and stores it.
 *
 * Validation is "prove the credential works", not "is some field non-empty": we
 * hand the candidate credential to the provider and ask it for its models (a real
 * API round-trip). Models returned ⇒ the credential is good.
 *
 * VALIDATION NO LONGER WRITES ANYTHING. It used to have to. `drupal/ai`'s
 * provider interface took a credential at runtime (`setAuthentication()`) but its
 * Ollama client read its host from SAVED config, so probing a server URL meant
 * writing config, constructing a fresh plugin, and rolling the config back in a
 * `finally` — a persistence dance in the middle of a validation, one crash away
 * from leaving a half-configured provider behind. Adapters take the credential
 * AND the endpoint as arguments, so the probe is now a pure function of what the
 * operator typed ({@see ProviderInventory::modelsForCredential()}), and the
 * rollback it needed no longer exists to get wrong.
 *
 * On success the secret is stored through the Key module's STATE provider (the
 * value lives in Drupal State, never in config/git; CLAUDE.md: secrets never in
 * git) and the provider's `api_key` setting is pointed at that key entity. The
 * chosen provider+model is pinned by binding the role layer's DEFAULT ROLE, and
 * the completion flag is set, which flips {@see self::needsOnboarding()} and the
 * chat layer's first-run gate off.
 */
final class ProviderConnector {

  /**
   * Providers that share a single API key, keyed by the primary shown in the UI.
   *
   * Some vendors are TWO provider ids that authenticate with the SAME credential
   * — most notably Google, where `gemini` (chat/vision) and `nanobanana` (the
   * Gemini image models) both take one Google AI Studio key. The onboarding
   * wizard presents such a group as ONE row (the primary), and
   * {@see self::connectAndStore()} stores the entered key against every member so
   * a single key entry lights up all of their capabilities at once. A provider
   * absent here is its own single-member group.
   *
   * @var array<string, list<string>>
   */
  public const KEY_GROUPS = [
    'gemini' => ['gemini', 'nanobanana'],
  ];

  /**
   * The Drupal State flag set once onboarding has succeeded.
   */
  public const STATE_COMPLETED = 'aincient_onboarding.completed';

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProviderInventory $providerManager,
    private readonly ModelRoleResolver $resolver,
  ) {}

  /**
   * How a provider authenticates, in the provider's own vocabulary.
   *
   * Returns the adapter's declared shape verbatim ('api_key' | 'api_key_endpoint'
   * | 'host') rather than a HOST_PROVIDERS list maintained here (and, until the
   * adapter migration, in duplicate in {@see ProviderCatalog}). It used to
   * collapse the three shapes into two, which was safe only for as long as the
   * third could not be connected at all.
   */
  public function authType(string $providerId): string {
    return $this->providerManager->authShape($providerId);
  }

  /**
   * Whether a provider needs a base URL ALONGSIDE its key.
   *
   * The distinction the connect step exists to honour: a host provider's URL IS
   * its credential, whereas an OpenAI-compatible endpoint needs both, and half of
   * both is not a connection.
   */
  private function needsEndpoint(string $providerId): bool {
    return $this->authType($providerId) === ProviderAdapterInterface::AUTH_KEY_ENDPOINT;
  }

  /**
   * Whether a provider actually has a stored, non-empty credential.
   *
   * The honest "connected?" signal for the Connect step. It used to be a second
   * credential reader living here, because `drupal/ai`'s `isUsable()` could not be
   * trusted (it answered TRUE for three providers with no key stored and FALSE for
   * one that had one). The registry now reads the same Key entity → State chain
   * this class writes, so the duplicate is gone and there is exactly one answer to
   * "is this connected?" on the site. Still no network.
   */
  public function hasStoredCredential(string $providerId): bool {
    return $this->providerManager->isConnected($providerId);
  }

  /**
   * Whether onboarding has been completed (a provider was connected).
   */
  public function isComplete(): bool {
    return (bool) $this->state->get(self::STATE_COMPLETED, FALSE);
  }

  /**
   * Whether the site has a usable AI configuration.
   *
   * True once onboarding has run (the completion flag), or — for the operator who
   * provisioned everything headlessly and never saw the wizard — when the everyday
   * chat role resolves to a provider that is actually connected.
   *
   * IT USED TO ASK A DIFFERENT QUESTION: whether `ai.settings`' default chat
   * provider reported itself usable. Both halves of that were unreliable — the
   * config is written FROM the role bindings, so it lagged them, and `isUsable()`
   * answered TRUE with no key stored, which let a keyless site call itself
   * configured and skip the wizard it needed. Asking the role layer and the stored
   * credential is the same question with both answers now true.
   */
  public function isConfigured(): bool {
    if ($this->isComplete()) {
      return TRUE;
    }
    $binding = $this->resolver->resolve(ModelRoles::TASK);
    $providerId = (string) ($binding['provider_id'] ?? '');
    return $providerId !== '' && $this->providerManager->isConnected($providerId);
  }

  /**
   * Whether the console should force the onboarding wizard.
   *
   * True only on a genuinely unconfigured site: onboarding never completed AND
   * no usable default chat provider. An operator who provisioned a provider
   * headlessly is already configured, so onboarding is skipped without them
   * seeing it.
   */
  public function needsOnboarding(): bool {
    return !$this->isComplete() && !$this->isConfigured();
  }

  /**
   * Validate a credential WITHOUT persisting; return models + role suggestions.
   *
   * The first half of the two-step onboarding handshake: prove the credential
   * works (a real round-trip that asks the provider for its chat models) and,
   * on success, hand back the model catalogue plus a suggested model per
   * AIncient role ({@see ModelRoleResolver::suggestForProvider()}) so the wizard
   * can pre-fill its per-role pickers. Nothing is written — host providers are
   * probed against a temporary config that is always rolled back.
   *
   * @param string $providerId
   *   The drupal/ai provider plugin id the user picked.
   * @param string $credential
   *   The API key (key providers) or server URL (host providers).
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key.
   *
   * @return array{ok: bool, message: string, models: array<string, string>, suggested: array<string, string>}
   *   On success: the provider's chat models (id => label) and a suggested
   *   model id per role. On failure: a friendly message and empty maps.
   */
  public function validate(string $providerId, string $credential, string $endpoint = ''): array {
    $probe = $this->probeModels($providerId, $credential, ProviderInventory::CHAT, $endpoint);
    if (!$probe['ok']) {
      return ['ok' => FALSE, 'message' => $probe['message'], 'models' => [], 'suggested' => []];
    }
    return [
      'ok' => TRUE,
      'message' => 'Validated.',
      'models' => $probe['models'],
      'suggested' => $this->resolver->suggestForProvider($providerId, $probe['models']),
    ];
  }

  /**
   * Validate a credential against the chosen provider and, on success, persist.
   *
   * Atomic by design: nothing is pinned as the site default unless the
   * credential actually answered with chat models (the probe rolls back any
   * temporary host config on failure, so a bad attempt never leaves a broken
   * default behind).
   *
   * On success the credential is stored, the provider+model is pinned as the
   * default chat provider, and every model role is bound to this provider —
   * using the caller's per-role choices where given and falling back to
   * suggestions otherwise — then projected onto the framework's routing so
   * stock FlowDrop nodes inherit the operator's choice. This leaves a fully
   * resolvable site, not just a single pinned chat model.
   *
   * @param string $providerId
   *   The drupal/ai provider plugin id the user picked.
   * @param string $credential
   *   The API key (key providers) or server URL (host providers).
   * @param string $preferredModel
   *   Optional model id to pin as the default chat model if the provider offers
   *   it (the legacy single-model path); ignored when a `task` role model is
   *   given. Otherwise the `task` role's suggestion is the default.
   * @param array<string, string> $roleModels
   *   Optional role id => chosen model id map (partial allowed). Unspecified or
   *   unavailable roles fall back to suggestions.
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key.
   *
   * @return array{ok: bool, message: string, model?: string}
   *   ok=TRUE with the pinned default model's label on success.
   */
  public function connect(string $providerId, string $credential, string $preferredModel = '', array $roleModels = [], string $endpoint = ''): array {
    $probe = $this->probeModels($providerId, $credential, ProviderInventory::CHAT, $endpoint);
    if (!$probe['ok']) {
      return ['ok' => FALSE, 'message' => $probe['message']];
    }
    $models = $probe['models'];

    // The pinned default chat model is the task-role choice when one was made,
    // else the legacy preferred model, else the task-role suggestion.
    $taskModel = trim((string) ($roleModels[ModelRoles::TASK] ?? ''));
    $modelId = ($taskModel !== '' && isset($models[$taskModel]))
      ? $taskModel
      : $this->pickModel($providerId, $models, $preferredModel);

    $this->persist($providerId, $credential, $modelId, $endpoint);
    $this->bindRoles($providerId, $models, $roleModels);

    return [
      'ok' => TRUE,
      'message' => 'AI connected.',
      'model' => (string) ($models[$modelId] ?? $modelId),
    ];
  }

  /**
   * Connect one provider (or key group): validate a credential and store it.
   *
   * The multi-provider onboarding primitive. Unlike {@see self::connect()}, this
   * neither binds roles nor sets the completion flag — it just proves a
   * credential works and persists it, so the wizard can connect several
   * providers before finalising role bindings across all of them
   * ({@see self::finalizeRoles()}).
   *
   * A provider may be a KEY GROUP ({@see self::KEY_GROUPS}): the credential is
   * probed and stored against every member, so one Google key lights up both
   * `gemini` (chat/vision) and `nanobanana` (image) at once. It succeeds when at
   * least one member answers with models for at least one capability; only
   * answering members are stored. Returned model maps and suggestions are
   * `provider:model`-qualified (the members differ), matching the value shape the
   * models step and {@see self::finalizeRoles()} consume.
   *
   * @param string $providerId
   *   The provider (or key-group primary) plugin id the user picked.
   * @param string $credential
   *   The API key (key providers) or server URL (host providers).
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key. Ignored by
   *   every other shape — a host provider's URL arrives as the credential.
   *
   * @return array{ok: bool, message: string, models: array{chat: array<string, string>, image: array<string, string>}, suggested: array<string, string>}
   *   On success: chat + image models (each "provider:model" => label) and a
   *   suggested "provider:model" per role. On failure: a friendly message.
   */
  public function connectAndStore(string $providerId, string $credential, string $endpoint = ''): array {
    $credential = trim($credential);
    $endpoint = trim($endpoint);
    $empty = ['chat' => [], 'image' => []];
    if ($credential === '') {
      return [
        'ok' => FALSE,
        'message' => $this->authType($providerId) === ProviderAdapterInterface::AUTH_HOST
          ? 'Enter your server URL.'
          : 'Enter your API key.',
        'models' => $empty,
        'suggested' => [],
      ];
    }
    // Half of what a provider needs is not a connection. This used to be where
    // an OpenAI-compatible endpoint was turned away entirely, because the connect
    // step rendered one field and could not collect the second — so the whole
    // shape was kept out of the picker and documented as a pair of `drush
    // state:set` calls. The step renders both fields now; what remains is the
    // ordinary check that both were filled in.
    if ($this->needsEndpoint($providerId) && $endpoint === '') {
      return [
        'ok' => FALSE,
        'message' => sprintf('Enter the base URL %s should call.', $this->labelFor($providerId)),
        'models' => $empty,
        'suggested' => [],
      ];
    }

    $chat = [];
    $image = [];
    $chatMember = '';
    $chatMemberModels = [];
    $failMessage = '';
    foreach (self::KEY_GROUPS[$providerId] ?? [$providerId] as $member) {
      $chatProbe = $this->probeModels($member, $credential, ProviderInventory::CHAT, $endpoint);
      $imageProbe = $this->probeModels($member, $credential, ProviderInventory::IMAGE, $endpoint);
      if (!$chatProbe['ok'] && !$imageProbe['ok']) {
        $failMessage = $chatProbe['message'] ?: $imageProbe['message'];
        continue;
      }
      // At least one capability answered — store this member's credential.
      $this->persistCredential($member, $credential, $endpoint);
      if ($chatProbe['ok']) {
        $chat += $this->qualify($member, $chatProbe['models']);
        if ($chatMember === '') {
          $chatMember = $member;
          $chatMemberModels = $chatProbe['models'];
        }
      }
      if ($imageProbe['ok']) {
        $image += $this->qualify($member, $imageProbe['models']);
      }
    }

    if ($chat === [] && $image === []) {
      return ['ok' => FALSE, 'message' => $failMessage ?: $this->failMessage($providerId), 'models' => $empty, 'suggested' => []];
    }

    return [
      'ok' => TRUE,
      'message' => 'Connected.',
      'models' => ['chat' => $chat, 'image' => $image],
      'suggested' => $this->suggestQualified($chatMember, $chatMemberModels, $image),
    ];
  }

  /**
   * Bind role → provider:model across connected providers, then finish setup.
   *
   * The finalisation half of multi-provider onboarding: it takes the wizard's
   * per-role model choices (each a concrete `{provider_id, model_id}` that may
   * point at a DIFFERENT connected provider — e.g. chat on Anthropic, image on
   * Nano Banana), binds each through the role resolver, projects the bindings
   * onto the framework's routing, and sets the completion flag. It assumes the
   * credentials were already stored by {@see self::connectAndStore()}.
   *
   * @param array<string, array{provider_id: string, model_id: string}> $roleBindings
   *   Role id => the chosen provider+model. Unknown roles and empty bindings are
   *   ignored; at least one valid binding is required.
   * @param string $profileId
   *   The tier these bindings came from, or '' when the operator picked per role.
   *   Stored as the operator's standing INTENT so the site can say which tier is
   *   active and so a later recommendations refresh can honour it; '' puts the
   *   site in Custom, which no refresh will ever overwrite.
   * @param string $recommendationsUpdated
   *   The recommendations document date $profileId resolved against.
   *
   * @return array{ok: bool, message: string}
   */
  public function finalizeRoles(array $roleBindings, string $profileId = '', string $recommendationsUpdated = ''): array {
    $bound = FALSE;
    foreach ($roleBindings as $role => $binding) {
      if (!ModelRoles::isRole((string) $role)) {
        continue;
      }
      $provider = trim((string) ($binding['provider_id'] ?? ''));
      $model = trim((string) ($binding['model_id'] ?? ''));
      if ($provider === '' || $model === '') {
        continue;
      }
      $this->resolver->bind((string) $role, $provider, $model);
      $bound = TRUE;
    }

    if (!$bound) {
      return ['ok' => FALSE, 'message' => 'Choose at least one model.'];
    }

    // Record the intent BEFORE projecting, so the config is consistent the
    // moment anything reads it.
    if (trim($profileId) !== '') {
      $this->resolver->setProfile($profileId, $recommendationsUpdated);
    }
    else {
      $this->resolver->clearProfile();
    }

    // Projecting writes each bound role onto drupal/ai's operation-type defaults
    // (the task role drives `default_providers.chat`) + flowdrop_chat, and
    // invalidates the model cache — so the site is fully routable, not just
    // pinned on one model.
    $this->resolver->project();
    $this->state->set(self::STATE_COMPLETED, TRUE);

    return ['ok' => TRUE, 'message' => 'AI connected.'];
  }

  /**
   * Enumerate a provider's (or key group's) models from its STORED credential.
   *
   * The decoupled counterpart to {@see self::connectAndStore()}: it lists the
   * chat + image models a provider offers WITHOUT a credential being passed in,
   * resolving the stored Key entity → State the provider is already wired to. So
   * the models step can show the full catalogue on load — including
   * on a re-run, or for a key set headlessly (`drush state:set`) — instead of
   * only what was (re)connected in the current session.
   *
   * A keyless or unreachable provider simply yields empty maps (never fatal).
   * Results are `provider:model`-qualified to match the value shape the models
   * step and {@see self::finalizeRoles()} consume; key-group members are merged.
   *
   * @param string $providerId
   *   The provider (or key-group primary) plugin id.
   *
   * @return array{chat: array<string, string>, image: array<string, string>}
   *   Chat + image models, each "provider:model" => label.
   */
  public function modelsForStored(string $providerId): array {
    $chat = [];
    $image = [];
    foreach (self::KEY_GROUPS[$providerId] ?? [$providerId] as $member) {
      // No capability gate to apply here any more. It used to need one because
      // Anthropic answered an image-model question with its chat list, so an
      // ungated read filled the image pool with models that cannot draw; image
      // capability is now a type the provider either has or does not.
      $chat += $this->qualify($member, $this->providerManager->models($member, ProviderInventory::CHAT));
      $image += $this->qualify($member, $this->providerManager->models($member, ProviderInventory::IMAGE));
    }
    return ['chat' => $chat, 'image' => $image];
  }

  /**
   * Remove a provider's (or key group's) stored credential and unbind its roles.
   *
   * The inverse of connecting: deletes the secret (State value + the
   * `<provider>_default_key` Key entity + the provider's `api_key` pointer, or
   * the host URL for host providers), for every member of a key group. Any model
   * role bound to a removed provider is unbound, the bindings are re-projected,
   * and any framework default still pointing at a removed provider is swept — so
   * the site is never left resolving against a deleted key. The completion flag
   * is deliberately left untouched (an admin disconnecting in the wizard is mid-
   * reconfiguration, not resetting first-run).
   */
  public function disconnect(string $providerId): void {
    $removed = [];
    foreach (self::KEY_GROUPS[$providerId] ?? [$providerId] as $member) {
      $this->removeCredential($member);
      $removed[$member] = TRUE;
    }
    $this->unbindProviders($removed);
  }

  /**
   * Delete a single provider's stored credential (key store or host config).
   *
   * The inverse of {@see self::persistCredential()}. Key providers lose their
   * Key entity and their State value; host providers lose their saved endpoint.
   * A provider that stores BOTH loses both — leaving the endpoint behind would
   * make `isConnected()` say no while a disconnected provider still carried half
   * a configuration for the next operator to be surprised by.
   */
  private function removeCredential(string $providerId): void {
    if ($this->authType($providerId) === ProviderAdapterInterface::AUTH_HOST) {
      $this->state->delete($this->endpointKeyFor($providerId));
      return;
    }
    // The Key entity first, then the secret it pointed at.
    $entity = $this->entityTypeManager->getStorage('key')->load($this->keyIdFor($providerId));
    if ($entity !== NULL) {
      $entity->delete();
    }
    $this->state->delete($this->stateKeyFor($providerId));
    if ($this->needsEndpoint($providerId)) {
      $this->state->delete($this->endpointKeyFor($providerId));
    }
  }

  /**
   * Unbind every model role pointing at a removed provider, then re-route.
   *
   * @param array<string, true> $removed
   *   Set of removed provider ids (key group members).
   */
  private function unbindProviders(array $removed): void {
    $roles = $this->configFactory->get('aincient_core.model_roles')->get('roles') ?? [];
    foreach ($roles as $role => $binding) {
      if (isset($removed[(string) ($binding['provider_id'] ?? '')])) {
        // Empty strings unbind the role (bindingFrom() treats them as unset).
        $this->resolver->bind((string) $role, '', '');
      }
    }
    // Re-project the SURVIVING bindings, then clear any framework default that
    // still names a removed provider — project() only writes bound roles, so a
    // role that just became unbound would otherwise leave its old default behind.
    $this->resolver->project();
    $this->sweepDefaults($removed);
  }

  /**
   * Drop any flowdrop_chat default that names a removed provider.
   *
   * @param array<string, true> $removed
   *   Set of removed provider ids.
   */
  private function sweepDefaults(array $removed): void {
    // flowdrop_chat stores a colon-joined "provider:model". Read-only get() never
    // creates the object, so an absent module/config is simply skipped.
    $llm = (string) $this->configFactory->get('flowdrop_chat.settings')->get('llm_provider');
    if ($llm !== '' && isset($removed[explode(':', $llm, 2)[0]])) {
      $this->configFactory->getEditable('flowdrop_chat.settings')->set('llm_provider', '')->save();
    }
  }

  /**
   * Store the credential in its native shape, pin the default, flag complete.
   *
   * The persistence half of {@see self::connect()}, split out so it can be
   * driven directly in tests without a live API round-trip. Stores an API key
   * (key providers) or a server URL (host providers), pins the chosen
   * provider+model as the default chat provider, and sets the completion flag.
   *
   * @param string $providerId
   *   The provider plugin id to make the default chat provider.
   * @param string $credential
   *   The API key (key providers) or server URL (host providers).
   * @param string $modelId
   *   The chat model id to pin (the caller resolves it from the provider's own
   *   models — no vendor default is assumed).
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key.
   */
  public function persist(string $providerId, string $credential, string $modelId = '', string $endpoint = ''): void {
    $this->persistCredential($providerId, $credential, $endpoint);

    // Pin the choice by BINDING THE DEFAULT ROLE, not by writing an operation-type
    // default into `ai.settings` as this used to. Same promise, expressed in the
    // vocabulary the site actually resolves through — and it is the pin the
    // console reads to decide whether onboarding is still owed
    // ({@see \Drupal\aincient_chat\Controller\ChatController::needsOnboarding()}).
    // Skipped without a model: binding a role to an empty model id is not a pin,
    // it is an unbind, and callers who pass none are storing a credential only.
    $modelId = trim($modelId);
    if ($modelId !== '') {
      $this->resolver->bind($this->resolver->defaultRole(), $providerId, $modelId);
      $this->resolver->project();
    }

    $this->state->set(self::STATE_COMPLETED, TRUE);
  }

  /**
   * Persist a credential in its native shape (key store or host config).
   *
   * The shared write path for both the legacy single-provider {@see self::persist()}
   * and the multi-provider {@see self::connectAndStore()} — it stores the secret
   * without touching the default provider or the completion flag, so callers
   * compose it with their own finalisation.
   *
   * @param string $providerId
   *   The provider whose credential is being stored.
   * @param string $credential
   *   The API key, or the base URL when the URL IS the credential (host).
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key.
   */
  private function persistCredential(string $providerId, string $credential, string $endpoint = ''): void {
    if ($this->authType($providerId) === ProviderAdapterInterface::AUTH_HOST) {
      // A host provider's whole credential is its base URL, and the registry reads
      // that from State ({@see PlatformRegistry::endpointFor()}) — so it is stored
      // like any other value, with no per-vendor config object, no host/port split,
      // and no default-port guessing. All three of those were `drupal/ai`'s Ollama
      // client dictating our storage shape.
      $this->state->set($this->endpointKeyFor($providerId), trim($credential));
      return;
    }
    $this->storeApiKey($providerId, $credential);
    // The second half, under the SAME State convention the registry already reads
    // for host providers — so an OpenAI-compatible endpoint needs no third storage
    // shape, and a `drush state:set`-provisioned install and a wizard-connected one
    // are byte-identical.
    if ($this->needsEndpoint($providerId)) {
      $this->state->set($this->endpointKeyFor($providerId), trim($endpoint));
    }
  }

  /**
   * Prefix a provider's model map with its id ("model" => "provider:model").
   *
   * The models step and {@see self::finalizeRoles()} speak in `provider:model`
   * because a role can point at any connected provider; this stamps a single
   * provider's raw model map into that namespace.
   *
   * @param array<string, string> $models
   *   Raw model map (id => label).
   *
   * @return array<string, string>
   *   Qualified map ("provider:id" => label).
   */
  private function qualify(string $providerId, array $models): array {
    $out = [];
    foreach ($models as $id => $label) {
      $out[$providerId . ':' . $id] = (string) $label;
    }
    return $out;
  }

  /**
   * A `provider:model` suggestion per role for a freshly connected group.
   *
   * Chat tiers + vision are suggested from the connected chat member (via the
   * resolver's needle matching), vision mirroring the task tier; the image role
   * is suggested as the first available image model. Every value is
   * `provider:model`-qualified. Roles with no basis are left unset so the wizard
   * falls back to the first option in the relevant pool.
   *
   * @param string $chatMember
   *   The member that answered with chat models (may be '').
   * @param array<string, string> $chatModels
   *   That member's raw chat models (id => label).
   * @param array<string, string> $imageModels
   *   The group's qualified image models ("provider:model" => label).
   *
   * @return array<string, string>
   *   Role id => suggested "provider:model".
   */
  private function suggestQualified(string $chatMember, array $chatModels, array $imageModels): array {
    $out = [];
    if ($chatMember !== '' && $chatModels !== []) {
      foreach ($this->resolver->suggestForProvider($chatMember, $chatModels) as $role => $modelId) {
        if ($modelId !== '') {
          $out[$role] = $chatMember . ':' . $modelId;
        }
      }
      if (isset($out[ModelRoles::TASK])) {
        $out[ModelRoles::VISION] = $out[ModelRoles::TASK];
      }
    }
    if ($imageModels !== []) {
      $out[ModelRoles::IMAGE] = (string) array_key_first($imageModels);
    }
    return $out;
  }

  /**
   * Store an API key via the Key module's State provider and wire the provider.
   *
   * The secret lives in Drupal State (the persistent volume in the appliance),
   * never in config — so a later `drush cex` captures at most "key_provider:
   * state", never the value. The (created-if-missing) Key entity is the named
   * handle on it; {@see \Drupal\aincient_core\Inference\PlatformRegistry}
   * resolves both by convention from the provider id, so there is no per-vendor
   * config object pointing at either. There used to be one — the
   * `ai_provider_<id>.settings: api_key` pointer the `drupal/ai` provider plugins
   * read — and it went with those modules.
   */
  private function storeApiKey(string $providerId, string $key): void {
    $keyId = $this->keyIdFor($providerId);
    $stateKey = $this->stateKeyFor($providerId);
    $this->state->set($stateKey, trim($key));

    $storage = $this->entityTypeManager->getStorage('key');
    /** @var \Drupal\key\KeyInterface|null $entity */
    $entity = $storage->load($keyId);
    if ($entity === NULL) {
      $entity = $storage->create([
        'id' => $keyId,
        'label' => ucfirst($providerId) . ' API key',
        'key_type' => 'authentication',
      ]);
    }
    $entity->set('key_provider', 'state');
    $entity->set('key_provider_settings', ['state_key' => $stateKey]);
    $entity->save();
  }

  /**
   * Probe a provider with a candidate credential, or fail with a message.
   *
   * The shared validation core of {@see self::validate()},
   * {@see self::connect()} and {@see self::connectAndStore()}: it asks the
   * provider for its models with the credential the operator just typed — a real
   * round-trip, which is what makes "models came back" mean "the key works".
   *
   * READS ONLY. Nothing is written, and nothing needs unwinding: the adapter takes
   * the candidate credential as an argument
   * ({@see ProviderInventory::modelsForCredential()}), where the old path had to
   * hand a live plugin a runtime override — or, for a host provider, SAVE the URL,
   * build a plugin so it could read it back, and roll the config back in a
   * `finally`. That rollback was the riskiest code in this file for the least
   * reason, and it is gone rather than ported.
   *
   * @param string $providerId
   *   The provider to probe.
   * @param string $credential
   *   The candidate API key, or the server URL for a host provider.
   * @param string $capability
   *   {@see ProviderInventory::CHAT} or {@see ProviderInventory::IMAGE}.
   * @param string $endpoint
   *   The base URL, for a provider that needs one alongside its key.
   *
   * @return array{ok: bool, message: string, models: array<string, string>}
   *   ok=TRUE with the model map (id => label) when the credential answered.
   */
  private function probeModels(string $providerId, string $credential, string $capability = ProviderInventory::CHAT, string $endpoint = ''): array {
    $credential = trim($credential);
    $endpoint = trim($endpoint);
    if ($credential === '') {
      return [
        'ok' => FALSE,
        'message' => $this->authType($providerId) === ProviderAdapterInterface::AUTH_HOST
          ? 'Enter your server URL.'
          : 'Enter your API key.',
        'models' => [],
      ];
    }
    if ($this->needsEndpoint($providerId) && $endpoint === '') {
      return [
        'ok' => FALSE,
        'message' => sprintf('Enter the base URL %s should call.', $this->labelFor($providerId)),
        'models' => [],
      ];
    }

    $models = $this->providerManager->modelsForCredential($providerId, $capability, $credential, $endpoint);
    if ($models === []) {
      return ['ok' => FALSE, 'message' => $this->failMessage($providerId), 'models' => []];
    }
    return ['ok' => TRUE, 'message' => '', 'models' => $models];
  }

  /**
   * Bind every model role to the connected provider, then project.
   *
   * Uses the caller's per-role choices where given and valid, falling back to
   * {@see ModelRoleResolver::suggestForProvider()} for the rest — so a connect
   * always leaves every role bound to a real model on the connected provider
   * (a fully resolvable site, not just a single pinned chat model). Projecting
   * writes the bindings onto drupal/ai's operation-type defaults + flowdrop_chat.
   *
   * @param array<string, string> $models
   *   The provider's available chat models (id => label).
   * @param array<string, string> $roleModels
   *   Role id => chosen model id (partial allowed).
   */
  private function bindRoles(string $providerId, array $models, array $roleModels): void {
    $suggested = $this->resolver->suggestForProvider($providerId, $models);
    foreach (ModelRoles::ids() as $role) {
      $model = trim((string) ($roleModels[$role] ?? ''));
      if ($model === '' || !isset($models[$model])) {
        $model = $suggested[$role] ?? '';
      }
      if ($model !== '') {
        $this->resolver->bind($role, $providerId, $model);
      }
    }
    $this->resolver->project();
  }

  /**
   * Choose which model to pin as the default chat model.
   *
   * Honours an explicit, available preference; otherwise defers to the role
   * layer's `task`-tier suggestion (the everyday tier the console runs on),
   * falling back to the first model.
   */
  private function pickModel(string $providerId, array $models, string $preferred = ''): string {
    $preferred = trim($preferred);
    if ($preferred !== '' && isset($models[$preferred])) {
      return $preferred;
    }
    $suggested = $this->resolver->suggestForProvider($providerId, $models);
    $task = (string) ($suggested[ModelRoles::TASK] ?? '');
    return $task !== '' ? $task : (string) array_key_first($models);
  }

  /**
   * The provider's human label (e.g. "Anthropic"), falling back to its id.
   */
  private function labelFor(string $providerId): string {
    return $this->providerManager->label($providerId);
  }

  /**
   * A friendly, provider-aware failure message for a credential that didn't work.
   */
  private function failMessage(string $providerId): string {
    $label = $this->labelFor($providerId);
    if ($this->authType($providerId) === ProviderAdapterInterface::AUTH_HOST) {
      return sprintf(
        'Couldn’t reach %s at that URL, or it has no chat models. Make sure the server is running and a model is pulled, then try again.',
        $label,
      );
    }
    if ($this->needsEndpoint($providerId)) {
      // Two fields, two candidate culprits, and the failure cannot tell them
      // apart — a wrong base URL and a refused key both come back as an empty
      // catalogue. Naming both beats guessing at one.
      return sprintf('Couldn’t reach %s — check the base URL and the key, then try again.', $label);
    }
    return sprintf('Couldn’t validate your %s key — check it and try again.', $label);
  }

  /**
   * The key entity id used to store a provider's secret (one per provider).
   */
  private function keyIdFor(string $providerId): string {
    return $providerId . '_default_key';
  }

  /**
   * The Drupal State key a provider's secret is stored under.
   */
  private function stateKeyFor(string $providerId): string {
    return 'aincient.' . $providerId . '_api_key';
  }

  /**
   * The Drupal State key a host provider's base URL is stored under.
   *
   * The same convention as the secret, and the same one
   * {@see \Drupal\aincient_core\Inference\PlatformRegistry::endpointFor()} reads —
   * one place, one spelling, no vendor config object in between.
   */
  private function endpointKeyFor(string $providerId): string {
    return 'aincient.' . $providerId . '_endpoint';
  }

}
