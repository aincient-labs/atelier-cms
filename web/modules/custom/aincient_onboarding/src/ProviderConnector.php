<?php

declare(strict_types=1);

namespace Drupal\aincient_onboarding;

use Drupal\aincient_core\ModelRoleResolver;
use Drupal\aincient_core\ModelRoles;
use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Connects AI for a fresh install — provider-aware, on top of drupal/ai.
 *
 * The onboarding wizard lets the user pick any installed chat provider
 * (Anthropic, OpenAI, Ollama, …). This service connects whichever they chose
 * WITHOUT hard-coding a single vendor: it leans on the drupal/ai provider
 * plugin API so each provider validates, stores its credential, and enumerates
 * its models in its own native way.
 *
 * Validation is "prove the credential works", not "is some field non-empty":
 * we hand the candidate credential to the provider and ask it for its chat
 * models (a real API round-trip). Models returned ⇒ the credential is good.
 *
 * Two credential shapes, by provider:
 * - API-key providers (anthropic, openai): the secret is injected at runtime
 *   via {@see AiProviderInterface::setAuthentication()} for validation, then —
 *   on success — stored through the Key module's STATE provider (the value
 *   lives in Drupal State, never in config/git; CLAUDE.md: secrets never in
 *   git) and the provider's `api_key` setting is pointed at that key entity.
 * - Host providers (ollama): no key — a server URL. drupal/ai's Ollama client
 *   reads its host straight from saved config (not runtime config), so the URL
 *   must be written before the client can reach it; we write, validate against
 *   a fresh instance, and roll the config back if it doesn't answer.
 *
 * On success the chosen provider+model is pinned as the default chat provider
 * (`ai.settings: default_providers.chat`) and the completion flag is set, which
 * flips {@see self::needsOnboarding()} and the chat layer's first-run gate off.
 */
final class ProviderConnector {

  /**
   * The operation type the console runs on — providers must support chat.
   */
  public const OPERATION_TYPE = 'chat';

  /**
   * The image-generation operation type (the Media studio's AI rail).
   */
  public const IMAGE_OPERATION_TYPE = 'text_to_image';

  /**
   * Providers that share a single API key, keyed by the primary shown in the UI.
   *
   * Some vendors ship as two drupal/ai provider plugins that authenticate with
   * the SAME credential — most notably Google, where `gemini` (chat/vision +
   * Imagen) and `nanobanana` (the Gemini 2.5 Flash Image "Nano Banana" model)
   * both take one Google AI Studio key. The onboarding wizard presents such a
   * group as ONE row (the primary), and {@see self::connectAndStore()} stores the
   * entered key against every member so a single key entry lights up all of their
   * capabilities at once. A provider absent here is its own single-member group.
   *
   * @var array<string, list<string>>
   */
  public const KEY_GROUPS = [
    'gemini' => ['gemini', 'nanobanana'],
  ];

  /**
   * Settings config object name overrides for non-`ai_provider_*` modules.
   *
   * {@see self::settingsNameFor()} assumes the `ai_provider_<id>.settings`
   * convention; providers whose module breaks it (e.g. `gemini_provider`) are
   * mapped here. Keyed by provider plugin id => config object name.
   *
   * @var array<string, string>
   */
  private const SETTINGS_CONFIG = [
    'gemini' => 'gemini_provider.settings',
  ];

  /**
   * The Drupal State flag set once onboarding has succeeded.
   */
  public const STATE_COMPLETED = 'aincient_onboarding.completed';

  /**
   * Provider plugin ids that authenticate with a host URL, not an API key.
   */
  private const HOST_PROVIDERS = ['ollama'];

  /**
   * Default Ollama port when the entered URL omits one.
   */
  private const OLLAMA_DEFAULT_PORT = 11434;

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiProviderPluginManager $providerManager,
    private readonly ModelRoleResolver $resolver,
  ) {}

  /**
   * Whether a provider authenticates with a host URL instead of an API key.
   */
  public function authType(string $providerId): string {
    return in_array($providerId, self::HOST_PROVIDERS, TRUE) ? 'host' : 'api_key';
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
   * True once onboarding has run (the completion flag), or — for the operator
   * who set a key via env without ever seeing the wizard — when the configured
   * default chat provider is actually usable.
   */
  public function isConfigured(): bool {
    if ($this->isComplete()) {
      return TRUE;
    }
    $default = $this->providerManager->getDefaultProviderForOperationType(self::OPERATION_TYPE);
    return is_array($default)
      && !empty($default['provider_id'])
      && $this->isUsable($default['provider_id']);
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
   *
   * @return array{ok: bool, message: string, models: array<string, string>, suggested: array<string, string>}
   *   On success: the provider's chat models (id => label) and a suggested
   *   model id per role. On failure: a friendly message and empty maps.
   */
  public function validate(string $providerId, string $credential): array {
    $probe = $this->probeModels($providerId, $credential);
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
   *
   * @return array{ok: bool, message: string, model?: string}
   *   ok=TRUE with the pinned default model's label on success.
   */
  public function connect(string $providerId, string $credential, string $preferredModel = '', array $roleModels = []): array {
    $probe = $this->probeModels($providerId, $credential);
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

    $this->persist($providerId, $credential, $modelId);
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
   * least one member answers with models for at least one operation type; only
   * answering members are stored. Returned model maps and suggestions are
   * `provider:model`-qualified (the members differ), matching the value shape the
   * models step and {@see self::finalizeRoles()} consume.
   *
   * @param string $providerId
   *   The provider (or key-group primary) plugin id the user picked.
   * @param string $credential
   *   The API key (key providers) or server URL (host providers).
   *
   * @return array{ok: bool, message: string, models: array{chat: array<string, string>, image: array<string, string>}, suggested: array<string, string>}
   *   On success: chat + image models (each "provider:model" => label) and a
   *   suggested "provider:model" per role. On failure: a friendly message.
   */
  public function connectAndStore(string $providerId, string $credential): array {
    $credential = trim($credential);
    $empty = ['chat' => [], 'image' => []];
    if ($credential === '') {
      return [
        'ok' => FALSE,
        'message' => $this->authType($providerId) === 'host' ? 'Enter your server URL.' : 'Enter your API key.',
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
      $chatProbe = $this->probeModels($member, $credential, self::OPERATION_TYPE);
      $imageProbe = $this->probeModels($member, $credential, self::IMAGE_OPERATION_TYPE);
      if (!$chatProbe['ok'] && !$imageProbe['ok']) {
        $failMessage = $chatProbe['message'] ?: $imageProbe['message'];
        continue;
      }
      // At least one capability answered — store this member's credential.
      $this->persistCredential($member, $credential);
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

    // A provider was just connected — its model list is now different; drop the
    // cache flowdrop_ai_provider keeps under this tag (see persist()).
    Cache::invalidateTags(['ai_provider_models']);

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
   *
   * @return array{ok: bool, message: string}
   */
  public function finalizeRoles(array $roleBindings): array {
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

    // Projecting writes each bound role onto drupal/ai's operation-type defaults
    // (the task role drives `default_providers.chat`) + flowdrop_chat, and
    // invalidates the model cache — so the site is fully routable, not just
    // pinned on one model.
    $this->resolver->project();
    $this->state->set(self::STATE_COMPLETED, TRUE);

    return ['ok' => TRUE, 'message' => 'AI connected.'];
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
   */
  public function persist(string $providerId, string $credential, string $modelId = ''): void {
    $this->persistCredential($providerId, $credential);

    $modelId = trim($modelId);
    $settings = $this->configFactory->getEditable('ai.settings');
    $providers = $settings->get('default_providers') ?? [];
    $providers[self::OPERATION_TYPE] = [
      'provider_id' => $providerId,
      'model_id' => $modelId,
    ];
    $settings->set('default_providers', $providers)->save();

    $this->state->set(self::STATE_COMPLETED, TRUE);

    // A provider was just connected (key stored, default pinned), so the AI
    // model list is now different. flowdrop_ai_provider's AiModelService caches
    // that list permanently under the `ai_provider_models` tag and only drops
    // it on this tag's invalidation — nothing else fires when a key lands in
    // State. Without this, the cache stays at its pre-onboarding (empty) value
    // and chat nodes resolve no model until a manual `drush cr`.
    Cache::invalidateTags(['ai_provider_models']);
  }

  /**
   * Persist a credential in its native shape (key store or host config).
   *
   * The shared write path for both the legacy single-provider {@see self::persist()}
   * and the multi-provider {@see self::connectAndStore()} — it stores the secret
   * without touching the default provider or the completion flag, so callers
   * compose it with their own finalisation.
   */
  private function persistCredential(string $providerId, string $credential): void {
    if ($this->authType($providerId) === 'host') {
      [$host, $port] = $this->parseHost($credential);
      $this->writeHostConfig($providerId, $host, $port);
    }
    else {
      $this->storeApiKey($providerId, $credential);
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
   * state", never the value. The provider's `api_key` setting is pointed at the
   * (created-if-missing) key entity.
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

    $this->configFactory->getEditable($this->settingsNameFor($providerId))
      ->set('api_key', $keyId)
      ->save();
  }

  /**
   * Write a host provider's URL to its settings; return the prior values.
   *
   * @return array{host: ?string, port: ?int}
   *   The values before the write, for rollback on a failed validation.
   */
  private function writeHostConfig(string $providerId, string $host, ?int $port): array {
    $config = $this->configFactory->getEditable($this->settingsNameFor($providerId));
    $prior = [
      'host' => $config->get('host_name'),
      'port' => $config->get('port'),
    ];
    $config->set('host_name', $host)->set('port', $port)->save();
    return $prior;
  }

  /**
   * Split a server URL into a scheme+host and a port (Ollama default if none).
   *
   * @return array{0: string, 1: int}
   *   [host_name, port] — host_name carries the scheme, port is separate, to
   *   match how the Ollama client rebuilds the base URL.
   */
  private function parseHost(string $url): array {
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
      $url = 'http://' . $url;
    }
    $parts = parse_url($url);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? 'localhost';
    $port = isset($parts['port']) ? (int) $parts['port'] : self::OLLAMA_DEFAULT_PORT;
    return [$scheme . '://' . $host, $port];
  }

  /**
   * Probe a provider with a credential and return its chat models, or fail.
   *
   * The shared validation core of {@see self::validate()} and
   * {@see self::connect()}: it asks the provider for its chat models (a real
   * round-trip that proves the credential). Host providers read their endpoint
   * from saved config, so the URL is written first and ALWAYS rolled back here —
   * callers that want to keep it persist it themselves afterwards. Leaves no
   * trace on failure.
   *
   * @param string $operationType
   *   The operation type to probe (defaults to chat; the image path passes
   *   {@see self::IMAGE_OPERATION_TYPE}).
   *
   * @return array{ok: bool, message: string, models: array<string, string>}
   *   ok=TRUE with the model map (id => label) when the credential answered.
   */
  private function probeModels(string $providerId, string $credential, string $operationType = self::OPERATION_TYPE): array {
    $credential = trim($credential);
    if ($credential === '') {
      return [
        'ok' => FALSE,
        'message' => $this->authType($providerId) === 'host'
          ? 'Enter your server URL.'
          : 'Enter your API key.',
        'models' => [],
      ];
    }

    $isHost = $this->authType($providerId) === 'host';
    $rollback = NULL;
    $models = [];
    try {
      if ($isHost) {
        [$host, $port] = $this->parseHost($credential);
        $rollback = $this->writeHostConfig($providerId, $host, $port);
        // Fresh instance: the Ollama client reads its host from saved config at
        // construction, so it must be created AFTER the write.
        $provider = $this->providerManager->createInstance($providerId);
      }
      else {
        $provider = $this->providerManager->createInstance($providerId);
        // Runtime-only key override; nothing is written by the probe.
        $provider->setAuthentication($credential);
      }
      $models = $provider->getConfiguredModels($operationType);
    }
    catch (\Throwable $e) {
      $models = [];
    }
    finally {
      // The probe never persists host config — the caller does, on success.
      if ($rollback !== NULL) {
        $this->writeHostConfig($providerId, $rollback['host'], $rollback['port']);
      }
    }

    if (empty($models)) {
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
   * Whether a provider is already configured and ready to use.
   */
  private function isUsable(string $providerId): bool {
    try {
      return $this->providerManager->createInstance($providerId)->isUsable(self::OPERATION_TYPE);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * The provider's human label (e.g. "OpenAI"), falling back to its id.
   */
  private function labelFor(string $providerId): string {
    $definitions = $this->providerManager->getDefinitions();
    $label = $definitions[$providerId]['label'] ?? '';
    return $label !== '' ? (string) $label : ucfirst($providerId);
  }

  /**
   * A friendly, provider-aware failure message for a credential that didn't work.
   */
  private function failMessage(string $providerId): string {
    $label = $this->labelFor($providerId);
    if ($this->authType($providerId) === 'host') {
      return sprintf(
        'Couldn’t reach %s at that URL, or it has no chat models. Make sure the server is running and a model is pulled (e.g. `ollama pull llama3`), then try again.',
        $label,
      );
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
   * The settings config object name for a provider module.
   *
   * Follows the `ai_provider_<id>.settings` convention, with per-provider
   * overrides ({@see self::SETTINGS_CONFIG}) for modules that break it (e.g.
   * `gemini_provider`, whose config is `gemini_provider.settings`).
   */
  private function settingsNameFor(string $providerId): string {
    return self::SETTINGS_CONFIG[$providerId] ?? 'ai_provider_' . $providerId . '.settings';
  }

}
