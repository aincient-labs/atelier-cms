<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Resolves AIncient model roles to concrete providers/models, and projects them.
 *
 * This is the seam that makes AIncient provider-neutral. The product's LLM nodes
 * never name a vendor model; they resolve through a *role* ({@see ModelRoles}).
 * An operator binds each role to a `provider:model` once — from onboarding, the
 * console settings form, or the manager CLI — and this service:
 *
 * - stores the binding in `aincient_core.model_roles` (the source of truth);
 * - {@see self::project()}s the bindings onto drupal/ai's operation-type defaults
 *   (`ai.settings:default_providers`) + `flowdrop_chat.settings:llm_provider`, so
 *   stock FlowDrop agent nodes inherit them via their empty-model fallback — no
 *   contrib patching;
 * - {@see self::resolve()}s a role to a usable provider+model with a graceful
 *   fallback chain, for any code that wants to honour a role directly.
 *
 * Bindings start empty on a fresh install (fully neutral): nothing routes until a
 * provider is connected, at which point onboarding suggests + pins models.
 */
final class ModelRoleResolver {

  private const CONFIG = 'aincient_core.model_roles';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AiProviderPluginManager $providerManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The role taxonomy merged with current bindings, in display order.
   *
   * The shape the UIs (onboarding, settings form, `drush aincient:model-list`)
   * render: every defined role, its label/description, and whatever provider+model
   * is currently bound (empty strings when unbound).
   *
   * @return array<string, array{label: string, description: string, provider_id: string, model_id: string, is_default: bool}>
   */
  public function roles(): array {
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];
    $default = $this->defaultRole();
    $out = [];
    foreach (ModelRoles::definitions() as $id => $def) {
      $out[$id] = [
        'label' => $def['label'],
        'description' => $def['description'],
        'provider_id' => (string) ($bindings[$id]['provider_id'] ?? ''),
        'model_id' => (string) ($bindings[$id]['model_id'] ?? ''),
        'is_default' => $id === $default,
      ];
    }
    return $out;
  }

  /**
   * The role FlowDrop chat nodes inherit (drives `default_providers.chat`).
   */
  public function defaultRole(): string {
    $configured = (string) $this->configFactory->get(self::CONFIG)->get('default_role');
    return ModelRoles::isRole($configured) ? $configured : ModelRoles::TASK;
  }

  /**
   * Resolve a role to a concrete provider + model.
   *
   * Fallback chain, first hit wins:
   *   1. the role's own binding;
   *   2. the default role's binding;
   *   3. drupal/ai's default for the role's first operation type (or `chat`);
   *   4. drupal/ai's default `chat` provider.
   * Returns empty strings if nothing is configured (a genuinely neutral site).
   *
   * @return array{provider_id: string, model_id: string}
   */
  public function resolve(string $role): array {
    if (!ModelRoles::isRole($role)) {
      $role = $this->defaultRole();
    }
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];

    $binding = $this->bindingFrom($bindings, $role);
    if ($binding !== NULL) {
      return $binding;
    }
    $binding = $this->bindingFrom($bindings, $this->defaultRole());
    if ($binding !== NULL) {
      return $binding;
    }

    $ops = ModelRoles::operationTypeMap()[$role] ?? [];
    $operationType = $ops[0] ?? 'chat';
    $default = $this->providerManager->getDefaultProviderForOperationType($operationType)
      ?: $this->providerManager->getDefaultProviderForOperationType('chat');
    if (is_array($default) && !empty($default['provider_id'])) {
      return [
        'provider_id' => (string) $default['provider_id'],
        'model_id' => (string) ($default['model_id'] ?? ''),
      ];
    }
    return ['provider_id' => '', 'model_id' => ''];
  }

  /**
   * The EXPLICIT image-role binding, or NULL — the gate for the Media AI rail.
   *
   * Unlike {@see self::resolve()}, this deliberately has NO fallback chain: the
   * {@see ModelRoles::IMAGE} role is either bound to a concrete image provider or
   * it is not. That binary IS the product gate — the Media studio's chat rail
   * (Nano Banana) appears only when this returns a binding, so an unbound site is
   * exactly the "no AI rail, non-AI editor only" state. Never resolve image
   * generation through the op-default: more than one installed provider advertises
   * `text_to_image`, so the op-default is ambiguous — this explicit binding is the
   * single deterministic answer.
   *
   * @return array{provider_id: string, model_id: string}|null
   */
  public function imageBinding(): ?array {
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];
    return $this->bindingFrom($bindings, ModelRoles::IMAGE);
  }

  /**
   * The EXPLICIT vision-role binding, or NULL — the models-page override readback.
   *
   * The vision twin of {@see self::imageBinding()}, but with a different contract:
   * this is NOT a gate. Consumers (the alt-text tool) resolve vision through
   * {@see self::resolve()}, which falls back to the default chat role when this is
   * unbound — so alt-text generation always works. This accessor exists only so the
   * models form can show what an operator EXPLICITLY pinned (empty ⇒ "use the
   * default chat model"), without the resolve() fallback masking an unset binding.
   *
   * @return array{provider_id: string, model_id: string}|null
   */
  public function visionBinding(): ?array {
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];
    return $this->bindingFrom($bindings, ModelRoles::VISION);
  }

  /**
   * Bind a role to a provider + model (writes the source of truth).
   *
   * Does not project — callers that want the binding to take effect across the
   * site call {@see self::project()} after binding (usually once, after binding
   * all roles, to avoid redundant config writes).
   */
  public function bind(string $role, string $providerId, string $modelId): void {
    if (!ModelRoles::isRole($role)) {
      return;
    }
    $config = $this->configFactory->getEditable(self::CONFIG);
    $roles = $config->get('roles') ?? [];
    $roles[$role] = [
      'provider_id' => trim($providerId),
      'model_id' => trim($modelId),
    ];
    $config->set('roles', $roles)->save();
  }

  /**
   * Project the current role bindings onto the framework's routing.
   *
   * Writes each bound role's provider+model into every drupal/ai operation-type
   * default it maps to, and sets `flowdrop_chat.settings:llm_provider` from the
   * default role. Unbound roles are skipped (their operation-type defaults are
   * left as-is). This is what makes stock FlowDrop pick up the operator's choice.
   */
  public function project(): void {
    $roles = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];
    $map = ModelRoles::operationTypeMap();

    $settings = $this->configFactory->getEditable('ai.settings');
    $providers = $settings->get('default_providers') ?? [];
    foreach ($map as $role => $operationTypes) {
      $binding = $this->bindingFrom($roles, $role);
      if ($binding === NULL) {
        continue;
      }
      foreach ($operationTypes as $operationType) {
        $providers[$operationType] = [
          'provider_id' => $binding['provider_id'],
          'model_id' => $binding['model_id'],
        ];
      }
    }
    $settings->set('default_providers', $providers)->save();

    // flowdrop_chat takes a colon-joined "provider:model" string; seed it from
    // the default role so the chat layer agrees with the console default. Guarded
    // because the role layer must not hard-require flowdrop_chat — it's one
    // consumer among others.
    $default = $this->bindingFrom($roles, $this->defaultRole());
    if ($default !== NULL && $this->moduleHandler->moduleExists('flowdrop_chat')) {
      $this->configFactory->getEditable('flowdrop_chat.settings')
        ->set('llm_provider', $default['provider_id'] . ':' . $default['model_id'])
        ->save();
    }

    // Rebinding roles changes which provider/model each operation type resolves
    // to. flowdrop_ai_provider's AiModelService caches its per-operation model
    // list permanently under the `ai_provider_models` tag; invalidate it so the
    // new bindings take effect immediately rather than after a manual cache
    // rebuild.
    Cache::invalidateTags(['ai_provider_models']);
  }

  /**
   * Suggest a model per role for a freshly connected provider.
   *
   * Walks {@see ModelRoles::tierHints()} for the provider against its available
   * chat models; the first needle match wins per role, else the first model. The
   * caller (onboarding) pre-fills the per-role pickers with these.
   *
   * @param string $providerId
   *   The connected provider plugin id.
   * @param array<string, string> $models
   *   Available chat models, keyed by model id (value = label).
   *
   * @return array<string, string>
   *   role id => suggested model id (empty string when no models are available).
   */
  public function suggestForProvider(string $providerId, array $models): array {
    $ids = array_keys($models);
    $first = $ids[0] ?? '';
    $hints = ModelRoles::tierHints()[$providerId] ?? [];

    $out = [];
    foreach (ModelRoles::ids() as $role) {
      $picked = $first;
      foreach ($hints[$role] ?? [] as $needle) {
        foreach ($ids as $id) {
          if (str_contains((string) $id, $needle)) {
            $picked = (string) $id;
            break 2;
          }
        }
      }
      $out[$role] = $picked;
    }
    return $out;
  }

  /**
   * A usable {provider_id, model_id} from a bindings array, or NULL if unset.
   *
   * @param array<string, mixed> $bindings
   *   The `roles` map from config.
   *
   * @return array{provider_id: string, model_id: string}|null
   */
  private function bindingFrom(array $bindings, string $role): ?array {
    $providerId = (string) ($bindings[$role]['provider_id'] ?? '');
    $modelId = (string) ($bindings[$role]['model_id'] ?? '');
    if ($providerId === '' || $modelId === '') {
      return NULL;
    }
    return ['provider_id' => $providerId, 'model_id' => $modelId];
  }

}
