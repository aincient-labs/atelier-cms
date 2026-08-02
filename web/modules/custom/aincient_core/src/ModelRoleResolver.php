<?php

declare(strict_types=1);

namespace Drupal\aincient_core;

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
 * - {@see self::project()}s the default role onto `flowdrop_chat.settings:llm_provider`,
 *   so the chat layer agrees with the console default — no contrib patching;
 * - {@see self::resolve()}s a role to a usable provider+model with a graceful
 *   fallback chain, for any code that wants to honour a role directly.
 *
 * Bindings start empty on a fresh install (fully neutral): nothing routes until a
 * provider is connected, at which point onboarding suggests + pins models.
 */
final class ModelRoleResolver {

  /**
   * The config object holding the bindings — public for the same reason
   * {@see \Drupal\aincient_core\Usage\ModelPricing::CONFIG} is: a surface
   * that renders these bindings needs their cache tag, and a hand-typed config
   * name in a second file is a rename waiting to go unnoticed.
   */
  public const CONFIG = 'aincient_core.model_roles';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
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
   *   2. the default role's binding.
   * Returns empty strings if nothing is configured (a genuinely neutral site).
   *
   * IT USED TO HAVE TWO MORE LINKS, reading drupal/ai's operation-type defaults
   * (`ai.settings: default_providers`) when no role was bound. They are gone, and
   * the reason is that they could only ever resolve to something that then failed:
   * that config is WRITTEN from these bindings by {@see self::project()}, so the
   * only way it could hold a provider the roles do not is if something outside the
   * role layer put one there — and after the move to `symfony/ai` such a provider
   * has no adapter, so the gateway answers UNUSABLE and the call never happens.
   * A fallback whose every distinct outcome is a failure is not a graceful
   * degradation, it is a longer path to the same empty answer. Projection onto
   * those defaults continues unchanged — stock FlowDrop still reads them.
   *
   * @return array{provider_id: string, model_id: string}
   */
  public function resolve(string $role): array {
    if (!ModelRoles::isRole($role)) {
      $role = $this->defaultRole();
    }
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];

    return $this->bindingFrom($bindings, $role)
      ?? $this->bindingFrom($bindings, $this->defaultRole())
      ?? ['provider_id' => '', 'model_id' => ''];
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
    return $this->binding(ModelRoles::IMAGE);
  }

  /**
   * The EXPLICIT binding for ANY role, or NULL when nothing is bound.
   *
   * The generalisation of the two named accessors around it, for a caller that
   * walks {@see ModelRoles::pickerDefinitions()} and must treat all five roles
   * the same way — the rate sheet, which asks "what is this role actually bound
   * to" five times and would otherwise need a switch to ask it. NO fallback:
   * unbound reads as unbound, which is the only answer a page reporting
   * configuration may give.
   *
   * @return array{provider_id: string, model_id: string}|null
   */
  public function binding(string $role): ?array {
    $bindings = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];
    return $this->bindingFrom($bindings, $role);
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
    return $this->binding(ModelRoles::VISION);
  }

  /**
   * Bind a role to a provider + model (writes the source of truth).
   *
   * Does not project — callers that want the binding to take effect across the
   * site call {@see self::project()} after binding (usually once, after binding
   * all roles, to avoid redundant config writes).
   *
   * Deliberately says NOTHING about the active profile: this is the low-level
   * primitive both a profile application and a hand pick go through. Which of
   * those happened is the caller's to declare, via {@see self::setProfile()} or
   * {@see self::clearProfile()} — see the profile accessors below for why the
   * distinction has to be explicit.
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
   * The profile (model tier) in force, or '' when the operator picked per role.
   *
   * The operator's INTENT, stored next to what it resolved to. Without it the
   * bindings are an answer with the question thrown away: we cannot show which
   * tier is active (a configured site can only be described as "Custom"), and a
   * refreshed recommendations document cannot reach a standing "keep me on
   * balanced" — the bindings stay frozen at whatever that tier meant on the day
   * it was chosen. Since page composition runs on the reasoning role, that is
   * precisely the binding most worth keeping current.
   */
  public function profile(): string {
    return (string) ($this->configFactory->get(self::CONFIG)->get('profile') ?? '');
  }

  /**
   * The recommendations date the active profile last resolved against.
   *
   * Empty in Custom, or when a profile predates this being recorded. Lets the UI
   * say "balanced · updated 2026-08-01" and lets a refresh tell a genuine change
   * from a no-op.
   */
  public function profileUpdated(): string {
    return (string) ($this->configFactory->get(self::CONFIG)->get('profile_updated') ?? '');
  }

  /**
   * Record that the bindings now express a profile, as of a document date.
   *
   * Call AFTER binding every role the profile resolved to. Auto mode is
   * all-or-nothing by design: a profile describes the whole site, so there is no
   * "balanced except one role" — {@see self::clearProfile()} covers that case.
   */
  public function setProfile(string $profileId, string $recommendationsUpdated = ''): void {
    $this->configFactory->getEditable(self::CONFIG)
      ->set('profile', trim($profileId))
      ->set('profile_updated', trim($recommendationsUpdated))
      ->save();
  }

  /**
   * Drop to Custom: the operator hand-picked, and owns the models from here.
   *
   * Every path that writes a binding the operator chose themselves must call
   * this, or the site would keep claiming a tier it no longer follows — and the
   * next recommendations refresh would overwrite a deliberate choice.
   */
  public function clearProfile(): void {
    $this->configFactory->getEditable(self::CONFIG)
      ->set('profile', '')
      ->set('profile_updated', '')
      ->save();
  }

  /**
   * Project the current role bindings onto the framework's routing.
   *
   * Sets `flowdrop_chat.settings:llm_provider` from the default role, so the
   * chat layer agrees with the console default.
   *
   * This used to ALSO write every bound role into the drupal/ai operation-type
   * defaults at `ai.settings:default_providers`, which is where the name
   * "project" comes from. That half is gone. Its readers were the `ai_provider_*`
   * config forms and `flowdrop_ai_provider`, all uninstalled once inference moved
   * to our own adapters, so it had become a write with no reader — and a
   * misleading one, because a stale operation-type default looks like a routing
   * table long after anything stopped routing by it. Roles are resolved through
   * {@see self::resolve()} directly.
   */
  public function project(): void {
    $roles = $this->configFactory->get(self::CONFIG)->get('roles') ?? [];

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
